#!/usr/bin/env bash
# /var/www/Bhabagure/deploy/wallet-database.sh — once, as root, on the VPS (docs/phase-7-hr-attendance-bonus-wallet.md §8,
# docs/deployment.md §7.6).
#
# Creates the super admin wallet's own database, bhabaghure_wallet, and its own MySQL user, bhabaghure_wallet@127.0.0.1,
# with rights on that database only. Writes the WALLET_* settings into api/.env: a generated password and encryption key
# that are never printed, the wallet host, and secure cookies. Checks that the company user has no way into the wallet.
#
# Touches nothing else on the shared MySQL: no other database, no other user, no server setting. Safe to run again: an
# existing database, user, password or key is kept. Afterwards: deploy.sh (it runs the wallet migrations).

set -Eeuo pipefail

ROOT=/var/www/Bhabagure
ENV_FILE=$ROOT/api/.env
DATABASE=bhabaghure_wallet
WALLET_USER=bhabaghure_wallet
DB_HOST=127.0.0.1
WALLET_HOST=wallet.bhabaghure.com.bd

die() { printf 'STOPPED: %s\n' "$*" >&2; exit 1; }
say() { printf '== %s\n' "$*"; }

[[ $EUID -eq 0 ]] || die "run as root."
[[ -f $ENV_FILE ]] || die "$ENV_FILE is missing."
mysql --protocol=socket -N -e 'SELECT 1' >/dev/null 2>&1 || die "MySQL isn't reachable as root over its socket."

env_value() { sed -n "s/^$1=//p" "$ENV_FILE" | tail -n 1; }

company_user=$(env_value DB_USERNAME)
[[ -n $company_user ]] || die "DB_USERNAME is blank in api/.env."
[[ $company_user != "$WALLET_USER" ]] || die "the company and the wallet must not share a MySQL user."

password=$(env_value WALLET_DB_PASSWORD)
key=$(env_value WALLET_KEY)
user_exists=$(mysql -N -e "SELECT COUNT(*) FROM mysql.user WHERE user = '$WALLET_USER' AND host = '$DB_HOST'")
if [[ -z $password ]]; then
  # 28 random letters and digits, then one of each class the server's validate_password policy asks for. The symbols
  # are safe inside the SQL quotes below and unquoted in api/.env.
  password="$(openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | head -c 28)Kq7-_"
  new_password=1
else
  new_password=0
fi
[[ -n $key ]] || key="base64:$(openssl rand -base64 32)"

say "Database $DATABASE and user $WALLET_USER@$DB_HOST"
# The SQL goes to mysql on stdin, so the password never appears in a process list or the shell history.
{
  printf 'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n' "$DATABASE"
  if [[ $user_exists == 0 ]]; then
    printf "CREATE USER '%s'@'%s' IDENTIFIED BY '%s';\n" "$WALLET_USER" "$DB_HOST" "$password"
  elif [[ $new_password == 1 ]]; then
    # The user exists but api/.env has no password for it (an earlier run stopped part-way): give it this one.
    printf "ALTER USER '%s'@'%s' IDENTIFIED BY '%s';\n" "$WALLET_USER" "$DB_HOST" "$password"
  fi
  printf "GRANT ALL PRIVILEGES ON \`%s\`.* TO '%s'@'%s';\n" "$DATABASE" "$WALLET_USER" "$DB_HOST"
} | mysql --protocol=socket

say "Isolation"
company_grants=$(mysql -N -e "SHOW GRANTS FOR '$company_user'@'$DB_HOST'")
# Neither a grant on the wallet database nor any server-wide privilege beyond USAGE.
if grep -qE "ON \`?$DATABASE\`?\." <<<"$company_grants"; then
  die "$company_user has a grant on $DATABASE. Remove it before the wallet holds anything."
fi
if grep -E 'ON \*\.\* ' <<<"$company_grants" | grep -vqE '^GRANT USAGE ON \*\.\* '; then
  die "$company_user has server-wide privileges, which reach $DATABASE. Narrow them to its own database first."
fi
printf '   %s has no grant on %s.\n' "$company_user" "$DATABASE"
wallet_grants=$(mysql -N -e "SHOW GRANTS FOR '$WALLET_USER'@'$DB_HOST'")
if grep -vE "^GRANT USAGE ON \*\.\* |ON \`$DATABASE\`\." <<<"$wallet_grants" | grep -q .; then
  die "$WALLET_USER has a grant beyond $DATABASE. Check SHOW GRANTS."
fi
printf '   %s has rights on %s only.\n' "$WALLET_USER" "$DATABASE"

say "api/.env (values not shown)"
set_env() {
  local name=$1 value=$2 tmp
  tmp=$(mktemp)
  grep -v "^$name=" "$ENV_FILE" > "$tmp" || true
  printf '%s=%s\n' "$name" "$value" >> "$tmp"
  # Written back into the same file, so its owner and mode (root:www-data 0640) stay as they are.
  cat "$tmp" > "$ENV_FILE"
  rm -f "$tmp"
}
set_env WALLET_DB_HOST "$DB_HOST"
db_port=$(env_value DB_PORT)
set_env WALLET_DB_PORT "${db_port:-3306}"
set_env WALLET_DB_DATABASE "$DATABASE"
set_env WALLET_DB_USERNAME "$WALLET_USER"
set_env WALLET_DB_PASSWORD "$password"
set_env WALLET_KEY "$key"
set_env WALLET_HOST "$WALLET_HOST"
set_env WALLET_COOKIE_SECURE true
unset password key
printf '   WALLET_DB_*, WALLET_KEY, WALLET_HOST and WALLET_COOKIE_SECURE are set.\n'

say "Done. Next: $ROOT/deploy/deploy.sh (runs the wallet migrations and re-caches the config)."
