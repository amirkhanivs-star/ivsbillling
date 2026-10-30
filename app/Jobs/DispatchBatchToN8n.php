<?php

namespace App\Jobs;

use App\Services\InvoicePayloadBuilder;
use App\Services\BatchDispatchLock;
use App\Services\PaidInvoiceEligibility;
use App\Services\Staging\DatabaseSafetyGuard;
use App\Services\WebhookSettingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class DispatchBatchToN8n implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;
    public int $maxExceptions = 3;
    public bool $failOnTimeout = true;
    public array $backoff = [60, 300, 900];

    public ?array $selectedNotificationIds = null;

    public function __construct(public string $batchId, ?array $selectedNotificationIds = null)
    {
        $this->selectedNotificationIds = $selectedNotificationIds;
    }


    public function retryUntil(): \DateTimeInterface
    {
        // Stored in the payload. A two-hour window bounds 30-second contention
        // releases below the database jobs.attempts unsigned-tinyint limit.
        return now()->addHours(2);
    }

    public function handle(): void
    {
        if ($this->queue === 'paid-future' && !config('services.n8n.paid_automatic_dispatch', true)) {
            if (!$this->job) {
                throw new RuntimeException('Automatic paid dispatch is disabled.');
            }
            $this->release(60);
            return;
        }
        $lock = app(BatchDispatchLock::class);
        if (!$lock->acquire($this->batchId)) {
            if (!$this->job) {
                throw new RuntimeException('Batch is busy; retry this explicit selection later.');
            }
            $this->release(30);
            return;
        }

        $nextJob = null;
        try {
            $nextJob = $this->dispatchLocked();
        } catch (\Throwable $e) {
            // Transport exception messages can contain secret URLs.
            $this->recover('Dispatch failed before completion ('.class_basename($e).').');
            throw $e;
        } finally {
            $lock->release();
        }

        if ($nextJob !== null) {
            dispatch($nextJob);
        }
    }

    public static function getChunkIntervalSeconds(): int
    {
        $seconds = config('services.n8n.batch_chunk_interval_seconds');
        if ($seconds !== null) {
            return max(0, (int) $seconds);
        }
        $minutes = config('services.n8n.batch_chunk_interval_minutes');
        if ($minutes !== null) {
            return max(0, ((int) $minutes) * 60);
        }
        return 1800; // default 30 minutes
    }

    private function dispatchLocked(): ?self
    {
        $startedAt = microtime(true);
        $batch = DB::table('notification_batches')->where('batch_id', $this->batchId)->first();
        if (!$batch || in_array($batch->status, ['submitted', 'completed', 'qa_suppressed'], true)) {
            return null;
        }

        if (($batch->webhook_type ?? 'monthly') === 'paid' && $this->selectedNotificationIds === null) {
            DB::table('notification_batches')->where('id', $batch->id)->update([
                'status' => 'blocked', 'error' => 'Legacy paid job has no explicit selection. Review and select invoices again.',
                'updated_at' => now(),
            ]);
            return null;
        }

        // The connection lock proves no other current worker owns this batch.
        // Abandoned HTTP intent is ambiguous and must never be blindly replayed.
        DB::table('notifications')->where('batch_id', $this->batchId)->where('status', 'processing')->update([
            'status' => 'blocked', 'error' => 'Previous worker stopped during dispatch. Reconcile n8n acceptance before resending.',
            'updated_at' => now(),
        ]);
        DB::table('notification_batches')->where('id', $batch->id)->update([
            'status' => 'processing', 'error' => null, 'updated_at' => now(),
        ]);

        // Safety guard: QA suppression
        if (config('services.notifications_disabled', false) || DatabaseSafetyGuard::isQaDatabase()) {
            DB::table('notification_batches')->where('id', $batch->id)->update([
                'status' => 'qa_suppressed',
                'error' => 'QA Portal: real notifications and external webhooks are disabled.',
                'updated_at' => now(),
            ]);
            $this->pendingNotifications()->update([
                'status' => 'qa_suppressed',
                'error' => 'QA Portal: real notifications and external webhooks are disabled.',
                'updated_at' => now(),
            ]);
            return null;
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
            $this->pendingNotifications()->update([
                'status' => 'blocked',
                'error' => $msg,
                'updated_at' => now(),
            ]);
            return null;
        }

        $previousChunksSent = (int) $batch->chunks_sent;
        $intervalSeconds = self::getChunkIntervalSeconds();

        // 30-minute interval rule:
        // Chunk 1 sends immediately when loaded into queue ($previousChunksSent === 0).
        // Chunk 2 sends 30 minutes after Chunk 1 was sent.
        // Remaining chunks send one every 30 minutes.
        if ($previousChunksSent > 0 && $intervalSeconds > 0) {
            $lastSentAt = null;
            if (isset($batch->last_chunk_at) && $batch->last_chunk_at) {
                $lastSentAt = \Illuminate\Support\Carbon::parse($batch->last_chunk_at);
            } else {
                $lastSubmitted = DB::table('notifications')
                    ->where('batch_id', $this->batchId)
                    ->where('status', 'submitted')
                    ->max('submitted_at');
                if ($lastSubmitted) {
                    $lastSentAt = \Illuminate\Support\Carbon::parse($lastSubmitted);
                }
            }

            if ($lastSentAt !== null) {
                $elapsed = now()->timestamp - $lastSentAt->timestamp;
                if ($elapsed < $intervalSeconds) {
                    $remainingDelay = $intervalSeconds - $elapsed;
                    DB::table('notification_batches')->where('id', $batch->id)->update([
                        'status' => 'queued',
                        'updated_at' => now(),
                    ]);
                    if ($this->job) {
                        $this->release(max(1, $remainingDelay));
                    }
                    return null;
                }
            }
        }

        // Fetch all notifications belonging to this batch that need dispatching
        $notifications = $this->pendingNotifications()->orderBy('chunk_index')->orderBy('id')->get();

        if ($notifications->isEmpty()) {
            $this->reconcileBatch();
            return null;
        }

        $payloadBuilder = app(InvoicePayloadBuilder::class);
        $invoiceEventType = ($webhookType === 'paid') ? 'invoice.paid' : 'invoice.monthly';
        $recipientPayloads = [];
        $validNotifications = [];

        foreach ($notifications as $notification) {
            if ($webhookType === 'paid' && (int) $notification->attempts > 0) {
                DB::table('notifications')->where('id', $notification->id)->update([
                    'status' => 'blocked', 'error' => 'Previous HTTP attempt requires acceptance reconciliation before resending.', 'updated_at' => now(),
                ]);
                continue;
            }
            // The dedicated queue is for explicitly selected paid notifications,
            // including imported invoices whose receipts meet the eligibility rules.
            if ($this->queue === 'paid-future' && $webhookType !== 'paid') {
                DB::table('notifications')->where('id', $notification->id)->update([
                    'status' => 'blocked', 'error' => 'This queue accepts paid invoice notifications only.', 'updated_at' => now(),
                ]);
                continue;
            }
            // Check if superseded
            if ($notification->is_superseded) {
                DB::table('notifications')->where('id', $notification->id)->update(['status' => 'superseded', 'updated_at' => now()]);
                continue;
            }

            $invoice = DB::table('invoices')->where('id', $notification->invoice_id)->first();
            if (!$invoice || in_array($invoice->status, ['void', 'voided'], true)) {
                DB::table('notifications')->where('id', $notification->id)->update([
                    'status' => 'superseded',
                    'is_superseded' => true,
                    'error' => 'Invoice was voided or superseded before dispatch.',
                    'updated_at' => now(),
                ]);
                continue;
            }

            $family = DB::table('families')->where('id', $invoice->family_id)->first();
            $channels = json_decode($batch->channels ?: '["whatsapp"]', true, 512, JSON_THROW_ON_ERROR);
            $eligibilityError = WebhookSettingService::evaluateContactEligibility($family, $channels);
            $isIntegrityHold = false;
            if ($webhookType === 'monthly') {
                $approverId = $notification->approved_by ?? $batch->approved_by ?? null;
                $approver = $approverId ? DB::table('users')->where('id', $approverId)->first() : null;
                $consolidatedBuilder = app(\App\Services\ConsolidatedInvoiceBuilder::class);
                $elig = $consolidatedBuilder->evaluateFamilyDispatchEligibility(
                    (int) $invoice->family_id,
                    $batch->billing_period ?? null,
                    $approver
                );
                if (!$elig['can_dispatch']) {
                    $eligibilityError = $elig['block_reason']
                        ?? "Data Integrity Issue: Invoice {$invoice->invoice_number} has an unverified consolidated payable balance. Flagged for staff review.";
                    $isIntegrityHold = true;
                }
            }
            if ($webhookType === 'paid' && !PaidInvoiceEligibility::eligible((int) $notification->invoice_id)) {
                $eligibilityError = 'Paid invoice is not fully paid or lacks a qualifying positive, unreversed receipt (portal provenance or historical cutoff).';
            }
            if ($eligibilityError !== null) {
                DB::table('notifications')->where('id', $notification->id)->update([
                    'status' => 'blocked', 'error' => $eligibilityError, 'updated_at' => now(),
                ]);
                if ($isIntegrityHold) {
                    DB::table('audit_logs')->insert([
                        'actor_id' => $notification->approved_by ?? $batch->approved_by ?? null,
                        'action' => 'notification.blocked_financial_integrity',
                        'subject_type' => 'notification',
                        'subject_id' => $notification->id,
                        'metadata' => json_encode([
                            'batch_id' => $this->batchId,
                            'invoice_id' => $invoice->id,
                            'invoice_number' => $invoice->invoice_number,
                            'family_id' => $invoice->family_id,
                            'reason' => $eligibilityError,
                        ]),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                continue;
            }

            // Build individual invoice payload
            $itemPayload = $payloadBuilder->build($notification, $invoice, $invoiceEventType);
            $recipientPayloads[] = $itemPayload;
            $validNotifications[] = $notification;
        }

        if (empty($recipientPayloads)) {
            $this->reconcileBatch();
            return null;
        }

        // Chunking configuration: maximum 50 recipients per chunk
        $chunkSize = max(1, min(50, (int) config('services.n8n.batch_chunk_size', 50)));
        $chunks = array_chunk($recipientPayloads, $chunkSize);
        $notificationChunks = array_chunk($validNotifications, $chunkSize);
        $totalChunksInPending = count($chunks);

        $otherQueued = DB::table('notifications')->where('batch_id', $this->batchId)
            ->whereIn('status', ['queued', 'failed'])
            ->whereNotIn('id', array_map(fn ($n) => $n->id, $validNotifications))->count();
        $totalChunksForBatch = max((int) $batch->chunks_total, $previousChunksSent + $totalChunksInPending + (int) ceil($otherQueued / $chunkSize));

        DB::table('notification_batches')->where('id', $batch->id)->update([
            'chunks_total' => $totalChunksForBatch,
            'updated_at' => now(),
        ]);

        $approverUser = $batch->approved_by ? DB::table('users')->where('id', $batch->approved_by)->first() : null;
        $approverName = $approverUser?->name ?? 'Authorized Staff';

        $channels = !empty($batch->channels) ? json_decode($batch->channels, true) : ['whatsapp'];

        // Process strictly ONE chunk (max 50 recipients)
        $chunkPayloads = $chunks[0];
        $notifChunk = $notificationChunks[0];
        $chunkIndex = (int) ($notifChunk[0]->chunk_index ?: ($previousChunksSent + 1));

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
            'total_chunks' => $totalChunksForBatch,
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

        foreach ($notifChunk as $pending) {
            if ($webhookType === 'paid' && !PaidInvoiceEligibility::eligible((int) $pending->invoice_id)) {
                throw new RuntimeException('Paid eligibility changed during payload preparation.');
            }
        }
        // Persist HTTP intent first; a killed worker must not cause blind replay.
        // Update notifications in chunk with payload & attempt
        $notifIds = array_map(fn($n) => $n->id, $notifChunk);
        DB::table('notifications')->whereIn('id', $notifIds)->update([
            'status' => 'processing',
            'chunk_index' => $chunkIndex,
            'attempts' => DB::raw('attempts + 1'),
            'last_attempt_at' => now(),
            'payload' => $body,
            'updated_at' => now(),
        ]);

        try {
            $lastResponseCode = null;
            $lastResponseBody = null;
            $response = Http::connectTimeout(10)->timeout(30)
                ->withHeaders($headers)
                ->withBody($body, 'application/json')
                ->post($setting->url);

            $lastResponseCode = $response->status();
            $lastResponseBody = substr($response->body(), 0, 1000);

            if (!$response->successful()) {
                throw new RuntimeException("Webhook HTTP {$lastResponseCode}");
            }

            $providerMsgId = $response->json('message_id') ?? $response->json('batch_id');
            $sentAt = now();

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
                    'submitted_at' => $sentAt,
                    'updated_at' => $sentAt,
                ]);

            $newChunksSent = $previousChunksSent + 1;
            $batchUpdates = [
                'chunks_sent' => $newChunksSent,
                'chunks_total' => $totalChunksForBatch,
                'response_code' => $lastResponseCode,
                'response_body' => $lastResponseBody,
                'error' => null,
                'updated_at' => $sentAt,
            ];
            if (Schema::hasColumn('notification_batches', 'last_chunk_at')) {
                $batchUpdates['last_chunk_at'] = $sentAt;
            }
            DB::table('notification_batches')->where('id', $batch->id)->update($batchUpdates);
        } catch (\Throwable $e) {
            // Preserve uncertainty for paid sends; retain the existing monthly retry behavior.
            DB::table('notifications')
                ->whereIn('id', $notifIds)
                ->whereNotIn('status', ['submitted', 'delivered', 'read'])
                ->update([
                    'status' => $webhookType === 'paid' ? 'blocked' : 'failed',
                    'response_code' => $lastResponseCode,
                    'response_body' => $lastResponseBody,
                    'error' => $webhookType === 'paid' ? 'HTTP acceptance uncertain; reconcile n8n before retrying ('.class_basename($e).').' : 'Webhook HTTP '.($lastResponseCode ?? 'connection failure'),
                    'updated_at' => now(),
                ]);

            DB::table('notification_batches')->where('id', $batch->id)->update([
                'status' => $webhookType === 'paid' ? 'blocked' : 'failed',
                'response_code' => $lastResponseCode,
                'response_body' => $lastResponseBody,
                'error' => $webhookType === 'paid' ? 'HTTP acceptance uncertain; reconcile n8n before retrying ('.class_basename($e).').' : 'Webhook HTTP '.($lastResponseCode ?? 'connection failure'),
                'updated_at' => now(),
            ]);

            throw $e;
        }

        // Check if additional pending chunks remain for this batch
        $hasMorePending = $this->pendingNotifications()->exists();
        if ($hasMorePending) {
            // Keep batch status as queued while remaining chunks wait for their interval
            DB::table('notification_batches')->where('id', $batch->id)->update([
                'status' => 'queued',
                'updated_at' => now(),
            ]);

            $targetQueue = $this->queue ?: ($webhookType === 'paid' ? 'paid-future' : 'notifications');
            $nextJob = (new self($this->batchId, $this->selectedNotificationIds))->onQueue($targetQueue);
            if ($intervalSeconds > 0) {
                $nextJob->delay($sentAt->copy()->addSeconds($intervalSeconds));
            }
            return $nextJob;
        }

        $this->reconcileBatch();
        return null;
    }

    private function pendingNotifications(): \Illuminate\Database\Query\Builder
    {
        return DB::table('notifications')->where('batch_id', $this->batchId)
            ->where(function ($q) {
                $q->whereIn('status', ['queued', 'failed']);
                if ($this->selectedNotificationIds !== null) {
                    $q->orWhere(fn ($blocked) => $blocked->where('status', 'blocked')->where('attempts', 0));
                }
            })
            ->when($this->selectedNotificationIds !== null, fn ($q) => $q->whereIn('id', $this->selectedNotificationIds));
    }

    private function recover(string $message): void
    {
        DB::table('notifications')->where('batch_id', $this->batchId)->where('status', 'processing')->update([
            'status' => 'blocked', 'error' => 'Worker stopped with HTTP acceptance unknown; reconcile before resending.', 'updated_at' => now(),
        ]);
        $this->pendingNotifications()->where('attempts', 0)->update(['status' => 'failed', 'error' => $message, 'updated_at' => now()]);
        $recordedError = DB::table('notification_batches')->where('batch_id', $this->batchId)->value('error');
        $this->reconcileBatch($recordedError ?: $message);
    }

    private function reconcileBatch(?string $error = null): void
    {
        $statuses = DB::table('notifications')->where('batch_id', $this->batchId)->pluck('status');
        $status = match (true) {
            $statuses->contains('processing') => 'processing',
            $statuses->contains('queued') => 'queued',
            $statuses->contains('failed') => 'failed',
            $statuses->contains('blocked') => 'blocked',
            default => 'submitted',
        };
        DB::table('notification_batches')->where('batch_id', $this->batchId)->update([
            'status' => $status, 'error' => $error,
            'submitted_at' => $status === 'submitted' ? now() : null, 'updated_at' => now(),
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        // Laravel invokes this on a fresh instance. Inspect persisted intent while
        // holding the lock so terminal recovery cannot modify an active worker.
        $lock = app(BatchDispatchLock::class);
        if (!$lock->acquire($this->batchId)) {
            return;
        }
        try {
            $batch = DB::table('notification_batches')->where('batch_id', $this->batchId)->first();
            if (!$batch || in_array($batch->status, ['submitted', 'completed', 'qa_suppressed'], true)) {
                return;
            }
            if (($batch->webhook_type ?? 'monthly') === 'paid' && $this->selectedNotificationIds === null) {
                return;
            }
            $hasPendingSelection = DB::table('notifications')->where('batch_id', $this->batchId)
                ->whereIn('status', ['queued', 'failed', 'processing'])
                ->when($this->selectedNotificationIds !== null, fn ($q) => $q->whereIn('id', $this->selectedNotificationIds))->exists();
            if ($hasPendingSelection) {
                $this->recover('Dispatch job exhausted retries or worker timed out. Review before retrying.');
            }
        } finally {
            $lock->release();
        }
    }
}
