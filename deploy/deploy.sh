#!/usr/bin/env bash
# Bhabaghure deploy — run on the server, as root, from anywhere:
#
#   /var/www/Bhabagure/deploy/deploy.sh                  ship origin/main: pull, install, build, migrate, reload
#   /var/www/Bhabagure/deploy/deploy.sh --force          same, even when the server is already on origin/main
#   /var/www/Bhabagure/deploy/deploy.sh --check          report pending commits and config drift; change nothing
#   /var/www/Bhabagure/deploy/deploy.sh --install-nginx  install deploy/nginx/bhabaghure.conf (nginx -t; restored on failure)
#   /var/www/Bhabagure/deploy/deploy.sh --install-units  install changed bhabaghure-* systemd units
#   /var/www/Bhabagure/deploy/deploy.sh --reload-config  after editing api/.env: re-cache config, reload our PHP-FPM,
#                                                        restart our queue worker (no pull, no build)
#
# Shared-server rules (docs/deployment.md): it touches only /var/www/Bhabagure and bhabaghure-* units. It never
# restarts a shared service; nginx is only ever reloaded by --install-nginx, and only after `nginx -t` passes (a file
# that fails is removed again, so the shared nginx is never left with a config it cannot load). Builds run in a
# capped systemd scope so they cannot starve the other sites. Nothing is edited by hand in /var/www/Bhabagure: a
# tracked file changed on the server stops the deploy.
#
# Order: pull → install → build (into directories nothing serves yet) → migrate → reload our PHP-FPM → restart our
# queue worker → switch the admin build and the website slot → health checks. A failed install or build stops before
# the database or anything live has changed.

set -Eeuo pipefail

ROOT=/var/www/Bhabagure
API=$ROOT/api
WEB=$ROOT/web
ADMIN=$ROOT/admin
STATE=$ROOT/.deploy
BRANCH=main
SITE_HOST=bhabaghure.com.bd
NGINX_SRC=$ROOT/deploy/nginx/bhabaghure.conf
NGINX_DST=/etc/nginx/sites-available/bhabaghure.conf
NGINX_LINK=/etc/nginx/sites-enabled/bhabaghure.conf
UNITS=(bhabaghure-php.service bhabaghure-web.service bhabaghure-queue.service bhabaghure-scheduler.service bhabaghure-scheduler.timer)

say() { printf '\n== %s\n' "$*"; }
note() { printf '   %s\n' "$*"; }
die() { printf '\nDEPLOY STOPPED: %s\n' "$*" >&2; exit 1; }

as_www() { runuser -u www-data -- env HOME="$ROOT/.cache/www-home" "$@"; }
artisan() { (cd "$API" && as_www php artisan "$@"); }

# A build step inside a transient scope: memory and CPU capped, lower priority than the sites being served.
capped() {
  local name=$1
  shift
  systemd-run --quiet --scope --unit="bhabaghure-build-${name}-$$-${RANDOM}" \
    -p MemoryHigh=1200M -p MemoryMax=1600M -p CPUQuota=150% --nice=10 -- "$@"
}

need_root() { [[ $EUID -eq 0 ]] || die "run as root (it reloads bhabaghure-* units)."; }

# ── Config drift: files in the repository that differ from what is installed ─────────────────────────────────────
drift_report() {
  local drift=0 unit
  if [[ ! -f $NGINX_DST ]]; then
    note "nginx: $NGINX_DST is not installed — run: $0 --install-nginx"
    drift=1
  elif ! cmp -s "$NGINX_SRC" "$NGINX_DST"; then
    note "nginx: deploy/nginx/bhabaghure.conf differs from the installed copy — review, then: $0 --install-nginx"
    diff -u "$NGINX_DST" "$NGINX_SRC" | sed 's/^/     /' || true
    drift=1
  fi
  for unit in "${UNITS[@]}"; do
    if ! cmp -s "$ROOT/deploy/systemd/$unit" "/etc/systemd/system/$unit"; then
      note "systemd: $unit differs from /etc/systemd/system — review, then: $0 --install-units"
      drift=1
    fi
  done
  [[ $drift -eq 0 ]] && note "nginx file and bhabaghure-* units match the repository."
  return 0
}

# ── --install-nginx ───────────────────────────────────────────────────────────────────────────────────────────────
install_nginx() {
  need_root
  say "Install $NGINX_DST"
  nginx -t -q 2>/dev/null || die "nginx -t fails BEFORE any change (another site's config?). Not touching nginx."
  [[ -f $NGINX_DST ]] && cmp -s "$NGINX_SRC" "$NGINX_DST" && [[ -L $NGINX_LINK ]] && { note "already installed and identical."; return 0; }

  local backup="" had_link=0
  if [[ -f $NGINX_DST ]]; then
    backup="$STATE/nginx-backup/bhabaghure.conf.$(date -u +%Y%m%dT%H%M%SZ)"
    mkdir -p "$STATE/nginx-backup"
    cp -p "$NGINX_DST" "$backup"
    diff -u "$NGINX_DST" "$NGINX_SRC" | sed 's/^/   /' || true
  fi
  [[ -L $NGINX_LINK ]] && had_link=1

  install -m 0644 -o root -g root "$NGINX_SRC" "$NGINX_DST"
  ln -sfn "$NGINX_DST" "$NGINX_LINK"

  if ! nginx -t; then
    if [[ -n $backup ]]; then cp -p "$backup" "$NGINX_DST"; else rm -f "$NGINX_DST"; fi
    [[ $had_link -eq 0 ]] && rm -f "$NGINX_LINK"
    if nginx -t -q 2>/dev/null; then
      die "the new bhabaghure.conf fails nginx -t. The previous state is restored; nginx was NOT reloaded."
    fi
    die "the new bhabaghure.conf fails nginx -t AND the restore still fails — fix /etc/nginx now: other sites cannot reload."
  fi
  systemctl reload nginx
  note "nginx -t passed; nginx reloaded (not restarted)."
}

# ── --install-units ───────────────────────────────────────────────────────────────────────────────────────────────
install_units() {
  need_root
  say "Install changed bhabaghure-* units"
  local unit changed=()
  for unit in "${UNITS[@]}"; do
    cmp -s "$ROOT/deploy/systemd/$unit" "/etc/systemd/system/$unit" && continue
    diff -u "/etc/systemd/system/$unit" "$ROOT/deploy/systemd/$unit" 2>/dev/null | sed 's/^/   /' || true
    install -m 0644 -o root -g root "$ROOT/deploy/systemd/$unit" "/etc/systemd/system/$unit"
    changed+=("$unit")
  done
  [[ ${#changed[@]} -eq 0 ]] && { note "all units already match."; return 0; }
  systemctl daemon-reload
  for unit in "${changed[@]}"; do
    systemd-analyze verify "/etc/systemd/system/$unit" || die "$unit does not verify."
    if [[ $unit == *.service ]] && systemctl is-active --quiet "$unit"; then
      systemctl restart "$unit"
      note "restarted $unit"
    fi
  done
  note "installed: ${changed[*]} (daemon-reload; only bhabaghure-* units restarted)."
}

# ── --reload-config ───────────────────────────────────────────────────────────────────────────────────────────────
# The API runs with a cached config (artisan optimize), so an edit to api/.env does nothing until this runs.
reload_config() {
  need_root
  say "Apply api/.env"
  chown root:www-data "$API/.env"
  chmod 0640 "$API/.env"
  artisan config:clear >/dev/null
  artisan optimize
  systemctl reload bhabaghure-php.service
  note "bhabaghure-php reloaded."
  systemctl restart bhabaghure-queue.service
  systemctl is-active --quiet bhabaghure-queue.service || die "bhabaghure-queue did not start: journalctl -u bhabaghure-queue -n 50"
  note "bhabaghure-queue restarted (its preflight re-checked the Redis settings)."
  artisan tinker --execute='$c = config("bhabaghure.notifications"); echo "   WhatsApp: ".($c["whatsapp"]["mode"] ?? "?")."   SMS: ".($c["sms"]["mode"] ?? "?")."   mail: ".config("mail.default")."   SSLCommerz: ".config("bhabaghure.sslcommerz.mode").PHP_EOL;' 2>/dev/null || true
}

# ── Deploy, stage 1: fetch and fast-forward, then continue with the script that was just pulled ─────────────────
update_code() {
  cd "$ROOT"
  if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
    git status --short --untracked-files=no
    die "tracked files were edited on the server. Nothing is edited in $ROOT: make the change locally, push, deploy. (git diff shows the edit; git checkout -- <file> discards it.)"
  fi
  local before target
  before=$(git rev-parse HEAD)
  git fetch --quiet origin "$BRANCH"
  target=$(git rev-parse "origin/$BRANCH")
  if [[ $before == "$target" && $FORCE -eq 0 ]]; then
    say "Already on origin/$BRANCH ($(git log -1 --format='%h %s'))"
    drift_report
    note "Nothing to deploy. --force rebuilds and reloads anyway."
    exit 0
  fi
  git merge --ff-only --quiet "origin/$BRANCH" || die "origin/$BRANCH does not fast-forward from $before (history was rewritten?)."
  say "Code: ${before:0:7} → ${target:0:7}"
  git log --oneline "$before..$target" | sed 's/^/   /' | head -40

  export BHABAGHURE_DEPLOY_STAGE=build BHABAGHURE_DEPLOY_FROM=$before BHABAGHURE_DEPLOY_LOG=$LOG
  exec "$ROOT/deploy/deploy.sh" "${ARGS[@]}"
}

# ── Deploy, stage 2 ───────────────────────────────────────────────────────────────────────────────────────────────
PAUSED=()
API_DOWN=0
restore_on_exit() {
  local status=$?
  if [[ $API_DOWN -eq 1 ]]; then
    artisan up >/dev/null 2>&1 || true
    note "API maintenance mode lifted."
  fi
  local unit
  for unit in "${PAUSED[@]}"; do
    systemctl start "$unit" || true
    note "started $unit again."
  done
  [[ $status -ne 0 ]] && printf '\nDeploy failed (exit %s). Log: %s\n' "$status" "$LOG" >&2
  return 0
}

build_and_release() {
  cd "$ROOT"
  local from=$BHABAGHURE_DEPLOY_FROM to
  to=$(git rev-parse HEAD)

  for file in "$API/.env" "$WEB/.env.production.local" "$ADMIN/.env.production.local"; do
    [[ -f $file ]] || die "$file is missing (docs/deployment.md §2)."
  done
  mkdir -p "$ROOT/.cache/www-home" "$ROOT/.cache/npm" "$ROOT/.cache/composer"
  chown www-data:www-data "$ROOT/.cache/www-home"

  trap restore_on_exit EXIT

  # The worker and the scheduler load PHP files from disk as they go; pause them while files are being replaced.
  local unit
  for unit in bhabaghure-scheduler.timer bhabaghure-queue.service; do
    if systemctl is-active --quiet "$unit"; then
      systemctl stop "$unit"
      PAUSED+=("$unit")
    fi
  done

  say "API dependencies: composer install --no-dev --optimize-autoloader"
  (cd "$API" && COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_HOME="$ROOT/.cache/composer" \
    capped composer composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist)
  chown -R www-data:www-data "$API/storage" "$API/bootstrap/cache"
  chown root:www-data "$API/.env" "$WEB/.env.production.local"
  chmod 0640 "$API/.env" "$WEB/.env.production.local"

  if [[ ! -d $ROOT/node_modules ]] || [[ $FORCE -eq 1 ]] || ! git diff --quiet "$from" "$to" -- package-lock.json; then
    say "JavaScript dependencies: npm ci"
    note "package-lock.json changed (or first install): the running website may answer errors until it restarts below."
    (cd "$ROOT" && npm_config_cache="$ROOT/.cache/npm" capped npm npm ci --no-audit --no-fund)
  else
    say "JavaScript dependencies: package-lock.json unchanged, npm ci skipped"
  fi

  say "Admin build → admin/dist-next (admin/dist keeps serving)"
  rm -rf "$ADMIN/dist-next"
  (cd "$ADMIN" && capped admin npx tsc -b && capped admin npx vite build --outDir dist-next --emptyOutDir)

  local active="" slot
  [[ -f $STATE/web-slot.env ]] && active=$(sed -n 's/^NEXT_DIST_DIR=//p' "$STATE/web-slot.env")
  slot=.next-a
  [[ $active == ".next-a" ]] && slot=.next-b
  say "Website build → web/$slot (${active:-nothing} keeps serving)"
  rm -rf "${WEB:?}/$slot"
  (cd "$WEB" && NEXT_DIST_DIR=$slot NEXT_TELEMETRY_DISABLED=1 NODE_OPTIONS=--max-old-space-size=1280 \
    capped web npx next build)
  [[ -f $WEB/$slot/BUILD_ID ]] || die "next build finished without $slot/BUILD_ID."

  # A build must not leave tracked files edited (the next deploy would refuse to run). Put them back and say so.
  if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
    note "the builds edited tracked files — restored; commit the change locally so builds stop making it:"
    git diff --stat | sed 's/^/     /'
    git checkout -- .
  fi

  say "Database"
  if artisan migrate:status --pending --no-ansi 2>/dev/null | grep -q "Pending"; then
    artisan down --retry=30 --refresh=15
    API_DOWN=1
    note "API in maintenance mode while migrations run."
  fi
  artisan migrate --force --no-interaction
  artisan db:seed --force --no-interaction
  # api/public belongs to root, so the link is made as root.
  [[ -L $API/public/storage ]] || (cd "$API" && php artisan storage:link --no-interaction)
  artisan optimize
  if [[ $API_DOWN -eq 1 ]]; then
    artisan up
    API_DOWN=0
  fi

  say "Reload bhabaghure-php (tests the pool file first; the shared php8.3-fpm is not touched)"
  if systemctl is-active --quiet bhabaghure-php.service; then
    systemctl reload bhabaghure-php.service
  else
    systemctl start bhabaghure-php.service
  fi

  say "Restart our queue worker and scheduler"
  PAUSED=()
  systemctl restart bhabaghure-queue.service
  systemctl start bhabaghure-scheduler.timer
  systemctl is-active --quiet bhabaghure-queue.service || die "bhabaghure-queue did not start: journalctl -u bhabaghure-queue -n 50"

  say "Switch the admin build"
  rm -rf "$ADMIN/dist-previous"
  [[ -d $ADMIN/dist ]] && mv "$ADMIN/dist" "$ADMIN/dist-previous"
  mv "$ADMIN/dist-next" "$ADMIN/dist"
  rm -rf "$ADMIN/dist-previous"

  say "Switch the website to $slot"
  chown -R www-data:www-data "$WEB/$slot"
  printf 'NEXT_DIST_DIR=%s\n' "$slot" > "$STATE/web-slot.env.new"
  mv "$STATE/web-slot.env.new" "$STATE/web-slot.env"
  systemctl restart bhabaghure-web.service
  if ! wait_for_web; then
    if [[ -n $active && -f $WEB/$active/BUILD_ID ]]; then
      printf 'NEXT_DIST_DIR=%s\n' "$active" > "$STATE/web-slot.env"
      systemctl restart bhabaghure-web.service
      die "the new website build did not answer; back on $active. journalctl -u bhabaghure-web -n 80"
    fi
    die "the website did not answer on 127.0.0.1:3340. journalctl -u bhabaghure-web -n 80"
  fi

  revalidate_all
  health
  printf '%s %s\n' "$to" "$(date -u +%FT%TZ)" > "$STATE/deployed"
  say "Deployed $(git log -1 --format='%h %s')"
  drift_report
}

# Both hosts must answer 200 on their home page — a redirect counts as a failure (a host-routing fault shows up as 308).
wait_for_web() {
  local i
  for i in $(seq 1 45); do
    if [[ $(web_status "$SITE_HOST") == 200 && $(web_status "customer.$SITE_HOST") == 200 ]]; then
      return 0
    fi
    sleep 2
  done
  note "website $(web_status "$SITE_HOST"), portal $(web_status "customer.$SITE_HOST") (both must be 200)"
  return 1
}

web_status() {
  curl -s -o /dev/null -w '%{http_code}' --max-time 5 -H "Host: $1" -H 'X-Forwarded-Proto: https' http://127.0.0.1:3340/ || true
}

# The build rendered pages from the API as it was before migrations; drop those copies so visitors get fresh ones.
revalidate_all() {
  local secret
  secret=$(sed -n 's/^REVALIDATE_SECRET=//p' "$WEB/.env.production.local" | tr -d '"')
  [[ -n $secret ]] || { note "REVALIDATE_SECRET is blank in web/.env.production.local; skipped content refresh."; return 0; }
  printf 'Authorization: Bearer %s\n' "$secret" | curl -fsS -o /dev/null --max-time 15 -H @- -H 'Content-Type: application/json' \
    -d '{"tags":["packages","departures","posts","team","reviews","gallery","settings"]}' http://127.0.0.1:3340/api/revalidate \
    && note "website content cache refreshed." || note "content refresh request failed (pages refresh within the hour anyway)."
}

health() {
  say "Health"
  local code
  note "website (127.0.0.1:3340)            HTTP $(web_status "$SITE_HOST")"
  note "customer portal (127.0.0.1:3340)    HTTP $(web_status "customer.$SITE_HOST")"
  if [[ -f $NGINX_DST ]]; then
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 http://127.0.0.1:3341/api/v1/public/settings) || true
    note "API over loopback (127.0.0.1:3341)  HTTP $code"
    # -k: this checks nginx → PHP-FPM → Laravel; certificate coverage is reported separately below.
    code=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 10 --resolve "api.$SITE_HOST:443:127.0.0.1" "https://api.$SITE_HOST/up") || true
    note "API through nginx (/up)            HTTP $code"
    [[ $code == 200 ]] || die "the API health check failed: tail $API/storage/logs/laravel-*.log; journalctl -u bhabaghure-php -n 50"
    local cert=/etc/letsencrypt/live/$SITE_HOST/fullchain.pem host names
    if [[ -f $cert ]]; then
      names=$(openssl x509 -in "$cert" -noout -ext subjectAltName 2>/dev/null | grep -o 'DNS:[^,]*' | tr -d ' ')
      for host in "$SITE_HOST" "www.$SITE_HOST" "admin.$SITE_HOST" "customer.$SITE_HOST" "wallet.$SITE_HOST" "api.$SITE_HOST"; do
        grep -qxE "DNS:($host|\*\.$SITE_HOST)" <<<"$names" || note "certificate does not cover $host yet"
      done
      note "certificate expires $(openssl x509 -in "$cert" -noout -enddate | cut -d= -f2)"
    fi
  else
    note "nginx file not installed yet: API checks skipped."
  fi
  for unit in bhabaghure-php.service bhabaghure-web.service bhabaghure-queue.service bhabaghure-scheduler.timer; do
    note "$(printf '%-30s' "$unit") $(systemctl is-active "$unit")"
  done
}

# ── main ──────────────────────────────────────────────────────────────────────────────────────────────────────────
ARGS=("$@")
FORCE=0
ACTION=deploy
for arg in "$@"; do
  case $arg in
    --force) FORCE=1 ;;
    --check) ACTION=check ;;
    --install-nginx) ACTION=install-nginx ;;
    --install-units) ACTION=install-units ;;
    --reload-config) ACTION=reload-config ;;
    -h|--help) sed -n '2,22p' "$0"; exit 0 ;;
    *) die "unknown option $arg (see --help)" ;;
  esac
done

need_root
mkdir -p "$STATE/logs"
chmod 0750 "$STATE"
# Stage 2 is the same deploy re-executed after the pull: it inherits the lock on fd 9 (and so does the log tee), so it
# must not try to take it again.
if [[ ${BHABAGHURE_DEPLOY_STAGE:-} != build ]]; then
  exec 9>"$STATE/deploy.lock"
  flock -n 9 || die "another deploy is running (lock: $STATE/deploy.lock)."
fi

case $ACTION in
  check)
    cd "$ROOT"
    git fetch --quiet origin "$BRANCH"
    say "Server on $(git log -1 --format='%h %s'); origin/$BRANCH is $(git rev-parse --short "origin/$BRANCH")"
    git log --oneline "HEAD..origin/$BRANCH" | sed 's/^/   pending: /'
    drift_report
    exit 0
    ;;
  install-nginx) install_nginx; exit 0 ;;
  install-units) install_units; exit 0 ;;
  reload-config) reload_config; exit 0 ;;
esac

if [[ ${BHABAGHURE_DEPLOY_STAGE:-} == build ]]; then
  # Output still flows through the log tee that stage 1 started.
  LOG=$BHABAGHURE_DEPLOY_LOG
  build_and_release
else
  LOG="$STATE/logs/deploy-$(date -u +%Y%m%dT%H%M%SZ).log"
  exec > >(tee -a "$LOG") 2>&1
  say "Deploy started $(date -u +%FT%TZ) — log $LOG"
  update_code
fi
