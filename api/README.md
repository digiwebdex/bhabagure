# Bhabaghure API

Laravel 13 REST API for the website, customer portal and staff admin → `api.bhabaghure.com.bd`.

Endpoint list, permissions and the schema rules it enforces: [`docs/phase-2-cms-api.md`](../docs/phase-2-cms-api.md).
Schema: [`docs/phase-1-schema.md`](../docs/phase-1-schema.md). Generated OpenAPI docs: `/docs/api` (local only).

## Run it locally

Prerequisites: PHP 8.3+ with `gd` (WebP), `pdo_mysql`, `intl`, `mbstring`, `bcmath`; Composer 2; MySQL 8.4.

### Database

Either `docker compose up -d` from the repo root (MySQL on 3307), or — without Docker — run the MySQL 8.4
binaries already on the machine as a project-local server. Everything stays in `.devdb/` (git-ignored);
no Windows service is installed and nothing is written outside the repository:

```bash
# once, from the repo root
mkdir -p .devdb && cat > .devdb/my.ini <<'INI'
[mysqld]
basedir="C:/Program Files/MySQL/MySQL Server 8.4"
datadir="<repo>/.devdb/data"
port=3307
bind-address=127.0.0.1
mysqlx=OFF
character-set-server=utf8mb4
collation-server=utf8mb4_0900_ai_ci
INI
mysqld --defaults-file=.devdb/my.ini --initialize-insecure

# each session
mysqld --defaults-file=.devdb/my.ini --console

# once: databases and a user (pick your own password; keep it out of git)
mysql -h127.0.0.1 -P3307 -uroot -e "
  CREATE DATABASE bhabaghure CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
  CREATE DATABASE bhabaghure_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
  CREATE DATABASE bhabaghure_e2e CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
  CREATE USER 'bhabaghure_user'@'127.0.0.1' IDENTIFIED BY '…';
  GRANT ALL ON bhabaghure.* TO 'bhabaghure_user'@'127.0.0.1';
  GRANT ALL ON bhabaghure_testing.* TO 'bhabaghure_user'@'127.0.0.1';
  GRANT ALL ON bhabaghure_e2e.* TO 'bhabaghure_user'@'127.0.0.1';"
```

The optional database triggers (`DB_GUARD_TRIGGERS`, off by default) are the only thing that would need
`log_bin_trust_function_creators=1`; add it to `my.ini` locally if you want `DatabaseGuardTriggersTest` to run
instead of skipping. Never change it on the shared server.

### App

```bash
cd api
composer install
cp .env.example .env            # set DB_PASSWORD
php artisan key:generate
php artisan jwt:secret
php artisan storage:link
php artisan migrate --seed      # roles, permissions and website content from packages/content-seed

php artisan db:seed --class=DevStaffSeeder      # optional: one login per role, random passwords printed once
php artisan db:seed --class=DemoContentSeeder   # optional: illustrative departures, reviews, gallery

php artisan serve               # http://localhost:8000
```

Phase 3 settings are in `.env.example`: `SSLCOMMERZ_MODE=fake` runs the whole payment flow locally without SSLCommerz;
`PASSPORT_OCR_PROVIDER=none` keeps OCR off; `PDF_CHROME_PATH` points invoice PDFs at a Chrome binary (desktop Chrome locally).
Run `npm install` at the repo root once for `packages/pdf`. Scheduled jobs (`payments:reconcile`, `bookings:complete-travelled`,
`passport-scans:prune`) need `php artisan schedule:work` locally or the scheduler on the server.

Phase 4 (notifications): `WASENDER_MODE=fake` writes every WhatsApp message to `storage/logs/whatsapp-fake.log` instead of
sending it, and `MAIL_MAILER=log` puts emails in `storage/logs/laravel.log`. Customer WhatsApp messages wait until a
notifications number is set in Admin → Site settings. Scheduled trip messages need `notifications:dispatch` (the
scheduler); on the server the queue worker sends them ([`docs/deployment.md`](../docs/deployment.md) §5).

Point the website at it with `CONTENT_SOURCE=api`, `API_URL=http://localhost:8000` and
`NEXT_PUBLIC_API_URL=http://localhost:8000` in `web/.env.local`.

### Tests

```bash
php artisan test        # feature tests on MySQL — the triggers and generated columns only exist there
vendor/bin/pint         # code style
```

Tests use the `bhabaghure_testing` database and refuse to run against any database whose name doesn't end
in `_testing`, because they drop every table.

## Rules this code keeps

- **Money** is `DECIMAL(12,2)` and leaves the API as a JSON number. `bookings.due_amount` and
  `invoices.balance_due` are generated columns.
- **Money and status have one writer each**: `LedgerService` for paid amounts and payment status (derived from the cash
  book), `BookingStateMachine` for booking status, `InvoiceIssuer` for invoice status — the models refuse other writes.
- **Online payments are never trusted from the browser**: `PaymentService::settle` validates with SSLCommerz and is
  idempotent. `SSLCOMMERZ_MODE=live` is refused unless `APP_ENV=production`; `fake` is refused in production.
- **Ledgers are append-only** (`transactions`, `journal_entries`, `journal_lines`, `audit_logs`, later `bonus_transactions` and the wallet): enforced in
  the application — the `AppendOnly` model trait and `LedgerQueryGuard`, which refuses UPDATE/DELETE SQL against
  them on every connection. Corrections are reversing entries. A new ledger table goes into `LedgerTables::TABLES`.
  Optional MySQL triggers enforce the same rules: `php artisan db:guard-triggers status|install|drop`.
- **Issued invoices are frozen snapshots**: the model refuses changes to what was billed. Void and reissue.
- **WhatsApp numbers always come from records.** No endpoint accepts a typed phone number to message, for any role;
  every automated WhatsApp message starts with the sender line; nothing goes to customers from an unpublished number.
- **Only published content is public.** Every CMS list starts in draft.
- **Uploads**: ≤ 5 MB, JPEG/PNG/WebP, re-encoded to WebP variants with EXIF removed; the raw file is never kept.
  Passport scans and payment evidence never go through this pipeline or the public disk.
- **Seeders never overwrite** existing rows and never truncate — the production MySQL instance is shared.
- **No secrets in git.** `.env` is ignored. `APP_KEY` encrypts passport numbers: back it up outside the
  server and outside git, or they are unrecoverable.
