@extends('layouts.app')
@section('title', request('paid_only') ? 'Paid Invoices Review & Batch Delivery' : 'Monthly Invoices Review & Batch Delivery')
@section('content')
<link rel="stylesheet" href="{{ asset('assets/iqra-challan.css') }}">
<style>
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
    margin-bottom: 20px;
}
.kpi-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 14px 16px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.kpi-card:hover {
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
}
.kpi-val {
    font-size: 24px;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.2;
}
.kpi-label {
    font-size: 11px;
    color: #64748b;
    text-transform: uppercase;
    font-weight: 700;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
}
.kpi-desc {
    font-size: 11px;
    color: #94a3b8;
    margin-top: 4px;
}

.iq-nav-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    border-bottom: 2px solid #e2e8f0;
    margin-bottom: 18px;
    padding-bottom: 0;
}
.iq-tab-item {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    font-size: 13px;
    font-weight: 700;
    color: #64748b;
    text-decoration: none;
    border-radius: 6px 6px 0 0;
    border: 1px solid transparent;
    border-bottom: none;
    margin-bottom: -2px;
    transition: all 0.15s ease;
}
.iq-tab-item:hover {
    color: #1e293b;
    background: #f8fafc;
}
.iq-tab-item.active {
    color: #6d28d9;
    background: #ffffff;
    border-color: #e2e8f0;
    border-bottom: 2px solid #ffffff;
    box-shadow: 0 -2px 5px rgba(0,0,0,0.02);
}
.iq-tab-badge {
    font-size: 10px;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 10px;
}

.badge-status {
    font-size: 10px;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 12px;
    text-transform: uppercase;
    display: inline-block;
}
.badge-queued { background: #e0f2fe; color: #0369a1; }
.badge-submitted { background: #ede9fe; color: #6d28d9; }
.badge-delivered { background: #dcfce7; color: #166534; }
.badge-read { background: #bbf7d0; color: #14532d; }
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
.due-month-pill {
    display: inline-block;
    background: #f1f5f9;
    color: #334155;
    font-size: 10px;
    padding: 1px 6px;
    border-radius: 3px;
    border: 1px solid #e2e8f0;
    margin-right: 3px;
    margin-bottom: 2px;
}
</style>

<div class="panel">
    {{-- TOP TITLE & WEBHOOK STATUS --}}
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:10px;">
        <div>
            @if(request('paid_only'))
                <h2 style="margin:0 0 4px 0; color:#0f172a;">Paid Invoices · Review &amp; Outbound Delivery</h2>
                <p class="muted" style="margin:0; font-size:12px;">Review settled parent payments and authorize delivery to the <strong>Paid Invoice Webhook</strong> (<code>invoice.paid.batch</code>).</p>
            @else
                <h2 style="margin:0 0 4px 0; color:#0f172a;">Monthly Invoices · Review &amp; Outbound Delivery</h2>
                <p class="muted" style="margin:0; font-size:12px;">Review active consolidated family challans for the selected month and authorize batch delivery to the <strong>Monthly Invoice Webhook</strong> (<code>invoice.monthly.batch</code>).</p>
            @endif
        </div>
        <div style="display:flex; align-items:center; gap:8px;">
            @if(request('paid_only'))
                <span class="badge" style="background:{{ $paidWebhookSetting->is_enabled && $paidWebhookSetting->url ? '#dcfce7' : '#fee2e2' }}; color:{{ $paidWebhookSetting->is_enabled && $paidWebhookSetting->url ? '#166534' : '#991b1b' }}; font-size:11px; padding:6px 12px; border-radius:4px; font-weight:700;">
                    Paid Webhook: {{ $paidWebhookSetting->is_enabled && $paidWebhookSetting->url ? 'Active & Configured' : 'Disabled / Unconfigured' }}
                </span>
            @else
                <span class="badge" style="background:{{ $monthlyWebhookSetting->is_enabled && $monthlyWebhookSetting->url ? '#dcfce7' : '#fee2e2' }}; color:{{ $monthlyWebhookSetting->is_enabled && $monthlyWebhookSetting->url ? '#166534' : '#991b1b' }}; font-size:11px; padding:6px 12px; border-radius:4px; font-weight:700;">
                    Monthly Webhook: {{ $monthlyWebhookSetting->is_enabled && $monthlyWebhookSetting->url ? 'Active & Configured' : 'Disabled / Unconfigured' }}
                </span>
            @endif
            @if(auth()->user()->role === 'super_admin')
                <a href="{{ route('settings.webhooks') }}" class="btn small" style="background:#f8fafc; color:#475569; border:1px solid #cbd5e1; font-size:11px; padding:5px 10px;">
                    ⚙️ Webhooks &amp; Authorizations
                </a>
            @endif
            <a href="{{ route('invoices.delivery_queue') }}" class="btn small" style="background:#ede9fe; color:#6d28d9; border:1px solid #c4b5fd; font-size:11px; padding:5px 10px; font-weight:700;">
                📋 Full Delivery Queue →
            </a>
        </div>
    </div>

    {{-- DASHBOARD KPI SUMMARY CARDS (SECTION 8) --}}
    <div class="kpi-grid">
        {{-- Card 1: Billing Month Selector --}}
        <div class="kpi-card" style="border-left: 4px solid #7c3aed;">
            <div class="kpi-label">📅 Billing Month</div>
            <form method="get" action="/invoices" id="month-selector-form" style="margin:4px 0 0 0;">
                <input type="hidden" name="tab" value="{{ $currentTab }}">
                @if(!empty($currentFilters['grade']))
                    <input type="hidden" name="grade" value="{{ $currentFilters['grade'] }}">
                @endif
                @if(!empty($currentFilters['department_id']))
                    <input type="hidden" name="department_id" value="{{ $currentFilters['department_id'] }}">
                @endif
                @if(!empty($currentFilters['campus_id']))
                    <input type="hidden" name="campus_id" value="{{ $currentFilters['campus_id'] }}">
                @endif
                <select name="period" class="input" style="font-weight:700; font-size:14px; padding:4px 8px; width:100%; border-color:#cbd5e1; background:#f8fafc;" onchange="this.form.submit()" title="Change Billing Month">
                    @foreach($availableMonths as $m)
                        @php($mLabel = \Carbon\Carbon::createFromFormat('Y-m', $m)->format('F Y'))
                        <option value="{{ $m }}" @selected($selectedPeriod === $m)>{{ $mLabel }}</option>
                    @endforeach
                </select>
            </form>
            <div class="kpi-desc">Latest generated billing cycle</div>
        </div>

        {{-- Card 2: Awaiting Review --}}
        <div class="kpi-card" style="border-left: 4px solid #f59e0b;">
            <div class="kpi-label">📋 Challans Awaiting Review</div>
            <div class="kpi-val" style="color:#d97706;">{{ number_format($dashboardStats['awaiting_review']) }}</div>
            <div class="kpi-desc">Unsent current monthly challans</div>
        </div>

        {{-- Card 3: Ready to Send Families --}}
        <div class="kpi-card" style="border-left: 4px solid #10b981;">
            <div class="kpi-label">👥 Ready to Send Families</div>
            <div class="kpi-val" style="color:#059669;">{{ number_format($dashboardStats['ready_families']) }}</div>
            <div class="kpi-desc">Consolidated recipient families</div>
        </div>

        {{-- Card 4: Processing --}}
        <div class="kpi-card" style="border-left: 4px solid #0284c7;">
            <div class="kpi-label">⏳ Processing</div>
            <div class="kpi-val" style="color:#0284c7;">{{ number_format($dashboardStats['processing']) }}</div>
            <div class="kpi-desc">Queued in in-flight batches</div>
        </div>

        {{-- Card 5: Submitted to n8n --}}
        <div class="kpi-card" style="border-left: 4px solid #6d28d9;">
            <div class="kpi-label">📬 Submitted to n8n</div>
            <div class="kpi-val" style="color:#6d28d9;">{{ number_format($dashboardStats['submitted']) }}</div>
            <div class="kpi-desc">Accepted by webhook / delivered</div>
        </div>

        {{-- Card 6: Needs Attention --}}
        <div class="kpi-card" style="border-left: 4px solid #ef4444;">
            <div class="kpi-label">⚠️ Needs Attention</div>
            <div class="kpi-val" style="color:#dc2626;">{{ number_format($dashboardStats['attention']) }}</div>
            <div class="kpi-desc">Failed or contact blocked</div>
        </div>
    </div>

    {{-- 5 CLEAR WORKFLOW SECTIONS (SECTION 5) --}}
    <nav class="iq-nav-tabs" aria-label="Invoice Delivery Workflow Sections">
        <a href="?tab=ready&period={{ $selectedPeriod }}" class="iq-tab-item @if($currentTab === 'ready') active @endif">
            📤 <strong>Ready to Send</strong>
            <span class="iq-tab-badge" style="background:#ede9fe; color:#6d28d9;">{{ number_format($dashboardStats['awaiting_review']) }}</span>
        </a>
        <a href="?tab=processing&period={{ $selectedPeriod }}" class="iq-tab-item @if($currentTab === 'processing') active @endif">
            ⏳ <strong>Processing</strong>
            @if($dashboardStats['processing'] > 0)
                <span class="iq-tab-badge" style="background:#e0f2fe; color:#0369a1;">{{ number_format($dashboardStats['processing']) }}</span>
            @endif
        </a>
        <a href="?tab=sent&period={{ $selectedPeriod }}" class="iq-tab-item @if($currentTab === 'sent') active @endif">
            📬 <strong>Sent Invoices</strong>
            <span class="iq-tab-badge" style="background:#dcfce7; color:#166534;">{{ number_format($dashboardStats['submitted']) }}</span>
        </a>
        <a href="?tab=attention&period={{ $selectedPeriod }}" class="iq-tab-item @if($currentTab === 'attention') active @endif">
            ⚠️ <strong>Needs Attention</strong>
            @if($dashboardStats['attention'] > 0)
                <span class="iq-tab-badge" style="background:#fee2e2; color:#991b1b;">{{ number_format($dashboardStats['attention']) }}</span>
            @endif
        </a>
        <a href="?tab=history&period={{ $selectedPeriod }}" class="iq-tab-item @if($currentTab === 'history') active @endif">
            📜 <strong>Financial Invoice History</strong>
        </a>
    </nav>

    {{-- TAB 1: READY TO SEND --}}
    @if($currentTab === 'ready')
        {{-- FILTERS BAR --}}
        <form method="get" class="iq-filter-row" style="display:flex; flex-wrap:wrap; gap:8px; align-items:center; background:#f8fafc; padding:12px; border-radius:6px; border:1px solid #e2e8f0; margin-bottom:14px;">
            <input type="hidden" name="tab" value="ready">
            <input type="hidden" name="period" value="{{ $selectedPeriod }}">

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

            <select class="input" name="contact_status" style="min-width:140px;" title="Contact Eligibility">
                <option value="all" @selected(request('contact_status','all') === 'all')>All Contacts</option>
                <option value="eligible_only" @selected(request('contact_status') === 'eligible_only')>Verified &amp; Consented</option>
                <option value="ineligible_only" @selected(request('contact_status') === 'ineligible_only')>Unverified / Missing Consent</option>
            </select>

            <input type="text" name="search" class="input" value="{{ request('search') }}" placeholder="Search family, student, GR, invoice..." style="min-width:200px;">

            <select class="input" name="per_page" style="width:90px;" title="Rows per page">
                <option value="25" @selected($perPage == 25)>25 / page</option>
                <option value="50" @selected($perPage == 50)>50 / page</option>
                <option value="100" @selected($perPage == 100)>100 / page</option>
            </select>

            <button type="submit" class="btn small" style="background:#7c3aed; color:#fff; font-weight:600; padding:6px 14px;">Filter</button>
            <a href="?tab=ready&period={{ $selectedPeriod }}" class="btn small gray" style="padding:6px 12px;">Reset</a>
        </form>

        {{-- SELECTION RECONCILIATION & SNAPSHOT BANNER --}}
        @if($selectionSnapshot)
            <div class="batch-snapshot-card">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                    <div>
                        <strong style="color:#5b21b6; font-size:14px;">📋 Selection Snapshot &amp; Delivery Reconciliation</strong>
                        <div style="font-size:11px; color:#6b7280; font-family:monospace;">Snapshot ID: {{ $selectionSnapshot['snapshot_id'] }}</div>
                    </div>
                    <div style="display:flex; gap:6px; flex-wrap:wrap;">
                        @foreach($selectionSnapshot['currencies_summary'] as $cCurr => $cStat)
                            <span class="badge" style="background:#ffffff; color:#3730a3; border:1px solid #c7d2fe; font-size:11px; font-weight:700; padding:4px 8px;">
                                {{ $cCurr }}: {{ \App\Services\Money::format($cStat['outstanding_minor']) }} ({{ $cStat['count'] }})
                            </span>
                        @endforeach
                    </div>
                </div>

                <div style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:6px; padding:10px 14px; margin-top:12px; font-size:12px; color:#334155; line-height:1.5;">
                    <div>
                        • <strong>Financial Records:</strong> <strong>{{ number_format($selectionSnapshot['total_matched_invoices']) }}</strong> raw financial invoices match current filters. <strong>{{ number_format($selectionSnapshot['matched_superseded_invoices_count'] ?? 0) }}</strong> of these are prior unpaid invoices consolidated into previous dues.
                    </div>
                    <div>
                        • <strong>Delivery Targets:</strong> Out of <strong>{{ number_format($selectionSnapshot['total_delivery_targets'] ?? 0) }}</strong> latest monthly challan targets,
                        @if(!empty($selectionSnapshot['already_submitted_recipients_count']))
                            <strong>{{ $selectionSnapshot['already_submitted_recipients_count'] }}</strong> was already submitted in a previous batch.
                        @endif
                        @if(!empty($selectionSnapshot['contact_ineligible_count']))
                            <strong>{{ $selectionSnapshot['contact_ineligible_count'] }}</strong> is excluded pending contact verification/consent.
                        @endif
                        This leaves exactly <strong>{{ number_format($selectionSnapshot['total_eligible_recipients']) }} new notifications to queue</strong> across <strong>{{ number_format($selectionSnapshot['distinct_family_count']) }} eligible families</strong>.
                    </div>
                </div>

                {{-- SIBLING CONSOLIDATION SUMMARY --}}
                @if(!empty($selectionSnapshot['target_grade']))
                    <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:6px; padding:10px 14px; margin-top:10px; font-size:12px;">
                        <span style="background:#7c3aed; color:#fff; font-size:11px; font-weight:700; padding:2px 8px; border-radius:4px;">
                            Target Grade: {{ $selectionSnapshot['target_grade'] }}
                        </span>
                        @if(!empty($selectionSnapshot['sibling_students_count']))
                            <span style="background:#dbeafe; color:#1e40af; font-size:11px; font-weight:600; padding:2px 8px; border-radius:4px; margin-left:6px;">
                                + {{ $selectionSnapshot['sibling_students_count'] }} Legitimate Sibling(s) across other grades
                            </span>
                        @endif
                    </div>
                @endif
            </div>
        @endif

        {{-- BATCH ACTION & SEND CONTROLS --}}
        <form method="post" action="/invoices/send-batch" id="iq-send-form">
            @csrf
            <input type="hidden" name="webhook_type" value="monthly">
            <input type="hidden" name="selection_mode" id="selection-mode" value="page">
            <input type="hidden" name="campus_id" value="{{ request('campus_id') }}">
            <input type="hidden" name="department_id" value="{{ request('department_id') }}">
            <input type="hidden" name="grade" value="{{ request('grade') }}">
            <input type="hidden" name="period" value="{{ $selectedPeriod }}">
            <input type="hidden" name="contact_status" value="{{ request('contact_status', 'all') }}">
            <input type="hidden" name="search" value="{{ request('search') }}">
            <input type="hidden" name="snapshot_id" value="{{ $selectionSnapshot['snapshot_id'] ?? '' }}">
            <div id="confirmed-grades-container">
                @if(!empty($selectionSnapshot['all_grades']))
                    @foreach($selectionSnapshot['all_grades'] as $g)
                        <input type="hidden" name="confirmed_grades[]" value="{{ $g }}">
                    @endforeach
                @endif
            </div>

            <div class="selection-toggle-bar">
                <span style="font-size:12px; font-weight:700; color:#475569;">Selection Scope:</span>
                <button type="button" class="btn-toggle-select active" id="btn-scope-page">
                    Select Current Page ({{ $readyChallans->count() }})
                </button>
                <button type="button" class="btn-toggle-select" id="btn-scope-all">
                    Select All Matching Filters ({{ number_format($selectionSnapshot['total_eligible_recipients'] ?? 0) }} Eligible Families)
                </button>
                <button type="button" class="btn-toggle-select" id="btn-scope-clear" style="margin-left:auto; color:#ef4444; border-color:#fca5a5;">
                    Clear Selection
                </button>
            </div>

            <div class="iq-send-controls" style="display:flex; justify-content:space-between; align-items:center; margin:15px 0; background:#f8fafc; padding:12px 16px; border-radius:6px; border:1px solid #e2e8f0; flex-wrap:wrap; gap:12px;">
                <div style="display:flex; align-items:center; gap:15px;">
                    <label style="font-weight:600;"><input type="checkbox" name="channels[]" value="whatsapp" checked> WhatsApp via n8n</label>
                    <label style="font-weight:600;"><input type="checkbox" name="channels[]" value="email"> Email via n8n</label>
                    <span class="muted" style="font-size:11px;">Verified contact required; WhatsApp also requires opt-in.</span>
                </div>
                <div style="display:flex; align-items:center; gap:12px;">
                    <label style="font-size:12px; font-weight:700; color:#1e293b; display:flex; align-items:center; gap:6px; cursor:pointer;">
                        <input type="checkbox" name="review_confirmed" value="1" id="review-confirmed-input" required>
                        I have reviewed the selected families &amp; approve this delivery batch
                    </label>
                    <button class="btn purple" type="submit" id="btn-submit-send" style="font-size:13px; font-weight:700; padding:8px 18px;">
                        Queue Approved Monthly Batch to Webhook
                    </button>
                </div>
            </div>

            {{-- READY TO SEND CHALLANS TABLE --}}
            <div class="table-wrap">
                <table class="table iq-invoice-review">
                    <thead>
                        <tr>
                            <th style="width:40px;"><input id="invoice-select-all" type="checkbox" aria-label="Select all on this page"></th>
                            <th>Invoice / Period</th>
                            <th>Family &amp; Guardian</th>
                            <th>Students &amp; Siblings</th>
                            <th>Current Fee</th>
                            <th>Previous Unpaid Dues</th>
                            <th>Total Payable</th>
                            <th>WhatsApp Destination</th>
                            <th style="text-align:right;">Challan PDF</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($readyChallans as $row)
                        <tr>
                            <td>
                                <input type="checkbox" class="invoice-check" name="invoice_ids[]" value="{{ $row->invoice_id }}"
                                       data-invoice="{{ $row->invoice_number }}"
                                       data-family="{{ $row->family_code }}"
                                       data-status="{{ $row->invoice_status }}">
                            </td>
                            <td>
                                <strong>{{ $row->invoice_number }}</strong>
                                <div class="muted" style="font-size:11px;">{{ \Carbon\Carbon::parse($row->billing_period)->format('M Y') }} &bull; Due: {{ $row->due_date }}</div>
                            </td>
                            <td>
                                <div><strong>{{ $row->family_code ?: ('Family #' . $row->family_id) }}</strong></div>
                                <div style="font-size:11px; color:#475569;">{{ $row->guardian_name ?: 'Guardian' }}</div>
                            </td>
                            <td>
                                @foreach($row->invoice_students as $st)
                                    <div style="font-size:11.5px; font-weight:600; color:#1e293b;">
                                        &bull; {{ $st->student_name }} <span class="muted">(GR: {{ $st->gr_number }})</span>
                                        <span class="badge" style="background:#e0e7ff; color:#3730a3; font-size:10px; padding:1px 5px;">{{ $st->grade_label ?: 'Unassigned' }}</span>
                                    </div>
                                @endforeach
                                @if($row->family_siblings->count() > $row->invoice_students->count())
                                    <div style="margin-top:3px;">
                                        <span class="badge" style="background:#dbeafe; color:#1e40af; font-size:10px; padding:1px 6px;">
                                            + {{ $row->family_siblings->count() - $row->invoice_students->count() }} Sibling(s) in Family
                                        </span>
                                    </div>
                                @endif
                            </td>
                            <td>
                                <strong style="color:#0f172a;">{{ $row->currency }} {{ \App\Services\Money::format($row->total_minor ?? $row->total_payable_minor ?? 0) }}</strong>
                            </td>
                            <td>
                                @if(!empty($row->dues_by_month))
                                    <div><strong>{{ $row->formatted_prev_dues }}</strong></div>
                                    <div style="margin-top:2px;">
                                        @foreach($row->dues_by_month as $mDue)
                                            <span class="due-month-pill" title="{{ $mDue['currency'] }} {{ \App\Services\Money::format($mDue['dues_minor']) }}">
                                                {{ \Carbon\Carbon::createFromFormat('Y-m', $mDue['month'])->format('M-y') }}: {{ \App\Services\Money::format($mDue['dues_minor']) }}
                                            </span>
                                        @endforeach
                                    </div>
                                @else
                                    <span class="muted" style="font-size:11px;">No previous dues</span>
                                @endif
                            </td>
                            <td>
                                @if($row->is_multi_currency)
                                    <div>
                                        @foreach($row->currencies_map as $cCode => $cData)
                                            <div style="margin-bottom:2px;">
                                                <span class="badge" style="background:#fee2e2; color:#991b1b; font-weight:800; font-size:11px;">
                                                    {{ $cData['formatted'] }}
                                                </span>
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <strong style="color:#991b1b; font-size:13px;">{{ $row->formatted_total_payable }}</strong>
                                @endif
                            </td>
                            <td>
                                @if(!empty($row->whatsapp))
                                    <div style="font-weight:700; font-size:12px;">{{ $row->whatsapp }}</div>
                                    <div style="margin-top:2px;">
                                        @if($row->contact_verified && $row->messaging_opt_in)
                                            <span style="background:#dcfce7; color:#166534; font-size:9.5px; padding:1px 5px; border-radius:3px; font-weight:700;">Verified &amp; Opted In</span>
                                        @elseif(!$row->contact_verified)
                                            <span style="background:#fee2e2; color:#991b1b; font-size:9.5px; padding:1px 5px; border-radius:3px; font-weight:700;">Unverified</span>
                                        @else
                                            <span style="background:#ffedd5; color:#9a3412; font-size:9.5px; padding:1px 5px; border-radius:3px; font-weight:700;">Consent Missing</span>
                                        @endif
                                    </div>
                                @else
                                    <span class="muted" style="font-size:11px;">No WhatsApp</span>
                                @endif
                            </td>
                            <td style="text-align:right;">
                                <div style="display:flex; flex-direction:column; gap:3px; align-items:flex-end;">
                                    <a href="{{ $row->consolidated_challan_url }}" target="_blank" rel="noopener" class="btn small" style="background:#7c3aed; color:#fff; font-size:10px; padding:3px 8px; font-weight:700;">
                                        📄 Preview Challan
                                    </a>
                                    <a href="/invoices/{{ $row->invoice_id }}/pdf" target="_blank" rel="noopener" class="btn small gray" style="font-size:10px; padding:2px 6px;">
                                        Invoice PDF
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" style="text-align:center; padding:30px; color:#64748b;">
                                <strong>No monthly challans awaiting review for {{ $dashboardStats['selected_month'] }}.</strong><br>
                                <span class="muted" style="font-size:12px;">All eligible challans may already be queued, submitted to n8n, or superseded.</span>
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="iq-confirm" id="send-preview" role="status" aria-live="polite" style="margin-top:14px; padding:12px 16px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; font-weight:600; color:#334155;">
                0 invoice(s) selected on this page. Select invoices or choose "Select All Matching Filters" to approve batch delivery.
            </div>
        </form>

        <div style="margin-top:14px;">
            {{ $readyChallans->links() }}
        </div>

    {{-- TAB 2: PROCESSING --}}
    @elseif($currentTab === 'processing')
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <div>
                <h3 style="margin:0; color:#0f172a;">Active In-Flight Batches &amp; Queued Jobs</h3>
                <p class="muted" style="margin:2px 0 0 0; font-size:12px;">These batches have been approved by staff and are currently being dispatched to n8n in chunks.</p>
            </div>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Notification ID</th>
                        <th>Batch ID</th>
                        <th>Chunk #</th>
                        <th>Invoice #</th>
                        <th>Family</th>
                        <th>WhatsApp</th>
                        <th>Status</th>
                        <th>Queued At</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($tabQueuePage as $qRow)
                    <tr>
                        <td><code>#{{ $qRow->notification_id }}</code></td>
                        <td>
                            @if(!empty($qRow->batch_id))
                                <a href="{{ route('invoices.batch_details', $qRow->batch_id) }}" style="color:#7c3aed; font-weight:700;">
                                    <code>{{ $qRow->batch_id }}</code>
                                </a>
                            @else
                                <span class="muted">—</span>
                            @endif
                        </td>
                        <td>Chunk {{ $qRow->chunk_index ?: 1 }}</td>
                        <td><strong>{{ $qRow->invoice_number }}</strong></td>
                        <td>{{ $qRow->family_code ?: 'Family #' . $qRow->family_id }}</td>
                        <td>{{ $qRow->whatsapp ?: 'N/A' }}</td>
                        <td><span class="badge-status badge-queued">QUEUED</span></td>
                        <td>{{ $qRow->created_at ? substr($qRow->created_at, 0, 16) : '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" style="text-align:center; padding:30px; color:#64748b;">
                            <strong>No background batch jobs currently in-flight.</strong><br>
                            <span class="muted" style="font-size:12px;">All approved batches have completed submission.</span>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div style="margin-top:14px;">
            {{ $tabQueuePage->links() }}
        </div>

    {{-- TAB 3: SENT INVOICES --}}
    @elseif($currentTab === 'sent')
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <div>
                <h3 style="margin:0; color:#0f172a;">Sent Invoices &amp; Verified Delivery Status</h3>
                <p class="muted" style="margin:2px 0 0 0; font-size:12px;">Accepted by n8n webhook (HTTP 200/202). WhatsApp delivery and read states update via verified downstream callbacks.</p>
            </div>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Batch ID</th>
                        <th>Invoice #</th>
                        <th>Family</th>
                        <th>WhatsApp</th>
                        <th>Webhook Submission</th>
                        <th>WhatsApp Delivery</th>
                        <th>Provider Msg ID</th>
                        <th>Submitted At</th>
                        <th>PDF</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($tabQueuePage as $sRow)
                    <tr>
                        <td>
                            @if(!empty($sRow->batch_id))
                                <a href="{{ route('invoices.batch_details', $sRow->batch_id) }}" style="color:#7c3aed; font-weight:700;">
                                    <code>{{ $sRow->batch_id }}</code>
                                </a>
                            @else
                                <span class="muted">Single</span>
                            @endif
                        </td>
                        <td><strong>{{ $sRow->invoice_number }}</strong></td>
                        <td>{{ $sRow->family_code ?: 'Family #' . $sRow->family_id }}</td>
                        <td>{{ $sRow->whatsapp }}</td>
                        <td><span class="badge-status badge-submitted">SUBMITTED (HTTP 200)</span></td>
                        <td>
                            @if($sRow->whatsapp_delivery_status === 'read')
                                <span class="badge-status badge-read">READ</span>
                            @elseif($sRow->whatsapp_delivery_status === 'delivered')
                                <span class="badge-status badge-delivered">DELIVERED</span>
                            @else
                                <span class="badge-status badge-queued">PENDING DISPATCH</span>
                            @endif
                        </td>
                        <td><code>{{ $sRow->provider_message_id ?: '—' }}</code></td>
                        <td>{{ $sRow->submitted_at ? substr($sRow->submitted_at, 0, 16) : '—' }}</td>
                        <td>
                            <a href="{{ $sRow->consolidated_challan_url }}" target="_blank" rel="noopener" class="btn small gray" style="font-size:10px; padding:2px 6px;">Challan PDF</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" style="text-align:center; padding:30px; color:#64748b;">
                            <strong>No invoices submitted for this month yet.</strong>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div style="margin-top:14px;">
            {{ $tabQueuePage->links() }}
        </div>

    {{-- TAB 4: NEEDS ATTENTION --}}
    @elseif($currentTab === 'attention')
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <div>
                <h3 style="margin:0; color:#b91c1c;">Needs Attention · Delivery Failures &amp; Contact Issues</h3>
                <p class="muted" style="margin:2px 0 0 0; font-size:12px;">Inspect diagnostic error reasons and safely retry failed notifications without resending successful ones.</p>
            </div>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Family</th>
                        <th>WhatsApp</th>
                        <th>Status</th>
                        <th>Diagnostic Details</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($tabQueuePage as $aRow)
                    <tr>
                        <td><strong>{{ $aRow->invoice_number }}</strong></td>
                        <td>{{ $aRow->family_code ?: 'Family #' . $aRow->family_id }}</td>
                        <td>
                            <div>{{ $aRow->whatsapp ?: 'No phone' }}</div>
                            <div class="muted" style="font-size:10px;">{{ $aRow->whatsapp_verified ? 'Verified' : 'Unverified' }} &bull; {{ $aRow->messaging_opt_in ? 'Opted In' : 'No Consent' }}</div>
                        </td>
                        <td>
                            @if($aRow->notification_status === 'failed')
                                <span class="badge-status badge-failed">FAILED</span>
                            @else
                                <span class="badge-status badge-blocked">BLOCKED</span>
                            @endif
                        </td>
                        <td style="max-width:320px; color:#b91c1c; font-size:11.5px; word-break:break-all;">
                            <strong>{{ $aRow->notification_error ?: 'No diagnostic error returned' }}</strong>
                        </td>
                        <td>
                            <form method="post" action="{{ route('invoices.notifications.retry', $aRow->notification_id) }}" style="margin:0;">
                                @csrf
                                <button class="btn small" type="submit" style="background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; font-weight:700;">
                                    Retry Failed Only
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="text-align:center; padding:30px; color:#166534;">
                            <strong>🎉 Zero delivery failures or blocked contacts for this period!</strong>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div style="margin-top:14px;">
            {{ $tabQueuePage->links() }}
        </div>

    {{-- TAB 5: FINANCIAL INVOICE HISTORY --}}
    @elseif($currentTab === 'history')
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <div>
                <h3 style="margin:0; color:#0f172a;">Financial Invoice History &amp; Complete Ledger</h3>
                <p class="muted" style="margin:2px 0 0 0; font-size:12px;">All historical financial invoice records, including MySkool opening balances, previous months, superseded invoices, and settled receipts.</p>
            </div>
        </div>

        {{-- HISTORY FILTERS --}}
        <form method="get" class="iq-filter-row" style="display:flex; flex-wrap:wrap; gap:8px; align-items:center; background:#f8fafc; padding:12px; border-radius:6px; border:1px solid #e2e8f0; margin-bottom:14px;">
            <input type="hidden" name="tab" value="history">

            <select class="input" name="payment_status" style="min-width:130px;">
                <option value="all" @selected(request('payment_status') === 'all')>All Payment Statuses</option>
                <option value="paid" @selected(request('payment_status') === 'paid')>Fully Paid</option>
                <option value="partial" @selected(request('payment_status') === 'partial')>Partially Paid</option>
                <option value="unpaid" @selected(request('payment_status') === 'unpaid')>Unpaid Only</option>
            </select>

            <input type="text" name="search" class="input" value="{{ request('search') }}" placeholder="Search invoice, family, guardian..." style="min-width:220px;">

            <select class="input" name="per_page" style="width:90px;">
                <option value="25" @selected($perPage == 25)>25 / page</option>
                <option value="50" @selected($perPage == 50)>50 / page</option>
                <option value="100" @selected($perPage == 100)>100 / page</option>
            </select>

            <button type="submit" class="btn small" style="background:#7c3aed; color:#fff; font-weight:600; padding:6px 14px;">Filter</button>
            <a href="?tab=history" class="btn small gray" style="padding:6px 12px;">Reset</a>
        </form>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Family</th>
                        <th>Period / Type</th>
                        <th>Department</th>
                        <th>Total Billed</th>
                        <th>Paid</th>
                        <th>Balance</th>
                        <th>Status</th>
                        <th>PDF</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($financialHistoryPage as $hRow)
                    @php($hBal = max(0, (int)$hRow->total_minor - (int)$hRow->paid_minor))
                    <tr>
                        <td><strong>{{ $hRow->invoice_number }}</strong></td>
                        <td>{{ $hRow->family_code ?: ('Family #' . $hRow->family_id) }}</td>
                        <td>
                            @if($hRow->billing_period)
                                {{ \Carbon\Carbon::parse($hRow->billing_period)->format('M Y') }}
                            @else
                                <span class="badge" style="background:#f1f5f9; color:#475569; font-size:10px;">MySkool Opening</span>
                            @endif
                        </td>
                        <td>{{ $hRow->department_name ?: 'General' }}</td>
                        <td>{{ $hRow->currency }} {{ \App\Services\Money::format($hRow->total_minor) }}</td>
                        <td>{{ \App\Services\Money::format($hRow->paid_minor) }}</td>
                        <td><strong style="color:{{ $hBal > 0 ? '#b91c1c' : '#166534' }};">{{ \App\Services\Money::format($hBal) }}</strong></td>
                        <td><span class="badge" style="font-size:10.5px;">{{ ucfirst($hRow->status) }}</span></td>
                        <td>
                            <a href="/invoices/{{ $hRow->id }}/pdf" target="_blank" rel="noopener" class="btn small gray" style="font-size:10px; padding:2px 6px;">Invoice PDF</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" style="text-align:center; padding:30px; color:#64748b;">
                            No financial invoices found.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div style="margin-top:14px;">
            {{ $financialHistoryPage->links() }}
        </div>
    @endif
</div>

{{-- CLIENT-SIDE JAVASCRIPT FOR SELECTION & CONFIRMATION --}}
<script>
(()=>{
    const boxes = [...document.querySelectorAll('.invoice-check')];
    const all = document.getElementById('invoice-select-all');
    const summary = document.getElementById('send-preview');
    const form = document.getElementById('iq-send-form');
    if (!form) return;

    const modeInput = document.getElementById('selection-mode');
    const reviewCheckbox = document.getElementById('review-confirmed-input');
    const btnScopePage = document.getElementById('btn-scope-page');
    const btnScopeAll = document.getElementById('btn-scope-all');
    const btnScopeClear = document.getElementById('btn-scope-clear');

    const serverTotalEligible = {{ (int)($selectionSnapshot['total_eligible_recipients'] ?? 0) }};
    const targetGrade = @json($selectionSnapshot['target_grade'] ?? null);
    const siblingCount = {{ (int)($selectionSnapshot['sibling_students_count'] ?? 0) }};

    let selectionMode = 'page';

    function update() {
        if (!summary) return;
        if (selectionMode === 'all_matching') {
            modeInput.value = 'all_matching';
            btnScopeAll?.classList.add('active');
            btnScopePage?.classList.remove('active');
            let gradeLabel = targetGrade ? `Target Grade: ${targetGrade}` : 'All Grades';
            if (siblingCount > 0) gradeLabel += ` (+ ${siblingCount} siblings across other grades)`;
            summary.textContent = `All Matching Filters selected: ${serverTotalEligible} eligible families ready for batch delivery (${gradeLabel}).`;
            return;
        }

        modeInput.value = 'page';
        btnScopePage?.classList.add('active');
        btnScopeAll?.classList.remove('active');

        const chosen = boxes.filter(b => b.checked);
        if (chosen.length) {
            summary.textContent = `${chosen.length} family challan(s) selected on this page.`;
        } else {
            summary.textContent = '0 invoice(s) selected on this page. Select invoices or choose "Select All Matching Filters" to approve batch delivery.';
        }
    }

    btnScopePage?.addEventListener('click', () => {
        selectionMode = 'page';
        boxes.forEach(b => b.checked = true);
        if (all) all.checked = true;
        update();
    });

    btnScopeAll?.addEventListener('click', () => {
        selectionMode = 'all_matching';
        boxes.forEach(b => b.checked = true);
        if (all) all.checked = true;
        update();
    });

    btnScopeClear?.addEventListener('click', () => {
        selectionMode = 'page';
        boxes.forEach(b => b.checked = false);
        if (all) all.checked = false;
        update();
    });

    boxes.forEach(b => b.addEventListener('change', () => {
        selectionMode = 'page';
        update();
    }));

    all?.addEventListener('change', () => {
        selectionMode = 'page';
        boxes.forEach(b => b.checked = all.checked);
        update();
    });

    form.addEventListener('submit', e => {
        const channels = [...form.querySelectorAll('input[name="channels[]"]')].filter(c => c.checked).map(c => c.value);
        if (!channels.length) {
            e.preventDefault();
            alert('Please choose at least one delivery channel (WhatsApp or Email).');
            return;
        }

        if (!reviewCheckbox || !reviewCheckbox.checked) {
            e.preventDefault();
            alert('Please acknowledge: "I have reviewed the selected families & approve this delivery batch."');
            reviewCheckbox?.focus();
            return;
        }

        if (selectionMode === 'all_matching') {
            if (serverTotalEligible === 0) {
                e.preventDefault();
                alert('No eligible recipients match the applied filters.');
                return;
            }
            if (!confirm(`Queue approved Monthly Batch for ${serverTotalEligible} eligible families across ALL matching pages to the Monthly Webhook?\n\nChannels: ${channels.join(' and ')}\n\nOne latest consolidated challan per family.`)) {
                e.preventDefault();
            }
            return;
        }

        const chosen = boxes.filter(b => b.checked);
        if (!chosen.length) {
            e.preventDefault();
            alert('Please select at least one family challan on the current page, or choose "Select All Matching Filters".');
            return;
        }

        if (!confirm(`Queue ${chosen.length} selected family challans on this page to the Monthly Webhook?\n\nChannels: ${channels.join(' and ')}`)) {
            e.preventDefault();
        }
    });

    update();
})();
</script>
@endsection
