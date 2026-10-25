# IQRA Laravel -> n8n -> BotCommerce/Email contract

> Historical flat-message contract below. The current batch dispatcher sends
> `invoice.monthly.batch` or `invoice.paid.batch` with an `invoices` array;
> each invoice includes `notification_id`, `event_id`, `idempotency_key`, nested
> invoice/family/student/PDF fields, and paid events include `payment_details`.
> HMAC still signs the exact raw JSON body. Current dispatch uses the per-type
> database webhook setting. The deployed n8n workflow must be checked against
> this batch structure before live verification; this document does not prove
> its configuration. See [paid dispatch operations](paid-dispatch-recovery.md).

## Delivery workflow

1. Finance staff generate invoices (status `generated`), review each family and click Send Selected.
2. Laravel checks verified email or verified + opted-in WhatsApp contact and inserts a unique `(invoice_id, channel, kind)` notification job.
3. Laravel queue worker signs JSON body with HMAC SHA-256 and POSTs to `N8N_INVOICE_WEBHOOK_URL`. Header `X-IQRA-Signature` is lowercase hex `hash_hmac('sha256', raw_body, N8N_WEBHOOK_SECRET)`.
4. n8n validates the signature against **raw request body** before parsing, checks `dedupe_key`, and sends approved WhatsApp template with document `pdf_url`, or email with PDF.
5. Immediate n8n 2xx means *submitted*, not *delivered*. n8n/Meta delivery callback POSTs to Laravel `/webhook/n8n/callback` with its own HMAC `N8N_CALLBACK_SECRET`, and status `submitted|delivered|read|failed`.

## Example outbound request

```json
{
  "event": "invoice.send",
  "notification_id": 123,
  "invoice_id": 152,
  "invoice_number": "SCH-2026-0000152",
  "channel": "whatsapp",
  "department_id": 1,
  "family_code": "9349",
  "currency": "SAR",
  "amount_due": "400.00",
  "recipient": "+00000000000",
  "pdf_url": "https://example.org/public/invoices/152/pdf?expires=...&signature=...",
  "dedupe_key": "invoice-123"
}
```

Contact and signed URL shown here are placeholders. Always use HTTPS, check that the source server is trusted, do not log tokens, check phone consent and compliance, and limit template sends based on actual BotCommerce/Meta rules. A 24-hour signed URL may expire before provider retries; regenerate for a new send rather than reusing expired links.

## Delivery status callback

```http
POST /webhook/n8n/callback
Content-Type: application/json
X-IQRA-Signature: <hex-hmac-of-exact-raw-body>
```

```json
{"notification_id":123,"status":"delivered","provider_message_id":"provider-abc"}
```

This endpoint is CSRF-exempt but validates its dedicated HMAC. No bank/reference number is needed for payment recording. `notifications.status` tracks send attempts independently of financial `invoices.status`.

## Rate/cost safeguard before live launch

n8n should use a configurable batch/throttle node and retry-with-backoff appropriate to the actual approved template and account messaging limits. The Laravel queue job avoids duplicate successful submissions but does not enforce cost ceilings on its own. Configure provider-specific throughput and daily budget in n8n/BotCommerce before sending to all families. Always test with a small internally approved recipient set first.
