# IQRA Billing — Phase 2C: Safe Voided Fee Restoration Report

**Date:** October 28, 2026  
**Environment:** Isolated QA/Staging Database (`:memory:` / SQLite Test Harness)  
**Status:** COMPLETED & VERIFIED (Zero Mutations to Live Production)

---

## Executive Summary

Phase 2C resolves a critical financial inconsistency in the IQRA Billing platform where invoice items that had been voided were still offered as chargeable in the Create Fee preview, but were subsequently rejected on Save with the generic error:
> *"An invoice has already been generated for this fee and period."*

This phase implements:
1. **Consistent Eligibility Rules** between the frontend Create Fee preview and the backend transactional Save operation across **7 distinct statuses**.
2. **Safe, Audited Restoration Mechanism** (`VoidedFeeRestorationService` and `billing:restore-voided-fee`) for accidentally voided financial obligations.
3. **Atomic Historical Settlement Integration** (Phase 2B integration), ensuring obligations historically paid in MySkool (such as Family 5929 / GR 5929 and GR 5930) are restored directly into their verified settled state without ever being exposed as collectible dues or inflating cashier cash totals.
4. **Comprehensive Automated Test Suite** (`tests/Feature/VoidedFeeRestorationTest.php`), passing all 15 scenarios (145 assertions), while maintaining 100% pass rates across existing regression suites.

---

## 1. Root Cause of the Create Fee Error

### Why Fees Were Offered in Preview but Rejected on Save

1. **Client-Side vs Server-Side Disconnect in Preview:**
   - The fee collection interface (`resources/views/collection.blade.php`) previously generated the Create Fee preview table entirely via client-side JavaScript (`updateCreateFeePreview()`), reading class-level catalog rates.
   - The JavaScript did not query existing `invoice_items`, `qa_staged_dues`, `qa_staged_collections`, or `qa_historical_settlements`.
   - Consequently, any voided item or historically settled obligation appeared in the preview as an ordinary, chargeable fee.

2. **Backend Rejection by Historical Protection on Save:**
   - When the user clicked "Generate & Save Fee", `BillingController::createChallanFee()` evaluated the selection.
   - Rule 7b (`HistoricalOpeningBalanceService::checkHistoricalObligation()`) correctly detected that the student's fee obligation already existed in historical MySkool records (`blocks_billing = true`).
   - Because the fee was blocked, `createdItems` remained `0`.
   - With `paidDuplicatesCount === 0` (since the voided item had `paid_minor = 0`), the logic fell through to the generic default error:
     > *"An invoice has already been generated for this fee and period."*

3. **Defect in Legacy `recreateVoidedFee()`:**
   - The existing `FeeAdjustmentService::recreateVoidedFee()` method was incapable of handling historically paid items: it checked `checkHistoricalObligation()` and explicitly aborted if historical records existed, and it forced `paid_minor = 0`.
   - If an administrator attempted to recreate an obligation that had been paid in MySkool, it would either fail or recreate it as unpaid dues, improperly re-billing the family.

---

## 2. Files Modified & Created

| File | Status | Description |
|---|---|---|
| `app/Services/FeeEligibilityService.php` | **NEW** | Unified service evaluating rate resolution, active invoices, voided items, opening balances, historical MySkool collections, and currency conflicts across 7 distinct statuses. Shared by preview and save. |
| `app/Services/VoidedFeeRestorationService.php` | **NEW** | Admin-only forensic inspection and atomic restoration engine. Preserves original invoice numbers, source lineage, void adjustments, and executes atomic historical settlement for post-cutoff MySkool payments. |
| `app/Console/Commands/RestoreVoidedFeeCommand.php` | **NEW** | CLI Artisan tool (`billing:restore-voided-fee`) supporting `--dry-run`, `--apply`, `--reason`, and `--confirm-owner` management gates. |
| `app/Services/Migration/HistoricalPaymentReconciliationService.php` | **MODIFIED** | Added `--allow-void-recovery` path to `apply()`. Added guard preventing source collections from being consumed twice across different invoice items. Added guard blocking reconciliation reversal if intervening cashier payments exist. |
| `app/Services/Migration/MySkoolReconciliationAuditService.php` | **MODIFIED** | Updated `overlappingRecords()` to surface staged collection and due IDs. |
| `app/Console/Commands/ReconcileMySkoolHistoricalPaymentsCommand.php` | **MODIFIED** | Added `--allow-void-recovery` flag. |
| `app/Http/Controllers/BillingController.php` | **MODIFIED** | Added `previewChallanFee()`, `inspectVoidedFee()`, and `restoreVoidedFee()`. Updated `createChallanFee()` to produce granular, friendly rejection messages matching eligibility statuses. |
| `routes/web.php` | **MODIFIED** | Registered `POST /challan/preview-fee`, `GET /challan/inspect-voided-fee/{id}`, and `POST /challan/restore-voided-fee`. |
| `resources/views/collection.blade.php` | **MODIFIED** | Upgraded Create Fee modal to query server-side preview with color-coded status badges, disabled Save button for 0 eligible items, and added `#myskoolRestorationDrawer` inspection/restoration drawer. |
| `tests/Feature/VoidedFeeRestorationTest.php` | **NEW** | 15 targeted feature tests (145 assertions) covering all Phase 2C scenarios. |

---

## 3. New Restoration Workflow

The restoration process follows a strict two-stage forensic accounting workflow:

```
[ User selects Fee in UI ] ──> POST /challan/preview-fee
                                    │
                                    ├── If "Requires restoration review" or "Previously voided"
                                    │   └── Displays "[Inspect & Restore]" Action Button
                                    │
[ Click "Inspect & Restore" ] ──> GET /challan/inspect-voided-fee/{id}
                                    │
                                    └── Opens Forensic Drawer:
                                        - Original approved amounts & discounts
                                        - Void adjustment audit history
                                        - Historical MySkool slip evidence
                                        - Safety status classification
                                    │
[ Authorized Admin Confirms ] ──> POST /challan/restore-voided-fee
                                    │
                                    ├── Validates Admin Role & Mandatory Reason
                                    ├── Acquires Row Locks (pessimistic locking)
                                    ├── Re-verifies Safety Checks under lock
                                    ├── Atomically restores net_minor & applies settlement
                                    ├── Inserts compensating 'restoration' fee_adjustment
                                    ├── Atomically updates qa_historical_settlements
                                    ├── Recalculates parent invoice totals & status
                                    └── Inserts immutable audit_logs entry
```

### The 7 Consistent Eligibility Statuses

Both `FeeEligibilityService::evaluateEligibility()` and `BillingController::createChallanFee()` enforce identical rules:

1. **`Eligible for creation` (`eligible`):** Student has active enrollment, approved rate, and no prior invoice or historical obligation for the period.
2. **`Already invoiced` (`already_invoiced`):** An active invoice item already exists on a generated, partial, or paid invoice.
3. **`Previously voided` (`previously_voided`):** The obligation was voided without any historical MySkool payment. Can be restored safely as unpaid dues.
4. **`Historical MySkool payment exists` (`historical_payment`):** Obligation is already settled in historical MySkool collections. Creation is blocked.
5. **`Historical opening balance exists` (`historical_ob`):** Obligation exists in historical MySkool opening balance dues. Regular billing is blocked.
6. **`Requires restoration review` (`restoration_review`):** Obligation was voided, but possesses historical MySkool collection evidence. Must be restored via atomic settlement.
7. **`Blocked by financial conflict` (`financial_conflict`):** Student is on review hold or cross-currency conflict exists between enrollment and staging records.

---

## 4. Historical Settlement Integration (Phase 2B Integration)

### Atomic Restoration & Zero Exposure of Historical Dues

A critical requirement of Phase 2C was ensuring that an accidentally voided obligation that was historically paid in MySkool is **never briefly exposed as collectible**:
- If an item was restored to `active` (`paid_minor = 0`) before running settlement, a cashier could view the portal during that window and demand payment from the family for an already-paid obligation.
- `VoidedFeeRestorationService::restore()` solves this by performing both operations inside a **single atomic database transaction**:
  - `invoice_items.net_minor` is restored to `approvedNetMinor`.
  - `invoice_items.paid_minor` is simultaneously set to `settledMinor`.
  - `invoice_items.status` transitions directly to `'paid'` (or `'partial'`).
  - `qa_historical_settlements` is inserted with full audit metadata.
  - `InvoiceService::refreshInvoice()` recalculates parent invoice totals and marks it `'paid'` or `'partial'`.
  - The balance outstanding drops to 0 without any intermediate un-settled state.

### Cashier Total & Slip Protection

- Historical settlements are recorded in `qa_historical_settlements`, **not** in `payments`.
- Counter cashier collection reports (which query `payments` and `payment_allocations`) show **zero increase**.
- Parent invoice `paid_minor` increases, but the audit trail shows the payment originated from MySkool historical receipts.
- `FeeHistoryService` displays the historical receipt slip without creating duplicate receipt records.

---

## 5. Financial Safety Safeguards

| Guard | Mechanism | Protection |
|---|---|---|
| **1. Source Lineage Preservation** | Restores original `invoice_items` row ID | Preserves parent invoice ID, student ID, and billing run lineage; never creates orphan duplicate charges. |
| **2. Non-Destructive Adjustments** | Inserts `fee_adjustments.kind = 'restoration'` | The original `void` adjustment row is preserved intact for forensic auditability. |
| **3. Approved Financial Value Recovery** | Queries void adjustment `previous_amount_minor` and opening balance lineage | Restores approved net amounts after approved discounts/exemptions, never guessed gross catalog amounts. |
| **4. Duplicate Restoration Prevention** | Checks `item.status !== 'voided'` under lock | Idempotent; repeated restoration calls fail with `BLOCKED_ALREADY_ACTIVE`. |
| **5. Invoice Constraint Conflict Guard** | Checks for active non-voided items for same student/fee_type/period | Rejects restoration with `BLOCKED_DUPLICATE_ACTIVE_ITEM` if another active item was generated in the interim. |
| **6. Cashier Activity Precedence** | Checks `payment_allocations` on the voided item | Blocks restoration with `BLOCKED_INTERVENING_PAYMENTS` if live portal cashier payments exist. |
| **7. Multi-Consumption Guard** | Checks `qa_historical_settlements.qa_staged_collection_ids` | A historical collection slip cannot be consumed across multiple invoice items. |
| **8. Reversal Safety Guard** | Checks `payment_allocations` in `HistoricalPaymentReconciliationService::reverse()` | Blocks reversal if intervening cashier payments have been allocated to the item. |
| **9. Parent Recalculation** | Calls `InvoiceService::refreshInvoice()` | Recomputes `invoices.total_minor`, `paid_minor`, and status transactionally. |
| **10. Role Gate & Audit Logging** | Requires `super_admin`, `finance_admin`, or `admin` role | Enforces mandatory justification string and writes JSON metadata to `audit_logs`. |

---

## 6. Reference Case: Family 5929 (GR 5929 & GR 5930)

Simulated test records equivalent to the live case in an isolated test database confirmed exact accounting behavior:

### GR 5929 (Fully Paid Case)
- **Original Obligation:** October 2026 Monthly Fee, QAR 280 (28,000 minor) for `GR 5929` (Hafsa Feroz).
- **Historical Evidence:** MySkool Collection Slip 27328 (`source_row = 34702`, `id = 74428`), paid QAR 280 on 2026-10-24.
- **Accidental Void:** Fee obligation was subsequently voided.
- **Accounting Verification:**
  - Create Fee preview flags item as `Requires restoration review`.
  - Save operation rejects duplicate creation.
  - Restoration inspects item as `SAFE_ATOMIC_HISTORICAL_SETTLEMENT`.
  - Atomic restoration restores `net_minor = 28000`, `paid_minor = 28000`, `status = 'paid'`.
  - Collectible balance is **QAR 0.00**.
  - Parent invoice status is **`paid`**.
  - Zero change to cashier cash drawer totals.
  - Future Create Fee attempts show `Already invoiced` and are permanently blocked.

### GR 5930 (Partial Paid / Concession Review Case — Shared Slip #27328)
- **Original Obligation:** October 2026 Monthly Fee, QAR 280 (28,000 minor) for `GR 5930` (Zahra Feroz).
- **Historical Evidence:** MySkool Collection Slip 27328 (`source_row = 34703`, `id = 74429`), paid QAR 230 on 2026-10-24 (sharing family receipt Slip #27328 with sibling `GR 5929`, distinguished by individual `qa_staged_collections` row ID and `source_row`).
- **Concession Context:** Potential sibling discount of QAR 50 not yet approved.
- **Accidental Void:** Fee obligation was subsequently voided.
- **Accounting Verification:**
  - Restoration inspects item as `SAFE_PARTIAL_HISTORICAL_SETTLEMENT`.
  - Flags `requires_concession_review = true` for the QAR 50 (5,000 minor) difference.
  - Atomic restoration restores `net_minor = 28000`, `paid_minor = 23000`, `status = 'partial'`.
  - Remaining QAR 50 balance is preserved under review.
  - Parent invoice status is **`partial`**.
  - November 2026 invoices remain completely separate without duplicate charges.

---

## 7. Test Results Summary

Targeted feature tests and comprehensive billing regression suites were executed in an isolated SQLite environment:

```
Test Suites:
✓ tests/Feature/VoidedFeeRestorationTest.php (15 tests, 145 assertions) — PASS
  - 01 create fee preview and save consistency across statuses
  - 02 normal accidental void restoration
  - 03 historically settled voided fee reference case gr 5929
  - 04 partially settled historical fee reference case gr 5930
  - 05 existing portal cashier activity blocks restoration and reversal
  - 06 existing discount and exemption preserved accurately
  - 07 duplicate restoration attempt rejected
  - 08 unauthorized restoration blocked by role gate
  - 09 invoice constraint conflicts block restoration
  - 10 parent invoice total recalculation across multiple items
  - 11 cross currency conflict blocked
  - 12 duplicate historical source allocation blocked
  - 13 interrupted restoration rolls back atomically
  - 14 artisan cli dry run matches apply
  - 15 web route inspect and restore endpoints

Regression Test Suites:
✓ tests/Feature/HistoricalPaymentReconciliationTest.php (18 tests, 90 assertions) — PASS
✓ tests/Feature/CreateFeeDuplicateProtectionTest.php (5 tests, 31 assertions) — PASS
✓ tests/Feature/MySkoolReconciliationAuditTest.php (7 tests, 58 assertions) — PASS
✓ tests/Feature/BillingLedgerTest.php (9 tests, 31 assertions) — PASS

Total: 54 automated feature tests passing, 355 assertions, 0 failures.
```

---

## 8. Current Known Limitations

1. **Active Cashier Payment Interventions:**
   If a voided fee obligation has received live portal cashier payments (`payment_allocations`) after being voided, restoration is blocked (`SAFETY_BLOCKED_INTERVENING_PAYMENTS`). Cashier allocations must be reversed or reallocated before the voided obligation can be restored.
2. **Pending Concession Resolution:**
   Unapproved sibling concessions (e.g., QAR 50 on GR 5930) remain in `partial` status. When school management approves the concession policy, an authorized discount must be applied via `FeeAdjustmentService::applyDiscount()`.
3. **Non-Standard Period One-Time Fees:**
   One-time fee items that lack structured dates (e.g. ad-hoc books or transport items without a `period_start`) require manual review if historical collections contain ambiguous descriptions.

---

## 9. Exact Safe Production Restoration Procedure

When restoring voided fee obligations in production:

### Step 1: Pre-Restoration Forensic Dry-Run (Read-Only)
Run the Artisan restoration command in dry-run mode to inspect the item:
```bash
php artisan billing:restore-voided-fee <INVOICE_ITEM_ID>
```
Verify the output forensics table:
- Confirm `Original Approved Net` matches financial records.
- Confirm `Safety Status` is either `SAFE_NORMAL_RESTORATION` or `SAFE_ATOMIC_HISTORICAL_SETTLEMENT`.
- Confirm `Historical Settlement Amount` matches verified MySkool slips.

### Step 2: Management Authorization Sign-Off
Obtain written confirmation from Finance Management confirming:
1. Target invoice item ID.
2. Verified justification reason.
3. Acknowledgment of any remaining dues or concession holds.

### Step 3: Transactional Execution
Execute the safe restoration with owner confirmation and reason:
```bash
php artisan billing:restore-voided-fee <INVOICE_ITEM_ID> \
  --apply \
  --confirm-owner \
  --reason="Authorized restoration of accidentally voided fee; verified against MySkool Slip 27328" \
  --force
```

### Step 4: Verification & Reconciliation Audit
Verify the post-restoration state:
1. Inspect the parent invoice total and status in the portal:
   ```bash
   php artisan billing:audit-myskool-reconciliation --summary
   ```
2. Verify `qa_historical_settlements` has recorded the settlement event.
3. Confirm counter cashier daily cash collections remain unaffected.

---

## 10. Remaining Phase 3 Integration QA Requirements

Before proceeding with Phase 3 (Delivery Engine & Parent Portal Integration):
1. **Consolidated Challan PDF Verification:**
   Ensure PDF challans rendered for restored items accurately reflect historical MySkool payments as "Previously Settled (MySkool)" without displaying duplicate receipt numbers.
2. **WhatsApp / Email Delivery Queue Integration:**
   Verify batch delivery filters honor restored invoices and do not send dunning reminders for items that have been historically settled.
3. **Formal Owner Acceptance Sign-off:**
   Present this report and the Family 5929 reference reconciliation to school management for final review.

---
*Implementation and testing complete. Awaiting user authorization to proceed to Phase 3.*
