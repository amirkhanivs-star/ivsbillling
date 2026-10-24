<?php

namespace App\Http\Controllers;

use App\Services\WebhookSettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WebhookSettingsController
{
    public function __construct(protected WebhookSettingService $webhookService) {}

    /**
     * Display the Webhook Settings dashboard for Super Admin.
     */
    public function index(Request $request)
    {
        abort_unless($request->user()->role === 'super_admin', 403, 'Unauthorized. Super Admin access required.');

        $monthlySetting = $this->webhookService->getSettings('monthly');
        $paidSetting = $this->webhookService->getSettings('paid');

        return view('settings.webhooks', compact('monthlySetting', 'paidSetting'));
    }

    /**
     * Save settings for Monthly or Paid webhook endpoint independently.
     */
    public function update(Request $request, string $type)
    {
        abort_unless($request->user()->role === 'super_admin', 403, 'Unauthorized. Super Admin access required.');
        $type = strtolower(trim($type));
        abort_unless(in_array($type, ['monthly', 'paid'], true), 404);

        // Normalize / trim input URL
        $rawUrl = $request->input('url', $request->input("{$type}_url"));
        $cleanUrl = is_string($rawUrl) ? trim($rawUrl) : null;
        if ($cleanUrl === '') {
            $cleanUrl = null;
        }

        $request->merge([
            'url' => $cleanUrl,
            'webhook_type' => $type,
        ]);

        $validator = Validator::make($request->all(), [
            'url' => [
                'nullable',
                'string',
                'max:2048',
                function ($attribute, $value, $fail) use ($type) {
                    $error = WebhookSettingService::validateWebhookUrl($value, $type);
                    if ($error) {
                        $fail($error);
                    }
                },
            ],
            'secret' => 'nullable|string|max:255',
            'is_enabled' => 'nullable|boolean',
        ]);

        $validator->setAttributeNames([
            'url' => ucfirst($type) . ' Webhook URL',
        ]);

        if ($validator->fails()) {
            return redirect()->route('settings.webhooks')
                ->withErrors($validator)
                ->withInput()
                ->with('webhook_target_error', $type);
        }

        try {
            $this->webhookService->updateSettings($type, [
                'url' => $cleanUrl,
                'secret' => $request->input('secret'),
                'is_enabled' => $request->boolean('is_enabled'),
            ], $request->user()->id);

            return redirect()->route('settings.webhooks')
                ->with('notice', ucfirst($type) . ' Invoice Webhook settings updated successfully.')
                ->with('saved_webhook_type', $type);
        } catch (\Throwable $e) {
            return redirect()->route('settings.webhooks')
                ->withErrors(['url' => $e->getMessage()])
                ->withInput()
                ->with('webhook_target_error', $type)
                ->with('error', 'Failed to update ' . ucfirst($type) . ' webhook settings: ' . $e->getMessage());
        }
    }

    /**
     * Test connection to a configured webhook endpoint using a harmless ping payload.
     */
    public function test(Request $request, string $type)
    {
        abort_unless($request->user()->role === 'super_admin', 403, 'Unauthorized. Super Admin access required.');
        $type = strtolower(trim($type));
        abort_unless(in_array($type, ['monthly', 'paid'], true), 404);

        $result = $this->webhookService->testConnection($type, $request->user()->id);

        $statusMsg = $result['success']
            ? "Test Connection to " . ucfirst($type) . " Webhook SUCCESSFUL: " . $result['message']
            : "Test Connection to " . ucfirst($type) . " Webhook FAILED: " . $result['message'];

        return redirect()->route('settings.webhooks')
            ->with($result['success'] ? 'notice' : 'error', $statusMsg)
            ->with('tested_webhook_type', $type);
    }
}
