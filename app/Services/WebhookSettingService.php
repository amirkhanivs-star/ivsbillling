<?php

namespace App\Services;

use App\Models\WebhookSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class WebhookSettingService
{
    /**
     * Retrieve settings for a specific webhook type ('monthly' or 'paid').
     */
    public function getSettings(string $type): WebhookSetting
    {
        $type = strtolower(trim($type));
        return WebhookSetting::firstOrCreate(
            ['webhook_type' => $type],
            [
                'url' => null,
                'secret' => null,
                'is_enabled' => false,
            ]
        );
    }

    /**
     * Update settings for a specific webhook type.
     */
    public function updateSettings(string $type, array $data, int $userId): WebhookSetting
    {
        $type = strtolower(trim($type));
        $setting = $this->getSettings($type);

        $url = !empty($data['url']) ? trim((string)$data['url']) : null;
        if ($url !== null && $url !== '') {
            $urlError = self::validateWebhookUrl($url, $type);
            if ($urlError) {
                throw new RuntimeException($urlError);
            }
        } else {
            $url = null;
        }

        $setting->url = $url;
        $setting->is_enabled = !empty($data['is_enabled']);
        $setting->updated_by = $userId;

        // Only update secret if explicitly supplied and non-empty
        if (isset($data['secret']) && trim((string)$data['secret']) !== '') {
            $setting->secret = trim((string)$data['secret']);
        }

        $setting->save();

        // Audit log (NEVER store secrets in audit logs or metadata)
        DB::table('audit_logs')->insert([
            'actor_id' => $userId,
            'action' => 'webhook_settings.update',
            'subject_type' => 'webhook_setting',
            'subject_id' => $setting->id,
            'metadata' => json_encode([
                'webhook_type' => $type,
                'url' => $url,
                'is_enabled' => $setting->is_enabled,
                'has_secret' => !empty($setting->secret),
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $setting;
    }

    /**
     * Test connection to the configured webhook endpoint.
     */
    public function testConnection(string $type, int $userId): array
    {
        $type = strtolower(trim($type));
        $setting = $this->getSettings($type);

        if (empty($setting->url)) {
            $result = [
                'success' => false,
                'message' => 'Webhook URL is not configured. Save a valid URL first.',
                'code' => null,
            ];
            $this->recordTestResult($setting, $result);
            return $result;
        }

        $urlError = self::validateWebhookUrl($setting->url, $type);
        if ($urlError) {
            $result = [
                'success' => false,
                'message' => $urlError,
                'code' => null,
            ];
            $this->recordTestResult($setting, $result);
            return $result;
        }

        $payload = [
            'event' => 'test.connection',
            'event_type' => 'test.connection',
            'event_id' => (string) Str::uuid(),
            'webhook_type' => $type,
            'timestamp' => now()->toIso8601String(),
            'source_system' => 'IQRA Virtual School',
            'message' => "Connection test from IQRA Virtual School billing portal ({$type} webhook)",
        ];

        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'IQRA-Virtual-School-Webhook/1.0',
        ];

        $secret = $setting->secret;
        if (!empty($secret)) {
            $headers['X-IQRA-Signature'] = hash_hmac('sha256', $body, $secret);
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders($headers)
                ->withBody($body, 'application/json')
                ->post($setting->url);

            $isSuccess = $response->successful();
            $statusCode = $response->status();
            $responseMsg = $isSuccess
                ? "HTTP {$statusCode} OK: Connection established successfully."
                : "HTTP {$statusCode}: Server returned non-2xx status.";

            $result = [
                'success' => $isSuccess,
                'message' => $responseMsg,
                'code' => $statusCode,
            ];
        } catch (\Throwable $e) {
            $result = [
                'success' => false,
                'message' => 'Connection failed: ' . substr($e->getMessage(), 0, 300),
                'code' => null,
            ];
        }

        $this->recordTestResult($setting, $result);

        // Audit log test attempt
        DB::table('audit_logs')->insert([
            'actor_id' => $userId,
            'action' => 'webhook_settings.test',
            'subject_type' => 'webhook_setting',
            'subject_id' => $setting->id,
            'metadata' => json_encode([
                'webhook_type' => $type,
                'success' => $result['success'],
                'code' => $result['code'],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $result;
    }

    /**
     * Record connection test outcome in webhook_settings table.
     */
    private function recordTestResult(WebhookSetting $setting, array $result): void
    {
        $setting->last_test_at = now();
        $setting->last_test_status = $result['success'] ? 'success' : 'failed';
        $setting->last_test_message = $result['message'];
        $setting->last_test_response_code = $result['code'] ?? null;
        $setting->save();
    }

    /**
     * Validate webhook URL format, scheme, and SSRF security.
     * Returns null if valid (or empty), or a descriptive error message if invalid.
     */
    public static function validateWebhookUrl(?string $url, string $type = 'webhook'): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $url = trim($url);

        $parsed = @parse_url($url);
        if ($parsed === false || empty($parsed['scheme'])) {
            return "The " . ucfirst($type) . " webhook URL must begin with https:// (or http:// for testing).";
        }

        $scheme = strtolower($parsed['scheme']);
        if (!in_array($scheme, ['https', 'http'], true)) {
            return "The " . ucfirst($type) . " webhook URL must begin with https:// (or http:// for testing).";
        }

        if (empty($parsed['host'])) {
            return "The " . ucfirst($type) . " webhook URL format is invalid. Please provide a full URL with domain and path.";
        }

        $hasValidFormat = Str::isUrl($url, ['http', 'https']) || (bool) filter_var($url, FILTER_VALIDATE_URL);
        if (!$hasValidFormat) {
            return "The " . ucfirst($type) . " webhook URL must be a valid URL (including /webhook-test/... or /webhook/... paths).";
        }

        $securityError = self::validateUrlSecurity($url);
        if ($securityError) {
            return "The " . ucfirst($type) . " webhook URL: " . $securityError;
        }

        return null;
    }

    /**
     * Validate URL security and protect against SSRF and insecure HTTP in production.
     */
    public static function validateUrlSecurity(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        $parsed = parse_url($url);
        if (!$parsed || empty($parsed['scheme']) || empty($parsed['host'])) {
            return 'Invalid webhook URL format.';
        }

        $scheme = strtolower($parsed['scheme']);
        $host = strtolower($parsed['host']);

        if (!in_array($scheme, ['https', 'http'], true)) {
            return 'Webhook URL must use HTTP or HTTPS.';
        }

        $allowPrivate = config('services.n8n.allow_private_network', env('ALLOW_PRIVATE_WEBHOOK_URLS', false));

        // In production, require HTTPS unless explicitly allowed private network
        if (app()->environment('production') && $scheme !== 'https') {
            if (!$allowPrivate) {
                return 'Webhook URL must use HTTPS in production.';
            }
        }

        // SSRF protection: block loopback and unsafe internal IP ranges
        if (!$allowPrivate) {
            if (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)) {
                return 'Webhook URL cannot point to localhost or internal loopback.';
            }

            // Check if host is a literal IP
            if (filter_var($host, FILTER_VALIDATE_IP)) {
                if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return 'Webhook URL cannot point to a private or reserved IP address.';
                }
            }
        }

        return null;
    }

    /**
     * Evaluate recipient family contact verification and messaging consent.
     * Returns null if eligible, or a specific, actionable failure reason string if blocked.
     */
    public static function evaluateContactEligibility(?object $family, array $channels): ?string
    {
        if (!$family) {
            return 'Family record not found';
        }

        $wantsWhatsapp = in_array('whatsapp', $channels, true);
        $wantsEmail = in_array('email', $channels, true);

        $whatsappVerified = !empty($family->whatsapp_verified) || !empty($family->contact_verified);
        $whatsappValid = $wantsWhatsapp
            && !empty($family->whatsapp)
            && $whatsappVerified
            && !empty($family->messaging_opt_in);

        // Explicit email verification required; carrying forward WhatsApp authorizations does NOT grant email verification
        $emailVerified = !empty($family->email_verified);
        $emailValid = $wantsEmail
            && !empty($family->email)
            && $emailVerified;

        if ($whatsappValid || $emailValid) {
            return null; // Eligible for delivery
        }

        // Determine specific failure reasons
        if ($wantsWhatsapp && !$wantsEmail) {
            if (empty($family->whatsapp)) {
                return 'WhatsApp missing';
            }
            if (!$whatsappVerified) {
                return 'Unverified contact';
            }
            if (empty($family->messaging_opt_in)) {
                return 'Consent not recorded (WhatsApp permission absent)';
            }
        }

        if ($wantsEmail && !$wantsWhatsapp) {
            if (empty($family->email)) {
                return 'Email missing';
            }
            if (!$emailVerified) {
                return 'Unverified contact';
            }
        }

        if ($wantsWhatsapp && $wantsEmail) {
            $parts = [];
            if (empty($family->whatsapp)) {
                $parts[] = 'WhatsApp missing';
            } elseif (!$whatsappVerified) {
                $parts[] = 'WhatsApp unverified';
            } elseif (empty($family->messaging_opt_in)) {
                $parts[] = 'WhatsApp consent not recorded';
            }

            if (empty($family->email)) {
                $parts[] = 'Email missing';
            } elseif (!$emailVerified) {
                $parts[] = 'Email unverified';
            }

            return implode(', ', $parts) ?: 'Unverified contact or consent absent';
        }

        return 'No delivery channel selected';
    }
}

