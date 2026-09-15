#!/usr/bin/env bash
# /var/www/Bhabagure/deploy/testrun.sh — every test suite, run on the VPS against throwaway databases (docs/handover.md §6).
# As root, in order:
#
#   testrun.sh setup [commit]   clone the deployed commit (or [commit]) into .testrun/repo; create the test databases and
#                               users; write that copy's api/.env
#   testrun.sh install          composer, npm and Playwright's Chromium, in a capped unit (needs the internet; ~10 min)
#   testrun.sh run              lint, unit tests, types, API, admin e2e, website e2e, wallet e2e, in a capped unit that
#                               can reach localhost only (~1–2 h); `run api` (or admin, web, …) runs one suite
#   testrun.sh status           which unit is running, and the results so far (logs in .testrun/logs)
#   testrun.sh cleanup          stop the units, drop the test databases and users, delete .testrun
#
# The suites rebuild their databases from scratch, so they never run against the live ones. This touches neither the
# live databases nor the live checkout, Redis or any shared service. The suites run as www-data with the whole
# filesystem read-only except .testrun and, for `run`, no network beyond localhost, so nothing can send a real message
# or take a payment. Databases: bhabaghure_testing, bhabaghure_e2e, bhabaghure_wallet_testing, bhabaghure_wallet_e2e.
# Users: bhabaghure_t and bhabaghure_wt @127.0.0.1, each with rights on its own two test databases only. Clean up the
# same day: the server's nightly backup (02:30 UTC) needs 5 GB free and would otherwise copy the test databases.

set -Eeuo pipefail

ROOT=/var/www/Bhabagure
RUN=$ROOT/.testrun
REPO=$RUN/repo
COMPANY_USER=bhabaghure_t
WALLET_USER=bhabaghure_wt
HOST=127.0.0.1

die() { printf 'STOPPED: %s\n' "$*" >&2; exit 1; }
say() { printf '== %s\n' "$*"; }
[[ $EUID -eq 0 ]] || die "run as root."

unit_props=(
  -p User=www-data -p Group=www-data -p WorkingDirectory="$REPO"
  -p ProtectSystem=strict -p ReadWritePaths="$RUN" -p PrivateTmp=yes -p ProtectHome=yes -p NoNewPrivileges=yes
  -p CPUQuota=100% -p MemoryHigh=2G -p MemoryMax=2600M -p MemorySwapMax=0 -p Nice=15 -p IOWeight=20
  -p OOMScoreAdjust=800 -p TasksMax=512 -p RuntimeMaxSec=4h
  -E HOME="$RUN/home" -E npm_config_cache="$RUN/npm-cache" -E COMPOSER_HOME="$RUN/composer-home"
  -E PLAYWRIGHT_BROWSERS_PATH="$RUN/ms-playwright" -E E2E_BROWSER=bundled -E NEXT_TELEMETRY_DISABLED=1
  -E NODE_OPTIONS=--max-old-space-size=1280 -E CI=1
)

sql_exists() {
  local dbs users
  dbs=$(mysql --protocol=socket -N -e "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name IN ('bhabaghure_testing','bhabaghure_e2e','bhabaghure_wallet_testing','bhabaghure_wallet_e2e')")
  users=$(mysql --protocol=socket -N -e "SELECT COUNT(*) FROM mysql.user WHERE user IN ('$COMPANY_USER','$WALLET_USER')")
  echo $((dbs + users))
}

set_env() {
  local file=$1 name=$2 value=$3 tmp
  tmp=$(mktemp)
  grep -v "^$name=" "$file" > "$tmp" || true
  printf '%s=%s\n' "$name" "$value" >> "$tmp"
  cat "$tmp" > "$file"
  rm -f "$tmp"
}

case ${1:-} in
  setup)
    commit=${2:-$(git -C "$ROOT" rev-parse HEAD)}
    [[ ! -e $RUN ]] || die "$RUN already exists (cleanup first)."
    [[ $(sql_exists) == 0 ]] || die "a test database or user already exists. Nothing created."
    free_gb=$(df -P --block-size=1G / | awk 'NR==2{print $4}')
    (( free_gb >= 7 )) || die "only ${free_gb} GB free; the nightly server backup needs 5."

    say "Workspace $RUN"
    install -d -m 0750 -o www-data -g www-data "$RUN" "$RUN/logs" "$RUN/home" "$RUN/npm-cache" "$RUN/composer-home" "$RUN/ms-playwright"
    runuser -u www-data -- env HOME="$RUN/home" git clone --quiet https://github.com/digiwebdex/bhabagure.git "$REPO"
    runuser -u www-data -- env HOME="$RUN/home" git -C "$REPO" -c advice.detachedHead=false checkout --quiet "$commit"
    printf '   %s\n' "$(runuser -u www-data -- env HOME="$RUN/home" git -C "$REPO" log -1 --format='%h %s')"

    say "Test databases and users"
    company_password="$(openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | head -c 28)Kq7-_"
    wallet_password="$(openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | head -c 28)Kq7-_"
    # On stdin, so the passwords never appear in a process list. The underscores are escaped: in a grant they are
    # wildcards otherwise.
    mysql --protocol=socket <<SQL
CREATE DATABASE bhabaghure_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE bhabaghure_e2e CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE bhabaghure_wallet_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE bhabaghure_wallet_e2e CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '$COMPANY_USER'@'$HOST' IDENTIFIED BY '$company_password';
GRANT ALL PRIVILEGES ON \`bhabaghure\_testing\`.* TO '$COMPANY_USER'@'$HOST';
GRANT ALL PRIVILEGES ON \`bhabaghure\_e2e\`.* TO '$COMPANY_USER'@'$HOST';
CREATE USER '$WALLET_USER'@'$HOST' IDENTIFIED BY '$wallet_password';
GRANT ALL PRIVILEGES ON \`bhabaghure\_wallet\_testing\`.* TO '$WALLET_USER'@'$HOST';
GRANT ALL PRIVILEGES ON \`bhabaghure\_wallet\_e2e\`.* TO '$WALLET_USER'@'$HOST';
SQL
    mysql --protocol=socket -N -e "SHOW GRANTS FOR '$COMPANY_USER'@'$HOST'; SHOW GRANTS FOR '$WALLET_USER'@'$HOST';" | sed 's/^/   /'

    say "api/.env for the test copy (from .env.example; no live value is copied)"
    env_file=$REPO/api/.env
    cp "$REPO/api/.env.example" "$env_file"
    set_env "$env_file" APP_ENV local
    set_env "$env_file" APP_KEY "base64:$(openssl rand -base64 32)"
    set_env "$env_file" LOG_LEVEL warning
    set_env "$env_file" DB_PORT 3306
    # The suites switch to bhabaghure_testing (phpunit.xml) and bhabaghure_e2e (scripts/e2e-api.mjs) themselves.
    set_env "$env_file" DB_DATABASE bhabaghure_testing
    set_env "$env_file" DB_USERNAME "$COMPANY_USER"
    set_env "$env_file" DB_PASSWORD "$company_password"
    set_env "$env_file" JWT_SECRET "$(openssl rand -hex 32)"
    set_env "$env_file" REVALIDATE_SECRET "$(openssl rand -hex 24)"
    set_env "$env_file" WALLET_DB_PORT 3306
    set_env "$env_file" WALLET_DB_DATABASE bhabaghure_wallet_testing
    set_env "$env_file" WALLET_DB_USERNAME "$WALLET_USER"
    set_env "$env_file" WALLET_DB_PASSWORD "$wallet_password"
    set_env "$env_file" WALLET_KEY "base64:$(openssl rand -base64 32)"
    set_env "$env_file" WALLET_COOKIE_SECURE false
    set_env "$env_file" AUTH_REFRESH_COOKIE_SECURE false
    # Nothing listens on port 1: a stray Redis call fails here instead of reaching the shared Redis.
    set_env "$env_file" REDIS_PORT 1
    set_env "$env_file" SSLCOMMERZ_MODE fake
    set_env "$env_file" WASENDER_MODE fake
    set_env "$env_file" BULKSMSBD_MODE fake
    set_env "$env_file" PDF_CHROME_PATH "$ROOT/tools/chrome-for-testing/current/chrome-headless-shell-linux64/chrome-headless-shell"
    chown www-data:www-data "$env_file"
    chmod 0600 "$env_file"
    unset company_password wallet_password
    echo "   written (values not shown)."
    ;;

  install)
    say "Dependencies (unit bhabaghure-testrun-install; logs .testrun/logs/install.log and install-unit.log)"
    # The unit's own output goes to a file systemd opens as root, so it must not be the step's log (www-data writes that).
    systemd-run --unit=bhabaghure-testrun-install --collect --quiet "${unit_props[@]}" \
      -p StandardOutput=append:"$RUN/logs/install-unit.log" -p StandardError=append:"$RUN/logs/install-unit.log" \
      /bin/bash "$ROOT/deploy/testrun-steps.sh" install
    ;;

  run)
    [[ -d $REPO/api/vendor && -d $REPO/node_modules ]] || die "install first."
    say "Suites (unit bhabaghure-testrun; localhost only; results .testrun/logs/results.txt)"
    systemd-run --unit=bhabaghure-testrun --collect --quiet "${unit_props[@]}" \
      -p IPAddressDeny=any -p IPAddressAllow=localhost \
      -p StandardOutput=append:"$RUN/logs/runner.log" -p StandardError=append:"$RUN/logs/runner.log" \
      /bin/bash "$ROOT/deploy/testrun-steps.sh" "${2:-all}"
    ;;

  status)
    for unit in bhabaghure-testrun-install bhabaghure-testrun; do
      printf '%-28s %s\n' "$unit" "$(systemctl is-active "$unit" 2>/dev/null || true)"
    done
    [[ -f $RUN/logs/results.txt ]] && cat "$RUN/logs/results.txt"
    df -h / | tail -1
    ;;

  cleanup)
    say "Stop the test units"
    systemctl stop bhabaghure-testrun.service bhabaghure-testrun-install.service 2>/dev/null || true
    say "Drop the test databases and users"
    mysql --protocol=socket <<SQL
DROP DATABASE IF EXISTS bhabaghure_testing;
DROP DATABASE IF EXISTS bhabaghure_e2e;
DROP DATABASE IF EXISTS bhabaghure_wallet_testing;
DROP DATABASE IF EXISTS bhabaghure_wallet_e2e;
DROP USER IF EXISTS '$COMPANY_USER'@'$HOST';
DROP USER IF EXISTS '$WALLET_USER'@'$HOST';
SQL
    [[ $(sql_exists) == 0 ]] && echo "   none left." || die "something is left; check information_schema and mysql.user."
    say "Delete $RUN"
    [[ $RUN == /var/www/Bhabagure/.testrun ]] && rm -rf "$RUN"
    df -h / | tail -1
    ;;

  *) sed -n '2,19p' "$0"; exit 1 ;;
esac
