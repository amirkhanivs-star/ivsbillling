# IQRA Billing upgrade status | 17 October 2026

## Baseline respected

The uploaded starter already contained Laravel 12, authentication/roles, seeded departments, invoice/receipt/report PDFs, CSV/XLSX/PDF exports, the MySkool-inspired UI, n8n dispatch/callback, and a MySkool XLSX importer. These modules were **not rebuilt**. The audit's statement that Windows migrations succeeded is user-reported; there is no access here to the Windows app or MySQL database.

## Changes in this upgrade

| Area | Targeted improvement |
| --- | --- |
| Monthly invoice creation | Jan–Dec month chips, year and due-day, separate full-month runs, atomic batch, no auto-send. Preserves the original custom-period path. |
| Duplicate family invoices | Reuses an existing unsent invoice for the same family, campus, department, currency and period. Rejects silent post-approval additions pending an explicit correction flow. |
| Receiving | Validates the family actually has an active enrollment or historical invoice in the selected campus/department/currency ledger. Keeps Receive free from bank, proof and verification fields. |
| Advance payments | New nullable `payments.selected_period` column. Month-targeted unapplied credits stay reserved for their intended calendar month, and general credits pay oldest eligible obligations before new ones. |
| User access | Non-global users require an assigned campus; department-role accounts require a department. Scoped selectors and ledger search. Enrollment edits for department staff cannot start from an otherwise inaccessible student. |
| Dashboard | Today receipt count and collection totals now come from payment ledger; currency values are never added together. Removed unsupported staff/inquiry KPI guesses. |
| Demo testing | Optional `BillingDemoSeeder` for empty local/test-only databases, with two siblings and three departments. Not run by the base seeder. |
| Dependencies | PhpSpreadsheet constraint upgraded from `^4.0` to `^5.10` to follow the version user previously installed locally. `.env.example` targets a local XAMPP `root` account; use a restricted DB account in production. |
| Tests | Added pure date unit tests and database/HTTP regression definitions for multi-month creation, same-period invoice reuse, post-approval protection, targeted advances and invalid ledger rejection. |

## Checks actually run in this environment

- PHP syntax checks passed for all PHP source, routes, migrations, seeders and test files.
- Standalone checks passed for existing money and legacy billing calculations, and for new monthly period date logic.
- The fee-creator's inline JavaScript passed `node --check`.
- `composer.json` parsed as valid JSON.

**Not tested here:** Composer dependency installation; Laravel boot/Artisan seeding/feature suite; MySQL migrations; PDF rendering; browser login/layout; the user's Windows permissions/cache; data import/reconciliation; n8n/webhook/WhatsApp/email delivery. This isolated PHP runtime does not contain Composer, a Laravel `vendor/` tree or MySQL/SQLite drivers. See `docs/LOCAL_WINDOWS_QA.md` for the exact test steps on the local Windows machine.

## Still pending, intentionally not guessed

1. **Windows smoke test:** seeding, admin creation if required, login, protected routes, receiving, PDFs, reports and `php artisan test`.
2. **Historical ledger reconciliation:** source MySkool fee and outstanding reports cannot be taken as reliable accounting entries without department/currency/period/receipt reconciliation. No historical payments/opening dues imported in this release.
3. **Tariff rules:** Quran fee-per-class-days mapping, School grade-level tariffs and Tuition subject/grade tariffs require explicit approved rates. Existing enrolled `amount_minor` continues to drive invoice generation, and no fees are automatically guessed.
4. **Money movement refinements:** refunds, receipt reversals, waivers, invoice correction after approval, advanced discount workflows, currency-specific minor units, concurrency stress tests and broader authorization test coverage.
5. **Pixel-accurate MySkool UI:** original screenshots were **not among the files available in this conversation**. Existing palette/layout is preserved and the changed screens refined, but screenshot-by-screenshot matching and responsiveness/browser testing are outstanding. Sample invoice/receipt PDFs also unavailable for exact comparison.
6. **Live messaging:** n8n workflow, BotCommerce template, email, rate/budget enforcement and webhook callback remain unverified. No real family sending should occur until contact permission, balances and delivery are approved.
7. **Composer lockfile:** cannot be generated here without Composer/extensions/network/dependency resolution. Generate `composer.lock` on the Windows PC after `composer update phpoffice/phpspreadsheet --with-all-dependencies` and commit it with the code.

This is a **development upgrade**, not a production-validated billing release.
