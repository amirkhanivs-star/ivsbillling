# IQRA Billing: Independent Investigation of Historical Payments and Duplicate Outstanding Charges

**Review date:** 27 October 2026  
**Review scope:** Source archive `iqra-laravel-billing (5).zip`, existing reconciliation CSV/report, related previous Fee Collection screenshot, and source-workbook version metadata. **Read-only inspection:** no application/database changes or deployment.

## Executive conclusion

The primary issue is a **missing reconciliation boundary between staged historical MySkool collections and historical opening-balance invoice posting**, not an absence of monthly-invoice duplicate prevention.

The code already prevents recurring invoices from being created twice for the same fee-plan/period, and it blocks recurrence where the corresponding opening balance or staged historical obligation exists. However, the opening-balance importer takes rows from `qa_staged_dues` and posts the positive amounts into `invoice_items` at `paid_minor=0` without netting payments from `qa_staged_collections` **made after the dues snapshot**. Historical payments are separately reconstructed for display in Fee History and are not equivalent to current `payment_allocations` in Laravel's ledger. Thus both an Oct 24 historical receipt and an unpaid October opening-balance line can appear on the same screen.

**Confidence:** High for this architectural explanation, based on the inspected code. The exact number of affected production records remains unverified because no live MySQL dataset was included.

## Evidence map: source files examined

| Code location | Observed operation |
|---|---|
| `app/Services/Migration/ReviewedWorkbookImportService.php:866-905` | Imports MySkool paid rows to `qa_staged_collections`; stores payment date, fee particular, currency and amount without ledger posting. |
| `app/Services/Migration/ReviewedWorkbookImportService.php:907-1055` | Imports the outstanding worksheet independently to `qa_staged_dues`. |
| `app/Services/Migration/HistoricalOpeningBalanceService.php:837-932` | Builds historical invoice groups from **positive staged due rows**, with checks for enrollment, department, student status, and currency; no payment-cutoff settlement reconciliation in this grouping function. |
| `app/Services/Migration/HistoricalOpeningBalanceService.php:764-800` | Posts opening-balance invoice items with `net_minor=amount_minor`, `paid_minor=0`, writes source-line lineage. |
| `app/Services/InvoiceService.php:57-106` | Regular monthly billing skips existing fee-plan/period items, matching non-void opening-balance items, and staged historical obligations. This is real duplicate-prevention logic. |
| `app/Http/Controllers/BillingController.php:1186-1284` | Manual Create Fee has corresponding regular invoice, opening balance, and staged historical protections. |
| `app/Services/Migration/HistoricalOpeningBalanceService.php:1416-1652` | Historical map indexes collections and dues by fee/period/subject/currency; retains both paid and outstanding figures. |
| `app/Services/Migration/HistoricalOpeningBalanceService.php:1757-1822` | Paid + outstanding in the same source key becomes `partial_same_currency`, which blocks *new* billing but does not reconcile existing ledger opening balances. |
| `app/Services/FeeHistoryService.php:24-58,346-381,499-544` | Fee History merges portal receipts with historical MySkool receipts reconstructed from staging. Historical records are labeled read-only and do not allocate payments to active invoice items. |
| `app/Http/Controllers/BillingController.php:861-888,975-1010` | Fee Collection displays active `invoice_items` and calculates outstanding using those ledger items, not the MySkool historical-receipt display. |
| `app/Services/PaidInvoiceEligibility.php` | Paid Invoices workflow selects settled ledger invoices and received portal payment allocations; this is distinct from a staged MySkool historical receipt in Fee History. |
| `app/Services/Migration/ReviewedWorkbookImportService.php:585-595,690-707` | Fee plans are provisioned using the current workbook monthly-fee amount, without inferring sibling concessions from historical lower payments. |
| `app/Services/InvoiceService.php:113-126` | Stores a previous-dues snapshot on the new invoice when generated, based on then-outstanding older invoices. |

## Family 5929 concrete example

From the screenshot and the included `storage/app/reconciliation/myskool_opening_balance_reconciliation_audit.csv`:

| GR | Oct 2026 opening balance | Oct 24 payment, MySkool slip 27328 | Conditional remaining Oct amount |
|---|---:|---:|---:|
| 5929 | QAR 280.00 | QAR 280.00 | QAR 0.00 |
| 5930 | QAR 280.00 | QAR 230.00 | QAR 50.00 **unless a concession is independently established** |
| Total | QAR 560.00 | QAR 510.00 | QAR 50.00 pending concession review |

Screenshot totals: October QAR 560.00 shown as outstanding and November QAR 560.00, summing to QAR 1,120.00.

* If October post-snapshot collection evidence is validated and QAR 50 remains genuinely due: **QAR 610.00** total outstanding (QAR 50 October + QAR 560 November).
* If QAR 50 is proven to be an authorized October concession and November QAR 560 is otherwise correct: **QAR 560.00** outstanding.

These are not executed corrections. The historical QAR 230 repeat pattern for GR 5930 is a **concession signal, not proof of discount authorization**. The November QAR 280 item is not automatically duplicated merely because October is paid. November is a distinct billing period.

## Why some items are skipped while others remain

1. **Already represented by a regular invoice:** `InvoiceService.php:59-68` skips.
2. **Already represented by an opening-balance invoice:** `InvoiceService.php:70-90` skips another fee being created, regardless of whether that existing opening balance has since become stale.
3. **Present in historical paid or dues staging:** `InvoiceService.php:92-106` blocks generating a *new obligation*, but does not adjust an existing opening-balance invoice.
4. **No older same-period obligation:** November 2026 is eligible to generate, because an October receipt does not settle November automatically.
5. **Positive dues after an earlier partial payment:** The remaining opening balance is legitimate if the payment occurred before the outstanding-sheet cutoff; subtracting it again would undercharge.
6. **Different fee/currency/subject or uncertain match:** Historical matching can hold or distinguish records. Blind merging by student name, description or family database ID is unsafe.

## Existing Phase 1 audit dataset: what is and isn't verified

The included Phase 1 CSV contains **478 overlapping opening-balance rows**; it is reproducible as a CSV count and includes matching entries for GR 5929 and 5930. The accompanying report states **10,257** opening-balance items scanned, **9,779** without collection overlap, and categories: **209** pre-cutoff residuals, **246** post-cutoff full payments, **13** partial payments, **6** possible concessions, and **4** ambiguous/currency holds. It also reports **108** November invoice snapshot records potentially affected.

**Verification level:** These counts are verified as what the included CSV/report *say*, not independently re-queryable ledger counts in this environment. The uploaded ZIP does not contain a live or local MySQL database dump. The local PHP runtime also lacks the MySQL PDO driver, so a safe test execution stopped at the project's database identity guard. Six billing/import/history PHP files passed static `php -l` syntax checks, which does **not** prove database correctness.

**Cutoff limitation:** `MySkoolReconciliationAuditService::DEFAULT_CUTOFF_DATE` and its Artisan command hard-code **2026-10-22**. The staging importer sets `present_at_22oct` to `null` for imported records. The cutoff is plausible and explicitly stated in project reports, but the code does not itself establish a source-authoritative snapshot timestamp. Confirm cutoff from original MySkool extraction logs before authorizing automated financial corrections.

**Important snapshot distinction:** The `previous_dues_snapshot_minor` field is written to November invoices, but a search through the included app/views did not find a display read of this field for Fee Collection. October's active invoice item, **not the November snapshot column alone**, explains the QAR 1,120 displayed balance. Snapshot cleanup may still matter for historical integrity and future uses.

## Additional project integrity observations

* The included archive's Git working tree is **not clean**: `18` modified tracked files and `4` untracked paths, including the Phase 1 reconciliation command/service/test/report. The earlier recovery assessment stating a clean Git tree reflects a different point in time. Preserve these working changes before applying further patches.
* The active source config (`config/imports.php`) points at `v4_final_myskool_20261025`, batch `BATCH-20261025-FINAL-MYSKOOL`, with expected SHA-256 matching the workbook included in this ZIP (`ac83e8d7...`). The separately uploaded `Tuition excluded full data My skool paid.xlsx` is **not byte-identical** (`8291c1ce...`). A different file hash does not prove different rows, but the workbook versions must be compared and deliberately approved before any import changes.
* Historical MySkool receipts are immutable reference evidence in Fee History and must never be rebooked as fresh portal cash payments.
* The existing opening-balance poster has source-line lineage and duplicate guards. Any correction should attach to this lineage and remain auditable; do not delete staged rows or original receipts.
* The Phase 1 overlap audit does not prove that all 9,779 unmatched opening-balance items are financially valid, nor that every production ledger item matches the local data. Live cashiers may have since collected or adjusted records.
* The tests in this project are designed to use an isolated in-memory database; the current analysis environment could not rerun them because Laravel boot tried to verify a MySQL connection unavailable here. This is an **environment limitation**, not a discovered test failure on the developer's Windows machine.

## Root cause and corrective architecture

**Primary root cause (high confidence):** The financial import has separate staging streams, and the opening-balance posting trusts dues-source residuals without reconciling later historical payments. The regular monthly engine correctly blocks duplicates, but does not fix stale existing ledger items.

**Contributing data cause (probable):** Collections were exported through October 24, while outstanding balances appear to represent a snapshot around October 22. This chronology should be verified against original export records. If dates are wrong, the classification counts change.

**Contributing fee-plan cause (confirmed behavior, unresolved policy):** Fee plans use current listed monthly rates, while historical receipts reveal potentially lower authorized amounts. The imported fee-plan schema does not infer sibling concessions from receipt history.

**Display behavior (confirmed):** Historical receipts and collectible ledger balances are separate sources. This is intentional, but without a reconciliation bridge creates understandable contradictory entries.

## Safe, phased response

**Phase A, complete now:** Preserve a snapshot of code state; confirm source workbook versions, original outstanding cutoff evidence, ledger lineage and exact single-case matches in a read-only report. No live changes.

**Phase B, rules and fictional tests:** Make a non-mutating decision engine using exact GR/family code, fee type, subject, period, currency, staged due id, receipt source-row/slip and payment dates. Distinguish pre-cutoff residuals, post-cutoff full receipts, partials, concessions needing proof, ambiguous rows, and conflicting cashier transactions. Test all edge cases.

**Phase C, local-only audited correction:** Implement explicit preview and targeted, reversible accounting entries for approved cases. Do not create fake cashier receipts or treat ordinary concessions as cash. Preserve original obligation, settlement evidence, currency, amounts, and adjustments. Verify invoice status, fee-history presentation, and totals after changes.

**Phase D, full QA:** Check collections, challans, receipts, family views, consolidated PDFs, monthly regeneration, reimports, notification previews, and cross-currency behavior. Compare monetary totals by currency.

**Phase E, production reconciliation:** Back up Hostinger, compare the live database's actual payments/allocations/adjustments with the source lineage, and recalculate eligibility separately. Never assume the local 246 candidates remain actionable in live data. Deploy code separately from approved financial operations, with a verification and rollback plan.

## What should not happen now

No automatic bulk void of all 478 overlaps; no source CSV/XLSX reimport to replace the live ledger; no blind subtraction of pre-cutoff payments; no implicit sibling discounts; no rewriting live portal receipts; and no production push of unreviewed local financial code.
