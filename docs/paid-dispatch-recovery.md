# Paid dispatch operations

Code changes do not authorize live recovery, queue execution, or webhook calls.
The following procedure is for a separately approved maintenance window.

## Worker configuration

- Portal-generated, portal-paid invoices now use `paid-future` after **Send Selected Queued Invoices**. Staging alone sends nothing. Positive provenance requires matching fee-plan links for every invoice item, a non-opening-balance run, no opening-balance import lineage, and an allocated receipt with a `payment_received` actor audit entry. Only these invoices bypass the October 9 receipt cutoff. Unknown or historical provenance retains the cutoff and stays on `notifications`.
- Automatic future-paid dispatch is enabled by `services.n8n.paid_automatic_dispatch` (environment override `PAID_AUTO_DISPATCH`, default true). Disabled mode defers future-paid jobs. The dedicated worker rejects non-portal invoice selections even if accidentally queued there.
- Windows task **IQRA Paid Future Worker** runs `deployment/windows/Start-PaidInvoiceWorker.ps1` hidden under the signed-in Windows account. It starts at login and has a one-minute restart check; the launcher also restarts PHP after clean exits or failures. It listens exclusively to `paid-future` and never consumes the parked `notifications` backlog. Its output is `storage/logs/paid-future-worker.log`. Inspect with `Get-ScheduledTask -TaskName 'IQRA Paid Future Worker'` and `Get-ScheduledTaskInfo -TaskName 'IQRA Paid Future Worker'`. Do not start the normal `notifications` worker as a substitute. A SYSTEM/boot task requires Windows administrator rights and was denied on this machine; the installed task requires the account to be logged in, matching the current XAMPP desktop setup.
- The database queue now enforces `retry_after >= 420` seconds, longer than the job's 300-second timeout. An older `DB_QUEUE_RETRY_AFTER=90` override cannot shorten it.
- Use the `notifications` queue, a 300-second worker timeout, and a process supervisor that allows at least 420 seconds for graceful shutdown. Use nonpersistent MySQL connections. GET_LOCK and RELEASE_LOCK must reach the same primary MySQL server; a connection-multiplexing proxy is unsuitable.
- Restart all existing workers after deploying code/configuration; do not mix old and new workers. Rebuild configuration cache only as part of the approved deployment.
- New jobs have a fixed two-hour retry deadline (keeping 30-second contention releases below the database attempt-counter limit) and a three-exception limit. Lock contention releases for 30 seconds without consuming that exception budget. Pre-HTTP failures use 60/300/900-second backoff. Older payloads retain their old queue metadata.
- MySQL connection-owned advisory locks coordinate serial and concurrent workers without a migration or cache table. A disconnected worker loses its lock. Persisted in-flight notification intent prevents blind HTTP replay after a crash. Long batches yield between chunks after approximately 240 seconds.
- For hard execution time limits, use a supervised PHP worker with pcntl support. Windows workers still have HTTP connect/request timeouts and the between-chunk time budget, but cannot enforce PHP's process timeout during a blocked database call.

## Recover the three historical batches

1. Stop notification workers and verify none remains active. Retain a database backup and a copy of the five notification rows and associated job payloads.
2. Repeat SELECTs for batches `BATCH-20261025-JO8RPX`, `BATCH-20261025-ODDX18`, `BATCH-20261025-AH6A6M`; notifications `4452`–`4456`; and jobs `134`–`138`. Do not rely on historical IDs without matching batch IDs and serialized class/selection again.
3. Inspect n8n execution history for these batch/notification IDs and their stable idempotency keys. If any request was accepted, reconcile its local submission evidence rather than resending it. Zero local attempts is strong evidence of pre-HTTP failure, but is not a substitute for checking external records.
4. After explicit approval of the exact rows, retire only the three obsolete null-selection paid job records (expected IDs 134–136), retaining their archived payloads. Do not clear the queue, retry all failures, or disturb jobs 137–138.
5. Only if all five notifications still have zero attempts, no payload, no last attempt, no submission, and no external acceptance: transactionally change just the three batch statuses from processing to queued with an audit note/timestamp. Keep notification IDs, selections, idempotency keys, financial records and amounts intact. Recheck those predicates while holding the rows locked; abort on any difference. No financial migration is needed.
6. Select one qualifying invoice explicitly for the controlled verification below. Unselected notifications remain queued. Later sends require explicit selections.

The code deliberately refuses to infer selection from legacy null-selection paid jobs. It never automatically resets these historical batches. Do not reset an attempted notification to zero attempts to bypass reconciliation.

## Controlled single-invoice verification

1. Obtain separate approval naming one invoice and its intended recipient/channel. Verify a positive allocation to an unreversed received payment dated October 9, 2026 or later and a positive invoice total fully covered by paid amount.
2. Keep normal notifications workers stopped so the unrelated backlog cannot execute. Stage the approved invoice if needed; record its exact notification ID and existing idempotency key.
3. Enqueue exactly one `DispatchBatchToN8n($batchId, [$notificationId])` on a temporary queue named `notifications-verification`. Do not enqueue a null selection. This enqueue and subsequent live worker run are approval-required operations, not part of the implementation tests.
4. Run a single worker for that queue only, for example `php artisan queue:work database --queue=notifications-verification --once --timeout=300`. If the job is deferred by a lock, investigate the owner; do not treat deferral as submission.
5. Verify exactly one selected invoice in the n8n `invoice.paid.batch` payload, its HMAC using the existing secret, stable notification idempotency key, and the HTTP response. Confirm the notification becomes submitted and its unselected siblings remain queued. Submitted means webhook acceptance; WhatsApp delivery requires provider/callback evidence.
6. Confirm there is no second n8n/provider execution and financial values remain identical. If the outcome is blocked/uncertain, stop and reconcile external acceptance before approving a retry. Restore normal workers only after separately approving the remaining backlog.

## Contract and uncertainty

The payload structure and HMAC scheme were preserved. The deployed n8n workflow was not accessed or triggered during implementation. It must deduplicate using each invoice notification's `idempotency_key`, not the newly generated envelope event ID. Validate this against the workflow export/configuration before live verification.

For paid requests, transport exceptions and non-2xx responses require reconciliation: even a server error may occur after a downstream side effect. The application blocks automatic replay instead of promising exactly-once delivery across an uncertain network. Existing monthly non-2xx retry semantics are preserved.
