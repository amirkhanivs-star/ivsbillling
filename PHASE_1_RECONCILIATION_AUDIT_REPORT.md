# IQRA Billing: Phase 1 — Read-Only Reconciliation Audit Report

**Audit Date:** October 27, 2026  
**Phase:** Phase 1 ONLY (Read-Only Reconciliation Audit)  
**Target Database:** `mysql` (`iqra_billing` — Local Development)  
**Active Staged Batch:** `BATCH-20261025-FINAL-MYSKOOL`  
**Verified Snapshot Cutoff:** `2026-10-22`  

---

## 1. Files Added or Modified

### Added Files
1. [`app/Services/Migration/MySkoolReconciliationAuditService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/Migration/MySkoolReconciliationAuditService.php) — Strictly read-only reconciliation audit engine matching opening-balance `invoice_items` against `qa_staged_collections` by original student GR, stable family code, normalized fee identity, billing period, subject, and currency.
2. [`app/Console/Commands/AuditMySkoolReconciliationCommand.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Console/Commands/AuditMySkoolReconciliationCommand.php) — Artisan CLI command (`billing:audit-myskool-reconciliation`) producing a concise summary table, subsequent snapshot impact analysis, and reviewable CSV export.
3. [`tests/Feature/MySkoolReconciliationAuditTest.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/tests/Feature/MySkoolReconciliationAuditTest.php) — Targeted regression test suite verifying all reconciliation classifications, portal activity conflict holds, duplicate prevention, fail-closed guards, and zero-write behavior.
4. [`PHASE_1_RECONCILIATION_AUDIT_REPORT.md`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/PHASE_1_RECONCILIATION_AUDIT_REPORT.md) — Phase 1 deliverable report.

*(No existing application files, migrations, or financial records were modified.)*

---

## 2. Exact Command and Usage

```bash
# Standard read-only audit with CSV export (uses default cutoff 2026-10-22 and active batch)
php artisan billing:audit-myskool-reconciliation --export=csv

# Optional flags for custom output path, explicit batch ID, cutoff date, or connection
php artisan billing:audit-myskool-reconciliation \
    --export=csv \
    --output="storage/app/reconciliation/myskool_opening_balance_reconciliation_audit.csv" \
    --batch-id="BATCH-20261025-FINAL-MYSKOOL" \
    --cutoff="2026-10-22" \
    --conn="mysql"
```

---

## 3. Actual Local Reconciliation Counts

### Dataset Scope
- **Staged Collections in Batch (`BATCH-20261025-FINAL-MYSKOOL`):** `34,712` rows (`2023-09-04` to `2026-10-24`)
- **Opening-Balance Invoice Items Scanned:** `10,257` items
- **Unmatched Opening-Balance Items (No overlapping collection row):** `9,779` items
- **Overlapping Opening-Balance Items Matched:** `478` items

### High-Level Reconciliation Summary (4 Core Categories)

| Reconciliation Bucket | Count | Audit Disposition |
| :--- | ---: | :--- |
| **1. Genuine Pre-Cutoff Residual Balances (`<= 2026-10-22`)** | **209** | **PROTECTED:** Already subtracted in MySkool's Oct 22 Outstanding snapshot; keep open. |
| **2. Post-Cutoff Full Settlements (`> 2026-10-22`)** | **246** | **PHASE 2 CANDIDATE:** Paid in full on Oct 23–24 in matching currency with 0 portal conflicts. |
| **3. Post-Cutoff Partial Payments or Possible Concessions (`> 2026-10-22`)** | **19** | **REVIEW / PARTIAL:** `13` ad-hoc partial payments (`POST_CUTOFF_PARTIAL_PAYMENT`) + `6` recurring/sibling concession candidates (`POST_CUTOFF_SIBLING_CONCESSION`). |
| **4. Anomalies & Conflicts Requiring Manual Review** | **4** | **MANUAL REVIEW HOLD:** `2` currency mismatches + `2` ambiguous duplicate OB items. |
| **TOTAL OVERLAPPING ITEMS** | **478** | **100% Accounted For (Zero financial writes)** |

### Granular Classification Breakdown

| Classification Code | Count | Description |
| :--- | ---: | :--- |
| `PRE_CUTOFF_RESIDUAL_BALANCE` | `209` | All matched historical payments occurred on or before `2026-10-22`. |
| `POST_CUTOFF_FULL_SETTLEMENT` | `246` | Post-cutoff payments (`2026-10-23` to `2026-10-24`) exactly equal `net_minor`. |
| `POST_CUTOFF_PARTIAL_PAYMENT` | `13` | Post-cutoff payments are `< net_minor` with a genuine remaining balance. |
| `POST_CUTOFF_SIBLING_CONCESSION` | `6` | Post-cutoff single-line payment is `< net_minor` matching prior recurring reduced fee or sibling slip discount pattern. |
| `CONFLICT_EXISTING_PORTAL_ACTIVITY` | `0` | Zero overlapping OB items in local DB have live portal payments or adjustments. |
| `MANUAL_REVIEW_CURRENCY_MISMATCH` | `2` | OB item currency differs from staged collection currency. |
| `MANUAL_REVIEW_AMBIGUOUS_MATCH` | `2` | Multiple OB items share the same `(GR, fee_particular, period_key)`. |
| `MANUAL_REVIEW_MISSING_EVIDENCE` | `0` | Zero missing GR/period/date records among the 478 matches. |
| `MANUAL_REVIEW_OVERPAYMENT` | `0` | Zero post-cutoff overpayments exceeding `net_minor`. |

### Currency Breakdown of the 478 Overlapping Items

| Currency | Overlapping Items | Original OB Net | Pre-Cutoff Paid (`<= Oct 22`) | Post-Cutoff Paid (`> Oct 22`) | Potential Remaining Balance |
| :--- | ---: | ---: | ---: | ---: | ---: |
| **SAR** | `303` | `60,021.00 SAR` | `22,797.00 SAR` | `44,321.00 SAR` | `15,700.00 SAR` |
| **AED** | `168` | `34,333.00 AED` | `11,379.00 AED` | `22,062.00 AED` | `12,931.00 AED` |
| **PKR** | `4` | `52,000.00 PKR` | `15,000.00 PKR` | `32,000.00 PKR` | `20,000.00 PKR` |
| **QAR** | `3` | `720.00 QAR` | `160.00 QAR` | `510.00 QAR` | `210.00 QAR` |

### Potentially Affected Subsequent Invoice Snapshots (Including November 2026)

- **Subsequent Invoices Across All 478 Overlap Families:** `315` invoices
- **Subsequent Invoices Affected by Post-Cutoff Settlements/Partials:** `108` invoices
- **November 2026 (`monthly:2026-11-01..2026-11-30`, Run #112) Invoices Affected:** `108` invoices
- **Total `previous_dues_snapshot_minor` Overstatement on Subsequent Invoices:**
  - **SAR:** `43,715.00 SAR`
  - **PKR:** `32,000.00 PKR`
  - **AED:** `20,258.00 AED`
  - **QAR:** `510.00 QAR`

---

## 4. Comparison with Previously Reported 478 Items

The dynamic rule-based audit independently reproduces the exact previously reported totals:
- **Total Overlapping Items:** `478` (Exact match)
- **Genuine Pre-Cutoff Residuals:** `209` (Exact match)
- **Post-Cutoff Full Settlements:** `246` (Exact match)
- **Post-Cutoff Partial Payments or Possible Concessions:** `19` (Exact match; now further sub-classified into `13` `POST_CUTOFF_PARTIAL_PAYMENT` and `6` `POST_CUTOFF_SIBLING_CONCESSION` cases)
- **Anomalies Requiring Manual Review:** `4` (Exact match; specifically `2` `MANUAL_REVIEW_CURRENCY_MISMATCH` and `2` `MANUAL_REVIEW_AMBIGUOUS_MATCH`)

---

## 5. Identified Edge Cases

1. **Cross-Currency Collection Mismatch (2 items — GR `8620`, Family `8563`):**
   - Opening-balance items `#19524` (`Monthly Fee - Sep-2026`) and `#19526` (`Monthly Fee - Oct-2026`) are denominated in `AED 330.00`, whereas post-cutoff MySkool Slip `27256` (`2026-10-23`) recorded `SAR 330.00`. Held as `MANUAL_REVIEW_CURRENCY_MISMATCH`.
2. **Duplicate Opening-Balance Split Items (2 items — GR `4814`, Family `4814`):**
   - Opening-balance items `#13069` (`AED 210.00`) and `#13070` (`AED 250.00`) both represent `Monthly Fee - May-2026` (from two split lines in `qa_staged_dues` rows `1377` and `1378`), while `qa_staged_collections` has Slip `24127` (`2026-07-03`, `AED 40.00`). Held as `MANUAL_REVIEW_AMBIGUOUS_MATCH` to prevent double-counting or ambiguous allocation.
   - *(Note: 5 additional students — GRs `8161`, `8410`, `5481`, `6562`, `4909` — also have split/duplicate OB items for the same month in `invoice_items`, though without matching collection rows for those months.)*
3. **Combined Pre-Cutoff Partial + Post-Cutoff Final Settlement (9 items):**
   - Nine items (GRs `6495`, `8649`, `7050`, `6928`, `8684`, `9596`, `7059`, `7266`, `9258`) had a partial payment on or before `2026-10-22` (already netted in the Oct 22 Outstanding balance) followed by a post-cutoff payment on Oct 23–24 that exactly settled the remaining opening balance (`post_cutoff_paid_minor === net_minor`). Properly classified as `POST_CUTOFF_FULL_SETTLEMENT`.
4. **Multi-Slip / Multi-Line Post-Cutoff Settlements (7 items):**
   - Includes items settled across two post-cutoff slips (e.g., GR `6495` Feb-2026 on Slips `27176` + `27177`: `152 + 58 = 210 SAR`; GR `8649` Apr-2026 on Slips `27237` + `27240`: `25 + 47 = 72 SAR`; GR `9826` Sep-2026 on Slips `27261` + `27329`: `100 + 130 = 230 SAR`) and two 50% installment lines on the same slip (`GR 6555` Aug-2026 on Slip `27173`: `135 + 135 = 270 SAR`).
5. **Recurring / Sibling Concessions (6 items among the 19 partial/concession cases):**
   - GR `5168` (`Fam 5166`, paid `100` vs `200 SAR` OB; paid `100 SAR` in 11 prior months, 5 siblings on Slip `27204`).
   - GR `5930` (`Fam 5929`, paid `230` vs `280 QAR` OB; paid `230 QAR` in 11 prior months, sibling `5929` paid full `280 QAR` on Slip `27328`).
   - GR `5502` (`Fam 5502`, paid `330` vs `400 SAR` OB; paid `330 SAR` in 11 prior months).
   - GR `5130` (`Fam 5130`, paid `270` vs `330 SAR` OB; matches prior month reduced rate of `270 SAR`).
   - GR `7266` (`Fam 7264`, paid `160` vs `220 AED` OB alongside siblings `7264` and `7265` on Slip `27287`).
   - GR `7364` (`Fam 5615`, paid `195` vs `230 SAR` OB alongside 3 siblings on Slip `27265`).

---

## 6. CSV Output Path

```text
C:\Users\hp\Desktop\iqra-laravel-billing\storage\app\reconciliation\myskool_opening_balance_reconciliation_audit.csv
```

The generated CSV contains `479` lines (`1` header row + `478` item rows) with all 14 required columns plus traceability columns (`Invoice Item ID`, `Invoice Number`, `Student Name`, `Potentially Affected Subsequent Invoice Snapshots`).

---

## 7. Tests Executed and Results

Executed command:
```bash
php artisan test tests/Feature/MySkoolReconciliationAuditTest.php
```

Result:
```text
PASS  Tests\Feature\MySkoolReconciliationAuditTest
  ✓ classifies post cutoff full settlement and pre cutoff residual correctly                                     0.57s
  ✓ distinguishes partial payments and sibling concessions                                                       0.22s
  ✓ aggregates multiple receipts and prevents duplicate allocation double counting                               0.22s
  ✓ protects items with existing portal payments or fee adjustments                                              0.22s
  ✓ detects currency mismatches and ambiguous or missing evidence                                                0.23s
  ✓ command exports csv and enforces strictly read only behavior                                                 0.24s
  ✓ fails closed when staged collection batch evidence is missing                                                0.23s

Tests:    7 passed (58 assertions)
Duration: 2.10s
```

---

## 8. Financial Data Write Verification

A pre/post ledger snapshot and `DB::listen` query audit were executed around `php artisan billing:audit-myskool-reconciliation --export=csv` against `iqra_billing`:

- **Total SQL Queries Executed:** `63` bounded `SELECT` queries (zero N+1 queries)
- **Total SQL Write Queries (`INSERT`/`UPDATE`/`DELETE`/`ALTER`/`DROP`/`TRUNCATE`):** `0`

| Ledger Metric | Before Audit | After Audit | Status |
| :--- | ---: | ---: | :--- |
| `invoices` count | `10,286` | `10,286` | **UNCHANGED** |
| `invoices.total_minor` sum | `637,775,700` | `637,775,700` | **UNCHANGED** |
| `invoices.paid_minor` sum | `28,000` | `28,000` | **UNCHANGED** |
| `invoices.previous_dues_snapshot_minor` sum | `498,524,800` | `498,524,800` | **UNCHANGED** |
| `invoice_items` count | `14,143` | `14,143` | **UNCHANGED** |
| `invoice_items.net_minor` sum | `637,775,700` | `637,775,700` | **UNCHANGED** |
| `invoice_items.paid_minor` sum | `28,000` | `28,000` | **UNCHANGED** |
| `payments` count / sum | `1` / `28,000` | `1` / `28,000` | **UNCHANGED** |
| `payment_allocations` count / sum | `1` / `28,000` | `1` / `28,000` | **UNCHANGED** |
| `fee_adjustments` count | `2` | `2` | **UNCHANGED** |
| `billing_runs` count | `40` | `40` | **UNCHANGED** |
| `qa_opening_balance_lineage` count | `10,257` | `10,257` | **UNCHANGED** |

---

## 9. Remaining Blockers / Owner Decisions Required Before Phase 2

1. **Owner Sign-Off on the 246 Post-Cutoff Full Settlements:**
   - Confirm whether the `246` `POST_CUTOFF_FULL_SETTLEMENT` items should be settled in Phase 2 via historical payment allocation or opening-balance adjustment/exemption, and how their parent opening-balance invoice totals/statuses should be refreshed.
2. **Owner Policy Decision on the 19 Partial / Concession Items:**
   - For the `6` `POST_CUTOFF_SIBLING_CONCESSION` items (GRs `5168`, `5130`, `5930`, `7266`, `5502`, `7364`), decide whether the remaining unpaid difference should be waived via a discount/concession adjustment or left open as an unpaid balance.
   - For the `13` `POST_CUTOFF_PARTIAL_PAYMENT` items, confirm that only the paid post-cutoff amount should be credited, leaving the remaining balance open.
3. **Owner Resolution on the 4 Manual-Review Anomalies:**
   - **GR `8620` (Items `#19524`, `#19526`):** Confirm whether the `SAR 330.00` collection on Slip `27256` (`2026-10-23`) was a currency-entry typo in MySkool for `AED 330.00` or requires a currency correction.
   - **GR `4814` (Items `#13069`, `#13070`):** Confirm that `May-2026` (`AED 210` + `AED 250`) is a pre-cutoff residual (`Slip 24127` was on `2026-07-03`) and remains open without reduction.
4. **November 2026 Snapshot Synchronization Policy:**
   - Confirm whether the `108` affected November 2026 invoices (`billing_run_id = 112`) should have their `previous_dues_snapshot_minor` recalculated in a subsequent phase after Phase 2 settlements are applied.
