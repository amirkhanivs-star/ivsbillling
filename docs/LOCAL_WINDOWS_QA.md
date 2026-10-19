# IQRA Billing: Windows/XAMPP upgrade and local QA

This is an **upgrade** of the verified starter. The previous migrations succeeded on the user's Windows PC, but the Windows database, seed, login, PDFs and browser cannot be operated or verified from this isolated development environment. No payment or messaging integration test has been run here.

## A. Safe upgrade (PowerShell in VS Code)

1. **Back up first**: export the `iqra_billing` database using phpMyAdmin, and copy the existing `iqra-laravel-billing` project folder to a separate backup directory. Do not copy its `.env` or credential files into chat.
2. Extract the provided `iqra-laravel-billing-updated.zip` to a temporary folder. Copy the revised PHP, views, CSS, documentation, tests and new migration into the existing project. **Keep the working `.env`, `vendor/`, any existing `composer.lock`, and your database.** Review Composer changes before updating packages.
3. In the existing local project, execute:

```powershell
cd C:\Users\hp\Desktop\iqra-laravel-billing
php -m
composer validate
composer update phpoffice/phpspreadsheet --with-all-dependencies
php artisan config:clear
php artisan migrate --pretend
php artisan migrate
php artisan db:seed
php artisan iqra:create-admin
php artisan cache:clear
php artisan route:list
php tests/standalone.php
php artisan test
php artisan serve
```

`php artisan iqra:create-admin` is needed only if an admin was **not already created**. Do not create a duplicate account. `db:seed` is safe to rerun: the base seed uses update-or-insert. Do **not** use `migrate:fresh`, `db:wipe`, `db:seed --class=BillingDemoSeeder`, or import `--commit` against actual student/finance records. Review `migrate --pretend` before applying. New schema change: adds `payments.selected_period` (nullable) to preserve month-restricted advance credits. The updated receiving code **requires** this migration.

Composer's lockfile must be regenerated **on the Windows machine that has Composer and required PHP extensions**, because none is shipped in this archive. Commit the generated `composer.lock` in your project version control for repeatable installs. The 5.x PhpSpreadsheet line is required to replace the starter's outdated `^4.0` constraint; if Composer reports a package incompatibility, record the precise output rather than disabling Composer security checks.

### If `php artisan cache:clear` still fails

```powershell
php artisan config:clear
New-Item -ItemType Directory -Force storage\framework\cache\data, storage\framework\sessions, storage\framework\views, storage\logs, bootstrap\cache
Get-Acl storage
Get-Acl bootstrap\cache
```

Ensure `.env` contains `CACHE_STORE=file` for local XAMPP. Close editors or processes holding locked files. Check Windows access permissions to `storage` and `bootstrap/cache` if they are not writable. Avoid broad permissions on the full project. Do not paste the contents of `.env` into chat.

If `php artisan test` fails with `could not find driver`, enable `pdo_sqlite` in XAMPP `php.ini` and restart your terminal. The shipped `phpunit.xml` uses isolated **in-memory SQLite** to avoid altering the local MySQL billing database. Verify `php -m` lists the expected SQLite extension. The test suite has NOT been executed against Windows yet.

## B. Browser smoke-test checklist

Run `php artisan serve`, visit <http://127.0.0.1:8000>, log in, then test:

- `/dashboard` shows scoped student counts and receipts today; amounts are not added across different currencies.
- `/manage-branches`, `/manage-classes`, `/manage-student`, `/manage-fee` load without errors.
- `/fee-creator` accepts a year plus several Jan–Dec month chips, creates separate monthly runs, skips duplicates, and **does not send any messages**.
- `/invoices` lists generated family invoices, and its PDF button renders correctly.
- `/challan` searches a demo family/GR, shows balances, and offers **one valid department/campus/currency ledger** to receive an amount without bank or verification number.
- Partial payments reduce outstanding balances. Repeating the same idempotency key does not produce an extra receipt.
- A payment targeted at a later month stays reserved until the invoice for that month is generated.
- `/receipts/{id}/pdf` renders the paid receipt; `/reports` exports reconcile with invoices and payments.
- A cashier scoped to one campus cannot access another campus's ledger or invoices.
- Mobile width, browser navigation, form error states, and original MySkool screenshots still need side-by-side acceptance testing.

## C. Optional demo data for a **brand-new empty test database only**

Run **only on a separate empty local test DB** after base seeding:

```powershell
php artisan db:seed --class=BillingDemoSeeder
```

The demo seeder refuses to run outside `local`/`testing` environments or when student/invoice/payment records already exist. It creates family `IQRA-DEMO-001`, two siblings (`DEMO-GR-001`, `DEMO-GR-002`), School fee lines 300.00 + 250.00 SAR, Tuition 150.00 SAR and Quran 100.00 SAR (configured two days/week). Creating one monthly run **for each department** should produce separate family invoices, not a single combined invoice. Quran class days are a descriptive plan field here; a separate approved tariff formula is still required for automated day-based fee calculation.

Do not send messages to families from this test. The demo has no verified parent contacts. To remove demo data, drop and recreate **only the disposable test database**, never a populated billing database.

## D. Before real data or billing

- Reconcile historical receipts, opening balances, waivers, discounts, departments, campuses, and currencies against the source. The separate MySkool fee/outstanding CSVs are not imported by the current XLSX importer.
- Obtain the reference MySkool screenshots at matching viewport sizes and invoice/receipt PDF samples for pixel-accurate styling.
- Approve the Quran weekly-day-to-fee rule and School/Tuition grade/subject tariffs. No values should be inferred from incomplete exports.
- Validate the n8n/BotCommerce/Namecheap workflow, real HTTPS signed PDF links, callbacks, cost/rate limits and authorized test recipients before enabling family messaging.
