# IQRA Billing System — CSV Import & Multi-Currency Compatibility Assessment

**Document Path:** [`docs/CSV_IMPORT_EDGE_CASES.md`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/docs/CSV_IMPORT_EDGE_CASES.md)  
**Date:** October 25, 2026  
**Context:** Local Development & Testing Environment Assessment  
**Authoritative Data Source:** MySkool Authoritative CSV Exports (`STAGING_students.csv`, `STAGING_dues.csv`, `STAGING_paid.csv`, `STAGING_currency_review.csv`)  
**Status:** Read-Only Architectural Assessment Completed  

---

## 1. Executive Summary & Core Accounting Principles

This document provides a comprehensive compatibility assessment of the IQRA Billing Laravel codebase against the authoritative MySkool data exports. The local Laravel application and MySQL database serve as development and verification systems; the authoritative truth resides in the verified MySkool CSV exports.

School accounting rules mandate strict ledger segregation:
1. **Independent Currency Ledgers:** Monetary values of different currencies must never be combined, converted, or summed together into a single scalar value. Each currency operates as a separate, self-balancing sub-ledger.
2. **Residual Opening Dues Principle:** Historical dues recorded in the MySkool "Dues Details" / "Outstanding Report" represent **net residual unpaid balances** after prior collections had already been credited at the time of export.
3. **Double-Crediting Prohibition:** Historical payment collections (from "Paid Details" / "Collection Report") must **never** be deducted again from opening balance dues without explicit item-level reconciliation. Doing so subtracts historical payments twice.
4. **Temporal Integrity:** A change in current enrollment currency (e.g. moving from Pakistan to Saudi Arabia) must never retroactively alter or rewrite historical transaction currencies.

---

## 2. Scenario A: Currency Changes During an Academic Year

### 2.1 Scenario Description
A student may be enrolled in one currency during an earlier term and transition to another currency later in the same academic year (e.g., student **GR 3230 Amna Khan**, who historically paid in **PKR** while residing in Pakistan, but transitioned to **SAR** upon admission to the Saudi Arabia online division).

In this scenario:
* Historical unpaid dues (e.g., June, July, August 2025) exist in **PKR**.
* Recent and current fee plan obligations (e.g., September 2025 onwards) exist in **SAR**.
* The current enrollment record reflects `currency = 'SAR'`.
* The system must preserve historical PKR invoices, collect PKR payments against PKR dues, and generate subsequent monthly recurring charges in SAR without currency conversion.

---

### 2.2 Current System Behavior

| Component | Current Implementation | Behavior in Scenario A |
|---|---|---|
| **`HistoricalOpeningBalanceService.php`** | `isVerifiedCurrency()` ([lines 1130–1155](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/Migration/HistoricalOpeningBalanceService.php#L1130-L1155)) checks whether the due currency matches: (1) current enrollment, (2) student historical collections, (3) other student enrollments, or (4) family enrollments. | **Partially Supported:** Verified for GR 3230 because Amna Khan had a matching historical payment in `qa_staged_collections`. Dues are posted as separate PKR and SAR opening balance invoices. |
| **`FeeImportService.php`** (Legacy manual importer) | `normalizeCurrency()` ([lines 66–77](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/FeeImportService.php#L66-L77)) has fallback `return $c ?: 'SAR';` and line 159 defaults `$currency = 'SAR'`. | **Broken / High Risk:** If a historical dues row has an empty, malformed, or missing currency column, it silently defaults to SAR, rewriting historical PKR dues into SAR. |
| **`InvoiceService.php`** (Recurring billing engine) | In `generate()` ([lines 22–24](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/InvoiceService.php#L22-L24)), bills strictly matching `e.currency = p.currency`. | **Safe:** Recurring monthly invoices are generated strictly in the student's active enrollment currency (SAR) and do not mutate past PKR invoices. |
| **`ReceivePaymentService.php`** | Enforces `where('currency', $currency)` across invoices and payments ([lines 70–75](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/ReceivePaymentService.php)). | **Safe:** Prevents cross-currency payment allocations (e.g., a SAR cash receipt cannot settle a PKR invoice). |
| **Consolidated Challan PDF** | Grouped by `currency` ([`ConsolidatedChallanTest.php:155`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/tests/Feature/ConsolidatedChallanTest.php#L155)). | **Safe:** Renders separate subtotal tables for PKR and SAR without combining unlike currencies. |

---

### 2.3 Missing Support & Identified Gaps
1. **Unattested Currency Transitions:** If a student transitioned currencies (e.g., from PKR to SAR) but has **no historical collection record** in `qa_staged_collections` (e.g. fees remained unpaid or were settled through an untracked bank transfer), `HistoricalOpeningBalanceService::isVerifiedCurrency()` returns `false`. The valid historical PKR dues are categorized as `currency_mismatch` and held indefinitely from posting.
2. **Single Active Currency on `enrollments` Schema:** The `enrollments` table schema ([`2026_10_16_000001_create_identity_and_enrollments.php:16`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/database/migrations/2026_10_16_000001_create_identity_and_enrollments.php#L16)) stores a single `currency char(3)` column per enrollment record without a historical currency timeline (`started_on`/`ended_on` per currency).
3. **Silent Fallback in Legacy Importer:** `FeeImportService` defaults unrecognized currencies to `SAR`, which threatens historical data integrity if the legacy import routes (`POST /challan/import-outstanding`) are triggered.

---

### 2.4 Double-Counting & Balance Risks
* **Implicit Currency Conversion:** If historical PKR dues (e.g. PKR 15,000) are mistakenly converted or merged into a SAR invoice, the student's balance is drastically distorted.
* **Prepayment Cross-Contamination:** If unallocated credits (`payments.unallocated_minor`) from an older PKR payment are automatically applied to a newer SAR monthly invoice, the ledger loses currency parity.

---

## 3. Scenario B: Multiple Currencies Within One Source Invoice / Slip

### 3.1 Scenario Description
In MySkool source data, a single voucher, collection slip, or student statement may encompass multiple currencies:
* A collection slip (e.g., Slip `#6465`) where different line items or siblings were collected in both **SAR** and **PKR**.
* A student having Tuition Fee charged in **SAR** while Registration or Book Charges were invoiced in **USD** or **PKR**.
* A family invoice covering multi-campus siblings where one child studies in the Main Campus (SAR) and another in a local branch (PKR).

The system must:
* Retain the individual amounts and exact original currencies.
* Preserve traceability to the common source slip/voucher number.
* Never add unlike currencies together.

---

### 3.2 Current System Behavior

| Component | Current Implementation | Behavior in Scenario B |
|---|---|---|
| **Database Schema (`invoices`)** | `invoices` table has `currency char(3)` and unique key `['billing_run_id', 'family_id', 'currency']` ([`2026_10_16_000002_create_billing_ledger.php:10`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/database/migrations/2026_10_16_000002_create_billing_ledger.php#L10)). | **Single-Currency Enforcement:** A single row in `invoices` can strictly hold only one currency. Multi-currency source vouchers cannot be saved as a single invoice entity. |
| **Database Schema (`payments`)** | `payments` table has `currency char(3)` ([line 12](file:///c:/Users/hp/Desktop/iqra-laravel-billing/database/migrations/2026_10_16_000002_create_billing_ledger.php#L12)). | **Single-Currency Enforcement:** A single payment record holds only one currency. |
| **`HistoricalOpeningBalanceService.php`** | In `preview()` and `buildInvoiceGroups()`, the grouping key is: `{$familyId}|{$deptId}|{$campusId}|{$curr}|{$period['period_key']}`. | **Architecturally Correct (Split Invoicing):** It splits multi-currency dues under the same family and period into distinct, currency-segregated invoice records. |
| **`PaidCollectionImportService.php`** | Iterates collection rows, grouping by `slip_number`. Generates distinct payment records: `REC-{$slip}` (e.g. SAR) and `REC-{$slip}-2` (e.g. PKR). | **Partially Supported (Split Receipts):** Creates separate `payments` rows per currency with unique receipt numbers, avoiding currency mixing. |
| **Invoices Dashboard UI** | `/invoices` multi-tab interface. | **Partial Weakness:** Tab views have historically run into array key errors (e.g. fixed in commit `6343620` for Ready to Send tab) when families have multiple active currencies in the same billing cycle. |

---

### 3.3 Missing Support & Identified Gaps
1. **Source Document Provenance Tracking:** When a composite MySkool slip (e.g. Slip `#6465`) containing multiple currencies is split into separate Laravel payment records (`REC-6465` for SAR and `REC-6465-2` for PKR), there is no parent `source_voucher_id` or `source_document_group` linking them together. Searching for a voucher by slip number requires pattern matching rather than a relational foreign key.
2. **Multi-Currency Split Invoices in the Same Billing Run:** In the clean billing schema, `invoices` enforces `unique(['billing_run_id', 'family_id', 'currency'])`. If a billing run encompasses both currencies, this unique constraint permits one invoice per currency per family. However, if a source slip contains two different fee items in the same currency but differing departments, department billing run isolation must be strictly observed.
3. **Cross-Currency Sibling Presentation:** When parents view consolidated family challans, the UI must clearly delineate that payments made in one branch/currency do not offset balances in another.

---

## 4. Residual Unpaid Dues & Archived Payments Reconciliation

### 4.1 The Cutoff & Residual Dues Rule
As audited in [`PHASE_3B_1_CUTOFF_AND_ACCOUNTING_CONTROLS_REPORT.md`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/PHASE_3B_1_CUTOFF_AND_ACCOUNTING_CONTROLS_REPORT.md):
* The rows in `STAGING_dues.csv` (16,706 rows) represent **residual net unpaid balances** exported from MySkool as of mid-October 2026.
* The rows in `STAGING_paid.csv` (37,595 rows) represent **historical cash receipts** collected between March 1, 2025 and October 17, 2026.
* **Double-Deduction Hazard:** The residual dues in MySkool were *already reduced* by the collections received prior to the report export.
* If an import process loads the residual dues as opening balance invoices AND loads the historical collections and auto-allocates them against those opening balance invoices:
  $$\text{Invoice Balance} = \text{Residual Due} - \text{Historical Collection} \implies \text{Double Deduction!}$$
  This reduces legitimate outstanding obligations to zero or creates false negative balances.

### 4.2 Safe Reconciliation Mechanism
1. **Opening Balance Invoices Must Stand as Net Dues:** Opening balance invoices created from `STAGING_dues.csv` (or `Outstanding (Compiled Valid)`) must represent the net uncollected residual amount as of the snapshot cutoff date.
2. **Archived Payments Recorded as Historical Receipts:** Historical payments from `STAGING_paid.csv` must be recorded in `payments` and linked to `payment_allocations` only for historical audit trail and receipt printing purposes. They must **not** reduce the opening balance residual invoices.
3. **Post-Cutoff Payment Attribution:** Only new collections received **after** the established cutoff date may be applied to reduce opening balance invoices.

---

## 5. Mandatory Requirements Compliance Matrix

| Requirement | Current Status | Analysis & Required Next Step |
|---|:---:|---|
| **1. Preserve original CSV values & source references** | **Compliant** | `qa_staged_dues` and `qa_staged_collections` store `source_row`, `raw_row_json`, and `currency_original`. Must be maintained in all future importers. |
| **2. Preserve effective historical currencies** | **Partially Compliant** | Supported in `HistoricalOpeningBalanceService` for collection-attested records. Missing support for unattested historical currency changes. |
| **3. Keep balances and subtotals separated by currency** | **Compliant** | Invoices and payments are strictly segregated by `currency char(3)`. Arithmetic uses `bcadd`/integer minors without cross-currency addition. |
| **4. Flag ambiguous currencies for review** | **Compliant** | Unresolved currencies (e.g. blank or invalid labels) are placed in `unresolved_currency` review rather than guessed. |
| **5. Preserve existing approvals where still valid** | **Compliant** | Currency and campus approvals stored in `staging_currency_review` and `staging_students` are preserved across runs. |
| **6. Support repeated imports without duplicates** | **Compliant** | Handled via SHA-256 batch provenance (`qa_opening_balance_lineage`, `import_batches`, and `idempotency_key`). |
| **7. Do not hard-code student or transaction counts** | **Compliant** | Batch queries use dynamic set operations, counts, and collections rather than fixed integer bounds. |
| **8. Preserve multi-campus & multi-department links** | **Compliant** | Grouping keys explicitly incorporate `campus_id` and `department_id`. |
| **9. Prevent overlapping opening balances & monthly fees** | **Compliant** | `billing_runs.frequency = 'opening_balance'` is strictly isolated from `monthly` billing runs in `InvoiceService`. |
| **10. Keep historical payment evidence traceable** | **Compliant** | Preserved via `receipt_number`, `slip_number`, and `payment_allocations`. |

---

## 6. Recommended Next Implementation Steps

To achieve 100% compatibility for both Scenario A and Scenario B without database rebuilds:

1. **Eliminate Silent Currency Fallbacks:**
   * Modify `FeeImportService::normalizeCurrency()` to remove the hardcoded fallback `return $c ?: 'SAR';`. Blank or unknown currencies must be flagged as `UNRESOLVED` to prevent silent data corruption.
2. **Expand Historical Currency Verification:**
   * Update `HistoricalOpeningBalanceService::isVerifiedCurrency()` to allow historical currency transitions attested by:
     * Student's registration history.
     * Documented country-of-residence changes.
     * Previous dues records in the same currency across prior months.
3. **Formalize Composite Source Document Lineage (Scenario B):**
   * For multi-currency slips (e.g. Slip `#6465`), introduce a structured `source_slip_composite_key` in payment/invoice metadata, allowing staff to view all currency components of a shared receipt voucher together.
4. **Enforce Net Residual Opening Balance Rule:**
   * Ensure that the final import pipeline posts opening balance dues as net unallocated obligations and does not re-apply historical collections against them.
5. **Add Isolated Feature Tests:**
   * Implement automated regression tests in `tests/Feature/CsvMultiCurrencyImportCompatibilityTest.php` to verify both Scenario A (mid-year currency changes) and Scenario B (multi-currency source slips) run cleanly in SQLite `:memory:` without modifying MySQL databases.

---

*Assessment completed. Ready for review and authorization before proceeding to implementation.*
