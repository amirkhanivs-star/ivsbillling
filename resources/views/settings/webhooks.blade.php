@extends('layouts.app')
@section('title', 'Webhook Settings')
@section('content')
<link rel="stylesheet" href="{{ asset('assets/iqra-challan.css') }}">
<style>
.webhook-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));
    gap: 20px;
    margin-top: 15px;
}
.webhook-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    display: flex;
    flex-direction: column;
}
.webhook-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #f1f5f9;
    padding-bottom: 12px;
    margin-bottom: 16px;
}
.webhook-title {
    font-size: 16px;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
}
.badge-status {
    font-size: 11px;
    font-weight: 600;
    padding: 3px 8px;
    border-radius: 12px;
    text-transform: uppercase;
}
.badge-enabled { background: #dcfce7; color: #166534; }
.badge-disabled { background: #f1f5f9; color: #64748b; }
.badge-success { background: #dcfce7; color: #166534; }
.badge-failed { background: #fee2e2; color: #991b1b; }
.form-group {
    margin-bottom: 14px;
}
.form-group label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: #475569;
    margin-bottom: 5px;
}
.test-section {
    margin-top: auto;
    padding-top: 16px;
    border-top: 1px dashed #e2e8f0;
}
.test-result-box {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 10px 12px;
    margin-top: 10px;
    font-size: 12px;
}
</style>

<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
        <div>
            <h2>Settings · Outbound Webhooks</h2>
            <p class="muted" style="margin:4px 0 0 0;">
                Configure independent n8n webhook destinations for monthly fee notices and paid invoice confirmations.
                Sensitive signing secrets are encrypted with Laravel application encryption and never exposed.
            </p>
        </div>
        <span class="badge" style="background:#7367f0; color:#fff; font-size:11px; padding:4px 10px; border-radius:4px; font-weight:600;">Super Admin Only</span>
    </div>

    <div class="webhook-grid">
        {{-- PANEL 1: MONTHLY INVOICE WEBHOOK --}}
        <div class="webhook-card">
            <div class="webhook-card-header">
                <div>
                    <h3 class="webhook-title">1. Monthly Invoice Webhook</h3>
                    <div style="font-size:11px; color:#64748b; margin-top:2px;">Event: <code>invoice.monthly</code> (Unpaid & Partial)</div>
                </div>
                <span class="badge-status {{ $monthlySetting->is_enabled ? 'badge-enabled' : 'badge-disabled' }}">
                    {{ $monthlySetting->is_enabled ? 'Active / Enabled' : 'Disabled' }}
                </span>
            </div>

            <form method="POST" action="{{ route('settings.webhooks.update', 'monthly') }}">
                @csrf
                <div class="form-group">
                    <label for="monthly_url">Webhook Destination URL</label>
                    <input type="url" id="monthly_url" name="url" class="input" style="width:100%; box-sizing:border-box;"
                           placeholder="https://n8n.your-domain.com/webhook/iqra-monthly-invoices"
                           value="{{ old('url', $monthlySetting->url) }}">
                    <div class="muted" style="font-size:11px; margin-top:4px;">Must use HTTPS in production. Excludes internal loopback destinations.</div>
                </div>

                <div class="form-group">
                    <label for="monthly_secret">HMAC Signing Secret</label>
                    <input type="password" id="monthly_secret" name="secret" class="input" style="width:100%; box-sizing:border-box;"
                           placeholder="{{ !empty($monthlySetting->secret) ? '•••••••••••••••• (Configured — leave blank to keep unchanged)' : 'Enter shared webhook signing secret' }}"
                           autocomplete="new-password">
                    <div class="muted" style="font-size:11px; margin-top:4px;">Transmitted in <code>X-IQRA-Signature</code> header as SHA-256 HMAC of raw body.</div>
                </div>

                <div class="form-group" style="margin-top:10px;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" name="is_enabled" value="1" @checked(old('is_enabled', $monthlySetting->is_enabled))>
                        <span>Enable Monthly Invoice Webhook Delivery</span>
                    </label>
                </div>

                <button type="submit" class="btn purple" style="width:100%; margin-top:6px;">Save Monthly Settings</button>
            </form>

            <div class="test-section">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <strong style="font-size:12px; color:#1e293b;">Connection Verification:</strong>
                    <form method="POST" action="{{ route('settings.webhooks.test', 'monthly') }}" style="margin:0;">
                        @csrf
                        <button type="submit" class="btn small" style="background:#f1f5f9; color:#334155; border:1px solid #cbd5e1;">Test Connection</button>
                    </form>
                </div>

                <div class="test-result-box">
                    @if($monthlySetting->last_test_at)
                        <div style="display:flex; justify-content:space-between; margin-bottom:4px;">
                            <span><strong>Status:</strong>
                                <span class="badge-status {{ $monthlySetting->last_test_status === 'success' ? 'badge-success' : 'badge-failed' }}">
                                    {{ strtoupper($monthlySetting->last_test_status) }}
                                </span>
                            </span>
                            <span class="muted">{{ $monthlySetting->last_test_at->format('Y-m-d H:i:s') }}</span>
                        </div>
                        <div style="color:#475569; word-break:break-all;">{{ $monthlySetting->last_test_message }}</div>
                    @else
                        <span class="muted">No connection tests have been performed yet for this endpoint.</span>
                    @endif
                </div>
            </div>
        </div>

        {{-- PANEL 2: PAID INVOICE WEBHOOK --}}
        <div class="webhook-card">
            <div class="webhook-card-header">
                <div>
                    <h3 class="webhook-title">2. Paid Invoice Webhook</h3>
                    <div style="font-size:11px; color:#64748b; margin-top:2px;">Event: <code>invoice.paid</code> (Settled Invoices)</div>
                </div>
                <span class="badge-status {{ $paidSetting->is_enabled ? 'badge-enabled' : 'badge-disabled' }}">
                    {{ $paidSetting->is_enabled ? 'Active / Enabled' : 'Disabled' }}
                </span>
            </div>

            <form method="POST" action="{{ route('settings.webhooks.update', 'paid') }}">
                @csrf
                <div class="form-group">
                    <label for="paid_url">Webhook Destination URL</label>
                    <input type="url" id="paid_url" name="url" class="input" style="width:100%; box-sizing:border-box;"
                           placeholder="https://n8n.your-domain.com/webhook/iqra-paid-invoices"
                           value="{{ old('url', $paidSetting->url) }}">
                    <div class="muted" style="font-size:11px; margin-top:4px;">Dedicated endpoint for fully settled fee receipt notices.</div>
                </div>

                <div class="form-group">
                    <label for="paid_secret">HMAC Signing Secret</label>
                    <input type="password" id="paid_secret" name="secret" class="input" style="width:100%; box-sizing:border-box;"
                           placeholder="{{ !empty($paidSetting->secret) ? '•••••••••••••••• (Configured — leave blank to keep unchanged)' : 'Enter shared webhook signing secret' }}"
                           autocomplete="new-password">
                    <div class="muted" style="font-size:11px; margin-top:4px;">Transmitted in <code>X-IQRA-Signature</code> header as SHA-256 HMAC of raw body.</div>
                </div>

                <div class="form-group" style="margin-top:10px;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" name="is_enabled" value="1" @checked(old('is_enabled', $paidSetting->is_enabled))>
                        <span>Enable Paid Invoice Webhook Delivery</span>
                    </label>
                </div>

                <button type="submit" class="btn purple" style="width:100%; margin-top:6px;">Save Paid Settings</button>
            </form>

            <div class="test-section">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <strong style="font-size:12px; color:#1e293b;">Connection Verification:</strong>
                    <form method="POST" action="{{ route('settings.webhooks.test', 'paid') }}" style="margin:0;">
                        @csrf
                        <button type="submit" class="btn small" style="background:#f1f5f9; color:#334155; border:1px solid #cbd5e1;">Test Connection</button>
                    </form>
                </div>

                <div class="test-result-box">
                    @if($paidSetting->last_test_at)
                        <div style="display:flex; justify-content:space-between; margin-bottom:4px;">
                            <span><strong>Status:</strong>
                                <span class="badge-status {{ $paidSetting->last_test_status === 'success' ? 'badge-success' : 'badge-failed' }}">
                                    {{ strtoupper($paidSetting->last_test_status) }}
                                </span>
                            </span>
                            <span class="muted">{{ $paidSetting->last_test_at->format('Y-m-d H:i:s') }}</span>
                        </div>
                        <div style="color:#475569; word-break:break-all;">{{ $paidSetting->last_test_message }}</div>
                    @else
                        <span class="muted">No connection tests have been performed yet for this endpoint.</span>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div style="margin-top:20px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px;">
        <h4 style="margin:0 0 8px 0; color:#1e293b; font-size:13px;">Security & Operational Policies</h4>
        <ul style="margin:0; padding-left:18px; font-size:12px; color:#64748b; line-height:1.6;">
            <li><strong>Fail-Closed Security:</strong> If an endpoint is disabled or its URL is not configured, invoice delivery will be blocked with a clear warning without corrupting queues.</li>
            <li><strong>Anti-SSRF Protection:</strong> Internal IP ranges and loopback destinations (<code>127.0.0.1</code>, <code>localhost</code>, RFC 1918 private subnets) are blocked unless explicitly configured in server environment.</li>
            <li><strong>Encrypted Secret Storage:</strong> All webhook signing secrets are encrypted at rest with AES-256-CBC using Laravel application key.</li>
            <li><strong>Harmless Test Pings:</strong> The "Test Connection" tool transmits an isolated <code>test.connection</code> diagnostic event without exposing student PII or financial amounts.</li>
        </ul>
    </div>
</div>
@endsection
