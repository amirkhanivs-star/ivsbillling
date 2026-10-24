@extends('layouts.app')
@section('title', request('paid_only') ? 'Paid Invoices Review & Sending' : 'Monthly Invoices Review & Sending')
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
</style>

<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
        <div>
            @if(request('paid_only'))
                <h2>Paid Invoices · Review &amp; Outbound Delivery</h2>
                <p class="muted">Review settled parent payments and authorize delivery of receipt confirmations to the <strong>Paid Invoice Webhook</strong> (<code>invoice.paid</code>).</p>
            @else
                <h2>Monthly Invoices · Review &amp; Outbound Delivery</h2>
                <p class="muted">Review outstanding/unpaid monthly invoices and authorize fee demands to the <strong>Monthly Invoice Webhook</strong> (<code>invoice.monthly</code>).</p>
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
        </div>
    </div>

    @if(in_array(request('send_mode'),['whatsapp','email'],true))
    <div class="iq-confirm">
        <strong>{{ request('send_mode')==='email' ? 'Email' : 'WhatsApp' }} invoice sending:</strong>
        Filter by grade, department, or month, then review the selected family invoices. Confirm any sibling grades in the selected family invoices before queuing delivery.
    </div>
    @endif

    {{-- FILTERS ROW --}}
    <form method="get" class="iq-filter-row">
        @if(in_array(request('send_mode'),['whatsapp','email'],true))
            <input type="hidden" name="send_mode" value="{{ request('send_mode') }}">
        @endif
        <select class="input" name="department_id">
            <option value="">All Departments</option>
            @foreach($departments as $d)
                <option value="{{ $d->id }}" @selected(request('department_id')==$d->id)>{{ $d->name }}</option>
            @endforeach
        </select>
        <select class="input" name="grade">
            <option value="">All Grades</option>
            @foreach($gradeOptions as $grade)
                <option value="{{ $grade }}" @selected(request('grade')===$grade)>{{ $grade }}</option>
            @endforeach
        </select>
        <input class="input" type="month" name="period" value="{{ request('period') }}">
        @if(!in_array(request('send_mode'),['whatsapp','email'],true))
            <label style="display:inline-flex; align-items:center; gap:6px; font-weight:600; cursor:pointer;">
                <input type="checkbox" name="paid_only" value="1" @checked(request('paid_only'))> Paid invoices only
            </label>
        @endif
        <button class="btn">Apply Filter</button>
        <a href="{{ in_array(request('send_mode'),['whatsapp','email'],true) ? '/invoices?send_mode='.request('send_mode') : '/invoices' }}" class="btn gray">Reset</a>
    </form>

    {{-- INVOICES TABLE & SEND FORM --}}
    <form method="post" action="/invoices/send" id="iq-send-form">
        @csrf
        <input type="hidden" name="webhook_type" value="{{ request('paid_only') ? 'paid' : 'monthly' }}">
        @if(in_array(request('send_mode'),['whatsapp','email'],true))
            <input type="hidden" name="send_mode" value="{{ request('send_mode') }}">
            <input type="hidden" name="selected_grade" value="{{ request('grade') }}">
        @endif

        <div class="iq-send-controls" style="display:flex; justify-content:space-between; align-items:center; margin:15px 0;">
            <div style="display:flex; align-items:center; gap:15px;">
                @if(request('send_mode')==='whatsapp')
                    <label><input type="checkbox" name="channels[]" value="whatsapp" checked> WhatsApp via n8n</label>
                @elseif(request('send_mode')==='email')
                    <label><input type="checkbox" name="channels[]" value="email" checked> Email via n8n</label>
                @else
                    <label><input type="checkbox" name="channels[]" value="whatsapp" checked> WhatsApp via n8n</label>
                    <label><input type="checkbox" name="channels[]" value="email"> Email via n8n</label>
                @endif
                <span class="muted" style="font-size:11px;">Verified contact is required; WhatsApp also requires opt-in.</span>
            </div>
            <button class="btn purple" type="submit" id="btn-submit-send">
                {{ request('paid_only') ? 'Send Selected Paid Invoices' : 'Send Selected Monthly Invoices' }}
            </button>
        </div>

        <div class="table-wrap">
            <table class="table iq-invoice-review">
                <thead>
                    <tr>
                        <th style="width:40px;"><input id="invoice-select-all" type="checkbox" aria-label="Select all invoices"></th>
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
                    @php($grades=$gradesByInvoice->get($i->id,[]))
                    <tr>
                        <td>
                            {{-- Checkboxes default UNCHECKED for a new session --}}
                            <input type="checkbox" class="invoice-check" name="invoice_ids[]" value="{{ $i->id }}"
                                   data-invoice="{{ $i->invoice_number }}"
                                   data-grades="{{ implode(' | ',$grades) }}"
                                   data-status="{{ $i->status }}">
                        </td>
                        <td><strong>{{ $i->invoice_number }}</strong></td>
                        <td>{{ $i->family_code ?: 'Unnumbered family #'.$i->family_id }}</td>
                        <td>{{ $i->department_name }}</td>
                        <td><span class="iq-invoice-grades">{{ count($grades)?implode(', ',$grades):'Not assigned' }}</span></td>
                        <td>{{ implode(', ', $grByInvoice->get($i->id,[])) ?: '—' }}</td>
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
                        <td><a href="/invoices/{{ $i->id }}/pdf" target="_blank" rel="noopener" class="btn small">View PDF</a></td>
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
            0 invoice(s) selected. Select invoices to preview the included grades and approve delivery.
        </div>

        <div id="grade-confirmations" style="margin-top:10px;"></div>

        <label style="display:block; margin:16px 0; font-size:13px; font-weight:600; cursor:pointer;">
            <input type="checkbox" name="review_confirmed" value="1" required>
            I reviewed the invoice PDFs, all included grades, and recipients, and approve sending the selected invoices to the {{ request('paid_only') ? 'Paid' : 'Monthly' }} Invoice Webhook.
        </label>

        <button class="btn purple" type="submit" id="btn-submit-send-bottom">
            {{ request('paid_only') ? 'Confirm & Queue Selected Paid Invoices' : 'Confirm & Queue Selected Monthly Invoices' }}
        </button>
    </form>

    <div style="margin-top:15px;">
        {{ $invoices->links() }}
    </div>

    {{-- DELIVERY STATUS & HISTORY SECTION --}}
    <div class="delivery-history-wrap">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
            <div>
                <h3 style="margin:0 0 6px 0; font-size:15px; color:#1e293b;">Outbound Webhook Delivery History (Recent Events)</h3>
                <p class="muted" style="margin:0; font-size:12px;">Audit trail of recently queued, submitted, or failed webhook dispatches. Failed or blocked events can be re-queued.</p>
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
                        <th>Invoice</th>
                        <th>Destination</th>
                        <th>Channels</th>
                        <th>Approved By</th>
                        <th>Approved At</th>
                        <th>Latest Attempt</th>
                        <th>Status</th>
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
                            <span style="font-weight:600; color:#334155;">{{ ucfirst($n->webhook_type ?? 'monthly') }}</span>
                            <span class="muted" style="font-size:10px;">({{ $n->event_type ?? 'invoice.monthly' }})</span>
                        </td>
                        <td>{{ strtoupper(str_replace(',', ' & ', $n->channel)) }}</td>
                        <td>{{ $n->approver_name ?: 'System / Staff' }}</td>
                        <td>{{ $n->created_at }}</td>
                        <td>{{ $n->last_attempt_at ?? ($n->submitted_at ?? '—') }}</td>
                        <td>
                            <span class="badge-status {{ $badgeClass }}">{{ $n->status === 'submitted' ? 'SUBMITTED TO WEBHOOK' : strtoupper($n->status) }}</span>
                        </td>
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
                        <td colspan="9" style="text-align:center; padding:16px; color:#64748b;">
                            No outbound invoice webhook events recorded yet.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
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

    function update() {
        const chosen = boxes.filter(b => b.checked);
        const grades = [...new Set(chosen.flatMap(b => b.dataset.grades.split(' | ').filter(Boolean)))].sort();

        if (chosen.length) {
            summary.textContent = `${chosen.length} invoice(s) selected: ${chosen.map(b => b.dataset.invoice + ' [' + (b.dataset.grades || 'unassigned grade') + ']').join(', ')}`;
        } else {
            summary.textContent = '0 invoice(s) selected. Select invoices to preview the included grades and approve delivery.';
        }

        out.replaceChildren();

        if (grades.length) {
            const heading = document.createElement('strong');
            heading.textContent = 'Confirm every grade in the selected family invoices:';
            out.append(heading);
            grades.forEach(g => {
                const label = document.createElement('label');
                label.style.cssText = 'display:inline-flex;align-items:center;gap:5px;margin:8px 12px 4px 0';
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.name = 'confirmed_grades[]';
                checkbox.value = g;
                label.append(checkbox, document.createTextNode(g));
                checkbox.checked = true;
                out.append(label);
            });
        } else if (chosen.length) {
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

    boxes.forEach(b => b.addEventListener('change', update));

    // Default to NO selected invoices for a new review session to avoid accidental bulk sending
    all.checked = false;
    all.addEventListener('change', () => {
        boxes.forEach(b => b.checked = all.checked);
        update();
    });

    form.addEventListener('submit', e => {
        const chosen = boxes.filter(b => b.checked);
        const grades = [...out.querySelectorAll('input[name="confirmed_grades[]"]')];

        if (!chosen.length) {
            e.preventDefault();
            alert('Please select at least one invoice before queuing delivery.');
            return;
        }

        if (!grades.length || grades.some(b => !b.checked)) {
            e.preventDefault();
            alert('Confirm every included grade before sending.');
            return;
        }

        const channels = [...form.querySelectorAll('input[name="channels[]"]')].filter(c => c.checked).map(c => c.value);
        if (!channels.length) {
            e.preventDefault();
            alert('Please choose at least one delivery channel (WhatsApp or Email).');
            return;
        }

        // Exact confirmation prompt per requirements
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
