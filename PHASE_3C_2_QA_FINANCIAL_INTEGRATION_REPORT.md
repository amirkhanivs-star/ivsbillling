# IQRA Billing — Phase 3C.2: QA Financial Integration and Safety Corrections Report

**Project:** IQRA Laravel Billing System  
**Baseline Commit:** `11c92c1`  
**Phase:** 3C.2 — QA Financial Integration and Safety Corrections  
**Date:** October 22, 2026  
**Status:** COMPLETE (Ledger Posting Blocked Pending Authorization)  

---

## Executive Summary

Phase 3C.2 addresses and resolves all findings identified during independent code review of Phase 3C.1. It establishes strict physical database isolation for the QA application, reconciles student financial approval counts with mathematical mutual exclusivity (summing to exactly 4,352), documents and preserves pre-existing QA test ledger fixtures in a dedicated SQL backup, hardens financial decimal parsing and version tracking in staging, implements a net historical opening-balance preview service with strict posting safeguards, and verifies billing portal protections for review-hold and historical-hold students.

All 123 automated feature and unit tests (847 assertions) pass with 100% green coverage. Zero records in the quarantined database (`iqra_billing`) or the approved staging database (`iqra_billing_clean`) were accessed or mutated.

---

## Task 1: Database Connection Isolation

### 1.1 Root Cause & Architectural Correction
In Phase 3C.1, billing controllers and standard Eloquent operations utilized Laravel's default connection (`mysql`), which in local environments could inadvertently point to an unverified target.

In Phase 3C.2, strict database isolation was implemented across both HTTP and CLI contexts:
1. **Default Connection Setting (`config/database.php`):**
   ```php
   'default' => env('DB_CONNECTION', 'qa'),
   ```
2. **Dedicated QA Database Binding (`config/database.php` & `.env`):**
   ```php
   'qa' => [
       'driver' => 'mysql',
       'database' => env('DB_QA_DATABASE', env('DB_DATABASE', 'iqra_billing_qa')),
       ...
   ],
   ```
3. **Application Boot-Time Safety Enforcer (`app/Providers/AppServiceProvider.php`):**
   Every HTTP request and non-test CLI execution validates that the active database is `iqra_billing_qa` and explicitly asserts that neither `iqra_billing` (quarantine) nor `iqra_billing_clean` (staging) is targeted:
   ```php
   if (!app()->runningInConsole() || (!app()->environment('testing') && !defined('PHPUNIT_COMPOSER_INSTALL'))) {
       $defaultConn = config('database.default');
       DatabaseSafetyGuard::assertQaDatabase($defaultConn);
       DatabaseSafetyGuard::assertNotQuarantineDatabase($defaultConn);
       DatabaseSafetyGuard::assertNotStagingDatabase($defaultConn);
   }
   ```
4. **Blade View Database Queries Unified:**
   `qa-dues.blade.php` and `qa-collections.blade.php` were updated to use standard Eloquent/QueryBuilder calls (`DB::table('students')`) over the default `qa` connection, eliminating cross-connection joins.

### 1.2 Verification Proof
- **CLI Connection:** `php artisan tinker --execute="echo DB::connection()->getDatabaseName();"` outputs `iqra_billing_qa`.
- **Browser HTTP Server:** The local web server running via `artisan serve` boots `AppServiceProvider` and binds to `iqra_billing_qa`. Any attempt to configure `DB_DATABASE=iqra_billing` or `iqra_billing_clean` immediately throws a `DatabaseSafetyException` and terminates execution.

---

## Task 2: Financial Approval Count Reconciliation

### 2.1 Resolution of Previous Overlap
The Phase 3C.1 report reported:
- 4,352 present registration records
- 4,280 active billable
- 55 REVIEW
- 17 YES
- 1 blank currency

**Audit Finding:** GR `10666` (Muhammad Yousuf) has:
- `currency` = `SAR`
- `currency_evidence` = `NO_EVIDENCE`
- `currency_provisional` = `YES`
- `financial_import_status` = `REVIEW_REQUIRED`

In Phase 3C.1, this student was tallied once under `currency_provisional = YES` and once under a separate "blank currency" category due to lack of payment slip evidence, resulting in an apparent discrepancy.

### 2.2 Mutually Exclusive Student Classification
Every one of the 4,352 registration GRs in `01_REGISTRATION_PRESENT (1)` was categorized into exactly one mutually exclusive bucket based on multi-column evaluation (`currency`, `currency_evidence`, `currency_provisional`, `Monthly Fee`, and `Date of Leaving`):

| Classification Category | Sub-Condition / Evidence | Registration Count | Monthly Fee Sum | Portal Billable Status |
|:---|:---|---:|---:|:---|
| **1. Active Billable Students** | Prov = NO, Valid Curr, Fee > 0, Leaving Date Blank | **3,754** | 1,842,886.00 | **Active (`billable = 1`)** |
| **2. Active Non-Billable Students** | Prov = NO, Valid Curr, Fee = 0.00, Leaving Date Blank | **367** | 0.00 | **Active (`billable = 0`)** |
| **3. Leaving Date Discrepancy** | Present in sheet, but Date of Leaving is populated | **159** | 68,367.00 | **Active (`leaving_review`)** (141 Fee > 0, 18 Fee = 0) |
| **4. Financial Review Hold — REVIEW** | Evidenced only by new dues PDF, not payment slip | **55** | 24,795.00 | **Review Hold (`review_hold`)** (49 Fee > 0, 6 Fee = 0) |
| **5. Financial Review Hold — YES** | Prov = YES or No Evidence (incl. GR 10666) | **17** | 1,230.00 | **Review Hold (`review_hold`)** (3 Fee > 0, 14 Fee = 0) |
| **TOTAL REGISTRATION RECORDS** | **Mutually Exclusive Sum** | **4,352** | **1,937,278.00** | **Exact Match (100.0%)** |

**Total Students in QA Portal Database:**
- October 22 Present Registration Records: **4,352**
- Preserved Historical Baseline Students (Oct 19 staging, `historical_hold`): **18**
- **Grand Total Students in `iqra_billing_qa`:** **4,370**

---

## Task 3: Pre-Existing QA Ledger Investigation

### 3.1 Investigation Findings
Prior to importing the reviewed workbook, `iqra_billing_qa` contained:
- 2,646 invoices
- 2,646 invoice items
- 1 payment
- 1 allocation
- 3 billing runs

**Audit Determination:**
These records originated between October 20, 2026 11:25:34 and October 21, 2026 11:04:35 during Phase 3C.0 interactive UI testing by User 1 (Admin):
1. **Billing Run #1:** Single-family test invoice `SCH-2026-0000001` (SAR 350.00) generated for September 2026.
2. **Billing Run #2:** Single-family test invoice `SCH-2026-0000002` (SAR 700.00) generated for October 2026.
3. **Billing Run #3:** Bulk test run generating 2,644 invoices (`SCH-2026-0000003` to `SCH-2026-0002646`) for October 2026 monthly fees.
4. **Payment #1 & Allocation #1:** Cash payment of SAR 350.00 received against Invoice #1.

### 3.2 Backup Preservation and Isolation
- **Dedicated Backup File:** `backups/qa_test_ledger_fixture_oct20_21.sql` (3,196,917 bytes).
- **Git Protection:** Ignored via `/backups/` and `*.sql` rules in `.gitignore`.
- **Status:** Preserved completely in `iqra_billing_qa`. Kept strictly separated from historical opening balances.
- **Safety Directive:** Zero records have been deleted. Any future reset or purge of this test fixture will require explicit administrative authorization.

---

## Task 4: Financial Staging Hardening and Version Tracking

### 4.1 Exact Decimal Arithmetic & Validation
`ReviewedWorkbookImportService` was hardened:
- `parseMoneyDecimal(mixed $val, ?string &$error = null): float`
- `parseMoneyMinor(mixed $val, ?string &$error = null): int`

Non-numeric or corrupted values are captured as formal errors and logged to `qa_staged_exceptions` under `INVALID_MONETARY_AMOUNT` rather than silently converting to `0.00`.

### 4.2 Staging Version Tracking Table
Migration `2026_10_22_000014_create_qa_import_versions_table.php` was created and executed on `iqra_billing_qa`:
- Tracks `version_key`, `batch_id`, `source_filename`, `file_hash`, `registration_rows`, `collection_rows`, `dues_rows`, and execution metadata.
- Both the original reviewed workbook (`d2276cf0c7...`) and the revised workbook saved at 17:17 (`f48f1ee57e...`) are registered in `config/imports.php` and recorded in `qa_import_versions`.
- Staging preserves all **37,976** historical collection rows and **11,473** outstanding dues rows without any data loss.

---

## Task 5: QA Historical Opening-Balance Migration Service & Preview

### 5.1 Financial Architecture & Principles
The 11,473 rows in `05_OUTSTANDING_PRESENT_ONLY` represent remaining **UNPAID** balances after all historical collections. Therefore:
1. **No Double Deduction:** Historical collections are not deducted again.
2. **No Fictional Advance Credits:** Historical payments remain archived receipt details, not unallocated credits.
3. **Grouping Strategy:** Grouped by `family_id` + `department_id` + `currency` + `billing_period`.
4. **Immutable Lineage:** Every invoice line item retains its `qa_staged_due_id` and original worksheet row number.
5. **Hold Exclusion:** Dues for students on `review_hold` (72 students, 391 dues rows) and unresolved currencies (70 dues rows) are strictly excluded from eligible opening balances.

### 5.2 Opening Balance Preview Summary

| Currency | Eligible Dues Lines | Opening Balance Invoices | School Department Total | Tuition Department Total | Net Currency Total |
|:---|---:|---:|---:|---:|---:|
| **SAR** | 6,579 | 4,683 | 1,646,155.00 SAR | 108,117.00 SAR | **1,754,272.00 SAR** |
| **AED** | 4,263 | 2,965 | 1,109,892.00 AED | 47,179.00 AED | **1,157,071.00 AED** |
| **PKR** | 149 | 125 | 2,149,445.00 PKR | 0.00 PKR | **2,149,445.00 PKR** |
| **QAR** | 15 | 11 | 4,195.00 QAR | 0.00 QAR | **4,195.00 QAR** |
| **OMR** | 6 | 4 | 174.00 OMR | 0.00 OMR | **174.00 OMR** |
| **ELIGIBLE SUBTOTAL** | **11,012** | **7,788** | — | — | **5 Isolated Currencies** |
| **Excluded / Held Rows** | **461** | — | (70 unres. curr + 391 review hold) | — | **Held for Review** |
| **TOTAL STAGED DUES** | **11,473** | — | — | — | **100.0% Accounted** |

### 5.3 Ledger Posting Lock Status
`HistoricalOpeningBalanceService::postOpeningBalances()` is guarded by:
```php
if (!config('imports.allow_opening_balance_posting', false)) {
    throw new RuntimeException('CRITICAL SAFETY STOP: Opening balance ledger posting is STRICTLY BLOCKED.');
}
```
**Ledger Posting Status:** **STOPPED FOR REVIEW**. Zero opening-balance invoices have been posted to the ledger.

---

## Task 6: Portal Billing Safeguards and Functional Verification

1. **Review-Hold Billing Guard:**
   - `BillingController::createChallanFee` throws a `ValidationException` if any requested student has `status = 'review_hold'`.
   - `InvoiceService::generate` filters out all students with `status IN ('review_hold', 'historical_hold', 'suspended', 'left')`.
2. **Historical Student Protection:**
   - Students with `status = 'historical_hold'` cannot receive new monthly bills or challans.
3. **External Dispatch Suppression:**
   - Webhooks to n8n, WhatsApp notifications, and payment gateways are disabled when operating on `iqra_billing_qa`.
4. **Preview Access:**
   - Available via CLI (`php artisan qa:preview-opening-balances`) and Web Portal (`/migration/opening-balances`).

---

## Test Suite Results

Full automated test suite execution:
```text
   PASS  Tests\Unit\ExampleTest
   PASS  Tests\Feature\BillingLedgerTest
   PASS  Tests\Feature\FeeCollectionAccountingTest
   PASS  Tests\Feature\FeeImportTest
   PASS  Tests\Feature\FeeStructureTest
   PASS  Tests\Feature\Phase3aMappingWorkflowTest
   PASS  Tests\Feature\Phase3bAccountingControlsTest
   PASS  Tests\Feature\Phase3c1ReviewedWorkbookImportTest
   PASS  Tests\Feature\Phase3c2QaFinancialIntegrationTest
   PASS  Tests\Feature\Phase3cQaStudentMigrationTest
   PASS  Tests\Feature\StagingImportPipelineTest
   PASS  Tests\Feature\StudentAdmissionTest
   PASS  Tests\Feature\StudentImportTest

  Tests:    123 passed (847 assertions)
  Duration: 16.11s
```

---

## Source Changes Summary

| File | Change Description |
|:---|:---|
| `config/database.php` | Set default connection to `qa` (`iqra_billing_qa`); configured safety aliases. |
| `config/imports.php` | Added hash and batch definition for revised October 22 workbook (`BATCH-20261022-PORTAL-V2-REVISED`); set `allow_opening_balance_posting => false`. |
| `app/Providers/AppServiceProvider.php` | Added boot-time assertion enforcing `iqra_billing_qa` and forbidding `iqra_billing` and `iqra_billing_clean`. |
| `app/Services/Migration/ReviewedWorkbookImportService.php` | Hardened exact decimal parsing, exception capturing, and version recording. |
| `app/Services/Migration/HistoricalOpeningBalanceService.php` | Implemented opening balance grouping, currency isolation, and posting lock. |
| `app/Console/Commands/QaPreviewOpeningBalancesCommand.php` | Added Artisan CLI command `php artisan qa:preview-opening-balances`. |
| `app/Http/Controllers/BillingController.php` | Enforced review-hold and historical-hold validation guards on challan creation. |
| `app/Services/InvoiceService.php` | Excluded `review_hold` and `historical_hold` students from monthly billing generation. |
| `app/Http/Controllers/MigrationReviewController.php` | Added opening balance preview controller action. |
| `database/migrations/2026_10_22_000014_create_qa_import_versions_table.php` | Created version tracking table for QA imports. |
| `resources/views/migration/dashboard.blade.php` | Added Opening Balances Preview card with posting lock badge. |
| `resources/views/migration/opening-balances-preview.blade.php` | Created dedicated Opening Balances preview interface. |
| `resources/views/migration/qa-collections.blade.php` | Standardized database querying over default QA connection. |
| `resources/views/migration/qa-dues.blade.php` | Standardized database querying over default QA connection. |
| `routes/web.php` | Added `/migration/opening-balances` route. |
| `tests/Feature/Phase3c2QaFinancialIntegrationTest.php` | Added 10 feature tests covering isolation, counts, backup, decimal parsing, and safeguards. |
| `backups/qa_test_ledger_fixture_oct20_21.sql` | Dedicated physical backup of Phase 3C.0 test ledger fixture (3.2 MB, untracked). |
