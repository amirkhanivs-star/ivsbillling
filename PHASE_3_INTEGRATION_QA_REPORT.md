# IQRA Billing — Phase 3: Complete Integration Testing & Financial Integrity Fixes Report

**Date:** October 28, 2026  
**Project:** `C:\Users\hp\Desktop\iqra-laravel-billing`  
**Phase:** Phase 3 — Complete Integration Testing, Defect Remediation & MariaDB Verification  
**Database Engine Verified:** `10.4.32-MariaDB` (`127.0.0.1:3306`) & SQLite In-Memory Test Harness  
**Live Local Database Status:** `iqra_billing` — **100% Untouched & Unmodified**  

---

## Executive Summary

Phase 3 completed end-to-end integration testing, defect remediation, MariaDB compatibility verification, and full regression testing across all 417 automated tests in the IQRA Billing system.

- **Database & Operational Safety (Task 1):** Verified that the live local database (`iqra_billing`) and Hostinger production database were never mutated, reset, or migrated. All MariaDB migration, schema, locking, and integration tests were executed against an isolated, disposable MariaDB database (`iqra_billing_phase3_test`), which was subsequently dropped and verified clean. External notifications (email, WhatsApp, n8n webhooks) remained disabled (`MAIL_MAILER=array`, `WHATSAPP_ENABLED=false`, `NOTIFICATIONS_ENABLED=false`).
- **Historical Reconciliation Engine (Task 2):** All 19 integration tests in `HistoricalPaymentReconciliationTest` passed on both SQLite and MariaDB (`10.4.32-MariaDB`). Confirmed implementation defects in multi-item invoice reversal recalculation, voided-item reversal restoration (`net_minor = 0`), ANSI quote portability, and source collection ID double-consumption prevention were identified and fixed.
- **Voided-Fee Restoration Engine (Task 3):** All 16 integration tests in `VoidedFeeRestorationTest` passed on both SQLite and MariaDB. Verified atomic restoration, original invoice/item ID preservation, historical MySkool settlement linkage, parent invoice recalculation, and Create Fee preview/save agreement.
- **MySkool Shared Slip `#27328` Discrepancy (Task 4):** Verified and corrected the Phase 2C report, tests, and service layer for Family `5929`: both **GR 5929** (`Hafsa Feroz`, QAR 280.00, `qa_staged_collections.id = 74428`, `source_row = 34702`) and **GR 5930** (`Zahra Feroz`, QAR 230.00, `qa_staged_collections.id = 74429`, `source_row = 34703`) were paid on October 24, 2026 under **MySkool Slip `#27328`**. Added source-row-level (`qa_staged_collections.id`) consumption tracking so siblings sharing a slip number are both recognized while double-consumption of the same source collection row is deterministically blocked.
- **Application Accounting & Reporting (Task 5):** Fixed defects in `BillingController` where reversed receipts (`status = 'reversed'`) and voided invoices (`status IN ('void', 'voided')`) could inflate dashboard KPI cards and cashier collection exports (`report`, `exportReport`, `exportXlsx`, `reportPdf`). Confirmed historical MySkool settlements (`qa_historical_settlements`) never create synthetic `payments` rows and never inflate cashier collections.
- **MariaDB Integration & Full Regression (Tasks 6 & 7):** Executed all 20 migrations (including rollback and re-application of `2026_10_28_000020_create_qa_historical_settlements_table` with foreign keys) and 128 core financial/reconciliation/delivery tests directly on MariaDB `10.4.32-MariaDB` (`100%` pass), plus the complete 417-test repository regression suite (`417/417` passing, `2,813` assertions, `0` failures).

---

## 1. Files Changed

### Application & Service Layer
| File | Summary of Changes |
|---|---|
| `app/Services/VoidedFeeRestorationService.php` | Added `SAFETY_BLOCKED_CONSTRAINT_CONFLICT` constant; scoped `qa_staged_collections` queries to active batch with deterministic `source_row` ordering; added `MySkoolReconciliationAuditService::deduplicateCollections()` and `parseFeeIdentity()` matching; implemented `hasConsumedCollectionIds()` in both `inspect()` and `restore()` to distinguish shared slip numbers (`#27328`) by `qa_staged_collections.id` and block double consumption; passed `$conn` into `InvoiceService::refreshInvoice()`; resolved `$actorId` to an existing admin user ID to satisfy MariaDB foreign key constraints in CLI mode. |
| `app/Services/Migration/HistoricalPaymentReconciliationService.php` | Added portable `hasConsumedCollectionIds()` helper to prevent double consumption of `qa_staged_collections.id` across `qa_historical_settlements`; normalized `qa_staged_collection_ids` to integer JSON arrays; replaced `"voided"` double-quoted SQL literal with `'voided'` for MariaDB `ANSI_QUOTES` safety; fixed `reverse()` to restore `net_minor = 0` when reversing a void-recovered item and to recalculate parent `invoices.total_minor`, `paid_minor`, and `status` from live `invoice_items`. |
| `app/Services/Migration/MySkoolReconciliationAuditService.php` | Exposed `public static function deduplicateCollections(Collection $rows, int $itemNetMinor = PHP_INT_MAX): Collection` so `VoidedFeeRestorationService` and `MySkoolReconciliationAuditService` share identical staging deduplication rules. |
| `app/Http/Controllers/BillingController.php` | Excluded reversed payments (`status != 'reversed'`) and voided invoices (`whereNotIn('status', ['void', 'voided'])`) from `dashboard()`, `report()`, `exportReport()`, `exportXlsx()`, and `reportPdf()`; unified same-month regular non-OB invoice lookup in `createChallanFee()` so simultaneous monthly + one-time fees attach to a single regular family invoice; added `family_code` resolution support in `createChallanFee()` and `previewChallanFee()`; included both restoration-review and paid-duplicate validation messages in `fee_type_ids` when both apply. |
| `app/Services/FeeEligibilityService.php` | Added fallback resolution from `family_code` when `family_id` is omitted or passed as a family code in `evaluateEligibility()`. |
| `app/Services/FeeAdjustmentService.php` | Included both `'item'` and `'void'` keys in `voidFee()` validation exceptions when blocking void operations on items with recorded payments. |
| `app/Services/InvoiceService.php` | Updated `refreshInvoice(int $invoiceId, ?string $conn = null)` to accept an explicit database connection name for multi-connection and MariaDB transaction support. |
| `app/Console/Commands/RestoreVoidedFeeCommand.php` | Updated CLI actor ID passing so `VoidedFeeRestorationService` resolves a valid existing admin user ID under MariaDB foreign key constraints. |
| `app/Services/BatchDispatchLock.php` | Added process-local active lock tracking alongside MySQL/MariaDB `GET_LOCK`/`RELEASE_LOCK` so same-connection re-entrant lock acquisition is blocked while preserving the exact SQL query contract. |
| `app/Services/Migration/HistoricalOpeningBalanceService.php` | Updated `buildHistoricalObligationMap()` to resolve the single posted batch from `qa_opening_balance_lineage` when no explicit `$batchId` argument is provided and the default config batch is absent, while preserving the `CRITICAL SAFETY STOP` guard for explicit mismatched batches or missing staging tables. |
| `app/Services/Migration/QaControlledReimportService.php` | Updated active workbook constant to `storage/app/imports/My skool paid (1).xlsx` (`ac83e8d71b89c992eb3a35ed2cd154929dd522432bb5c5c05742bdd0255622ae`). |
| `app/Console/Commands/QaResetTestLedgerCommand.php` | Updated default workbook path and added disposable SQLite test environment reset support when the gitignored local SQL backup dump is absent. |

### Database Migrations
| File | Summary of Changes |
|---|---|
| `database/migrations/2026_10_28_000020_create_qa_historical_settlements_table.php` | Added composite index `idx_qa_hist_settlements_item_status` on `['invoice_item_id', 'status']` and foreign key constraints `fk_qa_hist_settlements_invoice_item` (`invoice_items.id`), `fk_qa_hist_settlements_invoice` (`invoices.id`), `fk_qa_hist_settlements_student` (`students.id`), and `fk_qa_hist_settlements_family` (`families.id`). |

### Tests & Documentation
| File | Summary of Changes |
|---|---|
| `PHASE_2C_VOIDED_FEE_RESTORATION_REPORT.md` | Corrected `Slip #27329` reference for `GR 5930` (`Zahra Feroz`) to `Slip #27328` (`source_row = 34703`, `id = 74429`, QAR 230.00) and documented shared-slip sibling handling with `GR 5929` (`Hafsa Feroz`, `source_row = 34702`, `id = 74428`, QAR 280.00). |
| `tests/Feature/VoidedFeeRestorationTest.php` | Updated `test_03` and `test_04` to use verified `Slip #27328` (`id = 74429`, `source_row = 34703`) for `GR 5930`; added `test_16_shared_slip_27328_across_siblings_gr_5929_and_gr_5930_distinguishes_source_rows_and_prevents_double_consumption`. |
| `tests/Feature/HistoricalPaymentReconciliationTest.php` | Added `test_19_shared_slip_27328_across_family_5929_siblings_and_multi_item_reversal_integrity`. |
| `tests/Feature/FeeCollectionAccountingTest.php` | Added `test_dashboard_and_cashier_reports_exclude_reversed_receipts_voided_invoices_and_historical_myskool_settlements`. |
| `PHASE_3_INTEGRATION_QA_REPORT.md` | Created this consolidated Phase 3 Integration & QA report. |

---

## 2. Bugs Found and Fixed

### Bug 1: Shared MySkool Slip `#27328` Discrepancy & Source Collection Consumption Tracking (Tasks 3 & 4)
- **Root Cause:**
  1. In `PHASE_2C_VOIDED_FEE_RESTORATION_REPORT.md` and `VoidedFeeRestorationTest` (`test_03` and `test_04`), `GR 5930` (`Zahra Feroz`, QAR 230.00) was referenced under `Slip #27329`. Direct query of `iqra_billing.qa_staged_collections` confirmed that both `GR 5929` (`Hafsa Feroz`, QAR 280.00, `id = 74428`, `source_row = 34702`) and `GR 5930` (`Zahra Feroz`, QAR 230.00, `id = 74429`, `source_row = 34703`) were paid under **MySkool Slip `#27328`** on `2026-10-24`.
  2. `VoidedFeeRestorationService` did not check whether a staged collection row (`qa_staged_collections.id`) had already been consumed in `qa_historical_settlements` by another invoice item, nor did it filter `qa_staged_collections` by fee type/subject identity (`MySkoolReconciliationAuditService::parseFeeIdentity()`) or deduplicate identical snapshot rows (`deduplicateCollections()`).
  3. `HistoricalPaymentReconciliationService` used `orWhereJsonContains('qa_staged_collection_ids', $cid)` which could suffer from integer vs. string JSON scalar mismatch across SQLite/MariaDB drivers.
- **Fix:**
  - Corrected `PHASE_2C_VOIDED_FEE_RESTORATION_REPORT.md` and `VoidedFeeRestorationTest` to reflect `Slip #27328` (`source_row = 34702` for `GR 5929`, `source_row = 34703` for `GR 5930`).
  - Added `hasConsumedCollectionIds()` to both `VoidedFeeRestorationService` (`inspect()` and `restore()`) and `HistoricalPaymentReconciliationService`, normalizing `qa_staged_collection_ids` to integer arrays (`array_values(array_map('intval', ...))`) and blocking any attempt to consume the same `qa_staged_collections.id` twice (`SAFETY_BLOCKED_CONSTRAINT_CONFLICT`), while allowing distinct source rows sharing the same `slip_number` (`#27328`).
  - Added fee-identity matching (`parseFeeIdentity`) and staging deduplication (`deduplicateCollections`) to `VoidedFeeRestorationService::inspect()`.

### Bug 2: Out-of-Order Reversal & Void-Recovered Item Reversal in `HistoricalPaymentReconciliationService::reverse()` (Task 2)
- **Root Cause:**
  1. `reverse()` restored `invoice_items.paid_minor` and `status`, and updated `invoices.paid_minor` to stored `previous_invoice_paid_minor`, which became stale if multiple items on the same parent invoice were settled and later reversed out of order.
  2. When reversing a settlement that had recovered a previously voided item (`previous_item_status === 'voided'`), `reverse()` left `invoice_items.net_minor` at the restored positive amount instead of resetting `net_minor = 0` and recalculating `invoices.total_minor`.
- **Fix:**
  - Updated `HistoricalPaymentReconciliationService::reverse()` to set `net_minor = 0` when `previous_item_status` is `'void'` or `'voided'`, and to recalculate the parent invoice's `total_minor`, `paid_minor`, and `status` directly from live non-voided `invoice_items` rows inside the locked transaction.

### Bug 3: Dashboard & Cashier Collection Reports Including Reversed Payments and Voided Invoices (Task 5)
- **Root Cause:**
  - `BillingController::dashboard()` summed `payments.amount_minor` for `todayCollections` and counted `receipts_today` without excluding `status = 'reversed'`, and summed `invoices.total_minor` / `paid_minor` without excluding `status IN ('void', 'voided')`.
  - `BillingController::report()`, `exportReport()`, `exportXlsx()`, and `reportPdf()` joined `payments` via `payment_allocations` without filtering `p.status != 'reversed'` or excluding voided invoices/items.
- **Fix:**
  - Added `where('status', '!=', 'reversed')` to `todayCollections` and `receipts_today` queries in `BillingController::dashboard()`, and `whereNotIn('status', ['void', 'voided'])` to `dashboard()` invoice totals.
  - Added `where('p.status', '!=', 'reversed')`, `whereNotIn('i.status', ['void', 'voided'])`, and `whereNotIn('ii.status', ['void', 'voided'])` to `report()`, `exportReport()`, `exportXlsx()`, and `reportPdf()`.

### Bug 4: MariaDB Foreign Keys, CLI Actor Resolution & Non-Reentrant Named Locks (Task 6)
- **Root Cause:**
  1. `database/migrations/2026_10_28_000020_create_qa_historical_settlements_table.php` defined index columns for `invoice_item_id`, `invoice_id`, `student_id`, and `family_id`, but omitted explicit foreign key constraints and a composite `(invoice_item_id, status)` index.
  2. `VoidedFeeRestorationService::restore()` and `RestoreVoidedFeeCommand` defaulted `$actorId` to `1` when `auth()->id()` was `null` in CLI mode. On MariaDB, when `users.id = 1` did not exist, `fee_adjustments_user_id_foreign` threw a foreign key violation.
  3. On MariaDB/MySQL, `SELECT GET_LOCK(?, 0)` is re-entrant within the same database connection, causing single-connection concurrent lock tests in `PaidInvoicesTabTest` to acquire the same lock twice on MariaDB.
- **Fix:**
  - Added 4 foreign key constraints (`fk_qa_hist_settlements_invoice_item`, `fk_qa_hist_settlements_invoice`, `fk_qa_hist_settlements_student`, `fk_qa_hist_settlements_family`) and composite index `idx_qa_hist_settlements_item_status` to `2026_10_28_000020_create_qa_historical_settlements_table.php`.
  - Updated `VoidedFeeRestorationService::restore()` to verify that `$actorId` exists in `users` and fall back to the first existing admin user ID when running via CLI.
  - Updated `BatchDispatchLock` to track held locks in process alongside MySQL/MariaDB `GET_LOCK`/`RELEASE_LOCK`.

### Bug 5: Same-Month Multi-Category Invoice Consolidation in `BillingController::createChallanFee()` (Task 5)
- **Root Cause:**
  - When a user created both a monthly fee and a one-time fee in the same request for the same month, `createChallanFee()` looked up existing invoices using `billing_run_id` only for monthly fees and `billing_run_id IS NULL` for non-monthly fees, splitting same-month fees onto two separate regular invoices instead of consolidating onto the family's existing open regular invoice for that month.
- **Fix:**
  - Unified the open regular non-OB invoice lookup in `createChallanFee()` for the target `period_start` so all non-OB fees created for the same family and month attach to the same regular invoice.

---

## 3. MariaDB Integration Results (Task 6)

All MariaDB verification was performed against an isolated, disposable database **`iqra_billing_phase3_test`** on **`10.4.32-MariaDB`** (`127.0.0.1:3306`).

### 3.1 Migration & Rollback Verification
- Ran all 20 migrations from scratch on `iqra_billing_phase3_test`: **20/20 succeeded**.
- Tested rollback and re-migration of `2026_10_28_000020_create_qa_historical_settlements_table`:
  - `php artisan migrate:rollback --step=1 --force`: **DONE (17.79ms)**
  - `php artisan migrate --force`: **DONE (374.42ms)**

### 3.2 MariaDB Foreign Keys & Indexes Verified via `information_schema`
- **Foreign Keys on `qa_historical_settlements`:**
  - `fk_qa_hist_settlements_invoice_item`: `invoice_item_id -> invoice_items(id) ON DELETE CASCADE`
  - `fk_qa_hist_settlements_invoice`: `invoice_id -> invoices(id) ON DELETE CASCADE`
  - `fk_qa_hist_settlements_student`: `student_id -> students(id) ON DELETE SET NULL`
  - `fk_qa_hist_settlements_family`: `family_id -> families(id) ON DELETE SET NULL`
- **Unique & Composite Indexes on `qa_historical_settlements`:**
  - `PRIMARY` (`id`)
  - `qa_historical_settlements_reconciliation_id_unique` (`reconciliation_id`)
  - `idx_qa_hist_settlements_item_status` (`invoice_item_id`, `status`)
  - Single-column indexes on `batch_id`, `cutoff_date`, `invoice_item_id`, `invoice_id`, `student_id`, `family_id`, `student_gr`, `family_code`, `currency`, `period_key`, `qa_staged_due_id`, `reconciliation_classification`, `guard_class`, `status`.

### 3.3 Test Suites Executed Directly on MariaDB `10.4.32-MariaDB` (`iqra_billing_phase3_test`)
| Test Suite | MariaDB Tests | MariaDB Assertions | Result |
|---|---:|---:|---|
| `HistoricalPaymentReconciliationTest` | 19 | 141 | **PASS (100%)** |
| `VoidedFeeRestorationTest` | 16 | 118 | **PASS (100%)** |
| `MySkoolReconciliationAuditTest` | 9 | 56 | **PASS (100%)** |
| `CreateFeeDuplicateProtectionTest` | 7 | 49 | **PASS (100%)** |
| `BillingLedgerTest` | 5 | 41 | **PASS (100%)** |
| `FeeCollectionAccountingTest` | 32 | 264 | **PASS (100%)** |
| `PaidInvoicesTabTest` | 40 | 262 | **PASS (100%)** |
| **Total Executed Directly on MariaDB** | **128** | **931** | **128 / 128 PASS** |

### 3.4 Live Local Database (`iqra_billing`) Integrity Proof
Before and after all MariaDB tests and after dropping `iqra_billing_phase3_test`, row counts in `iqra_billing` were verified identical:
- `students`: `3,953` | `families`: `2,706` | `enrollments`: `3,953`
- `invoices`: `10,286` | `invoice_items`: `14,143`
- `payments`: `1` | `payment_allocations`: `1` | `fee_adjustments`: `2`
- `qa_staged_collections`: `34,712` | `qa_staged_dues`: `10,329` | `qa_opening_balance_lineage`: `10,257`
- `has_qa_historical_settlements_table` in `iqra_billing`: `0` (not migrated on live local DB).

---

## 4. Historical Reconciliation Test Results (Task 2)

Verified across 19 feature tests in [`HistoricalPaymentReconciliationTest`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/tests/Feature/HistoricalPaymentReconciliationTest.php):
1. **Dry-run non-mutation (`test_01`):** Preview mode computes exact settlement candidates without altering `invoices`, `invoice_items`, `payments`, or `qa_historical_settlements`.
2. **Full settlement (`test_02`):** Post-cutoff historical MySkool payments settle matching open obligations (`paid_minor = net_minor`, `status = 'paid'`) and recalculate the parent invoice (`status = 'paid'`, balance `0`).
3. **Partial settlement (`test_03`):** Partial historical payment (e.g. QAR 100 of QAR 250) sets `paid_minor = 10000`, `status = 'partial'`, leaving QAR 150.00 collectible.
4. **Legitimate unpaid preservation (`test_04`):** Obligations with no matching MySkool collection remain `status = 'active'`, `paid_minor = 0`.
5. **Existing cashier payment protection (`test_05`):** Items with existing `payment_allocations` are classified as `BLOCKED_EXISTING_PORTAL_PAYMENT` and never overwritten or duplicated.
6. **Voided item protection in batch reconciliation (`test_06`):** Voided items are classified as `BLOCKED_VOIDED_CONFLICT` during bulk reconciliation and routed to the dedicated Phase 2C restoration workflow.
7. **Concession/discount approval gate (`test_07`, `test_08`):** Unapproved discounts are blocked (`BLOCKED_DISCOUNT_APPROVAL_REQUIRED`); approved concessions settle only when `--approve-concessions` is explicitly provided.
8. **Currency mismatch & ambiguous obligation protection (`test_09`, `test_10`, `test_15`):** Currency conflicts (`SAR` vs `QAR`), multi-item ambiguity without deterministic mapping, and overpayments (`collection > obligation`) are blocked.
9. **Idempotency & double-apply prevention (`test_11`, `test_16`):** Re-running `reconcile` or attempting to reuse an already-consumed `qa_staged_collections.id` is blocked.
10. **Reversal integrity (`test_12`, `test_19`):** Reversing a reconciliation restores exact prior item and parent invoice balances, even when reversing one of multiple settled items on a shared family invoice.

---

## 5. Voided-Fee Restoration Verification & Slip `#27328` Fix (Tasks 3 & 4)

Verified across 16 feature tests in [`VoidedFeeRestorationTest`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/tests/Feature/VoidedFeeRestorationTest.php):
1. **Unpaid voided fee restoration (`test_01`, `test_02`):** Restores `invoice_items.net_minor` to its original approved obligation, preserves the original `invoice_id` and `invoice_item_id`, appends a `restoration` audit row to `fee_adjustments` without deleting the historical `void` adjustment row, and recalculates the parent invoice.
2. **Family 5929 Shared MySkool Slip `#27328` Verification (`test_03`, `test_04`, `test_16`):**
   - **GR 5929 (`Hafsa Feroz`):** Voided item `#14102` (QAR 280.00, Oct 2026) matches `qa_staged_collections.id = 74428` (`source_row = 34702`, `slip_number = 27328`, `paid_amount_minor = 28000`).
   - **GR 5930 (`Zahra Feroz`):** Voided item `#14103` (QAR 230.00, Oct 2026) matches `qa_staged_collections.id = 74429` (`source_row = 34703`, `slip_number = 27328`, `paid_amount_minor = 23000`).
   - Restoring both siblings in QA test harness transitions parent invoice (`INV-202610-001279`) from `void` (`total = 0, paid = 0`) -> `paid` (`total = QAR 280.00, paid = QAR 280.00`) -> `paid` (`total = QAR 510.00, paid = QAR 510.00, balance = QAR 0.00`), with zero duplicate invoices created and zero fake cashier `payments` rows created.
   - Attempting to consume `qa_staged_collections.id = 74428` or `74429` a second time on another item is blocked with `SAFETY_BLOCKED_CONSTRAINT_CONFLICT`.
3. **Create Fee Preview & Save Agreement (`test_05`, `test_06`, `test_11`, `test_12`):**
   - When an obligation is voided, both `POST /challan/preview` and `POST /challan/create` return `REQUIRES_RESTORATION_REVIEW` with the exact `voided_item_id` and parent `invoice_number`, blocking duplicate invoice creation until the operator restores the original item or explicitly passes `override_voided_recreate = true` with an override reason.

---

## 6. Invoice and Report Accuracy (Task 5)

Verified across [`FeeCollectionAccountingTest`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/tests/Feature/FeeCollectionAccountingTest.php) (32 tests) and [`BillingLedgerTest`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/tests/Feature/BillingLedgerTest.php) (5 tests):
- **Fee Collection & Partial Payments:** Cashier partial and full payments allocate accurately across single and multi-student family invoices, updating `invoice_items.paid_minor`, `invoices.paid_minor`, and `invoices.status`.
- **Reversal & Void Exclusion from Reports (`test_dashboard_and_cashier_reports_exclude_reversed_receipts_voided_invoices_and_historical_myskool_settlements`):**
  - Active cashier receipts are included in `dashboard` (`todayCollections`, `receipts_today`) and `/reports` (`rows`, `summary`, CSV/XLSX/PDF exports).
  - Reversed cashier receipts (`status = 'reversed'`) are excluded from `todayCollections`, `receipts_today`, and all `/reports` views and exports.
  - Voided invoices (`status IN ('void', 'voided')`) are excluded from dashboard `totals` and report queries.
  - Historical MySkool settlements (`qa_historical_settlements`) reduce outstanding invoice balances without creating `payments` rows and never inflate cashier collection reports.
- **Multi-Currency Accounting:** Currency isolation (`QAR`, `SAR`, `USD`, `PKR`, `INR`, `AED`, `EUR`, `GBP`) is enforced across invoices, payments, dashboard currency cards, and report summaries.

---

## 7. Regression Test Results (Task 7)

The entire repository test suite was executed via `php vendor/bin/phpunit`:

- **Total Tests Executed:** `417`
- **Total Assertions:** `2,813`
- **Passed:** `417` (`100%`)
- **Failed / Errors:** `0`

Key suites included in the 417-test run:
- `HistoricalPaymentReconciliationTest` (19 tests) — **PASS**
- `VoidedFeeRestorationTest` (16 tests) — **PASS**
- `MySkoolReconciliationAuditTest` (9 tests) — **PASS**
- `CreateFeeDuplicateProtectionTest` (7 tests) — **PASS**
- `FeeCollectionAccountingTest` (32 tests) — **PASS**
- `PaidInvoicesTabTest` (41 tests) — **PASS**
- `BillingLedgerTest` (5 tests) — **PASS**
- `Phase3c2QaFinancialIntegrationTest` (11 tests) — **PASS**
- `Phase3c3OpeningBalanceEngineTest` (13 tests) — **PASS**
- `Phase3c3ReimportReadinessTest` (21 tests) — **PASS**
- `GuardianNameResolutionTest` (6 tests) — **PASS**
- `MysqlBatchDetailsRegressionTest` (10 tests) — **PASS**
- `BatchDispatchLockTest` (2 tests) — **PASS**

### Distinction Between Verified Test Results and Untested Assumptions
- **Verified by Tests:** All SQL/MariaDB schema migrations, foreign key constraints, `SELECT ... FOR UPDATE` row locks, historical payment reconciliation (`dry-run`, `apply`, `reverse`), voided-fee restoration (`inspect`, `restore`, shared slip `#27328` handling), Create Fee duplicate/voided protection, dashboard/report exclusions, and n8n batch lock semantics.
- **Untested in Production Environment (By Design & Restriction):** Live execution of `php artisan billing:reconcile-myskool-payments --apply` and `php artisan billing:restore-voided-fee --apply` against the live `iqra_billing` database or Hostinger production database was intentionally **not** performed per Phase 3 restrictions.

---

## 8. Remaining Issues

**None.** All defects identified during Phase 3 integration testing and MariaDB verification have been resolved and covered by automated regression tests.

---

## 9. Production Readiness Assessment

**Status: READY FOR CONTROLLED PHASE 4 EXECUTION (Subject to Owner Sign-Off)**

1. **Schema & MariaDB Readiness:** `2026_10_28_000020_create_qa_historical_settlements_table.php` is fully compatible with MariaDB `10.4+` with foreign keys, unique constraints, and clean rollback support.
2. **Financial Safeguards:** All mutations are wrapped in `DB::transaction()` with `lockForUpdate()`, dry-run defaults, mandatory `--confirm-owner` / `--reason` gates, and full reversal capability.
3. **Reporting Integrity:** Historical MySkool settlements are strictly segregated in `qa_historical_settlements` and `audit_logs`, ensuring cashier collection reports (`payments` / `payment_allocations`) reflect only actual portal cashier collections.

---

## 10. Exact Recommendations for Phase 4

When authorized to proceed to Phase 4 (Controlled Production / Live Local Application):

1. **Pre-Execution Full Database Backup:**
   - Take a complete `mysqldump` snapshot (with routines, triggers, and `--single-transaction`) of `iqra_billing` (and Hostinger production prior to production cutover) and verify its SHA-256 checksum before running any migration or command.
2. **Run the New Settlement Table Migration:**
   - Execute `php artisan migrate --path=database/migrations/2026_10_28_000020_create_qa_historical_settlements_table.php --force` to create `qa_historical_settlements`.
3. **Execute Dry-Run Audit & Verify Classification Counts:**
   - Run `php artisan billing:reconcile-myskool-payments` (default dry-run mode) and verify the output matches the verified baseline (`1,134` full settlements, `2` partial settlements, `0` unexpected conflicts).
4. **Restore Confirmed Accidentally Voided Obligations First (Family 5929):**
   - Inspect item `#14102` (`GR 5929`, QAR 280.00) and item `#14103` (`GR 5930`, QAR 230.00):
     - `php artisan billing:restore-voided-fee 14102`
     - `php artisan billing:restore-voided-fee 14103`
   - After verifying the dry-run output (`Slip #27328`, `SAFE_WITH_HISTORICAL_SETTLEMENT`), apply with owner confirmation:
     - `php artisan billing:restore-voided-fee 14102 --apply --confirm-owner --reason="Restore accidentally voided Oct 2026 fee paid under MySkool Slip #27328"`
     - `php artisan billing:restore-voided-fee 14103 --apply --confirm-owner --reason="Restore accidentally voided Oct 2026 fee paid under MySkool Slip #27328"`
5. **Apply Historical Payment Reconciliation in Controlled Batch:**
   - Execute `php artisan billing:reconcile-myskool-payments --apply --confirm-owner --force` for the verified safe full and partial post-cutoff settlements.
   - Review the 12 concession-variance items (`BLOCKED_DISCOUNT_APPROVAL_REQUIRED`) with finance management before deciding whether to run with `--approve-concessions`.
6. **Post-Apply Verification:**
   - Re-run `php artisan billing:audit-myskool-reconciliation` and verify dashboard KPIs and `/reports` cashier totals remain unchanged by historical settlements.
