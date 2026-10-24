<?php

namespace App\Jobs;

use App\Services\InvoicePayloadBuilder;
use App\Services\Staging\DatabaseSafetyGuard;
use App\Services\WebhookSettingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class DispatchBatchToN8n implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;
    public array $backoff = [60, 300, 900];

    public function __construct(public string $batchId) {}

    public function handle(): void
    {
        $batch = DB::table('notification_batches')->where('batch_id', $this->batchId)->first();
        if (!$batch) {
            return;
        }

        // Idempotency: if already submitted, do not re-dispatch
        if (in_array($batch->status, ['submitted', 'completed'], true)) {
            return;
        }

        // Safety guard: QA suppression
        if (config('services.notifications_disabled', false) || DatabaseSafetyGuard::isQaDatabase()) {
            DB::table('notification_batches')->where('id', $batch->id)->update([
                'status' => 'qa_suppressed',
                'error' => 'QA Portal: real notifications and external webhooks are disabled.',
                'updated_at' => now(),
            ]);
            DB::table('notifications')->where('batch_id', $batch->batch_id)->update([
                'status' => 'qa_suppressed',
                'error' => 'QA Portal: real notifications and external webhooks are disabled.',
                'updated_at' => now(),
            ]);
            return;
        }

        $webhookType = $batch->webhook_type ?? 'monthly';
        $webhookService = app(WebhookSettingService::class);
        $setting = $webhookService->getSettings($webhookType);

        if (!$setting || !$setting->is_enabled || empty($setting->url)) {
            $msg = ucfirst($webhookType) . ' webhook is disabled or not configured in Settings → Webhooks.';
            DB::table('notification_batches')->where('id', $batch->id)->update([
                'status' => 'blocked',
                'error' => $msg,
                'updated_at' => now(),
            ]);
            DB::table('notifications')->where('batch_id', $batch->batch_id)->update([
                'status' => 'blocked',
                'error' => $msg,
                'updated_at' => now(),
            ]);
            return;
        }

        // Fetch all notifications belonging to this batch that need dispatching
        $notifications = DB::table('notifications')
            ->where('batch_id', $batch->batch_id)
            ->whereIn('status', ['queued', 'failed'])
            ->get();

        if ($notifications->isEmpty()) {
            return;
        }

        $payloadBuilder = app(InvoicePayloadBuilder::class);
        $invoiceEventType = ($webhookType === 'paid') ? 'invoice.paid' : 'invoice.monthly';
        $recipientPayloads = [];
        $validNotifications = [];

        foreach ($notifications as $notification) {
            // Check if superseded
            if ($notification->is_superseded) {
                continue;
            }

            $invoice = DB::table('invoices')->where('id', $notification->invoice_id)->first();
            if (!$invoice) {
                continue;
            }

            $family = DB::table('families')->where('id', $invoice->family_id)->first();
            if (!$family) {
                continue;
            }

            // Build individual invoice payload
            $itemPayload = $payloadBuilder->build($notification, $invoice, $invoiceEventType);
            $recipientPayloads[] = $itemPayload;
            $validNotifications[] = $notification;
        }

        if (empty($recipientPayloads)) {
            return;
        }

        // Chunking configuration
        $chunkSize = config('services.n8n.batch_chunk_size', 50);
        $chunks = array_chunk($recipientPayloads, $chunkSize);
        $notificationChunks = array_chunk($validNotifications, $chunkSize);
        $totalChunks = count($chunks);

        DB::table('notification_batches')->where('id', $batch->id)->update([
            'chunks_total' => $totalChunks,
            'updated_at' => now(),
        ]);

        $approverUser = $batch->approved_by ? DB::table('users')->where('id', $batch->approved_by)->first() : null;
        $approverName = $approverUser?->name ?? 'Authorized Staff';

        $channels = !empty($batch->channels) ? json_decode($batch->channels, true) : ['whatsapp'];

        $lastResponseCode = null;
        $lastResponseBody = null;
        $chunksSent = 0;

        foreach ($chunks as $idx => $chunkPayloads) {
            $chunkIndex = $idx + 1;
            $notifChunk = $notificationChunks[$idx];

            $batchPayload = [
                'event' => $batch->event_type,
                'event_type' => $batch->event_type,
                'batch_id' => $batch->batch_id,
                'event_id' => (string)Str::uuid(),
                'billing_period' => $batch->billing_period,
                'requested_channels' => $channels,
                'approved_by' => $approverName,
                'total_recipient_count' => count($chunkPayloads),
                'total_invoice_count' => count($chunkPayloads),
                'chunk_index' => $chunkIndex,
                'total_chunks' => $totalChunks,
                'timestamp' => now()->toIso8601String(),
                'source_system' => 'IQRA Virtual School',
                'invoices' => $chunkPayloads,
            ];

            $body = json_encode($batchPayload, JSON_THROW_ON_ERROR);

            $headers = [
                'Content-Type' => 'application/json',
                'User-Agent' => 'IQRA-Virtual-School-Billing/1.0',
            ];

            if (!empty($setting->secret)) {
                $headers['X-IQRA-Signature'] = hash_hmac('sha256', $body, $setting->secret);
            }

            // Update notifications in chunk with payload & attempt
            $notifIds = array_map(fn($n) => $n->id, $notifChunk);
            DB::table('notifications')->whereIn('id', $notifIds)->update([
                'chunk_index' => $chunkIndex,
                'attempts' => DB::raw('attempts + 1'),
                'last_attempt_at' => now(),
                'payload' => $body,
                'updated_at' => now(),
            ]);

            try {
                $response = Http::timeout(30)
                    ->withHeaders($headers)
                    ->withBody($body, 'application/json')
                    ->post($setting->url);

                $lastResponseCode = $response->status();
                $lastResponseBody = substr($response->body(), 0, 1000);

                if (!$response->successful()) {
                    throw new RuntimeException("Webhook HTTP {$lastResponseCode}");
                }

                $providerMsgId = $response->json('message_id') ?? $response->json('batch_id');

                // Mark chunk notifications as submitted (HTTP 200/202 indicates webhook acceptance)
                DB::table('notifications')
                    ->whereIn('id', $notifIds)
                    ->whereNotIn('status', ['delivered', 'read'])
                    ->update([
                        'status' => 'submitted',
                        'provider_message_id' => $providerMsgId,
                        'response_code' => $lastResponseCode,
                        'response_body' => $lastResponseBody,
                        'error' => null,
                        'submitted_at' => now(),
                        'updated_at' => now(),
                    ]);

                $chunksSent++;
                DB::table('notification_batches')->where('id', $batch->id)->update([
                    'chunks_sent' => $chunksSent,
                    'response_code' => $lastResponseCode,
                    'response_body' => $lastResponseBody,
                    'error' => null,
                    'updated_at' => now(),
                ]);
            } catch (\Throwable $e) {
                // Mark chunk notifications as failed
                DB::table('notifications')
                    ->whereIn('id', $notifIds)
                    ->whereNotIn('status', ['submitted', 'delivered', 'read'])
                    ->update([
                        'status' => 'failed',
                        'response_code' => $lastResponseCode ?? 500,
                        'response_body' => $lastResponseBody,
                        'error' => substr($e->getMessage(), 0, 1000),
                        'updated_at' => now(),
                    ]);

                DB::table('notification_batches')->where('id', $batch->id)->update([
                    'status' => 'failed',
                    'response_code' => $lastResponseCode ?? 500,
                    'response_body' => $lastResponseBody,
                    'error' => substr($e->getMessage(), 0, 1000),
                    'updated_at' => now(),
                ]);

                throw $e;
            }
        }

        // If all chunks sent successfully, mark batch as submitted
        if ($chunksSent >= $totalChunks) {
            DB::table('notification_batches')->where('id', $batch->id)->update([
                'status' => 'submitted',
                'submitted_at' => now(),
                'response_code' => $lastResponseCode,
                'response_body' => $lastResponseBody,
                'error' => null,
                'updated_at' => now(),
            ]);
        }
    }
}
