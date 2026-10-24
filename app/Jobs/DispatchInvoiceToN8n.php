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
use RuntimeException;

class DispatchInvoiceToN8n implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 45;
    public array $backoff = [60, 300, 900];

    public function __construct(public int $notificationId) {}

    public function handle(): void
    {
        $notification = DB::table('notifications')->where('id', $this->notificationId)->first();
        if (!$notification || in_array($notification->status, ['submitted', 'delivered', 'read'], true)) {
            return;
        }

        if (config('services.notifications_disabled', false) || DatabaseSafetyGuard::isQaDatabase()) {
            DB::table('notifications')->where('id', $notification->id)->update([
                'status' => 'qa_suppressed',
                'error' => 'QA Portal: real notifications and external webhooks are disabled.',
                'updated_at' => now(),
            ]);
            return;
        }

        $invoice = DB::table('invoices')->where('id', $notification->invoice_id)->first();
        $family = $invoice ? DB::table('families')->where('id', $invoice->family_id)->first() : null;
        if (!$invoice || !$family) {
            throw new RuntimeException('Missing invoice or family for notification delivery.');
        }

        // Determine webhook routing
        $webhookType = $notification->webhook_type ?? ($notification->kind === 'paid' ? 'paid' : 'monthly');
        $eventType = $notification->event_type ?? ($webhookType === 'paid' ? 'invoice.paid' : 'invoice.monthly');

        $webhookService = app(WebhookSettingService::class);
        $setting = $webhookService->getSettings($webhookType);

        if (!$setting || !$setting->is_enabled || empty($setting->url)) {
            DB::table('notifications')->where('id', $notification->id)->update([
                'status' => 'blocked',
                'error' => ucfirst($webhookType) . ' webhook is disabled or not configured in Settings → Webhooks.',
                'updated_at' => now(),
            ]);
            return;
        }

        // Channels & Consent verification
        $channels = [];
        if (!empty($notification->channels)) {
            $decoded = is_string($notification->channels) ? json_decode($notification->channels, true) : $notification->channels;
            if (is_array($decoded)) {
                $channels = $decoded;
            }
        }
        if (empty($channels) && !empty($notification->channel)) {
            $channels = explode(',', $notification->channel);
        }

        $hasValidChannel = false;
        if (in_array('whatsapp', $channels, true) && $family->whatsapp && $family->contact_verified && $family->messaging_opt_in) {
            $hasValidChannel = true;
        }
        if (in_array('email', $channels, true) && $family->email && $family->contact_verified) {
            $hasValidChannel = true;
        }

        if (!$hasValidChannel) {
            DB::table('notifications')->where('id', $notification->id)->update([
                'status' => 'blocked',
                'error' => 'Contact missing/unverified or WhatsApp permission absent',
                'updated_at' => now(),
            ]);
            return;
        }

        // Build structured JSON payload
        $payloadBuilder = app(InvoicePayloadBuilder::class);
        $payload = $payloadBuilder->build($notification, $invoice, $eventType);
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        DB::table('notifications')->where('id', $notification->id)->update([
            'attempts' => DB::raw('attempts + 1'),
            'last_attempt_at' => now(),
            'payload' => $body,
            'updated_at' => now(),
        ]);

        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'IQRA-Virtual-School-Billing/1.0',
        ];

        $secret = $setting->secret;
        if (!empty($secret)) {
            $headers['X-IQRA-Signature'] = hash_hmac('sha256', $body, $secret);
        }

        $statusCode = null;
        $responseBody = null;

        try {
            $response = Http::timeout(25)
                ->withHeaders($headers)
                ->withBody($body, 'application/json')
                ->post($setting->url);

            $statusCode = $response->status();
            $responseBody = substr($response->body(), 0, 1000);

            if (!$response->successful()) {
                throw new RuntimeException("Webhook HTTP {$statusCode}");
            }

            DB::table('notifications')
                ->where('id', $notification->id)
                ->whereNotIn('status', ['delivered', 'read'])
                ->update([
                    'status' => 'submitted',
                    'provider_message_id' => $response->json('message_id'),
                    'response_code' => $statusCode,
                    'response_body' => $responseBody,
                    'error' => null,
                    'submitted_at' => now(),
                    'updated_at' => now(),
                ]);
        } catch (\Throwable $e) {
            DB::table('notifications')
                ->where('id', $notification->id)
                ->update([
                    'status' => 'failed',
                    'response_code' => $statusCode ?? 500,
                    'response_body' => $responseBody,
                    'error' => substr($e->getMessage(), 0, 1000),
                    'updated_at' => now(),
                ]);

            throw $e;
        }
    }
}
