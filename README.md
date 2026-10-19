# IQRA Virtual School: Laravel + MySQL Billing Replica

**Implementation source, not a deployed or production-approved system.** This package contains real PHP/Laravel services, MySQL migrations, route/controllers, Blade screens styled after the supplied MySkool screenshots, branded PDF layouts, an n8n outbound job, Excel import command and regression tests. It is an initial billing MVP conversion of the user-provided Node/Express source, **not an assertion that every feature of the original Node application has been ported** or that UI screenshots are pixel-identical.

## Important business rules implemented

- Shared families and persistent GR numbers; multiple campuses and School/Quran/Tuition enrollments.
- Family invoice per campus + department + billing run + currency, with separate student/subject fee lines.
- Multiple fee frequencies: monthly, weekly, quarterly, term, annual, one-time and custom (matching fee plans must exist). The Challan UI supports Jan–Dec multi-month selection, generating one separate full-calendar-month run per selected month.
- Fee plan discounts, fixed or percentage, reduce billed fees without increasing collections.
- Unique obligation key `(fee_plan_item_id, period_key)` blocks duplicate charges for repeat invoice generation.
- Previous dues appear on PDF without becoming new invoice items.
- Partial payments, oldest-due-first across eligible invoice items; optional month targeting.
- Unallocated advance credit is applied to oldest eligible dues; month-targeted credits are reserved for the selected month.
- Receipt PDFs, invoice PDFs, invoice review, manual selected-channel send and n8n status callback.
- Verified family contact + WhatsApp messaging permission required before enqueueing.
- `POST /challan/receive` requires **no bank name, collection account or verification number**, neither in the form nor the backend.
- Finance/admin roles and tighter department/campus restrictions for billing and receiving.
- Department and class reports, cash collection by receipt month and CSV/XLSX/PDF exports, all currency-separated.

## Before deployment

Server must have **PHP 8.2+** (recommended 8.3/8.4), Composer 2, MySQL 8+, extensions `pdo_mysql`, `mbstring`, `xml`, `zip`, `gd`, `intl`, `curl`, `fileinfo`, `openssl`, `dom`. This build environment lacks Composer, Laravel vendor dependencies and database extensions, so **Composer install and Laravel integration tests have NOT been run here**. See `docs/LOCAL_WINDOWS_QA.md` for the Windows upgrade and test sequence. Laravel framework/vendor dependencies are not bundled.

Hostinger VPS suggested install (Ubuntu with Nginx + PHP FPM):

```bash
# After installing PHP, Composer 2, MySQL and required PHP extensions on your VPS:
cd /var/www/iqra-billing
composer install --no-dev --optimize-autoloader
cp .env.example .env
# Edit .env: DB credentials, APP_URL=https://your-domain, APP_DEBUG=false,
# APP_ENV=production, N8N_* secrets. Never commit .env.
php artisan key:generate
mkdir -p storage/app/private storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
php artisan migrate --force
php artisan db:seed --force
php artisan iqra:create-admin
php artisan optimize
```

Ensure `storage/` and `bootstrap/cache/` are writable by the PHP-FPM user. Set web server DOCUMENT_ROOT to `/var/www/iqra-billing/public`, with HTTPS. Never expose `.env`, spreadsheet exports, ZIP, SQLite or `/data`. Back up the MySQL database before imports and enable routine encrypted backups. Configure a separate worker:

```bash
php artisan queue:work database --queue=notifications,default --tries=3 --timeout=60
```

Run it under Supervisor/systemd, not a browser request. Configure `APP_URL` to an HTTPS host accessible by your n8n instance because PDFs use short-lived signed links. Do **not** send real invoices until n8n callback/signature and BotCommerce delivery have been verified.

### First-run import

The supplied `Myskool replica.xlsx` has a **Total data of Myskool** worksheet (GR, Family Code, Class, Monthly Fee) but **no student fee currency or department column** and its parent contact fields are empty. Do not guess currencies or departments. Split or classify rows into **homogeneous** workbook exports first, or have an administrator provide a reviewed mapping; then import:

```bash
php artisan iqra:import-myskool /secure/input/school_sar.xlsx --campus=main --department=school --currency=SAR
# Inspect dry-run counts, then explicitly commit:
php artisan iqra:import-myskool /secure/input/school_sar.xlsx --campus=main --department=school --currency=SAR --commit
```

The command deliberately does not mark contacts verified or WhatsApp permission granted. Never import unverified phone numbers as send-ready. There are separate `Outstanding Fee` and `Fee Report` sheets; **historical amounts are NOT blindly imported** by this command. They require transaction-level and currency reconciliation before posting verified opening balances. The uploaded source SQLite database and student uploads are **not included** in this package.

### First UI setup

1. Log in with the admin created via `iqra:create-admin`.
2. Add campuses and any missing programs/classes.
3. Review student admission details and add Quran/Tuition enrollments as needed.
4. Confirm each enrollment currency, student fee plan, subject/day structure, amount and discounts.
5. Enter verified family email/WhatsApp details and explicit WhatsApp messaging permission.
6. Enter per-department bank/payment **invoice instructions only** under Invoice Settings. This is intentionally separate from the Receive form.
7. Open `Challan`, generate a selected period's invoices, review them in `Invoices`, then queue selected channels to n8n.
8. Open `Fee Collection`, enter family/GR, select an eligible department/campus/currency ledger, optionally a month, enter amount, press **Receive**, print receipt.
9. Check Department/Class/Month reports and export.

### No bank or verification number in receiving

`app/Services/ReceivePaymentService.php` only accepts family, campus, department, currency, amount, date, selected period, note and idempotency key. No bank/master verification/paid slip columns or validation gates were copied from the Node source. PDF invoice payment instructions can still show banking details.

## Incomplete / requires production work

- The frontend closely follows MySkool's desktop layout but **has not undergone pixel-diff or browser acceptance testing** and several original non-billing areas are not implemented.
- Source Node.js source contains additional admission and discount editing flows, manually waived months, advanced bulk receipts, payment reversals, special user roles and more. This package ports the core calculator/payment/invoicing behavior, **not the entire Node app**.
- Legacy source discount calculations are ported as a standalone PHP adapter (`LegacyBillingCalculator`); importing historical JSON billings into normalized invoices needs a reviewed, reconciled migration plan.
- Original invoice sample's bank/QR data is deliberately **not hard-coded**. Add current approved banking and payment details through Invoice Settings. Receipt graphics/header are based on supplied project assets; a per-department PNG/JPEG header can be uploaded under Invoice Settings.
- Currency handling currently assumes **two decimal places**. Configure currency metadata before currencies with different minor units (e.g., JPY).
- Full refund/reversal/waiver workflows, editable fee-plan history, advanced invoice approval tiers, anti-fraud audits, sophisticated report cash-date analytics, granular permission administration and production contact-import rules remain future work.
- n8n workflow, BotCommerce approved templates, Namecheap SMTP credentials and production delivery confirmation are **not bundled**. Test real send + callbacks before using family accounts.
- No secret credentials, actual student records, databases or historical paid slips are present in this deliverable.

## Tests

Without Composer/dependencies, run the isolated source-logic checks:

```bash
php tests/standalone.php
```

With Laravel and a properly configured testing database (MySQL preferred; SQLite also works when `pdo_sqlite` is installed):

```bash
php artisan test
```

Tests cover family grouping, duplicate billing, multi-month generation, ledger-scope validation, month-targeted credits, partial/idempotent receiving and source calculator parity. Laravel tests still need execution on Windows. They are **not a substitute for a live migration reconciliation**.

## Project structure

- `database/migrations`: 2 original normalized schema migrations plus 1 upgrade migration for selected-month advance credits.
- `app/Services/InvoiceService.php`: family grouping, discount calculation, previous dues and duplicate charge prevention.
- `app/Services/ReceivePaymentService.php`: bank-free, verification-free amount receiving and oldest-first allocation.
- `app/Services/LegacyBillingCalculator.php`: JS source calculator parity adapter.
- `app/Jobs/DispatchInvoiceToN8n.php`: signed asynchronous outbound webhook.
- `app/Http/Controllers/BillingController.php`: web CRUD, reporting, export, PDF and n8n callback.
- `app/Console/Commands/ImportMySkool.php`: controlled dry-run XLSX import.
- `resources/views`: MySkool-inspired UI and invoice/receipt report templates.
- `public/assets/myskool.css`: visual style.
- `docs/n8n-contract.md`: message contract and safe deployment notes.

This should be reviewed by a Laravel developer and validated against real anonymized test data before accepting fees or sending notices to real families.

## Upgrade notes (2026-10-17)

See [`docs/LOCAL_WINDOWS_QA.md`](docs/LOCAL_WINDOWS_QA.md) before upgrading a working local installation. Preserve its `.env`, `vendor/`, database and existing lockfile until dependencies are updated. The `payments.selected_period` migration must run before receiving with this version. An opt-in, local-only `BillingDemoSeeder` is included for disposable empty databases, not for production data. Exact UI comparison, historical balances and live n8n delivery remain pending.
