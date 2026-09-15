#!/usr/bin/env bash
# /var/www/Bhabagure/deploy/testrun-steps.sh — runs inside the units deploy/testrun.sh starts, as www-data. One log per
# step in .testrun/logs, and one line per step in results.txt. A failing suite doesn't stop the next one.

set -uo pipefail

RUN=/var/www/Bhabagure/.testrun
REPO=$RUN/repo
RESULTS=$RUN/logs/results.txt

step() {
  local name=$1 start status
  shift
  start=$(date +%s)
  echo "=== $name started $(date -u +%T)"
  "$@" > "$RUN/logs/$name.log" 2>&1
  status=$?
  printf '%-10s exit %-3s %5ss  (finished %s UTC)\n' "$name" "$status" "$(( $(date +%s) - start ))" "$(date -u +%T)" | tee -a "$RESULTS"
  return "$status"
}

install_deps() {
  set -e
  cd "$REPO/api"
  composer install --no-interaction --no-progress --prefer-dist
  cd "$REPO"
  npm ci --no-audit --no-fund
  npx playwright install chromium-headless-shell
}

lint() { cd "$REPO" && npm run lint; }
unit() { cd "$REPO" && npm test; }
types() { cd "$REPO/admin" && npx tsc -b && cd "$REPO/wallet" && npx tsc -b && cd "$REPO/web" && npm run typecheck; }
api() { cd "$REPO/api" && php artisan config:clear && php artisan test; }
admin() { cd "$REPO/admin" && npx playwright test; }
web() { cd "$REPO/web" && npx playwright test; }
wallet() { cd "$REPO/wallet" && npx playwright test; }

case ${1:-} in
  install) step install install_deps ;;
  all)
    for name in lint unit types api admin web wallet; do
      step "$name" "$name" || true
    done
    echo "=== all done $(date -u +%T)"
    ;;
  lint|unit|types|api|admin|web|wallet) step "$1" "$1" ;;
  *) echo "usage: steps.sh install|all|lint|unit|types|api|admin|web|wallet"; exit 1 ;;
esac
