# IQRA Billing — Historical Reconciliation Verification & MySkool Payment Correction Implementation Report

**Date:** 2026-10-28  
**Project:** `C:\Users\hp\Desktop\iqra-laravel-billing`  
**Environment:** Local Development (`iqra_billing` read-only audit; isolated SQLite `:memory:` automated test verification)  
**Real Student Data Mutation Status:** **0 rows mutated in `iqra_billing`** (strictly stopped before executing corrections or migrations on real student records).

---

## 1. Previous Reconciliation Integrity Findings (Task 1)

A strictly read-only forensic audit was conducted against the local `iqra_billing` database and compared line-by-line against the pre-migration SQL dump `storage/app/backups/iqra_billing_local_pre_migration_20261028.sql` (`21,985,731` bytes, `SHA-256: 88b87ef960dc8cf602441d621380ffb9d779a4efb3959d24170e0fc5dd6df098`).

### 1.1 Audit of the 265 Historical Settlements
All `265` rows in `qa_historical_settlements` and `275` rows in `qa_historical_settlement_collections` were verified against `invoice_items`, `invoices`, and `qa_staged_collections`:

| Guard Classification | Reconciliation Classification | Settlements | Settled Minor | Currency Breakdown |
| :--- | :--- | :---: | :---: | :--- |
| `CANDIDATE_FULL_SETTLEMENT` | `POST_CUTOFF_SETTLED_HISTORICALLY` | 246 | `9,340,700` | `SAR 8,243,300` (219), `AED 696,600` (17), `QAR 400,800` (10) |
| `CANDIDATE_PARTIAL_SETTLEMENT` | `POST_CUTOFF_PARTIAL_PAYMENT` | 13 | `337,100` | `SAR 329,600` (12), `AED 7,500` (1) |
| `CONCESSION_REVIEW` | `POST_CUTOFF_POSSIBLE_CONCESSION` | 6 | `145,500` | `SAR 103,500` (4), `QAR 42,000` (2) |
| **Total** | — | **265** | **`9,823,300`** | **`SAR 8,676,400` (235), `AED 704,100` (18), `QAR 442,800` (12)** |

- **Zero Unexplained Mutations:** Every single difference between the pre-migration backup (`storage/app/backups/iqra_billing_local_pre_migration_20261028.sql`) and the current `iqra_billing` database is 100% accounted for by the `265` applied historical settlements (`179` opening-balance invoices and `108` downstream November 2026 `previous_dues_snapshot_minor` updates).
- **Source Collection Uniqueness:** All `275` rows in `qa_historical_settlement_collections` reference distinct `qa_staged_collection_id` values (`COUNT(*) = COUNT(DISTINCT qa_staged_collection_id) = 275`), and all `34,712` rows in `qa_staged_collections` remain 100% bit-for-bit identical to the backup.

### 1.2 Investigation of Family `5929` Report Discrepancies
A prior summary report contained inaccurate narrative descriptions of Family `5929` invoice and collection IDs. Forensic verification of `iqra_billing` confirmed that the **database ledger is 100% accurate**, and the discrepancy was purely in the prior prose report:

| Entity / Field | Prior Report Claim (Inaccurate Prose) | Actual Verified `iqra_billing` Database Identity |
| :--- | :--- | :--- |
| **Family Record** | Claimed separate OB invoices per sibling | Shared family `families.id = 3423` (`family_code = '5929'`, Guardian `Feroz Khan`) |
| **Shared OB Invoice** | Claimed separate invoices | Single shared OB invoice `invoices.id = 10236` (`SCH-OB-2026-0000085-512`), `total_minor = 56000` (`QAR 560.00`), `paid_minor = 51000` (`QAR 510.00`), `status = 'partial'` |
| **Shared Nov-2026 Invoice** | Omitted ID or misstated snapshot | Single shared Nov-2026 invoice `invoices.id = 16199` (`SCH-2026-0016199`), `total_minor = 56000`, `previous_dues_snapshot_minor = 5000` (`QAR 50.00`, reduced from `56000`) |
| **Sibling 1 (`GR 5929`)** | Confused source row vs PK | `Khadija Feroz` (`students.id = 4904`, `enrollments.id = 4931`), `invoice_items.id = 14328` (`net_minor = 28000`, `paid_minor = 28000`, `status = 'paid'`), settled via `qa_staged_collections.id = 74428` (`source_row = 34702`, Slip `27328`, `QAR 280.00` on `2026-10-24`), settlement `#59` (`RECON-OB-14328-4f405da5`) |
| **Sibling 2 (`GR 5930`)** | Confused source row vs PK | `Zahra Feroz` (`students.id = 4905`, `enrollments.id = 4932`), `invoice_items.id = 14329` (`net_minor = 28000`, `paid_minor = 23000`, `status = 'partial'`, `QAR 50.00` remaining), settled via `qa_staged_collections.id = 74429` (`source_row = 34703`, Slip `27328`, `QAR 230.00` on `2026-10-24`), settlement `#60` (`RECON-OB-14329-e3757617`) |

### 1.3 Verification of `invoice_items.id = 21882` and `invoices.id = 15846`
- **Student & Family:** `GR 1234` (`Tayyab`, `students.id = 3078`, `families.id = 2108`, `family_code = '1234'`).
- **Invoice (`invoices.id = 15846`):** `SCH-2026-0015846` (`billing_run_id = 35`, `regular:2026-10-01..2026-10-31`), `currency = 'SAR'`, `total_minor = 28000`, `paid_minor = 28000`, `previous_dues_snapshot_minor = 84000`, `status = 'paid'`.
- **Invoice Item (`invoice_items.id = 21882`):** `description = 'Monthly Fee'`, `period_key = 'regular:2026-10-01..2026-10-31'`, `amount_minor = 28000`, `net_minor = 28000`, `paid_minor = 28000`, `status = 'active'`.
- **Portal Payment & Allocation:** `payments.id = 1671` (`RCP-2026-0001671`, `SAR 280.00`, `received_on = '2026-10-26'`, `status = 'received'`) and `payment_allocations.id = 1683` (`amount_minor = 28000`).
- **Backup Diff Result:** Both `invoices.id = 15846` and `invoice_items.id = 21882` in `iqra_billing` are **100% identical** to `storage/app/backups/iqra_billing_local_pre_migration_20261028.sql`. Prior manual fee-restoration testing (`audit_logs.id = 5–7`) had been cleanly restored to its exact pre-migration state (`status = 'active'`, `paid_minor = 28000`, parent invoice `status = 'paid'`) before the pre-migration backup was taken.

### 1.4 Payment Allocations and Cashier Totals Verification
- `payments`: `1` row (`id = 1671`, `RCP-2026-0001671`, `SAR 280.00`).
- `payment_allocations`: `1` row (`id = 1683`, `payment_id = 1671`, `invoice_item_id = 21882`, `amount_minor = 28000`).
- **Cashier Daily Collections:** Unchanged at `SAR 280.00` (`28000` minor); none of the `265` historical settlements created synthetic rows in `payments` or `payment_allocations`.
- **Blocker Assessment:** **No blockers or unexplained financial mutations exist in `iqra_billing`.**

---

## 2. Files Changed

| File | Summary of Changes |
| :--- | :--- |
| [2026_10_28_000021_create_qa_myskool_payment_corrections_table.php](file:///c:/Users/hp/Desktop/iqra-laravel-billing/database/migrations/2026_10_28_000021_create_qa_myskool_payment_corrections_table.php) | New migration creating the immutable append-only `qa_myskool_payment_corrections` audit ledger with unique `(qa_staged_collection_id, sequence_no)` constraint (`uq_qa_ms_corr_col_seq`). Tested in isolated test environment; **not** migrated on `iqra_billing` pending owner approval. |
| [MySkoolPaymentCorrectionService.php](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/Migration/MySkoolPaymentCorrectionService.php) | New domain service implementing `inspect()` (strictly read-only 14-field forensic inspection + before/after preview + shared-slip sibling allocations) and `executeCorrection()` (Cases A–E + all 15 financial safety rules). |
| [HistoricalPaymentReconciliationService.php](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/Migration/HistoricalPaymentReconciliationService.php) | Updated `reverse()` so downstream `previous_dues_snapshot_minor` restoration adds back the exact settlement delta (`before_snapshot_minor - after_snapshot_minor`) rather than overwriting with a static snapshot (protecting out-of-order sibling reversals on shared family invoices); updated `hasConsumedCollectionIds()` to respect active correction holds in `qa_myskool_payment_corrections`. |
| [VoidedFeeRestorationService.php](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/VoidedFeeRestorationService.php) | Updated `hasConsumedCollectionIds()` to respect active correction holds (`reversed`, `disputed`, `under_review`, `corrected`) in `qa_myskool_payment_corrections`. |
| [FeeHistoryService.php](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/FeeHistoryService.php) | Updated `fetchHistoricalReceipts()` and `formatPortalReceipt()` to attach per-row `id` / `staged_collection_id` and `correction_status`, compute aggregate slip `correction_status` and `correction_badge` (`Reversed`, `Partially Reversed`, `Disputed`, `Corrected`, `Under Review`, `Reconciled`), and gate `can_correct_myskool` strictly to `super_admin`, `finance_admin`, and `admin`. |
| [BillingController.php](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Http/Controllers/BillingController.php) | Added administrator-only `inspectMySkoolPayment()` (`GET /challan/inspect-myskool-payment/{id}`) and `correctMySkoolPayment()` (`POST /challan/correct-myskool-payment`) controller actions. |
| [web.php](file:///c:/Users/hp/Desktop/iqra-laravel-billing/routes/web.php) | Registered `/challan/inspect-myskool-payment/{id}` (`challan.inspect-myskool-payment`) and `/challan/correct-myskool-payment` (`challan.correct-myskool-payment`) routes inside the authenticated web group. |
| [collection.blade.php](file:///c:/Users/hp/Desktop/iqra-laravel-billing/resources/views/collection.blade.php) | Added status badges (`REVERSED`, `PARTIALLY REVERSED`, `DISPUTED`, `CORRECTED`, `UNDER REVIEW`, `RECONCILED`) on MySkool rows in Fee History, administrator-only `"Review / Correct MySkool Payment"` action button (`&#8634;`), per-row selector on multi-row shared slips, and the two-stage read-only inspection + authorized correction modal (`#myskool-payment-correction-dialog`). |
| [MySkoolPaymentCorrectionTest.php](file:///c:/Users/hp/Desktop/iqra-laravel-billing/tests/Feature/MySkoolPaymentCorrectionTest.php) | Created comprehensive 12-test (`109` assertions) feature test suite covering all 15 required test scenarios in Task 7 and all Task 6 example verifications (`GR 8857`, shared slip `27328` for `GR 5929` & `GR 5930`, and `GR 8620` cross-currency conflict). |

---

## 3. MySkool Correction UI (Task 2)

In [collection.blade.php](file:///c:/Users/hp/Desktop/iqra-laravel-billing/resources/views/collection.blade.php), within the **Fee Collection → Fee History** table:
1. **Role-Gated Administrator Action:**
   - Rendered **only** when `$rcp->is_historical && ($rcp->can_correct_myskool ?? false)` and `in_array(auth()->user()?->role, ['super_admin', 'finance_admin', 'admin'], true)`.
   - Normal `cashier` and `auditor` users never see the `"Review / Correct MySkool Payment"` button or modal markup in the DOM or page source.
   - Styled with a distinct amber administrator badge (`&#8634;` icon, `title="Review / Correct MySkool Payment"`) alongside the existing View (`&#128065;`) and Print (`&#128424;`) buttons so it is visually separated from ordinary cashier operations.
2. **Read-Only Inspection Modal (`#myskool-payment-correction-dialog`):**
   - Clicking `"Review / Correct MySkool Payment"` calls `GET /challan/inspect-myskool-payment/{id}` (which performs zero database writes) and displays all **14 required forensic fields**:
     1. Original MySkool slip number (`#ms-corr-slip-no`)
     2. Imported source collection row ID & Excel source row (`#ms-corr-col-id`)
     3. Student name and GR (`#ms-corr-student`)
     4. Family code and guardian (`#ms-corr-family`)
     5. Fee type and billing period (`#ms-corr-fee-period`)
     6. Currency and cross-currency conflict indicator (`#ms-corr-currency`)
     7. Original receipt amount (`#ms-corr-amount`)
     8. Historical payment date and cutoff classification (`<= 2026-10-22` vs `> 2026-10-22`) (`#ms-corr-date`)
     9. Whether the receipt is already reconciled to an invoice (`#ms-corr-reconciled`)
     10. Invoice item ID and invoice number where available (`#ms-corr-invoice`)
     11. Current outstanding balance on the item and parent invoice (`#ms-corr-balance`)
     12. Existing adjustments, settlements, and prior corrections (`#ms-corr-history-box`)
     13. Financial before/after effect of the proposed correction (`#ms-corr-effect-tbody`)
     14. Whether reversal is safe and why (`#ms-corr-safe-flag` and `#ms-corr-safety-banner`)
   - **Shared-Slip Selector:** When a MySkool slip contains multiple collection rows (e.g., Slip `27328` for `GR 5929` and `GR 5930`), a row selector table (`#ms-corr-shared-box`) lets the administrator switch between individual `qa_staged_collections` rows and inspect/correct one allocation without affecting sibling rows.

---

## 4. Correction and Reversal Workflow (Task 3)

Implemented in [`MySkoolPaymentCorrectionService`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/Migration/MySkoolPaymentCorrectionService.php):

- **Case A (`CASE_A_RECONCILED_SETTLEMENT`) — Reconciled Historical Payment:**
  - Action: `reverse_settlement`
  - Delegates to [`HistoricalPaymentReconciliationService::reverse()`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/Migration/HistoricalPaymentReconciliationService.php#L497-L689) inside the same atomic transaction, restoring `invoice_items.paid_minor`, `invoice_items.status`, parent `invoices.paid_minor`, parent `invoices.status`, and downstream `previous_dues_snapshot_minor` while preserving the original invoice, `qa_historical_settlements` evidence (`status = 'reversed'`), and `qa_staged_collections` row. Never creates a second invoice or synthetic Portal payment.
- **Case B (`CASE_B_UNRECONCILED_PRE_CUTOFF` / `CASE_B_UNRECONCILED_POST_CUTOFF`) — Imported But Not Reconciled:**
  - Never blindly mutates `invoice_items.paid_minor`.
  - Determines whether `payment_date_parsed <= 2026-10-22` (the opening-balance snapshot cutoff).
  - Pre-cutoff receipts (`<= 2026-10-22`) are blocked from direct settlement reversal (`SAFETY_BLOCKED_PRE_CUTOFF_SNAPSHOT`) and routed to controlled manual review (`mark_under_review`, `mark_disputed`, or `propose_pre_cutoff_compensation` with documented `proposed_compensation_minor`).
- **Case C (`CASE_C_SHARED_SLIP_ALLOCATION`) — Shared MySkool Slip Across Multiple Students or Fee Periods:**
  - Operates strictly at the `qa_staged_collection_id` level (`74428` vs `74429` on Slip `27328`).
  - Reversing `GR 5929`'s settlement (`#59` on `invoice_items.id = 14328`) leaves `GR 5930`'s settlement (`#60` on `invoice_items.id = 14329`) `applied`, recalculates shared parent invoice `10236` from `paid_minor = 51000` to `23000` (`QAR 330.00` remaining), and updates shared November invoice `16199` `previous_dues_snapshot_minor` by the exact delta (`+28000` -> `33000`).
  - Fee History displays the shared slip badge as **`PARTIALLY REVERSED`**.
- **Case D (`CASE_D_DISPUTED_OR_MISASSIGNED`) — Incorrectly Entered or Assigned Receipt:**
  - Supports `mark_disputed`, `mark_under_review`, `mark_corrected`, and `propose_reassignment` (validating target `proposed_student_gr` existence and currency compatibility).
  - Records the correction in `qa_myskool_payment_corrections` and `audit_logs` while keeping the original `qa_staged_collections` row 100% unchanged and blocking automated reconciliation from consuming the disputed/reassigned row.
- **Case E (`CASE_E_REVERSED_CANDIDATE_REAPPLY`) — Safe Audited Reapplication:**
  - Action: `reapply_settlement`
  - Verifies that the settlement is currently `reversed`, that the source collection row has not been consumed elsewhere or placed under dispute, that no live Portal payment allocation (`payment_allocations`) or adjustment conflict has occurred on the target `invoice_item`, and that currency matches.
  - Restores `invoice_items.paid_minor`, parent `invoices.paid_minor`, and downstream `previous_dues_snapshot_minor`, reactivates `qa_historical_settlement_collections.active_staged_collection_id`, and appends an immutable `reapplied` record to `qa_myskool_payment_corrections`.

---

## 5. Handling of Pre-Cutoff Versus Post-Cutoff Receipts

| Attribute | Pre-Cutoff Receipts (`payment_date <= 2026-10-22`) | Post-Cutoff Reconciled Receipts (`payment_date > 2026-10-22`) |
| :--- | :--- | :--- |
| **Opening-Balance Snapshot Impact** | Already netted into MySkool's residual dues before opening-balance invoices were generated | Paid in MySkool after the snapshot cutoff; reconciled into IQRA OB invoices via `qa_historical_settlements` |
| **Direct `invoice_items.paid_minor` Reversal** | **Blocked (`SAFETY_BLOCKED_PRE_CUTOFF_SNAPSHOT`)** — changing `paid_minor` cannot restore a pre-cutoff obligation that was already netted into `net_minor` | **Permitted (`reverse_settlement`)** — safely reverses `qa_historical_settlements` and restores `invoice_items.paid_minor` and parent invoice balance |
| **Supported Correction Actions** | `mark_under_review`, `mark_disputed`, `propose_pre_cutoff_compensation` (logs verified compensating obligation adjustment proposal for finance approval) | `reverse_settlement`, `mark_disputed`, `mark_under_review`, `propose_reassignment`, and `reapply_settlement` (if reversed) |

---

## 6. Shared-Slip Allocation Protection

Shared MySkool slips (such as Slip `27328` containing `qa_staged_collections.id = 74428` for `GR 5929` `QAR 280.00` and `qa_staged_collections.id = 74429` for `GR 5930` `QAR 230.00`) are protected at three layers:
1. **Row-Level Identity:** Every inspection and correction request targets a specific `qa_staged_collection_id` (`74428` vs `74429`), never a bare `slip_number`.
2. **Sibling Settlement Isolation:** Reversing `qa_staged_collections.id = 74428` reverses only `qa_historical_settlements` row `#59` (`invoice_items.id = 14328`) and releases only `active_staged_collection_id = 74428`. Sibling row `74429` and settlement `#60` (`invoice_items.id = 14329`, `paid_minor = 23000`) remain untouched.
3. **Exact Delta Snapshot Restoration:** [`HistoricalPaymentReconciliationService::reverse()`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/Migration/HistoricalPaymentReconciliationService.php#L612-L639) restores downstream `previous_dues_snapshot_minor` on shared family invoices (`invoices.id = 16199`) by adding back the exact settlement reduction delta (`before_snapshot_minor - after_snapshot_minor = 28000`), ensuring `5000 + 28000 = 33000` (`QAR 330.00`) regardless of the order in which sibling settlements were applied or reversed.

---

## 7. Financial Audit Safeguards (Task 4)

All 15 mandatory financial safety rules are enforced in [`MySkoolPaymentCorrectionService`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/Migration/MySkoolPaymentCorrectionService.php):
1. **`qa_staged_collections` Immortality:** Zero `UPDATE` or `DELETE` queries ever target `qa_staged_collections`.
2. **Immutable Audit Trail:** Every correction appends a sequenced row to `qa_myskool_payment_corrections` (linked via `previous_correction_id`) and writes an entry to `audit_logs`.
3. **Strict Role Authorization:** Enforced in both `BillingController` and `MySkoolPaymentCorrectionService::resolveAuthorizedActor()` (`super_admin`, `finance_admin`, `admin` only; `cashier` and `auditor` receive HTTP `403`).
4. **Mandatory Reason:** Requires a non-empty audit reason of at least 10 characters.
5. **Before/After Financial Preview:** `inspect()` computes exact before/after values (`item_paid_minor`, `item_balance_minor`, `invoice_paid_minor`, `invoice_balance_minor`) and displays them in the confirmation modal prior to submission.
6. **Double Reversal / Double Application Prevention:** Blocked by settlement status checks, active correction status checks, and database unique indexes (`uq_qa_hist_set_col_active` and `uq_qa_ms_corr_col_seq`).
7. **Portal Cashier Payment Protection:** Checks `payment_allocations` joined with `payments` (`status != 'reversed'`) and blocks any historical reversal or reapplication if a live Portal receipt is allocated to the item.
8. **Transactions, Row Locking & Concurrency Locks:** Acquires `BatchDispatchLock("hist_col_alloc_{$id}")` and executes inside `$db->transaction()` with `lockForUpdate()` on `qa_staged_collections`, `qa_historical_settlements`, `invoice_items`, and `invoices`.
9. **Currency Isolation:** Blocks cross-currency reassignment or settlement when `currency` conflicts with the student's enrollment or invoice currency (`SAFETY_BLOCKED_CURRENCY_CONFLICT`).
10. **Safe Parent Invoice Recalculation:** Recomputes parent invoice `paid_minor` and `status` (`paid`, `partial`, `unpaid`/`generated`) from locked child items and updates downstream `previous_dues_snapshot_minor`.
11. **Zero Synthetic Portal Payments:** Never inserts into `payments` or `payment_allocations`; cashier daily collections remain completely unaffected.
12. **Original Evidence Preservation:** Original `qa_staged_collections` rows and `qa_historical_settlements` rows are never deleted (`ON DELETE RESTRICT`).
13. **Fee History Retention:** Reversed, disputed, and corrected MySkool receipts remain visible in Fee History with clear status badges.
14. **Explicit Status Badges:** Displays `REVERSED`, `PARTIALLY REVERSED`, `DISPUTED`, `CORRECTED`, `UNDER REVIEW`, or `RECONCILED` in Fee History and the inspection modal.
15. **Pre-Cutoff Manual Review Hold:** Unapplied pre-cutoff receipts (`<= 2026-10-22`) cannot be directly reversed against `paid_minor` and remain under manual review unless a documented compensation proposal is logged.

---

## 8. Test Results (Task 6 & Task 7)

All automated tests were executed using the isolated SQLite `:memory:` configuration (`phpunit.xml`) without touching `iqra_billing`:

```text
PASS  Tests\Feature\MySkoolPaymentCorrectionTest
  ✓ 01 imported receipt inspection is read only and returns all 14 forensic fields                               1.03s
  ✓ 02 authorized correction and settlement reversal case a                                                      0.65s
  ✓ 03 unauthorized correction blocked for cashier auditor and missing reason                                    0.66s
  ✓ 04 shared slip 27328 partial correction gr 5929 and gr 5930 case c                                           0.63s
  ✓ 05 original staged collection preservation and immutable audit trail                                         0.63s
  ✓ 06 repeated reversal prevention and safe reapplication case e                                                0.64s
  ✓ 07 gr 8857 existing portal cashier receipt and november obligation protected                                 0.62s
  ✓ 08 pre cutoff receipt reflected in snapshot blocked from direct reversal case b                              0.62s
  ✓ 09 gr 8620 cross currency conflict and disputed reassignment workflow case d                                 0.62s
  ✓ 10 fee collection invoice pdf consolidated challan and concurrency integration                               1.60s
  ✓ 11 case d reassignment proposal and consumed hold protection                                                 0.63s
  ✓ 12 case e reapplication blocked when portal payment exists on target item                                    0.63s

PASS  Tests\Feature\HistoricalPaymentReconciliationTest (23 passed)
PASS  Tests\Feature\UnifiedFeeHistoryTest (5 passed)
PASS  Tests\Feature\VoidedFeeRestorationTest (17 passed)
PASS  Tests\Feature\MySkoolReconciliationAuditTest (7 passed)

Tests:    64 passed (536 assertions)
Duration: 25.93s
```

---

## 9. Read-Only Local Inspection & UI Verification (Task 8)

Read-only verification against `iqra_billing` confirmed:
1. **Shared Slip `27328` (`Family 5929`):**
   - Inspecting `qa_staged_collections.id = 74428` (`GR 5929`, `Khadija Feroz`, `QAR 280.00`) returns `CASE_C_SHARED_SLIP_ALLOCATION`, `is_reconciled = true`, `invoice_item_id = 14328`, `invoice_number = 'SCH-OB-2026-0000085-512'`, `is_safe_to_reverse = true`, and previews `item_paid_minor: 28000 -> 0`, `item_balance_minor: 0 -> 28000`, `invoice_paid_minor: 51000 -> 23000`, `invoice_balance_minor: 5000 -> 33000`.
   - Inspecting `qa_staged_collections.id = 74429` (`GR 5930`, `Zahra Feroz`, `QAR 230.00`) returns `CASE_C_SHARED_SLIP_ALLOCATION`, `invoice_item_id = 14329`, `item_balance_formatted = '50.00'`, `is_safe_to_reverse = true`, and previews `item_paid_minor: 23000 -> 0`, `item_balance_minor: 5000 -> 28000`, `invoice_paid_minor: 51000 -> 28000`, `invoice_balance_minor: 5000 -> 28000`.
2. **Pre-Cutoff Receipt (`GR 8857`, `qa_staged_collections.id = 71102`, Slip `25192`, `2026-09-21`):**
   - Returns `CASE_B_UNRECONCILED_PRE_CUTOFF`, `is_pre_cutoff = true`, `is_safe_to_reverse = false`, `safety_status = 'BLOCKED_PRE_CUTOFF_SNAPSHOT'`.
3. **Cross-Currency Conflict (`GR 8620`, `qa_staged_collections.id = 74284`, Slip `27256`, `2026-10-23`):**
   - Returns `CASE_D_DISPUTED_OR_MISASSIGNED`, `currency = 'SAR'`, `student_expected_currency = 'AED'`, `has_currency_conflict = true`, `is_safe_to_reverse = false`, `safety_status = 'BLOCKED_CURRENCY_CONFLICT'`.
4. **Role Gate & Portal Receipt Invariance:**
   - Administrator (`super_admin` / `finance_admin` / `admin`) receives `can_correct_myskool = true` on MySkool receipts and sees `"Review / Correct MySkool Payment"`.
   - Cashier (`role = 'cashier'`) receives `can_correct_myskool = false`, does not see `"Review / Correct MySkool Payment"` in Fee History, and receives `403 Forbidden` on `/challan/inspect-myskool-payment/{id}` and `/challan/correct-myskool-payment`.
   - Portal receipt `RCP-2026-0001671` (`payments.id = 1671`, `GR 1234`) retains `can_reverse = true` and `can_correct_myskool = false`, preserving existing Portal cancellation behavior unchanged.
5. **Zero Mutation Confirmation on `iqra_billing`:**
   - Before and after verification: `qa_historical_settlements = 265`, `qa_historical_settlement_collections = 275`, `payments = 1`, `payment_allocations = 1`, `SUM(invoices.paid_minor) = 9,851,300`, `SUM(invoice_items.paid_minor) = 9,851,300`, `qa_staged_collections = 34,712`, `audit_logs = 272`.

---

## 10. Remaining Issues and Management Approvals Required

1. **Owner Approval to Run Migration on `iqra_billing`:**
   - Per project instructions (*"If new tables are necessary, provide a migration and test it in a disposable test environment. Do not migrate the existing financial database without owner approval"*), the migration [2026_10_28_000021_create_qa_myskool_payment_corrections_table.php](file:///c:/Users/hp/Desktop/iqra-laravel-billing/database/migrations/2026_10_28_000021_create_qa_myskool_payment_corrections_table.php) has **not** yet been run against `iqra_billing`.
   - `MySkoolPaymentCorrectionService::inspect()` and `FeeHistoryService` gracefully check `$schema->hasTable('qa_myskool_payment_corrections')` so read-only inspection works immediately without error.
   - Before executing any live correction/reversal action in `iqra_billing`, owner approval is required to run:
     ```powershell
     php artisan migrate --path=database/migrations/2026_10_28_000021_create_qa_myskool_payment_corrections_table.php
     ```
2. **Management Review of Remaining Hold Items from Historical Reconciliation:**
   - The `6` `CONCESSION_REVIEW` items (including `GR 5930`'s remaining `QAR 50.00` on `invoice_items.id = 14329`) and cross-currency/duplicate-source holds (such as `GR 8620` Slip `27256`) remain safely held for explicit management policy sign-off.
