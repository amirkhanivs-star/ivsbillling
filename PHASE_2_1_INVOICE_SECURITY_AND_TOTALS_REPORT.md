# Phase 2.1 — Secure Consolidated Invoice Access and Eliminate Remaining Total Mismatches Report

**Date**: 2026-10-29  
**Project**: `C:\Users\hp\Desktop\iqra-laravel-billing`  
**Phase**: Phase 2.1 (Focused Security & Financial Total Reconciliation Correction)  
**Approved Fee Invoice Template**: `resources/views/pdf/consolidated-challan.blade.php`  
**Canonical Financial Builder**: `app/Services/ConsolidatedInvoiceBuilder.php`

---

## 1. Original Security and Fallback Behavior

### 1.1 Preview Authorization & Scope Mismatch (Task 1)
- In Phase 2, `ConsolidatedInvoiceBuilder::baseUnpaidItemsQuery(?object $user = null)` and `InvoiceDeliveryQueueService::getReadyToSendChallans()` filtered invoice items for restricted staff (`$user->role` not in `['super_admin', 'finance_admin']`) by their assigned `campus_id` and `department_id`.
- However, `InvoiceDeliveryQueueService::getReadyToSendChallans()` (as well as `getPaginatedQueue()` and `getPaidInvoices()`) called `PdfAccessService::getFamilyChallanPdfUrl()` and `PdfAccessService::getInvoicePdfUrl()` without user context, returning unrestricted permanent public HMAC token URLs (`/public/challans/{family}/{token}/Monthly-Fee-Invoice.pdf` and `/public/invoices/{invoice}/{token}/Monthly-Fee-Invoice.pdf`) in `$row->consolidated_challan_url`, `$row->signed_challan_url`, and `$row->invoice_pdf_url`.
- If a restricted staff member copied and opened that public token link in an anonymous/logged-out browser session (`$r->user() === null`), `BillingController::familyConsolidatedChallanPdf()` invoked `ConsolidatedInvoiceBuilder::buildFamilyViewData()` with `$user = null`, retrieving all unpaid obligations across all campuses and departments for the family instead of only the staff member's authorized scope.

### 1.2 Inconsistent Invoice-Header Total Fallback (Task 2)
- In `InvoiceDeliveryQueueService::getReadyToSendChallans()`, lines 1044–1069 previously contained a fallback (`if (empty($currenciesMap))`) that calculated `selected_month_minor` and `total_payable_minor` directly from the `invoices` header (`total_minor - paid_minor`) whenever `ConsolidatedInvoiceBuilder::summarizeFamiliesOutstanding()` returned no valid unpaid `invoice_items`.
- Because `ConsolidatedInvoiceBuilder::buildFamilyViewData()` only renders valid unpaid `invoice_items` from `baseUnpaidItemsQuery()`, any inconsistent invoice header lacking valid unpaid `invoice_items` caused Ready to Send to present an unverified header balance as a verified payable total while the Consolidated Fee Invoice PDF showed zero unpaid items.

---

## 2. Exact Files Changed

1. **`app/Services/PdfAccessService.php`**
   - Added `isRestrictedStaff(?object $user): bool`.
   - Updated `getFamilyChallanPdfUrl(int $familyId, ?string $period = null, ?object $user = null): string` and `getInvoicePdfUrl(int $invoiceId, ?object $user = null): string` so that restricted staff receive the authenticated, scope-aware preview routes (`/families/{family}/challan/pdf?period=...` and `/invoices/{invoice}/pdf`), while global administrators (`super_admin`, `finance_admin`) and parent-facing outbound notification payloads (`$user = null`) continue to receive the permanent public HMAC token URLs.
2. **`app/Services/ConsolidatedInvoiceBuilder.php`**
   - Hardened `baseUnpaidItemsQuery(?object $user = null)` so restricted staff with a missing `campus_id` match nothing (`(int) ($user->campus_id ?? 0)`).
   - Scoped `$familyStudents`, `$targetInvoiceId` period lookup, and `$primaryDeptId` fallback in `buildFamilyViewData()` and `buildStudentViewData()` for restricted staff.
   - Added read-only `detectFamilyHeaderDiscrepancies(array $familyIds, Collection $validUnpaidItems, ?object $user = null): array` to detect unpaid `invoices` headers within the authorized scope that have no matching valid unpaid `invoice_items` (`missing_valid_unpaid_items`) or whose valid unpaid item balances do not reconcile with the header (`header_item_balance_mismatch`).
   - Surfaced `has_data_integrity_issue`, `is_payable_verified`, `data_integrity_status`, `data_integrity_issues`, and `data_integrity_message` in both `summarizeFamiliesOutstanding()` and `buildFamilyViewData()`.
   - Updated `buildReferenceInvoiceMeta()` so an inconsistent unpaid invoice header without valid unpaid items is marked `UNVERIFIED — DATA INTEGRITY REVIEW REQUIRED` with `total_minor = 0` and `remaining_minor = 0` (preserving `header_total_minor` and `header_remaining_minor` for audit reference).
3. **`app/Http/Controllers/BillingController.php`**
   - In `familyConsolidatedChallanPdf()` and `studentConsolidatedChallanPdf()`, enforced `abort_unless(!empty($r->user()->campus_id), 403)` for restricted staff and verified that any explicit `?invoice_id=` parameter passed by a restricted staff user belongs to their authorized campus and department via `assertAccess()`.
4. **`app/Services/InvoiceDeliveryQueueService.php`**
   - Passed `$user` into `PdfAccessService::getFamilyChallanPdfUrl()` and `PdfAccessService::getInvoicePdfUrl()` across `getReadyToSendChallans()`, `getPaginatedQueue()`, and `getPaidInvoices()`.
   - Removed the invoice-header total fallback (`if (empty($currenciesMap))`) from `getReadyToSendChallans()`.
   - Populated `has_data_integrity_issue`, `is_payable_verified`, `data_integrity_status`, `data_integrity_error`, `data_integrity_message`, and `data_integrity_issues` on each Ready to Send row from `ConsolidatedInvoiceBuilder`, keeping all payable minor amounts at `0` when no valid unpaid `invoice_items` exist and setting formatted labels to `UNVERIFIED — Staff Review Required`.
5. **`resources/views/invoices.blade.php`**
   - Updated the Ready to Send table to highlight rows where `has_data_integrity_issue` is true, disable their batch-dispatch checkbox, render a `⚠ Data Integrity Issue — Staff Review Required` badge and discrepancy description, and prevent unverified header figures from being displayed as verified payable totals.
6. **`tests/Feature/Phase2ReadyToSendStandardizationTest.php`**
   - Added mandatory Phase 2.1 security regression test (`test_j_phase_2_1_restricted_staff_vs_full_family_pdf_authorization_and_scope`) and mandatory financial fallback regression test (`test_k_phase_2_1_inconsistent_invoice_header_without_valid_unpaid_items_is_flagged_and_never_substituted`).

---

## 3. Authorization Rules After Correction

| Actor / Context | Ready to Send Scope | Generated Preview Link (`consolidated_challan_url`) | Consolidated PDF Scope |
| :--- | :--- | :--- | :--- |
| **Restricted Staff** (`cashier`, `department_manager`, `auditor`, etc.) | Filtered strictly to `$user->campus_id` and `$user->department_id` | Authenticated route: `/families/{family}/challan/pdf?period=...` (never public token URL) | Filtered strictly to `$user->campus_id` and `$user->department_id`; requires active authentication (`401`/`302` if logged out, `403` if outside campus/department scope) |
| **Authorized Administrators** (`super_admin`, `finance_admin`) | Full-family across all campuses and departments (unless explicitly filtered) | Public HMAC token URL: `/public/challans/{family}/{token}/Monthly-Fee-Invoice.pdf?period=...` | Full-family across all campuses and departments |
| **Parents (Outbound Delivery / Public Link)** | N/A (`InvoicePayloadBuilder` uses `$user = null`) | Public HMAC token URL: `/public/challans/{family}/{token}/Monthly-Fee-Invoice.pdf` | Full-family across all applicable unpaid obligations (`$user = null`) |

- Previously issued HMAC tokens, `PdfAccessService::isPathTokenValid()`, and signed URL verification remain completely unchanged and backward-compatible.

---

## 4. Restricted Staff Versus Full-Family Test Results

Tested in `Phase2ReadyToSendStandardizationTest::test_j_phase_2_1_restricted_staff_vs_full_family_pdf_authorization_and_scope` using fictional family `FAM-P21-SEC` with three unpaid obligations in `2026-10`:
- **School Department (Main Campus)**: `SAR 350.00` (`35000` minor)
- **Another Department (Tahfeez Department, Main Campus)**: `SAR 250.00` (`25000` minor)
- **Another Campus (South Branch Campus, School Department)**: `SAR 400.00` (`40000` minor)

| Verification Check | Expected | Actual Result | Status |
| :--- | :--- | :--- | :--- |
| School cashier Ready to Send total | `SAR 350.00` (`35000` minor) | `SAR 350.00` (`35000` minor) | **PASS** |
| School cashier generated preview URL | Authenticated `/families/{family}/challan/pdf?period=2026-10` (no `/public/challans/`) | `/families/{family}/challan/pdf?period=2026-10` | **PASS** |
| School cashier authenticated PDF total | `SAR 350.00` (`35000` minor; excludes `250.00`, `400.00`, and `1,000.00`) | `SAR 350.00` (`35000` minor) | **PASS** |
| Logged-out access to cashier's generated preview URL | Rejected (`302` / `401` / `403`), no full-family disclosure | `302` redirect to `/login` | **PASS** |
| Authorized administrator Ready to Send & full-family PDF | `SAR 1,000.00` (`100000` minor) | `SAR 1,000.00` (`100000` minor) | **PASS** |
| Valid parent-facing public token link (logged out & authenticated as admin) | `SAR 1,000.00` (`100000` minor), HTTP `200` `application/pdf` | `SAR 1,000.00` (`100000` minor), HTTP `200` | **PASS** |

---

## 5. Financial Fallback Correction

- Removed the `if (empty($currenciesMap))` header-balance fallback from `InvoiceDeliveryQueueService::getReadyToSendChallans()`.
- Both `InvoiceDeliveryQueueService::getReadyToSendChallans()` and `ConsolidatedInvoiceBuilder::buildFamilyViewData()` / `buildForInvoice()` now use `ConsolidatedInvoiceBuilder` as the single authoritative financial calculation source.
- When an unpaid `invoices` header (`total_minor > paid_minor`, status not in `['void', 'voided', 'paid']`) has no matching valid unpaid `invoice_items`:
  1. No header amount is substituted into `previous_outstanding_minor`, `selected_month_minor`, `later_period_minor`, `total_payable_minor`, or `total_outstanding_payable_minor` (all remain `0`).
  2. `ConsolidatedInvoiceBuilder::detectFamilyHeaderDiscrepancies()` detects the discrepancy and flags the family and invoice with `has_data_integrity_issue = true`, `is_payable_verified = false`, and `data_integrity_status = 'data_integrity_issue'`.
  3. The Ready to Send UI displays `⚠ Data Integrity Issue — Staff Review Required` and `UNVERIFIED — Staff Review Required`, disables the batch selection checkbox, and shows the exact discrepancy message.
  4. Both the PDF and Ready to Send agree (`0` verified payable minor units and `has_data_integrity_issue = true`).
  5. Zero automatic deletion, repair, or regeneration of database records occurs.

---

## 6. Tests Passed and Failed

### PHP Syntax Checks (`php -l`)
- `app/Services/PdfAccessService.php` — **No syntax errors detected**
- `app/Services/ConsolidatedInvoiceBuilder.php` — **No syntax errors detected**
- `app/Services/InvoiceDeliveryQueueService.php` — **No syntax errors detected**
- `app/Http/Controllers/BillingController.php` — **No syntax errors detected**
- `tests/Feature/Phase2ReadyToSendStandardizationTest.php` — **No syntax errors detected**

### Automated Feature & Regression Test Suites
| Test Suite | Passed | Failed | Assertions |
| :--- | :---: | :---: | :---: |
| `Tests\Feature\Phase2ReadyToSendStandardizationTest` (Tests A–K) | 11 | 0 | 197 |
| `Tests\Feature\ConsolidatedChallanTest` | 13 | 0 | 140 |
| `Tests\Feature\PermanentPublicPdfLinksTest` | 4 | 0 | 49 |
| `Tests\Feature\BillingLedgerTest` | 10 | 0 | 53 |
| `Tests\Feature\InvoiceUiHotfixTest` | 7 | 0 | 28 |
| **Total** | **45** | **0** | **467** |

---

## 7. Confirmation of Zero Financial-Record Modifications

- All calculations in `ConsolidatedInvoiceBuilder::summarizeFamiliesOutstanding()`, `detectFamilyHeaderDiscrepancies()`, `buildFamilyViewData()`, `buildStudentViewData()`, `buildForInvoice()`, and `InvoiceDeliveryQueueService::getReadyToSendChallans()` are strictly read-only (`SELECT` queries only).
- Verified in both `test_h_calculating_ready_to_send_and_opening_pdf_performs_zero_financial_writes` and `test_k_phase_2_1_inconsistent_invoice_header_without_valid_unpaid_items_is_flagged_and_never_substituted` that `invoices`, `invoice_items`, `payments`, and `payment_allocations` snapshots before and after Ready to Send and PDF generation are 100% identical.
- No production database records were touched; all tests executed against isolated in-memory SQLite test fixtures (`RefreshDatabase`).

---

## 8. Confirmation That the Approved PDF Design Remains Unchanged

- The approved Consolidated Fee Invoice template (`resources/views/pdf/consolidated-challan.blade.php`) was **not modified** during Phase 2.1 (`0` lines changed from Phase 2).
- All Phase 1 and Phase 2 consolidated invoice features (previous outstanding fees, current monthly fees, already-generated later-period fees, partial-payment balances, historical opening balances, individual saved due dates, separate currency totals, and the single `Preview / Print Fee Invoice` button) remain intact.

---

## 9. Git Status and Commit Reference

- **Current Commit Reference (`HEAD`)**: `1f90a35` (`feat(notifications): 50 recipient chunks every 30 minutes`)
- **Modified / Added Files for Phase 2.1**:
  - `M app/Services/PdfAccessService.php`
  - `M app/Services/ConsolidatedInvoiceBuilder.php`
  - `M app/Services/InvoiceDeliveryQueueService.php`
  - `M app/Http/Controllers/BillingController.php`
  - `M resources/views/invoices.blade.php`
  - `M tests/Feature/Phase2ReadyToSendStandardizationTest.php`
  - `?? PHASE_2_1_INVOICE_SECURITY_AND_TOTALS_REPORT.md`

---

## 10. Remaining Blockers

- **None.** All Phase 2.1 security and financial total reconciliation requirements and regression tests are complete and passing. Stopping here for owner review prior to Phase 3.
