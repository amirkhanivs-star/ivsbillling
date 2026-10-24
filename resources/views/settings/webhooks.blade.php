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

    {{-- SECTION: MYSKOOL WHATSAPP AUTHORIZATION CARRY-FORWARD --}}
    @if(isset($carryForwardPreview))
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; margin-bottom:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
            <div>
                <h3 style="font-size:16px; font-weight:700; color:#1e293b; margin:0 0 4px 0;">
                    Carry Forward Existing MySkool WhatsApp Authorizations
                </h3>
                <p class="muted" style="margin:0; font-size:12px; line-height:1.5;">
                    <strong>Confirmed School Policy:</strong> Parents' WhatsApp numbers were previously verified in MySkool and had already agreed to receive school fee notices.
                    Carry forward their pre-existing contact verification and messaging consent without requiring individual reverification of thousands of families.
                </p>
            </div>
            @if($carryForwardPreview['eligible_to_authorize_count'] === 0)
                <span class="badge-status badge-enabled" style="white-space:nowrap;">Authorizations Active</span>
            @else
                <span class="badge-status" style="background:#fef3c7; color:#92400e; white-space:nowrap;">Action Ready ({{ $carryForwardPreview['eligible_to_authorize_count'] }} Pending)</span>
            @endif
        </div>

        {{-- Statistics Row --}}
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px; margin-bottom:16px;">
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:12px;">
                <div style="font-size:11px; color:#64748b; font-weight:600; text-transform:uppercase;">Eligible to Authorize</div>
                <div style="font-size:22px; font-weight:700; color:#166534; margin-top:2px;">
                    {{ number_format($carryForwardPreview['eligible_to_authorize_count']) }}
                    <span style="font-size:12px; font-weight:normal; color:#64748b;">families</span>
                </div>
                <div style="font-size:11px; color:#64748b; margin-top:2px;">Valid WhatsApp &amp; MySkool dataset</div>
            </div>

            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:12px;">
                <div style="font-size:11px; color:#64748b; font-weight:600; text-transform:uppercase;">Already Authorized</div>
                <div style="font-size:22px; font-weight:700; color:#0284c7; margin-top:2px;">
                    {{ number_format($carryForwardPreview['already_authorized_count']) }}
                    <span style="font-size:12px; font-weight:normal; color:#64748b;">families</span>
                </div>
                <div style="font-size:11px; color:#64748b; margin-top:2px;">Verified in portal</div>
            </div>

            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:12px;">
                <div style="font-size:11px; color:#64748b; font-weight:600; text-transform:uppercase;">Excluded Families</div>
                <div style="font-size:22px; font-weight:700; color:#991b1b; margin-top:2px;">
                    {{ number_format($carryForwardPreview['excluded_counts']['total']) }}
                    <span style="font-size:12px; font-weight:normal; color:#64748b;">families</span>
                </div>
                <div style="font-size:11px; color:#64748b; margin-top:2px;">Missing, invalid, or test records</div>
            </div>

            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:12px;">
                <div style="font-size:11px; color:#64748b; font-weight:600; text-transform:uppercase;">Invoice Impact</div>
                <div style="font-size:18px; font-weight:700; color:#7367f0; margin-top:2px;">
                    {{ number_format($carryForwardPreview['invoice_impact']['before_eligible']) }} &rarr; <span style="color:#166534;">{{ number_format($carryForwardPreview['invoice_impact']['after_eligible']) }}</span>
                </div>
                <div style="font-size:11px; color:#64748b; margin-top:2px;">Eligible invoices for WhatsApp delivery</div>
            </div>
        </div>

        {{-- Execution Form or Success Banner --}}
        @if($carryForwardPreview['eligible_to_authorize_count'] > 0)
            <form method="POST" action="{{ route('settings.webhooks.carry_forward') }}" style="background:#fffbeb; border:1px solid #fde68a; border-radius:6px; padding:14px; margin-bottom:14px;">
                @csrf
                <input type="hidden" name="confirmation" value="CONFIRM_AUTHORIZATION">
                <div style="display:flex; align-items:flex-start; gap:10px; margin-bottom:12px;">
                    <input type="checkbox" id="confirm_carry_forward" name="confirm_carry_forward" value="1" required style="margin-top:3px;">
                    <label for="confirm_carry_forward" style="font-size:12px; color:#92400e; font-weight:600; cursor:pointer;">
                        I confirm that these {{ number_format($carryForwardPreview['eligible_to_authorize_count']) }} families originate from the approved final MySkool dataset, their WhatsApp numbers were previously verified, and messaging consent was continuously active.
                    </label>
                </div>
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <button type="submit" class="btn" style="background:#166534; color:#fff; font-weight:600; padding:8px 18px;">
                        Carry Forward MySkool WhatsApp Authorizations ({{ number_format($carryForwardPreview['eligible_to_authorize_count']) }} Families)
                    </button>
                    <span style="font-size:11px; color:#78350f;">
                        🛡️ Zero financial ledger mutation · Preserves actual contact numbers · Independent review-and-confirm delivery
                    </span>
                </div>
            </form>
        @else
            <div style="background:#dcfce7; border:1px solid #bbf7d0; border-radius:6px; padding:12px 16px; margin-bottom:14px; display:flex; justify-content:space-between; align-items:center;">
                <div style="color:#166534; font-size:12px; font-weight:600;">
                    ✅ All {{ number_format($carryForwardPreview['total_eligible_count']) }} eligible MySkool families have active WhatsApp authorizations! {{ number_format($carryForwardPreview['invoice_impact']['before_eligible']) }} invoices are ready for delivery review.
                </div>
                @if(!empty($carryForwardPreview['last_execution']))
                    <span style="font-size:11px; color:#15803d;">
                        Authorized on: {{ $carryForwardPreview['last_execution']['timestamp'] }}
                    </span>
                @endif
            </div>
        @endif

        {{-- Collapsible Details for Excluded Families --}}
        <details style="font-size:12px; color:#64748b; border-top:1px dashed #e2e8f0; padding-top:10px;">
            <summary style="cursor:pointer; font-weight:600; color:#475569;">
                View Breakdown of {{ $carryForwardPreview['excluded_counts']['total'] }} Excluded Families &amp; Safe Reasons
            </summary>
            <div style="margin-top:10px; display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:12px;">
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:4px; padding:10px;">
                    <strong style="color:#991b1b;">1. Missing WhatsApp Number ({{ $carryForwardPreview['excluded_counts']['missing_whatsapp'] }} families)</strong>
                    <p style="font-size:11px; margin:4px 0 6px 0;">No phone number was recorded in the MySkool workbook. Requires phone number entry before verification.</p>
                    <ul style="margin:0; padding-left:16px; font-size:11px;">
                        @foreach($carryForwardPreview['excluded_samples']['missing_whatsapp'] as $sample)
                            <li>Family #{{ $sample['id'] }} (Code: {{ $sample['family_code'] ?? 'None' }})</li>
                        @endforeach
                    </ul>
                </div>

                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:4px; padding:10px;">
                    <strong style="color:#991b1b;">2. Invalid WhatsApp Format ({{ $carryForwardPreview['excluded_counts']['invalid_whatsapp'] }} families)</strong>
                    <p style="font-size:11px; margin:4px 0 6px 0;">Missing international country code (e.g. <code>+</code> prefix) or malformed digits:</p>
                    <ul style="margin:0; padding-left:16px; font-size:11px;">
                        @foreach($carryForwardPreview['excluded_samples']['invalid_whatsapp'] as $sample)
                            <li>Family #{{ $sample['id'] }} (Code: {{ $sample['family_code'] }}): <code>{{ $sample['whatsapp'] }}</code></li>
                        @endforeach
                    </ul>
                </div>

                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:4px; padding:10px;">
                    <strong style="color:#991b1b;">3. Manual / New Admissions ({{ $carryForwardPreview['excluded_counts']['test_or_new_admission'] }} family)</strong>
                    <p style="font-size:11px; margin:4px 0 6px 0;">Created after the MySkool batch import. Governed by normal admission verification workflow.</p>
                    <ul style="margin:0; padding-left:16px; font-size:11px;">
                        @foreach($carryForwardPreview['excluded_samples']['test_or_new_admission'] as $sample)
                            <li>Family #{{ $sample['id'] }} (Code: {{ $sample['family_code'] }}): {{ $sample['guardian_name'] }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </details>
    </div>
    @endif

    <div class="webhook-grid">
        {{-- PANEL 1: MONTHLY INVOICE WEBHOOK --}}
        <?php
            $isOldMonthly = old('webhook_type') === 'monthly' || session('webhook_target_error') === 'monthly';
            $monthlyUrl = $isOldMonthly ? old('url', $monthlySetting->url) : $monthlySetting->url;
            $monthlyEnabled = $isOldMonthly ? old('is_enabled', $monthlySetting->is_enabled) : $monthlySetting->is_enabled;
        ?>
        <div class="webhook-card" id="card-monthly-webhook">
            <div class="webhook-card-header">
                <div>
                    <h3 class="webhook-title">1. Monthly Invoice Webhook</h3>
                    <div style="font-size:11px; color:#64748b; margin-top:2px;">Event: <code>invoice.monthly</code> (Unpaid & Partial)</div>
                </div>
                <span class="badge-status {{ $monthlySetting->is_enabled ? 'badge-enabled' : 'badge-disabled' }}">
                    {{ $monthlySetting->is_enabled ? 'Active / Enabled' : 'Disabled' }}
                </span>
            </div>

            @if(session('saved_webhook_type') === 'monthly' && session('notice'))
                <div style="background:#dcfce7; color:#166534; padding:8px 12px; border-radius:6px; font-size:12px; font-weight:600; margin-bottom:12px; border:1px solid #bbf7d0;">
                    ✓ {{ session('notice') }}
                </div>
            @endif

            <form method="POST" action="{{ route('settings.webhooks.update', 'monthly') }}" id="form-monthly-webhook">
                @csrf
                <input type="hidden" name="webhook_type" value="monthly">
                <div class="form-group">
                    <label for="monthly_url">Webhook Destination URL</label>
                    <input type="text" inputmode="url" id="monthly_url" name="url" class="input" style="width:100%; box-sizing:border-box; {{ $isOldMonthly && $errors->has('url') ? 'border-color:#dc2626; background:#fff5f5;' : '' }}"
                           placeholder="https://n8n.your-domain.com/webhook/iqra-monthly-invoices"
                           value="{{ $monthlyUrl }}">
                    @if($isOldMonthly && $errors->has('url'))
                        <div class="field-error" style="color:#dc2626; font-size:12px; margin-top:5px; font-weight:600;">
                            ⚠️ {{ $errors->first('url') }}
                        </div>
                    @endif
                    <div class="muted" style="font-size:11px; margin-top:4px;">Must use HTTPS in production. Accepts <code>/webhook/...</code> and <code>/webhook-test/...</code> paths. Excludes internal loopback destinations.</div>
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
                        <input type="checkbox" name="is_enabled" value="1" @checked($monthlyEnabled)>
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
        <?php
            $isOldPaid = old('webhook_type') === 'paid' || session('webhook_target_error') === 'paid';
            $paidUrl = $isOldPaid ? old('url', $paidSetting->url) : $paidSetting->url;
            $paidEnabled = $isOldPaid ? old('is_enabled', $paidSetting->is_enabled) : $paidSetting->is_enabled;
        ?>
        <div class="webhook-card" id="card-paid-webhook">
            <div class="webhook-card-header">
                <div>
                    <h3 class="webhook-title">2. Paid Invoice Webhook</h3>
                    <div style="font-size:11px; color:#64748b; margin-top:2px;">Event: <code>invoice.paid</code> (Settled Invoices)</div>
                </div>
                <span class="badge-status {{ $paidSetting->is_enabled ? 'badge-enabled' : 'badge-disabled' }}">
                    {{ $paidSetting->is_enabled ? 'Active / Enabled' : 'Disabled' }}
                </span>
            </div>

            @if(session('saved_webhook_type') === 'paid' && session('notice'))
                <div style="background:#dcfce7; color:#166534; padding:8px 12px; border-radius:6px; font-size:12px; font-weight:600; margin-bottom:12px; border:1px solid #bbf7d0;">
                    ✓ {{ session('notice') }}
                </div>
            @endif

            <form method="POST" action="{{ route('settings.webhooks.update', 'paid') }}" id="form-paid-webhook">
                @csrf
                <input type="hidden" name="webhook_type" value="paid">
                <div class="form-group">
                    <label for="paid_url">Webhook Destination URL</label>
                    <input type="text" inputmode="url" id="paid_url" name="url" class="input" style="width:100%; box-sizing:border-box; {{ $isOldPaid && $errors->has('url') ? 'border-color:#dc2626; background:#fff5f5;' : '' }}"
                           placeholder="https://n8n.your-domain.com/webhook/iqra-paid-invoices"
                           value="{{ $paidUrl }}">
                    @if($isOldPaid && $errors->has('url'))
                        <div class="field-error" style="color:#dc2626; font-size:12px; margin-top:5px; font-weight:600;">
                            ⚠️ {{ $errors->first('url') }}
                        </div>
                    @endif
                    <div class="muted" style="font-size:11px; margin-top:4px;">Dedicated endpoint for fully settled fee receipt notices. Accepts <code>/webhook/...</code> and <code>/webhook-test/...</code> paths.</div>
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
                        <input type="checkbox" name="is_enabled" value="1" @checked($paidEnabled)>
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
