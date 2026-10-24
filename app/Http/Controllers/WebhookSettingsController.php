<?php

namespace App\Http\Controllers;

use App\Services\WebhookSettingService;
use Illuminate\Http\Request;

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
     * Save settings for Monthly or Paid webhook endpoint.
     */
    public function update(Request $request, string $type)
    {
        abort_unless($request->user()->role === 'super_admin', 403, 'Unauthorized. Super Admin access required.');
        abort_unless(in_array($type, ['monthly', 'paid'], true), 404);

        $data = $request->validate([
            'url' => 'nullable|url|max:500',
            'secret' => 'nullable|string|max:255',
            'is_enabled' => 'nullable|boolean',
        ]);

        try {
            $this->webhookService->updateSettings($type, $data, $request->user()->id);
            return redirect()->route('settings.webhooks')
                ->with('notice', ucfirst($type) . ' Invoice Webhook settings updated successfully.');
        } catch (\Throwable $e) {
            return redirect()->route('settings.webhooks')
                ->with('error', 'Failed to update ' . ucfirst($type) . ' webhook settings: ' . $e->getMessage());
        }
    }

    /**
     * Test connection to a configured webhook endpoint using a harmless ping payload.
     */
    public function test(Request $request, string $type)
    {
        abort_unless($request->user()->role === 'super_admin', 403, 'Unauthorized. Super Admin access required.');
        abort_unless(in_array($type, ['monthly', 'paid'], true), 404);

        $result = $this->webhookService->testConnection($type, $request->user()->id);

        if ($result['success']) {
            return redirect()->route('settings.webhooks')
                ->with('notice', "Test Connection to " . ucfirst($type) . " Webhook SUCCESSFUL: " . $result['message']);
        }

        return redirect()->route('settings.webhooks')
            ->with('error', "Test Connection to " . ucfirst($type) . " Webhook FAILED: " . $result['message']);
    }
}
