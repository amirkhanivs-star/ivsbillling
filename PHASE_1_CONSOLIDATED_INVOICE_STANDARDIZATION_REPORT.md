# Phase 1: Consolidated Fee Invoice Standardization Report

## 1. Executive Summary

Phase 1 standardizes the approved **Consolidated Fee Invoice** (`resources/views/pdf/consolidated-challan.blade.php`) as the single canonical fee-invoice PDF template across the IQRA Laravel Billing application.

A shared, strictly read-only document builder service (`app/Services/ConsolidatedInvoiceBuilder.php`) now powers every parent-facing and internal fee-invoice PDF route (`BillingController::invoicePdf`, `BillingController::familyConsolidatedChallanPdf`, `BillingController::studentConsolidatedChallanPdf`, and `resources/views/pdf/invoice.blade.php`). Every parent-facing fee invoice displays the consistent heading **`MONTHLY FEE INVOICE`** and includes **all applicable unpaid fee obligations** recorded in the authoritative billing ledger—including prior unpaid months, valid opening balances, current monthly fees, remaining balances on partially paid fees, other unpaid fee types, and any already-generated later-period unpaid obligations identified by their actual billing month—without restricting the consolidated statement to only a single selected month.

---

## 2. Approved Template Preserved

- **Canonical Template**: `resources/views/pdf/consolidated-challan.blade.php` (with `resources/views/pdf/invoice.blade.php` delegating directly to it).
- **Document Heading**: Standardized consistently to **`MONTHLY FEE INVOICE`** across all currency pages and empty/settled states.
- **Preserved Visual Elements**:
  - Official IQRA Virtual School (IVS) banner and affiliation header.
  - Approved burgundy/red (`#982931` / `#962a31` / `#b53139`) color scheme.
  - Student and guardian metadata block (Family Code, Guardian/Father Name via `GuardianNameResolver`, Student Name, GR Number, Class/Program, Statement Ref, Issue Date, Due Date, Referenced Invoice when opened via an individual invoice link, Prior/Overdue Due Dates, and Later Period Due Dates).
  - Structured month-wise fee particulars table (`#`, `Month`, `Student`, `Particular / Description`, `Invoice Ref`, `Saved Due Date`, `Billed`, `Balance`).
  - Per-student subtotals (in family mode) and currency-specific `TOTAL OUTSTANDING PAYABLE ({CURRENCY})` banner.
  - Multi-currency non-conversion summary table when a family or student holds unpaid obligations in more than one currency.
  - Approved banking details (`IQRA VIRTUALSOLUTIONS` Meezan Bank Peshawar & `IVSGLOBAL` Mashreq Bank UAE), QR code (`public/assets/receipt-qr.jpg`), payment instructions, Accounts Manager signature (`public/assets/receipt-sign.jpg`), and official footer contact details (`+92 325 524551`, `acivs2021@gmail.com`).
- **A4 Pagination & Readability Improvements**:
  - Tuned page margins, banner max-height, table cell padding, and settlement block spacing so standard 1–3 student consolidated invoices fit cleanly on a single A4 portrait page per currency while preserving `page-break-inside: avoid` for longer statements.

---

## 3. Financial Rule Implemented for Outstanding Balances

All consolidated fee-invoice PDFs query the authoritative `invoices`, `invoice_items`, and `billing_runs` tables in `ConsolidatedInvoiceBuilder` using one canonical rule:

- **Included Obligations**:
  1. Previous months' unpaid fees (`ii.net_minor > ii.paid_minor`).
  2. Historical opening balances (`billing_runs.frequency = 'opening_balance'` or `-OB-` invoice numbers) represented as valid outstanding ledger obligations.
  3. Current monthly fees.
  4. Remaining unpaid balances on partially paid fee items (`max(0, ii.net_minor - ii.paid_minor)`), while explicitly displaying `Original`, `Discount`, `Paid`, and `Remaining` breakdown details in the fee particulars column.
  5. Other legitimate unpaid fee types (admission, registration, custom/annual fees).
  6. Already-generated later-period unpaid obligations (e.g., November fees already generated when an October invoice is opened), identified by their actual billing month (`Nov-2026`) and saved due date.
- **Excluded Obligations**:
  - Fully settled items (`ii.net_minor <= ii.paid_minor` or `ii.status = 'paid'` or `i.status = 'paid'`).
  - Voided or exempted obligations (`ii.status IN ('void', 'voided', 'exempted')` or `i.status IN ('void', 'voided')`).
- **No Single-Month Restriction**:
  - Selecting a billing month in Ready to Send or opening a specific month's invoice link never restricts the payable total to that month alone. For example, if August (`SAR 300.00`), September (`SAR 200.00`), and October (`SAR 350.00`) are unpaid, the PDF displays all three months and **`TOTAL OUTSTANDING PAYABLE (SAR): SAR 850.00`**.

---

## 4. Family vs Student Scope Behavior

- **Family Scope (`mode = 'family'`)**:
  - Displays all applicable unpaid fee obligations for every student belonging to the family.
  - Groups obligations by currency, then by student (`Student Name • GR {gr_number} • {grade_label}`), then chronologically by billing period (`period_start`).
  - Displays per-student subtotals (`Subtotal for {student_name} ({currency})`) and the family total payable for each currency.
- **Student Scope (`mode = 'student'`)**:
  - Uses the exact same approved `resources/views/pdf/consolidated-challan.blade.php` template.
  - Queries only `ii.student_id = $studentId`, strictly excluding siblings' fee obligations.
- **Individual Invoice Route Scope (`BillingController::invoicePdf`)**:
  - Uses `ConsolidatedInvoiceBuilder::buildForInvoice($invoice, ...)` and renders `resources/views/pdf/consolidated-challan.blade.php`.
  - Includes the complete applicable family outstanding balance so parent-facing `Invoice PDF` links never present an incomplete single-month balance as the total payable.
  - Preserves the original accounting invoice reference via a dedicated `Referenced Invoice` metadata line (`{invoice_number} ({period_label}) • Specific Invoice Billed: ... • Specific Invoice Remaining: ...`) and per-row `Invoice Ref` column so an individual accounting invoice is never silently relabeled as the entire family balance.

---

## 5. Due-Date Handling Across Multiple Months

- All completed due-date features in `/fee-creator`, `App\Services\BillingPeriod`, and `App\Services\InvoiceService` (Issue Date selection, default due day `5`, minimum payment window `10` days, calculated due-date preview, and saved invoice due dates) are preserved untouched.
- `ConsolidatedInvoiceBuilder::resolveAuthoritativeChallanDates()` preserves every obligation's saved `due_date` and `issued_on` from the ledger without modification:
  - **Current Billing Obligation**: Displays the current statement `Issue Date` and `Due Date` in the header and labels matching current-period rows as `Current Monthly Due`.
  - **Earlier Unpaid Obligations (`period_start < current_period_start` or opening balance)**: Listed separately under `Prior / Overdue Due Dates` in the header and labeled `Historical Overdue` (for opening balances) or `Prior Overdue` (for prior monthly periods) alongside each obligation's individual saved due date.
  - **Already-Generated Later-Period Obligations (`period_start > current_period_start`)**: Listed separately under `Later Period Due Dates` in the header and labeled `Generated Later Period ({M-Y})` in the table. They are **never** labeled overdue unless their saved `due_date` is actually in the past relative to the current date.

---

## 6. Multi-Currency Handling

- Unpaid obligations are grouped strictly by `ii.currency` (`SAR`, `AED`, `PKR`, etc.).
- Different currencies are **never** added together or converted.
- Each currency renders on its own dedicated page/section with its own `OUTSTANDING FEE PARTICULARS ({CURRENCY})` table and `TOTAL OUTSTANDING PAYABLE ({CURRENCY})` banner, plus a `Separate Currency / Outstanding (Not Converted)` summary table when multiple currencies are present.

---

## 7. PDF Entry Points Standardized

All 6 PDF entry points were inspected, standardized onto `resources/views/pdf/consolidated-challan.blade.php` via `ConsolidatedInvoiceBuilder`, and verified:

| # | Entry Point | UI View / Route | Controller Method | Scope & Template |
|---|---|---|---|---|
| 1 | **Monthly Invoices, Preview Challan** | `resources/views/invoices.blade.php` (`/public/challans/{family}/{token}/Monthly-Fee-Invoice.pdf`) | `BillingController::familyConsolidatedChallanPdf` | Family consolidated (`pdf.consolidated-challan`) |
| 2 | **Monthly Invoices, Invoice PDF** | `resources/views/invoices.blade.php` & `invoices-queue.blade.php` (`/public/invoices/{invoice}/{token}/Monthly-Fee-Invoice.pdf`) | `BillingController::invoicePdf` | Family consolidated + Referenced Invoice metadata (`pdf.consolidated-challan`) |
| 3 | **Fee Collection, Print** | `resources/views/collection.blade.php` (`/families/{family}/challan/pdf`) | `BillingController::familyConsolidatedChallanPdf` | Family consolidated (`pdf.consolidated-challan`) |
| 4 | **Student Profile, Print Challan** | `resources/views/student-details.blade.php` (`/students/{student}/challan/pdf`) | `BillingController::studentConsolidatedChallanPdf` | Single-student consolidated (`pdf.consolidated-challan`) |
| 5 | **Internal Individual Invoice PDF** | `resources/views/invoices.blade.php` Financial History (`/invoices/{invoice}/pdf`) | `BillingController::invoicePdf` | Family consolidated + Referenced Invoice metadata (`pdf.consolidated-challan`) |
| 6 | **Public & Signed Invoice/Challan Links** | `routes/web.php` (`invoices.clean_invoice_pdf`, `invoices.signed_pdf`, `invoices.signed_pdf_named`, `families.clean_challan_pdf`, `families.signed_challan_pdf`, `families.signed_challan_pdf_named`) | `BillingController::invoicePdf` & `familyConsolidatedChallanPdf` | Canonical consolidated (`pdf.consolidated-challan`) |

---

## 8. Files Changed

- `app/Services/ConsolidatedInvoiceBuilder.php` — Created canonical read-only view-model and due-date classification builder for family, student, and individual-invoice consolidated PDFs.
- `app/Http/Controllers/BillingController.php` — Refactored `invoicePdf`, `renderInvoice`, `studentConsolidatedChallanPdf`, `familyConsolidatedChallanPdf`, and `resolveAuthoritativeChallanDates` to delegate to `ConsolidatedInvoiceBuilder` and render `pdf.consolidated-challan`.
- `resources/views/pdf/consolidated-challan.blade.php` — Standardized heading to `MONTHLY FEE INVOICE`, added `Referenced Invoice` metadata display, added `Later Period Due Dates` / `Generated Later Period` classification, preserved Original/Discount/Paid/Remaining partial-payment breakdown, and tuned A4 pagination spacing.
- `resources/views/pdf/invoice.blade.php` — Refactored into a thin canonical wrapper delegating to `ConsolidatedInvoiceBuilder` and `@include('pdf.consolidated-challan')`.
- `tests/Feature/ConsolidatedChallanTest.php` — Added automated feature tests for Mandatory Scenarios A–H and updated empty-state heading assertion to `MONTHLY FEE INVOICE`.

---

## 9. Automated Test Results

- **Standalone Regression & Due-Date Policy Suite (`php tests/standalone.php`)**:
  - 15/15 assertions passed (`All standalone regression checks passed`, `All standalone period checks passed`).
- **PHPUnit Suites**:
  - `Tests\Unit\BillingPeriodTest`: 5 passed.
  - `Tests\Feature\BillingLedgerTest`: 10 passed (including `test_fee_creator_due_date_policy_and_unified_invoice_pdf_display`).
  - `Tests\Feature\ConsolidatedChallanTest`: 13 passed (including Mandatory Tests A, B, C, D, E, F, G, H).
  - `Tests\Feature\PermanentPublicPdfLinksTest`: 4 passed.
  - `Tests\Feature\MySkoolPaymentCorrectionTest`: 12 passed.
  - `Tests\Feature\WebhookNotificationTest`: Passed.

---

## 10. Sanitized Sample PDF Verification

Four sanitized fictional PDF samples were rendered and visually inspected across A4 portrait pages:
1. **Single student with prior and current dues** (`sample_1_single_student_prior_and_current.pdf`): Shows Aug-2026 (`SAR 300.00`, `Prior Overdue`), Sep-2026 (`SAR 200.00`, `Prior Overdue`), and Oct-2026 (`SAR 350.00`, `Current Monthly Due`), totaling **`SAR 850.00`** on a single A4 page.
2. **Family with multiple students** (`sample_2_family_multiple_students.pdf`): Shows 3 siblings (`Omar Farhan`, `Sara Farhan`, `Ali Farhan`) grouped by student with individual GR numbers, grades, student subtotals (`250.00`, `300.00`, `400.00`), and family total **`SAR 950.00`** on a single A4 page.
3. **Family with partial payments** (`sample_3_family_partial_payments.pdf`): Shows `Original: 350.00 • Discount: 50.00` and `Paid: 100.00 • Remaining: 200.00` for August alongside October (`350.00`), totaling **`SAR 550.00`**, plus `Referenced Invoice: SCH-2026-10-3002` metadata on a single A4 page.
4. **Family with multiple currencies** (`sample_4_family_multiple_currencies.pdf`): Renders `SAR 850.00` on Page 1 and `AED 400.00` on Page 2 with the `Separate Currency / Outstanding (Not Converted)` summary table, without combining or converting currencies.

---

## 11. Financial Non-Mutation Verification

- Verified via automated before/after table snapshots in `ConsolidatedChallanTest::test_f_g_h_all_pdf_routes_use_canonical_design_preserve_due_dates_and_do_not_mutate_db` that hitting all 6 PDF entry points performs **zero** writes or mutations to `invoices`, `invoice_items`, `payments`, or `payment_allocations`.
- Verified that `.env` (`DB_DATABASE=iqra_billing_vps_test`) and persistent MySQL financial records were untouched.

---

## 12. Git Commit Hash

- Base HEAD commit: `1f90a35e7d53967c92e3389e6cfe2df2966f4df9` (working tree contains Phase 1 Consolidated Fee Invoice standardization changes).

---

## 13. Confirmation of Safety Restrictions

- **No Hostinger deployment occurred.**
- **No database reset, migration reset, historical import, payment reconciliation, or financial record modification occurred.**

---

## 14. Recommended Next Phase (Phase 2)

With all fee-invoice PDF routes now standardized onto the approved `MONTHLY FEE INVOICE` consolidated template and verified to produce identical financial totals for the same scope:
1. **Simplify Monthly Invoices UI Buttons**: Replace redundant dual buttons (`Preview Challan` + `Invoice PDF`) in Ready to Send / Monthly Invoices with one clear canonical `Monthly Fee Invoice PDF` action where appropriate.
2. **Unify Notification Link Payloads**: Ensure WhatsApp/email notification payload builders consistently reference the canonical consolidated invoice link and consolidated payable totals.
