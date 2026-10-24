@extends('layouts.app')
@section('title', request('paid_only') ? 'Paid Invoices Review & Batch Delivery' : 'Monthly Invoices Review & Batch Delivery')
@section('content')
<link rel="stylesheet" href="{{ asset('assets/iqra-challan.css') }}">
<style>
.delivery-history-wrap {
    margin-top: 30px;
    border-top: 2px solid #e2e8f0;
    padding-top: 20px;
}
.badge-status {
    font-size: 11px;
    font-weight: 600;
    padding: 3px 8px;
    border-radius: 12px;
    text-transform: uppercase;
}
.badge-queued { background: #e0f2fe; color: #0369a1; }
.badge-submitted { background: #ede9fe; color: #6d28d9; }
.badge-delivered { background: #dcfce7; color: #166534; }
.badge-failed { background: #fee2e2; color: #991b1b; }
.badge-blocked { background: #ffedd5; color: #9a3412; }
.badge-suppressed { background: #f1f5f9; color: #475569; }

.batch-snapshot-card {
    background: linear-gradient(135deg, #f8fafc 0%, #ede9fe 100%);
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 16px;
    margin: 15px 0;
}
.batch-snapshot-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 12px;
    margin-top: 10px;
}
.batch-stat-box {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 10px 14px;
}
.batch-stat-val {
    font-size: 20px;
    font-weight: 700;
    color: #1e293b;
}
.batch-stat-label {
    font-size: 11px;
    color: #64748b;
    text-transform: uppercase;
    font-weight: 600;
}

.selection-toggle-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    padding: 10px 14px;
    border-radius: 6px;
    margin: 12px 0;
}
.btn-toggle-select {
    padding: 6px 14px;
    font-size: 12px;
    font-weight: 600;
    border-radius: 4px;
    cursor: pointer;
    border: 1px solid #cbd5e1;
    background: #f8fafc;
    color: #334155;
}
.btn-toggle-select.active {
    background: #7c3aed;
    color: #ffffff;
    border-color: #6d28d9;
}
</style>

<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
        <div>
            @if(request('paid_only'))
                <h2>Paid Invoices · Review &amp; Outbound Delivery</h2>
                <p class="muted">Review settled parent payments and authorize delivery of receipt confirmations to the <strong>Paid Invoice Webhook</strong> (<code>invoice.paid.batch</code>).</p>
            @else
                <h2>Monthly Invoices · Review &amp; Outbound Delivery</h2>
                <p class="muted">Review outstanding monthly fee demands and authorize batch delivery to the <strong>Monthly Invoice Webhook</strong> (<code>invoice.monthly.batch</code>).</p>
            @endif
        </div>
        <div>
            @if(request('paid_only'))
                <span class="badge" style="background:{{ $paidWebhookSetting->is_enabled && $paidWebhookSetting->url ? '#dcfce7' : '#fee2e2' }}; color:{{ $paidWebhookSetting->is_enabled && $paidWebhookSetting->url ? '#166534' : '#991b1b' }}; font-size:12px; padding:6px 12px; border-radius:4px; font-weight:600;">
                    Paid Webhook: {{ $paidWebhookSetting->is_enabled && $paidWebhookSetting->url ? 'Active & Configured' : 'Disabled / Unconfigured' }}
                </span>
            @else
                <span class="badge" style="background:{{ $monthlyWebhookSetting->is_enabled && $monthlyWebhookSetting->url ? '#dcfce7' : '#fee2e2' }}; color:{{ $monthlyWebhookSetting->is_enabled && $monthlyWebhookSetting->url ? '#166534' : '#991b1b' }}; font-size:12px; padding:6px 12px; border-radius:4px; font-weight:600;">
                    Monthly Webhook: {{ $monthlyWebhookSetting->is_enabled && $monthlyWebhookSetting->url ? 'Active & Configured' : 'Disabled / Unconfigured' }}
                </span>
            @endif
            @if(auth()->user()->role === 'super_admin')
                <a href="{{ route('settings.webhooks') }}" class="btn small" style="margin-left:8px; background:#f8fafc; color:#475569; border:1px solid #cbd5e1; font-size:11px; padding:5px 10px;">
                    ⚙️ Webhooks &amp; Authorizations
                </a>
            @endif
        </div>
    </div>

    {{-- FILTERS ROW --}}
    <form method="get" class="iq-filter-row" style="display:flex; flex-wrap:wrap; gap:8px; align-items:center; background:#f8fafc; padding:12px; border-radius:6px; border:1px solid #e2e8f0; margin-bottom:14px;">
        @if(in_array(request('send_mode'),['whatsapp','email'],true))
            <input type="hidden" name="send_mode" value="{{ request('send_mode') }}">
        @endif

        @if(in_array(auth()->user()->role, ['super_admin', 'finance_admin'], true))
            <select class="input" name="campus_id" style="min-width:140px;">
                <option value="">All Campuses</option>
                @foreach($campuses as $c)
                    <option value="{{ $c->id }}" @selected(request('campus_id') == $c->id)>{{ $c->name }}</option>
                @endforeach
            </select>
        @endif

        <select class="input" name="department_id" style="min-width:140px;">
            <option value="">All Departments</option>
            @foreach($departments as $d)
                <option value="{{ $d->id }}" @selected(request('department_id') == $d->id)>{{ $d->name }}</option>
            @endforeach
        </select>

        <select class="input" name="grade" style="min-width:130px;">
            <option value="">All Grades</option>
            @foreach($gradeOptions as $grade)
                <option value="{{ $grade }}" @selected(request('grade') === $grade)>{{ $grade }}</option>
            @endforeach
        </select>

        <input class="input" type="month" name="period" value="{{ request('period') }}" title="Billing Month (Period)">

        <select class="input" name="contact_status" style="min-width:140px;" title="Contact Eligibility">
            <option value="all" @selected(request('contact_status','all') === 'all')>All Contacts</option>
            <option value="eligible_only" @selected(request('contact_status') === 'eligible_only')>Verified &amp; Consented</option>
            <option value="ineligible_only" @selected(request('contact_status') === 'ineligible_only')>Unverified / Missing Consent</option>
        </select>

        <select class="input" name="payment_status" style="min-width:130px;" title="Payment Status">
            <option value="all" @selected(request('payment_status','all') === 'all')>All Balances</option>
            <option value="unpaid" @selected(request('payment_status') === 'unpaid')>Unpaid Only</option>
            <option value="partial" @selected(request('payment_status') === 'partial')>Partially Paid</option>
            @if(request('paid_only'))
                <option value="paid" @selected(request('payment_status') === 'paid')>Fully Paid</option>
            @endif
        </select>

        @if(!in_array(request('send_mode'),['whatsapp','email'],true))
            <label style="display:inline-flex; align-items:center; gap:6px; font-weight:600; cursor:pointer; font-size:12px;">
                <input type="checkbox" name="paid_only" value="1" @checked(request('paid_only'))> Paid invoices only
            </label>
        @endif

        <button class="btn" type="submit">Apply Filter</button>
        <a href="{{ in_array(request('send_mode'),['whatsapp','email'],true) ? '/invoices?send_mode='.request('send_mode') : '/invoices' }}" class="btn gray">Reset</a>
    </form>

    {{-- BOUNDED SELECTION SNAPSHOT CARD --}}
    <div class="batch-snapshot-card" id="snapshot-summary-card">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:10px;">
            <div>
                <h3 style="margin:0 0 4px 0; font-size:15px; color:#4c1d95;">
                    Server-Side Batch Selection Snapshot
                </h3>
                <p class="muted" style="margin:0; font-size:12px;">
                    Consolidated across ALL matching pages for routine monthly delivery (1 latest challan per eligible recipient/cycle, including previous dues).
                </p>
            </div>
            @if(!empty($selectionSnapshot['sample_preview_family_id']))
                <a href="{{ route('families.challan.pdf', $selectionSnapshot['sample_preview_family_id']) }}" target="_blank" rel="noopener" class="btn small" style="background:#ffffff; color:#6d28d9; border:1px solid #c4b5fd; font-weight:600;">
                    📄 Preview Sample Consolidated Challan
                </a>
            @endif
        </div>

        <div class="batch-snapshot-grid">
            <div class="batch-stat-box">
                <div class="batch-stat-label">Eligible Recipients</div>
                <div class="batch-stat-val" style="color:#059669;">{{ number_format($selectionSnapshot['total_eligible_recipients']) }}</div>
                <div style="font-size:10px; color:#64748b; margin-top:2px;">Distinct Delivery Targets</div>
            </div>
            <div class="batch-stat-box">
                <div class="batch-stat-label">Distinct Families</div>
                <div class="batch-stat-val">{{ number_format($selectionSnapshot['distinct_family_count']) }}</div>
                <div style="font-size:10px; color:#64748b; margin-top:2px;">Sibling Groups Preserved</div>
            </div>
            <div class="batch-stat-box">
                <div class="batch-stat-label">Total Matched Invoices</div>
                <div class="batch-stat-val">{{ number_format($selectionSnapshot['total_matched_invoices']) }}</div>
                <div style="font-size:10px; color:#64748b; margin-top:2px;">Across all pagination pages</div>
            </div>
            <div class="batch-stat-box">
                <div class="batch-stat-label">Excluded / Blocked / Superseded</div>
                <div class="batch-stat-val" style="color:#d97706;">
                    {{ number_format(array_sum($selectionSnapshot['excluded_breakdown'])) }}
                </div>
                <div style="font-size:10px; color:#64748b; margin-top:2px;">
                    {{ $selectionSnapshot['excluded_breakdown']['superseded'] }} superseded · 
                    {{ $selectionSnapshot['excluded_breakdown']['contact_ineligible'] }} ineligible · 
                    {{ $selectionSnapshot['excluded_breakdown']['already_submitted'] + $selectionSnapshot['excluded_breakdown']['already_delivered'] }} already sent
                </div>
            </div>
            <div class="batch-stat-box">
                <div class="batch-stat-label">Currency Breakdown</div>
                <div style="font-size:13px; font-weight:700; color:#1e293b; margin-top:4px;">
                    @forelse($selectionSnapshot['currencies_summary'] as $curr => $cData)
                        <div>{{ $curr }} {{ \App\Services\Money::format($cData['outstanding_minor']) }} <span class="muted" style="font-size:10px;">({{ $cData['count'] }})</span></div>
                    @empty
                        <span class="muted">No dues</span>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- SELECTION MODE TOGGLE & BATCH ACTION BAR --}}
    <form method="post" action="/invoices/send-batch" id="iq-send-form">
        @csrf
        <input type="hidden" name="webhook_type" value="{{ request('paid_only') ? 'paid' : 'monthly' }}">
        <input type="hidden" name="selection_mode" id="selection-mode" value="page">
        <input type="hidden" name="campus_id" value="{{ request('campus_id') }}">
        <input type="hidden" name="department_id" value="{{ request('department_id') }}">
        <input type="hidden" name="grade" value="{{ request('grade') }}">
        <input type="hidden" name="period" value="{{ request('period') }}">
        <input type="hidden" name="contact_status" value="{{ request('contact_status', 'all') }}">
        <input type="hidden" name="payment_status" value="{{ request('payment_status', 'all') }}">
        @if(in_array(request('send_mode'),['whatsapp','email'],true))
            <input type="hidden" name="send_mode" value="{{ request('send_mode') }}">
            <input type="hidden" name="selected_grade" value="{{ request('grade') }}">
        @endif

        <div class="selection-toggle-bar">
            <span style="font-size:12px; font-weight:700; color:#475569;">Selection Scope:</span>
            <button type="button" class="btn-toggle-select active" id="btn-scope-page">
                Select Current Page ({{ $invoices->count() }})
            </button>
            <button type="button" class="btn-toggle-select" id="btn-scope-all">
                Select All Matching Filters ({{ number_format($selectionSnapshot['total_eligible_recipients']) }} Eligible Recipients)
            </button>
            <button type="button" class="btn-toggle-select" id="btn-scope-clear" style="margin-left:auto; color:#ef4444; border-color:#fca5a5;">
                Clear Selection
            </button>
        </div>

        <div class="iq-send-controls" style="display:flex; justify-content:space-between; align-items:center; margin:15px 0; background:#f8fafc; padding:12px 16px; border-radius:6px; border:1px solid #e2e8f0;">
            <div style="display:flex; align-items:center; gap:15px;">
                @if(request('send_mode')==='whatsapp')
                    <label style="font-weight:600;"><input type="checkbox" name="channels[]" value="whatsapp" checked> WhatsApp via n8n</label>
                @elseif(request('send_mode')==='email')
                    <label style="font-weight:600;"><input type="checkbox" name="channels[]" value="email" checked> Email via n8n</label>
                @else
                    <label style="font-weight:600;"><input type="checkbox" name="channels[]" value="whatsapp" checked> WhatsApp via n8n</label>
                    <label style="font-weight:600;"><input type="checkbox" name="channels[]" value="email"> Email via n8n</label>
                @endif
                <span class="muted" style="font-size:11px;">Verified contact required; WhatsApp also requires opt-in.</span>
            </div>
            <button class="btn purple" type="submit" id="btn-submit-send" style="font-size:13px; font-weight:700; padding:8px 18px;">
                {{ request('paid_only') ? 'Queue Approved Paid Batch to Webhook' : 'Queue Approved Monthly Batch to Webhook' }}
            </button>
        </div>

        {{-- INVOICES TABLE --}}
        <div class="table-wrap">
            <table class="table iq-invoice-review">
                <thead>
                    <tr>
                        <th style="width:40px;"><input id="invoice-select-all" type="checkbox" aria-label="Select all on this page"></th>
                        <th>Invoice</th>
                        <th>Family</th>
                        <th>Department</th>
                        <th>Grade(s) included</th>
                        <th>Registration / GR No.</th>
                        <th>Period</th>
                        <th>Amount</th>
                        <th>Paid</th>
                        <th>Status</th>
                        <th>WhatsApp</th>
                        <th>PDF</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($invoices as $i)
                    @php($grades = $gradesByInvoice->get($i->id, []))
                    <tr>
                        <td>
                            <input type="checkbox" class="invoice-check" name="invoice_ids[]" value="{{ $i->id }}"
                                   data-invoice="{{ $i->invoice_number }}"
                                   data-grades="{{ implode(' | ', $grades) }}"
                                   data-status="{{ $i->status }}">
                        </td>
                        <td><strong>{{ $i->invoice_number }}</strong></td>
                        <td>{{ $i->family_code ?: 'Unnumbered family #' . $i->family_id }}</td>
                        <td>{{ $i->department_name }}</td>
                        <td><span class="iq-invoice-grades">{{ count($grades) ? implode(', ', $grades) : 'Not assigned' }}</span></td>
                        <td>{{ implode(', ', $grByInvoice->get($i->id, [])) ?: '—' }}</td>
                        <td>{{ $i->period_start }}</td>
                        <td>{{ $i->currency }} {{ \App\Services\Money::format($i->total_minor) }}</td>
                        <td>{{ \App\Services\Money::format($i->paid_minor) }}</td>
                        <td><span class="badge" style="font-size:11px;">{{ ucfirst($i->status) }}</span></td>
                        <td>
                            @if(!empty($i->whatsapp))
                                <div style="font-weight:600; font-size:12px;">{{ $i->whatsapp }}</div>
                                <div style="display:flex; flex-wrap:wrap; gap:3px; margin-top:2px;">
                                    @if($i->contact_verified && $i->messaging_opt_in)
                                        <span style="background:#dcfce7; color:#166534; font-size:10px; padding:1px 5px; border-radius:3px; font-weight:600;">Verified &amp; Opted In</span>
                                    @elseif(!$i->contact_verified)
                                        <span style="background:#fee2e2; color:#991b1b; font-size:10px; padding:1px 5px; border-radius:3px; font-weight:600;">Unverified Contact</span>
                                    @else
                                        <span style="background:#ffedd5; color:#9a3412; font-size:10px; padding:1px 5px; border-radius:3px; font-weight:600;">Consent Missing</span>
                                    @endif
                                </div>
                            @else
                                <span class="muted" style="font-size:11px;">No WhatsApp</span>
                            @endif

                            <div style="margin-top:4px;">
                                @if($i->whatsapp_status === 'submitted')
                                    <span class="badge-status badge-submitted" style="font-size:10px; padding:1px 6px;">SUBMITTED TO WEBHOOK</span>
                                @elseif($i->whatsapp_status === 'delivered')
                                    <span class="badge-status badge-delivered" style="font-size:10px; padding:1px 6px;">DELIVERED</span>
                                @elseif($i->whatsapp_status === 'queued')
                                    <span class="badge-status badge-queued" style="font-size:10px; padding:1px 6px;">QUEUED</span>
                                @elseif($i->whatsapp_status === 'blocked')
                                    <span class="badge-status badge-blocked" style="font-size:10px; padding:1px 6px;" title="{{ $i->whatsapp_error }}">BLOCKED: {{ $i->whatsapp_error }}</span>
                                @elseif($i->whatsapp_status === 'failed')
                                    <span class="badge-status badge-failed" style="font-size:10px; padding:1px 6px;">FAILED</span>
                                @else
                                    <span class="muted" style="font-size:10px;">Not sent</span>
                                @endif
                            </div>

                            @if((!$i->contact_verified || !$i->messaging_opt_in) && isset($studentByInvoice))
                                @php($firstStudentId = $studentByInvoice->get($i->id))
                                @if($firstStudentId)
                                    <div style="margin-top:3px;">
                                        <a href="/manage-student/{{ $firstStudentId }}" style="font-size:10px; color:#7367f0; text-decoration:underline;">Verify Contact</a>
                                    </div>
                                @endif
                            @endif
                        </td>
                        <td>
                            <div style="display:flex; flex-direction:column; gap:3px;">
                                <a href="/invoices/{{ $i->id }}/pdf" target="_blank" rel="noopener" class="btn small" style="font-size:10px; padding:2px 6px;">Invoice PDF</a>
                                <a href="{{ route('families.challan.pdf', $i->family_id) }}" target="_blank" rel="noopener" class="btn small gray" style="font-size:10px; padding:2px 6px;">Challan PDF</a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="12" style="text-align:center; padding:24px; color:#64748b;">
                            @if(request('paid_only'))
                                No fully settled paid invoices match these filter criteria.
                            @else
                                No unpaid or partially paid monthly invoices match these filter criteria.
                            @endif
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="iq-confirm" id="send-preview" role="status" aria-live="polite" style="margin-top:14px;">
            0 invoice(s) selected on this page. Select invoices or choose "Select All Matching Filters" to approve batch delivery.
        </div>

        <div id="grade-confirmations" style="margin-top:10px;"></div>

        <label style="display:block; margin:16px 0; font-size:13px; font-weight:600; cursor:pointer;">
            <input type="checkbox" name="review_confirmed" value="1" required>
            I reviewed the consolidated challans, included grades, and recipients, and approve queuing this batch for delivery to the {{ request('paid_only') ? 'Paid' : 'Monthly' }} Invoice Webhook.
        </label>

        <button class="btn purple" type="submit" id="btn-submit-send-bottom">
            {{ request('paid_only') ? 'Confirm & Queue Paid Batch Delivery' : 'Confirm & Queue Monthly Batch Delivery' }}
        </button>
    </form>

    <div style="margin-top:15px;">
        {{ $invoices->links() }}
    </div>

    {{-- OUTBOUND DELIVERY HISTORY (BATCHES & INDIVIDUAL RECENT EVENTS) --}}
    <div class="delivery-history-wrap">
        {{-- SECTION 1: BATCH CAMPAIGNS --}}
        <div style="margin-bottom:24px;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
                <div>
                    <h3 style="margin:0 0 6px 0; font-size:15px; color:#1e293b;">Approved Outbound Batch Delivery Campaigns</h3>
                    <p class="muted" style="margin:0; font-size:12px;">Audit history of single-batch n8n submissions. Each batch contains multiple recipient payloads sent under one campaign ID.</p>
                </div>
                @if(!empty($queuedJobCount))
                    <form method="post" action="{{ route('invoices.notifications.process_queue') }}" style="margin:0;">
                        @csrf
                        <button class="btn small" type="submit" style="background:#e0f2fe; color:#0369a1; border:1px solid #7dd3fc; font-weight:700;">
                            ⚡ Process Pending Queue ({{ $queuedJobCount }})
                        </button>
                    </form>
                @endif
            </div>

            <div class="table-wrap">
                <table class="table" style="font-size:12px;">
                    <thead>
                        <tr>
                            <th>Batch ID</th>
                            <th>Destination</th>
                            <th>Period</th>
                            <th>Recipients / Invoices</th>
                            <th>Chunks</th>
                            <th>Approved By</th>
                            <th>Approved At</th>
                            <th>Status</th>
                            <th>Outcome / Diagnostic</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($recentBatches as $batch)
                        <?php
                            $bClass = match($batch->status) {
                                'submitted' => 'badge-submitted',
                                'completed' => 'badge-delivered',
                                'failed' => 'badge-failed',
                                'qa_suppressed' => 'badge-suppressed',
                                default => 'badge-queued',
                            };
                        ?>
                        <tr>
                            <td><strong><code>{{ $batch->batch_id }}</code></strong></td>
                            <td>
                                <span style="font-weight:600;">{{ ucfirst($batch->webhook_type) }} Batch</span>
                                <div class="muted" style="font-size:10px;">{{ $batch->event_type }}</div>
                            </td>
                            <td>{{ $batch->billing_period ?: '—' }}</td>
                            <td>
                                <strong>{{ number_format($batch->total_recipients) }}</strong> recipients
                                <span class="muted">({{ number_format($batch->total_invoices) }} inv)</span>
                            </td>
                            <td>
                                {{ $batch->chunks_sent }} / {{ $batch->chunks_total }} sent
                            </td>
                            <td>{{ $batch->approver_name ?: 'System / Staff' }}</td>
                            <td>{{ $batch->created_at }}</td>
                            <td>
                                <span class="badge-status {{ $bClass }}">
                                    {{ $batch->status === 'submitted' ? 'SUBMITTED TO WEBHOOK' : strtoupper($batch->status) }}
                                </span>
                            </td>
                            <td style="max-width:220px; word-break:break-all; color:#64748b;">
                                @if($batch->error)
                                    <span style="color:#b91c1c; font-weight:600;">{{ Str::limit($batch->error, 100) }}</span>
                                @elseif($batch->response_code)
                                    HTTP {{ $batch->response_code }} {{ $batch->submitted_at ? '· ' . $batch->submitted_at : '' }}
                                @else
                                    Pending worker dispatch
                                @endif
                            </td>
                            <td>
                                @if($batch->status === 'failed')
                                    <form method="post" action="{{ route('invoices.batches.retry_failed', $batch->batch_id) }}" style="margin:0;">
                                        @csrf
                                        <button class="btn small" type="submit" style="background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; font-weight:700;">
                                            Retry Failed Only
                                        </button>
                                    </form>
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" style="text-align:center; padding:14px; color:#64748b;">
                                No batch delivery campaigns recorded yet.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- SECTION 2: INDIVIDUAL RECIPIENT NOTIFICATIONS --}}
        <div>
            <h3 style="margin:0 0 6px 0; font-size:15px; color:#1e293b;">Recent Recipient Notification Events</h3>
            <p class="muted" style="margin:0 0 12px 0; font-size:12px;">Recipient-level delivery status across batches. Downstream delivery status updates via n8n callback.</p>

            <div class="table-wrap">
                <table class="table" style="font-size:12px;">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Batch ID</th>
                            <th>Destination</th>
                            <th>Channels</th>
                            <th>Status</th>
                            <th>Latest Attempt</th>
                            <th>Diagnostic Details</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($recentNotifications as $n)
                        <?php
                            $badgeClass = match($n->status) {
                                'submitted' => 'badge-submitted',
                                'delivered' => 'badge-delivered',
                                'failed' => 'badge-failed',
                                'blocked' => 'badge-blocked',
                                'qa_suppressed' => 'badge-suppressed',
                                default => 'badge-queued',
                            };
                        ?>
                        <tr>
                            <td><strong>{{ $n->invoice_number }}</strong></td>
                            <td>
                                @if(!empty($n->batch_id))
                                    <code>{{ $n->batch_id }}</code>
                                @else
                                    <span class="muted">Single</span>
                                @endif
                            </td>
                            <td>
                                <span style="font-weight:600; color:#334155;">{{ ucfirst($n->webhook_type ?? 'monthly') }}</span>
                            </td>
                            <td>{{ strtoupper(str_replace(',', ' & ', $n->channel)) }}</td>
                            <td>
                                <span class="badge-status {{ $badgeClass }}">{{ $n->status === 'submitted' ? 'SUBMITTED TO WEBHOOK' : strtoupper($n->status) }}</span>
                            </td>
                            <td>{{ $n->last_attempt_at ?? ($n->submitted_at ?? '—') }}</td>
                            <td style="max-width:240px; word-break:break-all; color:#64748b;">
                                @if($n->error)
                                    <span style="color:#b91c1c; font-weight:600;">{{ Str::limit($n->error, 120) }}</span>
                                @elseif($n->provider_message_id)
                                    <span style="color:#166534;">ID: {{ $n->provider_message_id }}</span>
                                @elseif($n->response_code)
                                    HTTP {{ $n->response_code }}
                                @else
                                    —
                                @endif
                            </td>
                            <td>
                                @if(in_array($n->status, ['failed', 'blocked'], true))
                                    <form method="post" action="{{ route('invoices.notifications.retry', $n->id) }}" style="margin:0;">
                                        @csrf
                                        <button class="btn small" type="submit" style="background:#f1f5f9; color:#b91c1c; border:1px solid #cbd5e1; font-weight:600;">Retry</button>
                                    </form>
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" style="text-align:center; padding:16px; color:#64748b;">
                                No outbound invoice webhook events recorded yet.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
(()=>{
    const filter = document.querySelector('.iq-filter-row');
    filter.querySelectorAll('select,input[type=month],input[name=paid_only]').forEach(control => {
        control.addEventListener('change', () => filter.requestSubmit());
    });

    const boxes = [...document.querySelectorAll('.invoice-check')];
    const all = document.getElementById('invoice-select-all');
    const summary = document.getElementById('send-preview');
    const out = document.getElementById('grade-confirmations');
    const form = document.getElementById('iq-send-form');
    const webhookType = form.querySelector('input[name="webhook_type"]').value;
    const modeInput = document.getElementById('selection-mode');

    const btnScopePage = document.getElementById('btn-scope-page');
    const btnScopeAll = document.getElementById('btn-scope-all');
    const btnScopeClear = document.getElementById('btn-scope-clear');

    // Server-resolved all-grades from snapshot
    const serverSnapshotGrades = @json($selectionSnapshot['all_grades'] ?? []);
    const serverTotalEligible = {{ (int)($selectionSnapshot['total_eligible_recipients'] ?? 0) }};

    let selectionMode = 'page'; // 'page' or 'all_matching'

    function renderGradeConfirmations(gradesList) {
        out.replaceChildren();
        if (gradesList.length) {
            const heading = document.createElement('strong');
            heading.textContent = 'Confirm every grade in the selected delivery scope:';
            out.append(heading);
            gradesList.forEach(g => {
                const label = document.createElement('label');
                label.style.cssText = 'display:inline-flex;align-items:center;gap:5px;margin:8px 12px 4px 0;font-size:12px;font-weight:600;';
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.name = 'confirmed_grades[]';
                checkbox.value = g;
                checkbox.checked = true;
                label.append(checkbox, document.createTextNode(g));
                out.append(label);
            });
        } else {
            const label = document.createElement('label');
            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.name = 'confirmed_grades[]';
            cb.value = 'Not assigned';
            cb.checked = true;
            label.append(cb, document.createTextNode(' Confirm invoices without assigned grade'));
            out.append(label);
        }
    }

    function update() {
        if (selectionMode === 'all_matching') {
            modeInput.value = 'all_matching';
            btnScopeAll.classList.add('active');
            btnScopePage.classList.remove('active');
            summary.textContent = `All Matching Filters selected: ${serverTotalEligible} eligible recipients across all pagination pages ready for single-batch delivery.`;
            renderGradeConfirmations(serverSnapshotGrades);
            return;
        }

        modeInput.value = 'page';
        btnScopePage.classList.add('active');
        btnScopeAll.classList.remove('active');

        const chosen = boxes.filter(b => b.checked);
        const grades = [...new Set(chosen.flatMap(b => b.dataset.grades.split(' | ').filter(Boolean)))].sort();

        if (chosen.length) {
            summary.textContent = `${chosen.length} invoice(s) selected on this page: ${chosen.map(b => b.dataset.invoice + ' [' + (b.dataset.grades || 'unassigned grade') + ']').join(', ')}`;
        } else {
            summary.textContent = '0 invoice(s) selected on this page. Select invoices or choose "Select All Matching Filters" to approve batch delivery.';
        }

        if (chosen.length) {
            renderGradeConfirmations(grades);
        } else {
            out.replaceChildren();
        }
    }

    btnScopePage.addEventListener('click', () => {
        selectionMode = 'page';
        boxes.forEach(b => b.checked = true);
        all.checked = true;
        update();
    });

    btnScopeAll.addEventListener('click', () => {
        selectionMode = 'all_matching';
        boxes.forEach(b => b.checked = true);
        all.checked = true;
        update();
    });

    btnScopeClear.addEventListener('click', () => {
        selectionMode = 'page';
        boxes.forEach(b => b.checked = false);
        all.checked = false;
        update();
    });

    boxes.forEach(b => b.addEventListener('change', () => {
        selectionMode = 'page';
        update();
    }));

    all.addEventListener('change', () => {
        selectionMode = 'page';
        boxes.forEach(b => b.checked = all.checked);
        update();
    });

    form.addEventListener('submit', e => {
        const grades = [...out.querySelectorAll('input[name="confirmed_grades[]"]')];
        const channels = [...form.querySelectorAll('input[name="channels[]"]')].filter(c => c.checked).map(c => c.value);

        if (!channels.length) {
            e.preventDefault();
            alert('Please choose at least one delivery channel (WhatsApp or Email).');
            return;
        }

        if (selectionMode === 'all_matching') {
            if (serverTotalEligible === 0) {
                e.preventDefault();
                alert('No eligible recipients match the applied filters.');
                return;
            }
            if (!grades.length || grades.some(b => !b.checked)) {
                e.preventDefault();
                alert('Confirm every included grade across all matching records before submitting.');
                return;
            }

            const promptMsg = webhookType === 'paid'
                ? `Queue Paid Batch for ${serverTotalEligible} eligible recipients across ALL matching pages to the Paid Invoice Webhook?`
                : `Queue Monthly Batch for ${serverTotalEligible} eligible recipients across ALL matching pages to the Monthly Invoice Webhook?`;

            if (!confirm(`${promptMsg}\n\nChannels: ${channels.join(' and ')}\nGrades: ${grades.map(g => g.value).join(', ')}\n\nOne latest consolidated challan per recipient. Delivery delays handled inside n8n.`)) {
                e.preventDefault();
            }
            return;
        }

        // Current page mode
        const chosen = boxes.filter(b => b.checked);
        if (!chosen.length) {
            e.preventDefault();
            alert('Please select at least one invoice on the current page, or choose "Select All Matching Filters".');
            return;
        }

        if (!grades.length || grades.some(b => !b.checked)) {
            e.preventDefault();
            alert('Confirm every included grade before sending.');
            return;
        }

        const promptMsg = webhookType === 'paid'
            ? `Send ${chosen.length} selected paid invoices to the Paid Invoice Webhook?`
            : `Send ${chosen.length} selected monthly invoices to the Monthly Invoice Webhook?`;

        if (!confirm(`${promptMsg}\n\nChannels: ${channels.join(' and ')}\nGrades: ${grades.map(g => g.value).join(', ')}\n\nReview the PDFs first. Sending requires verified recipients and consent.`)) {
            e.preventDefault();
        }
    });

    update();
})();
</script>
@endsection
