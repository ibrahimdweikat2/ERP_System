# دفتر — ERP for a single appliance store

Laravel 12 / Sanctum, MySQL 8.4, database queue, React / TypeScript / Tailwind. Arabic RTL interface. Requirements are in the numbered files under `docs/`.

Implementation proceeds through the phase gates in [the progress record](docs/IMPLEMENTATION_PROGRESS.md). The complete production ERP is still being built; unimplemented modules are visibly unavailable.

## Local development

The configured workspace has a MySQL 8.4 server on **127.0.0.1:3307**, active database `erp_store_mysql8`, API on **127.0.0.1:8188**, and UI at **http://127.0.0.1:5173**. A separate MySQL instance on **127.0.0.1:3308** hosts `erp_test` and `erp_browser_test`; its disposable data is under `%LOCALAPPDATA%/ERP-MySQL-Tests/data` on C: to isolate test I/O from the active store on E:.

Existing accounts and passwords were preserved during the authorized MariaDB migration. No fixed default password is committed. Original environment, schema/data backup, SHA-256 manifest and the row/FK verification report are stored in ignored `.runtime/migration-backups`; the source MariaDB database is preserved. The first owner on a new installation must be provisioned using `php artisan erp:create-owner email@example.com` after migrations and seeding.

From PowerShell:

```powershell
./scripts/start-development.ps1
```

For a separately provisioned server:

1. Install PHP 8.2+ with BCMath, cURL, Fileinfo, Mbstring, OpenSSL, PDO MySQL, XML and Zip; install Composer and MySQL 8+.
2. In `backend`, copy `.env.example` to `.env`, configure database/mail/URLs, run `composer install`, `php artisan key:generate`, and `php artisan migrate --seed`.
3. Use a dedicated migration account with trigger creation privileges. Production runtime credentials must not have schema-altering privileges or update/delete permission on audit/posted ledger tables.
4. In `frontend`, run `npm ci` and `npm run build`. Serve `dist` and proxy `/api` and `/sanctum` to Laravel on the same origin.
5. Set secure session cookies and HTTPS in production. Configure real SMTP before using password-reset email. Local mail uses Laravel's log driver.

## Verification

```powershell
./scripts/start-test-database.ps1
$env:PHPRC = "$PWD/.runtime/php.ini"
Set-Location backend
php artisan test --compact
php vendor/bin/pint --test
Set-Location ../frontend
npm run build
npm run lint
```

Backend tests refuse to run unless the database name ends in `_test`. They use real MySQL migrations, transactions, triggers, concurrent processes and database workers. Browser checks use a separate test store on API 8199/UI 5199, never the active store. From the workspace root:

Run the backend and browser suites sequentially: rebuilding MySQL schemas concurrently with browser requests can cause I/O-related browser timeouts on the local machine. The backend target guard runs before Laravel's migration test hooks.

```powershell
./scripts/start-browser-tests.ps1 -ResetData
$env:ERP_E2E_URL = 'http://127.0.0.1:5199'
$env:ERP_E2E_CREDENTIALS = "$PWD/.runtime/e2e-credentials.json"
Set-Location frontend
npx playwright test
```

`-ResetData` resets only the configured browser test database, whose name and isolated MySQL port are checked by the helper. Omit it to retain existing test fixtures. Financial browser scenarios create disposable test transactions and output print previews/screenshots under ignored `frontend/test-results`.

## Workers and scheduler

```text
php artisan queue:work database --queue=default --tries=3 --timeout=60
php artisan queue:work database --queue=reports,exports --tries=2 --timeout=300
php artisan queue:work database --queue=notifications,maintenance --tries=5 --timeout=120
php artisan schedule:run
php artisan erp:queue-probe
php artisan queue:failed
```

Use [Supervisor configuration](deploy/supervisor.conf) on Linux. The queue reservation timeout is 360 seconds, above the longest worker timeout. Schedule `schedule:run` once per minute. Core financial posting remains inside the request transaction.

## Configuration and release

No operational opening balances or live VAT rate are seeded. Set the store identity, fiscal years, tax versions and account mappings before operations. Posted journals are immutable at the MySQL level, and reversals preserve their original currency conversion.

Production release requires every applicable phase gate, reconciliation, restore rehearsal and accountant configuration review in the supplied documentation. Dependency support references and test results are recorded in `docs/IMPLEMENTATION_PROGRESS.md`.
