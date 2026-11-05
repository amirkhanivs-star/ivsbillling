# Phase 2 — Ready to Send Invoice Buttons & Outstanding Totals Standardization Report

**Date**: 2026-10-29  
**Project**: `C:\Users\hp\Desktop\iqra-laravel-billing`  
**Local Configured Database**: `iqra_billing_vps_test` (read-only verified; automated tests executed in isolated SQLite `:memory:`)  
**Phase Scope**: Phase 2 only (Unify Ready to Send invoice buttons and standardize Ready to Send financial totals with the canonical consolidated PDF builder)

---

## 1. Original Ready to Send Calculation

Prior to Phase 2, `InvoiceDeliveryQueueService::getReadyToSendChallans()` in [`app/Services/InvoiceDeliveryQueueService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/InvoiceDeliveryQueueService.php) calculated family financial amounts using a separate, ad-hoc query path that differed from [`ConsolidatedInvoiceBuilder`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/ConsolidatedInvoiceBuilder.php):

1. **Selected-month amount**:
   - In `InvoiceDeliveryQueueService::getReadyToSendChallans()`, `$currFee` summed `i.total_minor - i.paid_minor` at the `invoices` header level only for invoices belonging to the selected month's `billing_runs` (`b.period_start LIKE '{period}%'`).
   - Worse, in [`resources/views/invoices.blade.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/resources/views/invoices.blade.php), the "Current Fee" column rendered `{{ $row->currency }} {{ Money::format($row->total_minor ?? $row->amount_minor ?? 0) }}`, which was the raw billed total of only the **first** invoice (`$primaryInv->total_minor`), ignoring partial payments (`paid_minor`), ignoring sibling invoices in the selected month, and ignoring multi-currency breakdowns.
2. **Previous dues**:
   - Calculated via a separate `duesRows` query filtering `ob.period_start < $earliestPeriod` (or `opening_balance`), omitting campus/department authorization constraints on `old_inv`.
3. **Already-generated later-period unpaid fees**:
   - Completely excluded from `getReadyToSendChallans()`. Any already-generated unpaid invoice items for periods after the selected filter month (e.g., November 2026 when viewing October 2026) were omitted from the Ready to Send total.

---

## 2. Confirmed Cause of Amount Discrepancies

Comparing [`InvoiceDeliveryQueueService::getReadyToSendChallans()`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/InvoiceDeliveryQueueService.php#L829-L1143) against [`ConsolidatedInvoiceBuilder::buildFamilyViewData()`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/ConsolidatedInvoiceBuilder.php#L218-L345) confirmed four root causes of disagreement between the Ready to Send screen and the consolidated PDF:

1. **Omission of already-generated later-period unpaid obligations**:
   - `ConsolidatedInvoiceBuilder` includes all unpaid ledger obligations recorded in `invoice_items` (`ii.net_minor > ii.paid_minor`), including already-generated later-month charges (e.g., November when viewing October). `getReadyToSendChallans()` filtered `ob.period_start < $earliestPeriod` and `b.period_start LIKE '{period}%'`, dropping all `period_start > {period}` obligations.
2. **Blade template rendering raw primary invoice `total_minor` instead of remaining balance**:
   - `resources/views/invoices.blade.php` displayed `$row->total_minor` (the gross billed amount of a single invoice) in the "Current Fee" column rather than the family's remaining unpaid balance (`net_minor - paid_minor`) for the selected month.
3. **Invoice-header vs. canonical `invoice_items` aggregation**:
   - `getReadyToSendChallans()` summed `i.total_minor - i.paid_minor` at the invoice header level for the current month, whereas `ConsolidatedInvoiceBuilder` aggregates `MAX(0, ii.net_minor - ii.paid_minor)` across non-void, non-exempted, unpaid `invoice_items`.
4. **Missing staff authorization scope on historical dues in Ready to Send**:
   - While `ConsolidatedInvoiceBuilder` restricted unpaid items by `$user->campus_id` and `$user->department_id` for non-admin staff, `getReadyToSendChallans()` did not apply campus/department constraints to its `duesRows` subquery.

---

## 3. Files Modified

| File | Summary of Changes |
| :--- | :--- |
| [`app/Services/ConsolidatedInvoiceBuilder.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/ConsolidatedInvoiceBuilder.php) | Extracted canonical `baseUnpaidItemsQuery(?object $user = null)` and added batched `summarizeFamiliesOutstanding(array $familyIds, string $selectedPeriod, ?object $user = null)` to partition all unpaid family obligations per currency into previous, selected-month, later-period, and total outstanding payable using the exact same ledger rules as the PDF. |
| [`app/Services/InvoiceDeliveryQueueService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/InvoiceDeliveryQueueService.php) | Refactored `getReadyToSendChallans()` to delegate family financial calculation to `ConsolidatedInvoiceBuilder::summarizeFamiliesOutstanding()`, expose `previous_outstanding_minor`, `selected_month_minor`, `later_period_minor`, `total_outstanding_payable_minor`, `later_dues_by_month`, and pass `$period` to `getFamilyChallanPdfUrl()`. |
| [`app/Services/PdfAccessService.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/PdfAccessService.php) | Updated `getFamilyChallanPdfUrl(int $familyId, ?string $period = null)` to optionally include `?period={period}` when linking from a period-filtered screen while preserving permanent token URLs without query strings when `$period` is `null`. |
| [`app/Http/Controllers/BillingController.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Http/Controllers/BillingController.php) | Updated `familyConsolidatedChallanPdf()` staff scope check so restricted staff can open the family consolidated PDF when the family has either an enrollment or an invoice in their authorized campus/department scope. |
| [`resources/views/invoices.blade.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/resources/views/invoices.blade.php) | Updated the Ready to Send table (`table.iq-invoice-review`) to display `Previous Outstanding`, `Selected-Month Unpaid`, `Later-Period Unpaid`, and `TOTAL OUTSTANDING PAYABLE`, and replaced the two competing PDF buttons (`Preview Challan` and `Invoice PDF`) with one primary action: `Preview / Print Fee Invoice`. |
| [`tests/Feature/Phase2ReadyToSendStandardizationTest.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/tests/Feature/Phase2ReadyToSendStandardizationTest.php) | Added automated tests covering Mandatory Scenarios A through I (9 test methods, 117 assertions). |

---

## 4. Updated Consolidated Outstanding Calculation

[`ConsolidatedInvoiceBuilder::baseUnpaidItemsQuery(?object $user = null)`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/ConsolidatedInvoiceBuilder.php#L20-L47) is now the single source of truth for both PDF generation (`buildFamilyViewData`, `buildStudentViewData`) and Ready to Send table aggregation (`summarizeFamiliesOutstanding`):

- **Inclusion criteria**:
  - `ii.net_minor > ii.paid_minor` (remaining balance `MAX(0, ii.net_minor - ii.paid_minor) > 0`).
  - Includes earlier unpaid fee periods (`itemMonth < selectedPeriod`), valid historical opening-balance items (`frequency = 'opening_balance'` or `-OB-` invoice numbers), selected-month unpaid fees (`itemMonth === selectedPeriod`), partially paid remaining balances, and already-generated later-period unpaid fees (`itemMonth > selectedPeriod`).
- **Exclusion criteria**:
  - `ii.status NOT IN ('void', 'voided', 'exempted', 'paid')` (or `ii.status IS NULL`).
  - `i.status NOT IN ('void', 'voided', 'paid')`.
  - Unbilled future charges (only posted/issued `invoice_items` in the database are queried; zero charges are created during calculation).
- **Period partitioning per currency**:
  - **Previous Outstanding** (`previous_dues_minor` / `previous_outstanding_minor`): Sum of remaining balances where `is_opening_balance` is true or `itemMonth < selectedPeriod`, with month-wise pills in `dues_by_month`.
  - **Selected-Month Unpaid** (`selected_month_minor` / `current_fee_minor`): Sum of remaining balances where `itemMonth === selectedPeriod` and not an opening balance.
  - **Later-Period Unpaid** (`later_period_minor`): Sum of remaining balances where `itemMonth > selectedPeriod` and not an opening balance, with month-wise breakdown in `later_dues_by_month`. Each later-period entry is labeled **`Generated Later Period`** unless its stored `due_date` has actually passed (`due_date < today`), in which case it is labeled `Later Period Overdue`.
  - **TOTAL OUTSTANDING PAYABLE** (`total_payable_minor` / `total_outstanding_payable_minor`):
    $$\text{Total Outstanding Payable} = \text{Previous Outstanding} + \text{Selected-Month Unpaid} + \text{Later-Period Unpaid}$$
- **Batched efficiency**:
  - `summarizeFamiliesOutstanding()` executes a single batched SQL query (`WHERE i.family_id IN (...)`) for all families on the current Ready to Send page, avoiding N+1 queries or per-row PDF rendering.

---

## 5. Updated Ready to Send Button

In [`resources/views/invoices.blade.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/resources/views/invoices.blade.php#L583-L595), the two competing buttons (`Preview Challan` and `Invoice PDF`) in the Ready to Send table were replaced with a single primary button:

- **Button Label**: `📄 Preview / Print Fee Invoice`
- **Target URL**: `$row->consolidated_challan_url` (`/public/challans/{family}/{token}/Monthly-Fee-Invoice.pdf?period={period}`)
- **Link Attributes**: `target="_blank" rel="noopener"` (opens in a new browser tab using the existing permanent HMAC token mechanism).
- **Preserved Routes**: Individual invoice PDF routes (`/invoices/{invoice}/pdf`, `/public/invoices/{invoice}/{token}/...`), Paid Invoices receipt actions (`Paid Receipt PDF`), and notification dispatch payloads remain untouched.

---

## 6. Multi-Currency Handling

- Both [`ConsolidatedInvoiceBuilder::summarizeFamiliesOutstanding()`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/ConsolidatedInvoiceBuilder.php#L109-L189) and [`ConsolidatedInvoiceBuilder::buildFamilyViewData()`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/app/Services/ConsolidatedInvoiceBuilder.php#L275-L308) group obligations strictly by `ii.currency` (`SAR`, `AED`, `PKR`, etc.).
- Currencies are never converted or summed into a single combined number.
- When a family owes multiple currencies (e.g., `SAR 850.00` and `AED 400.00`), the Ready to Send row displays separate per-currency lines in `Previous Outstanding`, `Selected-Month Unpaid`, `Later-Period Unpaid`, and `TOTAL OUTSTANDING PAYABLE` (with a `Separate currencies` indicator), matching the separate single-currency ledger pages in the consolidated PDF.

---

## 7. Authorization Safeguards

- **Role-based Campus & Department Scoping**:
  - In `ConsolidatedInvoiceBuilder::baseUnpaidItemsQuery($user)`, when `$user` is not `super_admin` or `finance_admin`, queries are strictly constrained to `i.campus_id = $user->campus_id` and (when assigned) `i.department_id = $user->department_id`.
  - In `InvoiceDeliveryQueueService::getReadyToSendChallans()`, the base invoice query, `$siblingsByFamily` query, and `summarizeFamiliesOutstanding($pagedFamilyIds, $period, $user)` all enforce the same staff scope.
- **Public Token Isolation**:
  - Public challan links (`/public/challans/{family}/{token}/Monthly-Fee-Invoice.pdf`) validate the HMAC-SHA256 token bound to the specific `family_id` via `PdfAccessService::verifyFamilyToken()` and never expose records from any other family.

---

## 8. Test Cases and Exact Results

All 9 mandatory Phase 2 test scenarios (Tests A–I) were implemented in [`tests/Feature/Phase2ReadyToSendStandardizationTest.php`](file:///c:/Users/hp/Desktop/iqra-laravel-billing/tests/Feature/Phase2ReadyToSendStandardizationTest.php) and executed alongside the existing regression suites:

| Test ID | Scenario | Method in `Phase2ReadyToSendStandardizationTest` | Result |
| :--- | :--- | :--- | :--- |
| **Test A** | Previous (`Aug SAR 300` + `Sep SAR 200`) + Current (`Oct SAR 350`) = `SAR 850.00` in Ready to Send & PDF | `test_a_previous_plus_current_totals_reconcile_between_ready_to_send_and_pdf` | **PASS** |
| **Test B** | Already-generated later month (`Nov SAR 350`) included (`SAR 1,200.00`) and labeled `Generated Later Period` (not overdue) | `test_b_already_generated_later_month_included_and_not_labeled_overdue` | **PASS** |
| **Test C** | Partial payments (`Aug` net `SAR 300`, paid `SAR 100` -> `SAR 200` remaining) in both Ready to Send & PDF | `test_c_partial_payments_use_remaining_balance_in_both_ready_to_send_and_pdf` | **PASS** |
| **Test D** | Multi-student family includes all sibling balances and student rows | `test_d_multi_student_family_includes_all_sibling_balances` | **PASS** |
| **Test E** | Multi-currency (`SAR 850.00` and `AED 400.00`) displayed separately and never summed | `test_e_multi_currency_balances_displayed_separately_and_never_summed` | **PASS** |
| **Test F** | Restricted staff (`campus_cashier`) cannot include unauthorized campus or department obligations | `test_f_restricted_staff_cannot_include_unauthorized_campus_or_department_records` | **PASS** |
| **Test G** | Unified `Preview / Print Fee Invoice` button replaces dual buttons and opens canonical consolidated PDF | `test_g_ready_to_send_has_single_unified_preview_print_fee_invoice_button` | **PASS** |
| **Test H** | Zero financial writes (`invoices`, `invoice_items`, `payments`, `payment_allocations` unchanged) | `test_h_calculating_ready_to_send_and_opening_pdf_performs_zero_financial_writes` | **PASS** |
| **Test I** | Existing workflows (pagination, month filter, search, contact status filter, batch selection, paid receipt links) | `test_i_existing_ready_to_send_and_paid_receipt_workflows_remain_intact` | **PASS** |

### Exact Command Output

```text
PASS  Tests\Feature\ConsolidatedChallanTest (13 passed)
PASS  Tests\Feature\Phase2ReadyToSendStandardizationTest (9 passed)
PASS  Tests\Feature\BillingLedgerTest (10 passed)
PASS  Tests\Feature\PermanentPublicPdfLinksTest (4 passed)
PASS  Tests\Unit\BillingPeriodTest (5 passed)

Tests:    41 passed (376 assertions)
Duration: 35.30s
```

Standalone regression script (`php tests/standalone.php`):
```text
All standalone regression checks passed.
All standalone period checks passed.
```

---

## 9. Screenshots of the Updated Ready to Send Table

Visual verification was performed using fictional records in an isolated in-memory database:
- **`FAM-P2-101`** (`Bilal Al-Harbi`): Previous `SAR 500.00` (`Aug-26: 300.00`, `Sep-26: 200.00`) + Selected-Month `SAR 350.00` (`Oct 2026 unpaid`) + Later-Period `SAR 350.00` (`Nov-26: 350.00 • Generated Later Period`) = **`SAR 1,200.00`**.
- **`FAM-P2-102`** (`Fatima Siddiqui`): Partial payment on August (`SAR 300.00` billed, `SAR 100.00` paid -> `Aug-26: 200.00` remaining) + `Sep-26: 200.00` (`SAR 400.00` previous) + Selected-Month `SAR 350.00` = **`SAR 750.00`**.
- **`FAM-P2-103`** (`Omar Al-Mansoor`): Multi-currency family with **`AED 400.00`** (`AED 150.00` previous + `AED 250.00` Oct) and **`SAR 850.00`** (`SAR 500.00` previous + `SAR 350.00` Oct) displayed separately.

Saved screenshot artifact:
- `C:\Users\hp\.gemini\antigravity\brain\709a810b-2435-4c58-8bbb-48301d34d498\ready_to_send_table_phase2.png`

---

## 10. Sanitized Matching PDF Examples

Each row's **`Preview / Print Fee Invoice`** action opens the canonical consolidated family fee invoice (`resources/views/pdf/consolidated-challan.blade.php`), which reconciles 1:1 with the Ready to Send table:

1. **Family `FAM-P2-101` (`SAR 1,200.00` with Generated Later Period)**:
   - Itemized rows: `Aug 2026` (`300.00`), `Sep 2026` (`200.00`), `Oct 2026` (`350.00`), `Nov 2026` (`350.00`, labeled `Generated Later Period (Nov-2026)`).
   - Final PDF Total: **`TOTAL OUTSTANDING PAYABLE (SAR): SAR 1,200.00`**.
   - Saved image: `C:\Users\hp\.gemini\antigravity\brain\709a810b-2435-4c58-8bbb-48301d34d498\pdf_fam1_sar_1200_later_period.png`
2. **Family `FAM-P2-102` (`SAR 750.00` with Partial Payment)**:
   - Itemized rows: `Aug 2026` (`Billed: 300.00`, `Paid: 100.00 • Remaining: 200.00`, `Balance: 200.00`), `Sep 2026` (`200.00`), `Oct 2026` (`350.00`).
   - Final PDF Total: **`TOTAL OUTSTANDING PAYABLE (SAR): SAR 750.00`**.
   - Saved image: `C:\Users\hp\.gemini\antigravity\brain\709a810b-2435-4c58-8bbb-48301d34d498\pdf_fam2_sar_750_partial.png`
3. **Family `FAM-P2-103` (Multi-Currency `AED 400.00` & `SAR 850.00`)**:
   - Page 1 (`SINGLE-CURRENCY LEDGER: AED`): `Aug 2026` (`150.00`) + `Oct 2026` (`250.00`) = **`TOTAL OUTSTANDING PAYABLE (AED): AED 400.00`**.
   - Page 2 (`SINGLE-CURRENCY LEDGER: SAR`): `Sep 2026` (`500.00`) + `Oct 2026` (`350.00`) = **`TOTAL OUTSTANDING PAYABLE (SAR): SAR 850.00`**, with separate currency summary table (`AED 400.00`, `SAR 850.00` — not converted or summed).
   - Saved image: `C:\Users\hp\.gemini\antigravity\brain\709a810b-2435-4c58-8bbb-48301d34d498\pdf_fam3_multi_currency.png`

---

## 11. Remaining Issues

- **None within Phase 2 scope.**
- As instructed, outbound notification payload redesign (Phase 3) and historical financial database reconciliation were not started in this phase.

---

## 12. Git Status and Commit Reference

- **Branch**: `main` (uncommitted working tree changes preserved as instructed; no production deployment performed).
- **Phase 2 Modified/Created Files**:
  - `M app/Services/ConsolidatedInvoiceBuilder.php`
  - `M app/Services/InvoiceDeliveryQueueService.php`
  - `M app/Services/PdfAccessService.php`
  - `M app/Http/Controllers/BillingController.php`
  - `M resources/views/invoices.blade.php`
  - `?? tests/Feature/Phase2ReadyToSendStandardizationTest.php`
  - `?? PHASE_2_INVOICE_UI_TOTALS_STANDARDIZATION_REPORT.md`
