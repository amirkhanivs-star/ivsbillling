# IQRA Billing — Phase 3: Consolidated Invoice Notification Consistency and Safe Dispatch Report

## 1. Executive Summary

Phase 3 standardizes all outbound monthly fee invoice notifications across individual WhatsApp/email sending, bulk batch delivery, scheduled/chunked delivery, delivery queue views, and retry workflows so that they use:
- The approved **Consolidated Fee Invoice** template (`resources/views/pdf/consolidated-challan.blade.php`) via the permanent family consolidated PDF URL (`/public/challans/{family}/{token}/Monthly-Fee-Invoice.pdf`), and
- The authoritative financial builder (`app/Services/ConsolidatedInvoiceBuilder.php`) as the single source of truth for all unpaid family obligations.

All Phase 1, Phase 2, and Phase 2.1 implementations and uncommitted changes were preserved intact. No existing financial data in `iqra_billing_vps_test` was modified, no real WhatsApp/email/n8n messages were sent, and no deployment to Hostinger was performed.

---

## 2. Outbound Notification Paths Inspected & Standardized

| Outbound Path | Entry Point / Service / Job | Previous Behavior | Phase 3 Standardized Behavior |
|---|---|---|---|
| **1. Individual WhatsApp & Email Sending** | `BillingController::approveSend()` (`POST /invoices/send`) -> `DispatchInvoiceToN8n` | Used single-invoice header balance (`i.total_minor - i.paid_minor`) + SQL previous-dues query (`period_start < current`) and `/public/invoices/{invoice}/{token}/Monthly-Fee-Invoice.pdf`. Did not enforce server-side data integrity holds or full-family staff authorization. | Enforces full-family authorization (`canDispatchFullFamilyStatement`) and `evaluateFamiliesDispatchEligibility()` server-side before queueing. Dispatches canonical `ConsolidatedInvoiceBuilder` totals and `/public/challans/{family}/{token}/Monthly-Fee-Invoice.pdf`. |
| **2. Bulk WhatsApp & Email Sending (Page & All-Matching)** | `BillingController::approveSendBatch()` (`POST /invoices/send-batch`) -> `BatchDeliveryService::createAndDispatchBatch()` -> `DispatchBatchToN8n` | Snapshot (`resolveFilteredSelection`) and batch payload used single-invoice header remaining + prior-only SQL sum (omitting already-generated later months) and lacked server-side financial integrity blocking. | `resolveFilteredSelection()` and `createAndDispatchBatch()` use `ConsolidatedInvoiceBuilder::summarizeFamiliesOutstanding()`, exclude/block unverified `has_data_integrity_issue` rows with audit logging, and require full-family authorization (`super_admin` / `finance_admin`). |
| **3. Scheduled / Chunked Batch Dispatch** | `DispatchBatchToN8n::handle()` (`dispatchLocked()`) | Checked contact eligibility (`WebhookSettingService::evaluateContactEligibility`), then built payloads without re-checking financial data integrity or full-family authorization if a discrepancy appeared after queueing. | Re-validates full-family authorization and `ConsolidatedInvoiceBuilder::evaluateFamilyDispatchEligibility()` immediately before HTTP POST for every notification in the chunk. Marks unverified rows `blocked` and writes `audit_logs`. |
| **4. Individual Queued Job Dispatch** | `DispatchInvoiceToN8n::handle()` | Checked contact eligibility only, then dispatched to n8n. | Re-validates full-family authorization and `ConsolidatedInvoiceBuilder::evaluateFamilyDispatchEligibility()` immediately before HTTP POST. Blocks and audits unverified rows without sending HTTP requests. |
| **5. Failed / Blocked Notification Retries (Individual & Batch)** | `BillingController::retryNotification()` (`POST /invoices/notifications/{id}/retry`) & `BillingController::retryBatchFailed()` (`POST /invoices/batches/{batch}/retry-failed`) -> `BatchDeliveryService::retryFailedBatchRecipients()` | Re-queued failed/blocked notifications without checking whether the invoice had a financial data-integrity hold or whether the staff user was authorized for full-family dispatch. | Re-validates full-family authorization (`403` for restricted staff) and `evaluateFamilyDispatchEligibility()` / `evaluateFamiliesDispatchEligibility()` before re-queueing. Keeps unverified notifications `blocked` with audit logs. |
| **6. Outbound Webhook Payload Builder** | `InvoicePayloadBuilder::build()` | Calculated `previous_dues` only for `period_start < current`, omitted already-generated later periods, listed only the selected invoice's items in `students[].itemized_fee_particulars`, and set `pdf_attachment.pdf_url` to the individual invoice URL. | Uses `ConsolidatedInvoiceBuilder::summarizeFamiliesOutstanding()` and `buildFamilyViewData()` for monthly notifications: includes all unpaid items across all generated periods (previous, opening balances, partial remaining, current, and later periods), sets `pdf_attachment.pdf_url` to the family consolidated PDF URL (preserving `invoice_pdf_url` for internal accounting), exposes per-currency `currencies_breakdown`, `school_details`, and `statement_metadata`. |
| **7. Delivery Queue & Batch Details UI** | `InvoiceDeliveryQueueService::getPaginatedQueue()` & `resources/views/invoices-queue.blade.php` | Displayed `remaining_balance_minor + previous_dues_minor` without later-period dues, data-integrity flags, or dispatched snapshot metadata. | Enriches monthly queue rows with `ConsolidatedInvoiceBuilder` canonical totals (`later_period_minor`, `total_payable_minor`, `currencies_map`, `has_data_integrity_issue`) plus `dispatched_calculated_at` and `dispatched_totals_by_currency` parsed from stored `n.payload`. |
| **8. Paid Receipt Notifications** | `BillingController::sendQueuedPaid()`, `BatchDeliveryService::sendQueuedPaidInvoices()`, `InvoicePayloadBuilder::build('invoice.paid')` | Used `Paid-Receipt.pdf` (`PdfAccessService::getReceiptPdfUrl()`) and `PaidInvoiceEligibility`. | Preserved 100% unchanged. Paid receipt notifications continue to reference `Paid-Receipt.pdf` and actual payment allocations, never an unpaid consolidated invoice. |

---

## 3. Files Modified & Created

1. `app/Services/ConsolidatedInvoiceBuilder.php`
   - Added `canDispatchFullFamilyStatement(?object $user): bool`
   - Added `evaluateFamiliesDispatchEligibility(array $familyIds, ?string $selectedPeriod = null, ?object $user = null): array`
   - Added `evaluateFamilyDispatchEligibility(int $familyId, ?string $selectedPeriod = null, ?object $user = null): array`
2. `app/Services/InvoicePayloadBuilder.php`
   - Standardized `build()` for monthly notifications on `ConsolidatedInvoiceBuilder`, family consolidated PDF links (`getFamilyChallanPdfUrl`), multi-currency `currencies_breakdown`, `school_details`, and `statement_metadata`.
3. `app/Services/BatchDeliveryService.php`
   - Updated `resolveFilteredSelection()`, `createAndDispatchBatch()`, and `retryFailedBatchRecipients()` to use canonical `ConsolidatedInvoiceBuilder` balances and enforce server-side data-integrity holds and full-family dispatch authorization.
4. `app/Http/Controllers/BillingController.php`
   - Updated `approveSend()`, `approveSendBatch()`, `retryNotification()`, and `retryBatchFailed()` to enforce full-family dispatch authorization and server-side financial integrity validation.
5. `app/Jobs/DispatchInvoiceToN8n.php`
   - Added pre-dispatch re-validation of full-family authorization and `evaluateFamilyDispatchEligibility()`.
6. `app/Jobs/DispatchBatchToN8n.php`
   - Added pre-dispatch re-validation of full-family authorization and `evaluateFamilyDispatchEligibility()` per recipient in `dispatchLocked()`.
7. `app/Services/InvoiceDeliveryQueueService.php`
   - Enriched `buildQueueQuery()` and `getPaginatedQueue()` with `n.payload`, canonical `ConsolidatedInvoiceBuilder` balances, integrity hold flags, and dispatched snapshot metadata (`dispatched_calculated_at`, `dispatched_totals_by_currency`).
8. `resources/views/invoices-queue.blade.php`
   - Updated Delivery Queue table to surface data-integrity holds, later-period dues, multi-currency breakdowns, dispatched-at snapshot balances, and `Consolidated Fee Invoice (Live) ↗`.
9. `tests/Feature/Phase3NotificationConsistencyAndDispatchTest.php` (New)
   - Added comprehensive regression tests covering Mandatory Tests A through K.

---

## 4. Standardization of Consolidated Invoice Links, Totals & Multi-Currency

- **Primary Parent-Facing PDF Link**:
  - For all monthly fee notifications (`invoice.monthly` / `invoice.monthly.batch`), `pdf_attachment.pdf_url` and `pdf_attachment.consolidated_challan_url` point to the permanent HMAC-protected family consolidated route:
    `/public/challans/{family}/{token}/Monthly-Fee-Invoice.pdf`
  - `pdf_attachment.invoice_pdf_url` (`/public/invoices/{invoice}/{token}/Monthly-Fee-Invoice.pdf`) is preserved in the payload for internal accounting reference.
- **Canonical Consolidated Totals**:
  - When a billing campaign month (e.g., `2026-10`) is selected, the notification communicates the family's complete unpaid ledger obligations:
    - Previous unpaid monthly fees and valid historical opening balances (`previous_dues_amount` / `previous_dues_minor`)
    - Selected campaign month unpaid fees (`selected_month_amount` / `selected_month_minor`)
    - Remaining balances on partially paid items
    - Already-generated later-period unpaid fees (`later_period_amount` / `later_period_minor`, `later_dues_by_month`)
  - Example verified in Test A & Test B:
    - Aug `SAR 300.00` + Sep `SAR 200.00` + Oct `SAR 350.00` = `SAR 850.00`
    - Adding already-generated Nov `SAR 350.00` = `SAR 1,200.00` (`120000` minor)
- **Multi-Currency Isolation**:
  - Multi-currency families (e.g., `SAR 850.00` and `AED 400.00`) have `invoice.is_multi_currency = true`, `invoice.currencies_breakdown` containing separate per-currency entries (`SAR` -> `850.00`, `AED` -> `400.00`), and `invoice.formatted_totals_by_currency` (`["SAR 850.00", "AED 400.00"]`).
  - Currencies are never converted or summed into a combined number (`1,250.00` is never produced).

---

## 5. Server-Side Financial Integrity Holds & Staff Authorization

- **Financial Integrity Enforcement**:
  - Before queueing or dispatching any monthly notification (`approveSend`, `approveSendBatch`, `createAndDispatchBatch`, `retryNotification`, `retryBatchFailed`, `DispatchInvoiceToN8n::handle`, `DispatchBatchToN8n::dispatchLocked`), the system calls `ConsolidatedInvoiceBuilder::evaluateFamilyDispatchEligibility()`.
  - If an invoice header is inconsistent with its underlying unpaid `invoice_items` (`has_data_integrity_issue === true` or `!is_payable_verified`), dispatch is blocked server-side, the notification row is recorded with `status = 'blocked'` and the diagnostic hold reason in `error`, and an `audit_logs` entry (`notification.blocked_financial_integrity` / `notification.retry_blocked_financial_integrity`) is written.
  - Legitimate partial payments and historical opening balances with valid unpaid `invoice_items` pass verification without false positives.
- **Full-Family Dispatch Authorization**:
  - Outbound parent notifications send a full-family consolidated statement via the public token link.
  - `ConsolidatedInvoiceBuilder::canDispatchFullFamilyStatement($user)` restricts full-family outbound dispatch to `super_admin` and `finance_admin` (plus system/null context when `approved_by` was already authorized).
  - Restricted campus/department staff (such as `cashier`) receive `403 Forbidden` if they attempt to dispatch or retry a full-family monthly statement via controller endpoints or direct service calls.

---

## 6. Live PDF Link Behavior & Proposal for Immutable Snapshots

### Confirmed Current Behavior
1. **Dynamic Live Ledger Rendering**: Public family consolidated PDF URLs (`/public/challans/{family}/{token}/Monthly-Fee-Invoice.pdf`) and public invoice PDF URLs dynamically query the current unpaid ledger via `ConsolidatedInvoiceBuilder` at the time the parent opens the link.
2. **Preparation/Dispatch Metadata vs. Live View**:
   - Outbound payloads now include `statement_metadata`:
     - `statement_mode`: `'live_consolidated_ledger'`
     - `is_live_statement`: `true`
     - `is_immutable_snapshot`: `false`
     - `calculated_at`: ISO-8601 timestamp when the payload was prepared/dispatched
     - `dispatched_totals_by_currency`: Exact verified per-currency breakdown at dispatch time
     - `live_statement_notice`: Explicitly states that the public URL renders current outstanding ledger obligations when opened while `dispatched_totals_by_currency` records the exact balances calculated at dispatch time.
   - `InvoiceDeliveryQueueService::getPaginatedQueue()` extracts `dispatched_calculated_at` and `dispatched_totals_by_currency` from the stored `notifications.payload` JSON so staff can compare the balance at dispatch time against the live consolidated balance in `resources/views/invoices-queue.blade.php`.

### Minimal Proposal for Immutable Sent-Document Snapshots (Future Phase — For Owner Approval Only)
If permanent historical reproduction of the exact PDF state at dispatch time is required in a future phase:
1. Create a dedicated `notification_statement_snapshots` table (linked to `notification_id` and `family_id`) storing the canonical `ConsolidatedInvoiceBuilder` view-data JSON (`currencyGroups`, `referenceInvoice`, `dueDate`, `issuedOn`, `statementNumber`) and SHA-256 content hash at the moment `DispatchInvoiceToN8n` / `DispatchBatchToN8n` receives HTTP 200/202 acceptance.
2. Keep `/public/challans/{family}/{token}/Monthly-Fee-Invoice.pdf` as the live consolidated statement (or allow an optional signed `?snapshot={notification_id}` parameter for staff/auditors to render the frozen `notification_statement_snapshots` payload through `resources/views/pdf/consolidated-challan.blade.php` without storing large binary PDF blobs on disk).
*(Not implemented in Phase 3 per instructions.)*

---

## 7. Verification & Regression Test Results

### Phase 3 Regression Suite (`tests/Feature/Phase3NotificationConsistencyAndDispatchTest.php`)
- **Test A (`test_a_family_consolidated_notification_uses_canonical_850_total_and_consolidated_pdf`)**: PASS — Previous SAR 500 + Oct SAR 350 = SAR 850.00; primary PDF URL opens approved consolidated invoice.
- **Test B (`test_b_already_generated_future_month_is_included_in_1200_total_when_october_is_dispatched`)**: PASS — Previous SAR 500 + Oct SAR 350 + Nov SAR 350 = SAR 1,200.00; all 4 periods represented.
- **Test C (`test_c_partial_payment_notification_uses_remaining_200_balance`)**: PASS — Original SAR 300, Paid SAR 100 -> Remaining SAR 200.00 used in notification and PDF.
- **Test D (`test_d_multi_currency_family_preserves_separate_sar_and_aed_totals_without_combining`)**: PASS — Separate SAR 850.00 and AED 400.00 balances preserved; never combined to 1,250.
- **Test E (`test_e_financial_integrity_hold_blocks_sending_across_ui_service_queued_job_and_retry`)**: PASS — Inconsistent header without valid unpaid items is blocked across UI snapshot, direct POST, `BatchDeliveryService`, queued `DispatchInvoiceToN8n` / `DispatchBatchToN8n` jobs, and retry endpoints.
- **Test F (`test_f_restricted_staff_cannot_dispatch_full_family_statement_while_admins_can`)**: PASS — Department cashier is blocked (`403`) from full-family dispatch while `super_admin` and `finance_admin` succeed.
- **Test G (`test_g_secure_parent_pdf_accepts_valid_token_rejects_invalid_and_isolates_family_data`)**: PASS — Valid family token returns 200 PDF, tampered/cross-family tokens return 403, and zero unrelated family records appear.
- **Test H (`test_h_notification_consolidated_pdf_and_totals_match_approved_staff_preview`)**: PASS — Staff Ready-to-Send preview, outbound notification payload, and consolidated PDF view data reconcile to the exact minor unit (`105000` = SAR 1,050.00).
- **Test I (`test_i_delivery_safety_fakes_and_qa_suppression_guard_prevent_real_external_calls`)**: PASS — `Http::fake()`, `Queue::fake()`, and `services.notifications_disabled` / `DatabaseSafetyGuard` prevent any real external calls.
- **Test J (`test_j_existing_workflows_batch_chunking_live_metadata_and_paid_receipts_remain_functional`)**: PASS — Batch selection, pagination, chunking, delivery callbacks, live vs. dispatched metadata, and `Paid-Receipt.pdf` workflows remain functional.
- **Test K (`test_k_preparation_preview_and_payload_creation_perform_zero_financial_writes`)**: PASS — Zero database writes to `invoices`, `invoice_items`, `payments`, or `payment_allocations`.

### Full Combined Test Suite Execution
Command:
```powershell
php artisan test tests/Feature/Phase3NotificationConsistencyAndDispatchTest.php tests/Feature/Phase2ReadyToSendStandardizationTest.php tests/Feature/ConsolidatedChallanTest.php tests/Feature/PermanentPublicPdfLinksTest.php tests/Feature/WebhookNotificationTest.php tests/Feature/BatchDeliveryTest.php tests/Feature/InvoiceDeliveryQueueTest.php tests/Feature/BatchChunkWorkerRulesTest.php tests/Feature/PaidInvoicesTabTest.php tests/Feature/BillingLedgerTest.php
```
Result: **146 passed (1,343 assertions), 0 failures**.
