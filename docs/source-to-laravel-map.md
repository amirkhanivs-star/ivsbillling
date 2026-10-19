# Uploaded Node project -> Laravel mapping

| Original source | Ported target | Status |
| --- | --- | --- |
| `utils/calcPendingDues.js` | `app/Services/LegacyBillingCalculator.php` | Ported expected/paid/pending/status/discount/fee history core; isolated regression checked |
| `views/admission-details.ejs` | `resources/views/student-details.blade.php` | Student/family/enrollment and contact setup; advanced edit/history remains TODO |
| `views/fee-collection.ejs` | `resources/views/collection.blade.php` | Family/GR search + Receive; bank and verification fields intentionally absent |
| `server.js` `/api/fee-collection/receive` | `ReceivePaymentService.php`, POST `/challan/receive` | Core oldest-first/partial/advance payment path; no bank/master verification gates; discounts handled at fee plan level rather than identical old payment discount modal |
| `server.js` `/api/bulk-challan/create` | `InvoiceService.php`, POST `/fee-creator` | Family invoicing across supported fee plan frequencies; ledger idempotency |
| `server.js` `/api/bulk-challan/send-whatsapp` | POST `/invoices/send` + `DispatchInvoiceToN8n` | Manual send selection and n8n/BotCommerce payload |
| `utils/makeFamilyChallanPdf.js` + `makeMonthlyChallanPdf.js` | `resources/views/pdf/invoice.blade.php` | Brand header, due/previous dues/lines, bank instructions configurable; visual QA needed |
| `utils/makeMonthlyPaidReceiptPdf.js` | `resources/views/pdf/receipt.blade.php` | Paid receipt/status consistent with amount |
| Legacy SQLite `admissions` | `students`, `families`, `enrollments`, `fee_plan_items` | Normalized schema; source SQLite conversion not yet executed |
| MySkool `Total data of Myskool` worksheet | `iqra:import-myskool` | Dry run and controlled group/currency import; historical dues/collections excluded until reconciled |

Not in MVP: automatic historical outstanding import, full payment reversal/editing, exact source permissions matrix, source admissions detail workflow, pixel-diff UI testing, and production messaging/account provisioning.
