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
            @if($currentTab === 'paid')
                <h2 style="margin:0 0 4px 0; color:#0f172a;">Paid Invoices · Review &amp; Outbound Delivery</h2>
                <p class="muted" style="margin:0; font-size:12px;">Review settled parent payments and authorize delivery to the <strong>Paid Invoice Webhook</strong> (<code>invoice.paid.batch</code>).</p>
            @else
                <h2 style="margin:0 0 4px 0; color:#0f172a;">Monthly Invoices · Review &amp; Outbound Delivery</h2>
                <p class="muted" style="margin:0; font-size:12px;">Review active consolidated family challans for the selected month and authorize batch delivery to the <strong>Monthly Invoice Webhook</strong> (<code>invoice.monthly.batch</code>).</p>
            @endif
        </div>
        <div style="display:flex; align-items:center; gap:8px;">
            @if($currentTab === 'paid')
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

        {{-- Card: Paid Invoices --}}
        <div class="kpi-card" style="border-left: 4px solid #16a34a;">
            <div class="kpi-label">💳 Paid Invoices</div>
            <div class="kpi-val" style="color:#16a34a;">{{ number_format($dashboardStats['paid_invoices'] ?? 0) }}</div>
            <div class="kpi-desc">Fully settled parent invoices</div>
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
        <a href="?tab=paid&period={{ $selectedPeriod }}" class="iq-tab-item @if($currentTab === 'paid') active @endif">
            💳 <strong>Paid Invoices</strong>
            @if(($dashboardStats['paid_invoices'] ?? 0) > 0)
                <span class="iq-tab-badge" style="background:#dcfce7; color:#166534;">{{ number_format($dashboardStats['paid_invoices']) }}</span>
            @endif
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

    {{-- TAB: PAID INVOICES --}}
    @elseif($currentTab === 'paid')
        {{-- FILTERS BAR --}}
        <form method="get" class="iq-filter-row" style="display:flex; flex-wrap:wrap; gap:8px; align-items:center; background:#f8fafc; padding:12px; border-radius:6px; border:1px solid #e2e8f0; margin-bottom:14px;">
            <input type="hidden" name="tab" value="paid">
            <input type="hidden" name="period" value="{{ $selectedPeriod }}">

            @if(in_array(auth()->user()->role, ['super_admin', 'finance_admin'], true))
                <select class="input" name="campus_id" style="min-width:140px;" title="Filter by Campus">
                    <option value="">All Campuses</option>
                    @foreach($campuses as $c)
                        <option value="{{ $c->id }}" @selected(request('campus_id') == $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>
            @endif

            <select class="input" name="department_id" style="min-width:140px;" title="Filter by Department">
                <option value="">All Departments</option>
                @foreach($departments as $d)
                    <option value="{{ $d->id }}" @selected(request('department_id') == $d->id)>{{ $d->name }}</option>
                @endforeach
            </select>

            <select class="input" name="grade" style="min-width:130px;" title="Filter by Grade">
                <option value="">All Grades</option>
                @foreach($gradeOptions as $grade)
                    <option value="{{ $grade }}" @selected(request('grade') === $grade)>{{ $grade }}</option>
                @endforeach
            </select>

            <select class="input" name="contact_status" style="min-width:140px;" title="Contact Eligibility">
                <option value="all" @selected(request('contact_status','all') === 'all')>All Contacts</option>
                <option value="eligible_only" @selected(request('contact_status') === 'eligible_only')>Verified &amp; Consented</option>
                <option value="ineligible_only" @selected(request('contact_status') === 'ineligible_only')>Consent Missing / Ineligible</option>
            </select>

            <input type="text" class="input" name="search" value="{{ request('search') }}" placeholder="Search invoice #, family, student, phone..." style="flex:1; min-width:200px;">

            <select class="input" name="per_page" style="width:90px;" title="Items per page" onchange="this.form.submit()">
                <option value="25" @selected($perPage == 25)>25 / page</option>
                <option value="50" @selected($perPage == 50)>50 / page</option>
                <option value="100" @selected($perPage == 100)>100 / page</option>
            </select>

            <button type="submit" class="btn small" style="background:#16a34a; color:#fff; font-weight:600; padding:6px 14px;">Filter</button>
            <a href="?tab=paid&period={{ $selectedPeriod }}" class="btn small gray" style="padding:6px 12px;">Reset</a>
        </form>

        {{-- SELECTION RECONCILIATION & SNAPSHOT BANNER --}}
        @if($selectionSnapshot)
            <div class="batch-snapshot-card" style="border-left-color:#16a34a;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                    <div>
                        <strong style="color:#166534; font-size:14px;">💳 Paid Invoices Selection Snapshot &amp; Delivery Reconciliation</strong>
                        <div style="font-size:11px; color:#6b7280; font-family:monospace;">Snapshot ID: {{ $selectionSnapshot['snapshot_id'] }}</div>
                    </div>
                    <div style="display:flex; gap:6px; flex-wrap:wrap;">
                        @foreach($selectionSnapshot['currencies_summary'] as $cCurr => $cStat)
                            <span class="badge" style="background:#ffffff; color:#166534; border:1px solid #bbf7d0; font-size:11px; font-weight:700; padding:4px 8px;">
                                {{ $cCurr }}: {{ \App\Services\Money::format($cStat['total_minor']) }} ({{ $cStat['count'] }} paid)
                            </span>
                        @endforeach
                    </div>
                </div>

                <div style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:6px; padding:10px 14px; margin-top:12px; font-size:12px; color:#334155; line-height:1.5;">
                    <div>
                        • <strong>Matched Records:</strong> <strong>{{ number_format($selectionSnapshot['total_matched_invoices']) }}</strong> fully settled invoice(s) match current filters.
                    </div>
                    <div>
                        • <strong>Delivery Targets:</strong>
                        @if(!empty($selectionSnapshot['already_submitted_recipients_count']))
                            <strong>{{ $selectionSnapshot['already_submitted_recipients_count'] }}</strong> was already submitted to webhook.
                        @endif
                        @if(!empty($selectionSnapshot['contact_ineligible_count']))
                            <strong>{{ $selectionSnapshot['contact_ineligible_count'] }}</strong> is excluded pending contact verification/consent.
                        @endif
                        This leaves exactly <strong>{{ number_format($selectionSnapshot['total_eligible_recipients']) }} eligible paid invoices to dispatch</strong> to the <strong>Paid Invoice Webhook</strong> (<code>invoice.paid.batch</code>).
                    </div>
                </div>
            </div>
        @endif

        {{-- BATCH ACTION & SEND CONTROLS --}}
        <form method="post" action="/invoices/send-batch" id="iq-send-form">
            @csrf
            <input type="hidden" name="webhook_type" value="paid">
            <input type="hidden" name="paid_only" value="1">
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
                    Select Current Page ({{ $paidInvoicesPage ? $paidInvoicesPage->count() : 0 }})
                </button>
                <button type="button" class="btn-toggle-select" id="btn-scope-all">
                    Select All Matching Filters ({{ number_format($selectionSnapshot['total_eligible_recipients'] ?? 0) }} Eligible Invoices)
                </button>
                <button type="button" class="btn-toggle-select" id="btn-scope-clear" style="margin-left:auto; color:#ef4444; border-color:#fca5a5;">
                    Clear Selection
                </button>
            </div>

            <div class="iq-send-controls" style="display:flex; justify-content:space-between; align-items:center; margin:15px 0; background:#f8fafc; padding:12px 16px; border-radius:6px; border:1px solid #e2e8f0; flex-wrap:wrap; gap:12px;">
                <div style="display:flex; align-items:center; gap:15px;">
                    <label style="font-weight:600;"><input type="checkbox" name="channels[]" value="whatsapp" checked> WhatsApp via Paid Webhook</label>
                    <label style="font-weight:600;"><input type="checkbox" name="channels[]" value="email"> Email via Paid Webhook</label>
                    <span class="muted" style="font-size:11px;">Sends to Paid n8n Webhook only (<code>invoice.paid.batch</code>).</span>
                </div>
                <div style="display:flex; align-items:center; gap:12px;">
                    <label style="font-size:12px; font-weight:700; color:#1e293b; display:flex; align-items:center; gap:6px; cursor:pointer;">
                        <input type="checkbox" name="review_confirmed" value="1" id="review-confirmed-input" required>
                        I have reviewed the selected paid invoices &amp; payment receipts, and approve this delivery batch
                    </label>
                    <button class="btn green" type="submit" id="btn-submit-send" style="background:#16a34a; color:#fff; font-size:13px; font-weight:700; padding:8px 18px; border:none; border-radius:4px; cursor:pointer;">
                        Queue Approved Paid Batch to Webhook
                    </button>
                </div>
            </div>

            {{-- PAID INVOICES TABLE --}}
            <div class="table-wrap">
                <table class="table iq-invoice-review">
                    <thead>
                        <tr>
                            <th style="width:40px;"><input id="invoice-select-all" type="checkbox" aria-label="Select all on this page"></th>
                            <th>Invoice / Period</th>
                            <th>Family &amp; Guardian</th>
                            <th>Student(s) &amp; Grade</th>
                            <th>Payment Receipt(s)</th>
                            <th>Amount Paid</th>
                            <th>WhatsApp Destination</th>
                            <th>Delivery Status</th>
                            <th style="text-align:right;">PDF Documents</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($paidInvoicesPage ?? [] as $row)
                        <tr @if($row->is_already_queued_or_sent) style="background:#fcfdfd; opacity:0.85;" @endif>
                            <td>
                                @if($row->is_already_queued_or_sent)
                                    <input type="checkbox" class="invoice-check" name="invoice_ids[]" value="{{ $row->id }}"
                                           data-invoice="{{ $row->invoice_number }}"
                                           data-family="{{ $row->family_code }}"
                                           data-status="{{ $row->delivery_status }}"
                                           title="Already queued or sent in batch {{ $row->delivery_batch_id }}"
                                           disabled>
                                @else
                                    <input type="checkbox" class="invoice-check" name="invoice_ids[]" value="{{ $row->id }}"
                                           data-invoice="{{ $row->invoice_number }}"
                                           data-family="{{ $row->family_code }}"
                                           data-status="{{ $row->invoice_status }}">
                                @endif
                            </td>
                            <td>
                                <div>
                                    <a href="/invoices/{{ $row->id }}/pdf" target="_blank" rel="noopener" style="font-weight:700; color:#0f172a; text-decoration:none;">
                                        📄 {{ $row->invoice_number }}
                                    </a>
                                </div>
                                <div class="muted" style="font-size:11px;">
                                    {{ \Carbon\Carbon::parse($row->billing_period)->format('M Y') }} &bull; Issued: {{ $row->issued_on }}
                                </div>
                                @if($row->department_name)
                                    <span class="badge" style="background:#f1f5f9; color:#475569; font-size:10px; padding:1px 5px;">{{ $row->department_name }}</span>
                                @endif
                            </td>
                            <td>
                                <div><strong>{{ $row->family_code ?: ('Family #' . $row->family_id) }}</strong></div>
                                <div style="font-size:11px; color:#475569;">{{ $row->guardian_name ?: 'Guardian' }}</div>
                            </td>
                            <td>
                                @if(!empty($row->students) && count($row->students) > 0)
                                    @foreach($row->students as $st)
                                        <div style="font-size:11.5px; font-weight:600; color:#1e293b;">
                                            &bull; {{ $st->name }} <span class="muted">(GR: {{ $st->gr_number }})</span>
                                            <span class="badge" style="background:#e0e7ff; color:#3730a3; font-size:10px; padding:1px 5px;">{{ $st->grade_label ?: 'Unassigned' }}</span>
                                        </div>
                                    @endforeach
                                @else
                                    <span class="muted" style="font-size:11px;">Family Challan</span>
                                @endif
                            </td>
                            <td>
                                @if(!empty($row->receipts) && count($row->receipts) > 0)
                                    @foreach($row->receipts as $rc)
                                        <div style="margin-bottom:4px; padding:3px 6px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:4px; font-size:11.5px;">
                                            <div style="display:flex; justify-content:space-between; align-items:center; gap:6px;">
                                                <a href="{{ $rc['receipt_pdf_url'] }}" target="_blank" rel="noopener" style="font-weight:700; color:#166534; text-decoration:none;">
                                                    🧾 {{ $rc['receipt_number'] }}
                                                </a>
                                                <span style="font-weight:700; color:#166534;">{{ $rc['currency'] }} {{ $rc['formatted_amount'] }}</span>
                                            </div>
                                            <div class="muted" style="font-size:10.5px;">Paid on: {{ $rc['payment_date'] }}</div>
                                        </div>
                                    @endforeach
                                @else
                                    <span class="muted" style="font-size:11px;">Direct Settlement</span>
                                @endif
                            </td>
                            <td>
                                <strong style="color:#16a34a; font-size:13px;">{{ $row->currency }} {{ \App\Services\Money::format($row->paid_minor) }}</strong>
                                <div style="margin-top:2px;">
                                    <span class="badge" style="background:#dcfce7; color:#166534; font-size:10px; padding:2px 6px; font-weight:700;">Fully Paid</span>
                                </div>
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
                            <td>
                                @if($row->is_already_queued_or_sent)
                                    @if(in_array($row->delivery_status, ['submitted', 'delivered', 'read'], true))
                                        <span class="badge" style="background:#dcfce7; color:#166534; font-size:10px; padding:2px 6px; font-weight:700;">
                                            Sent ({{ strtoupper($row->delivery_status) }})
                                        </span>
                                    @elseif($row->delivery_status === 'queued')
                                        <span class="badge" style="background:#e0f2fe; color:#0369a1; font-size:10px; padding:2px 6px; font-weight:700;">
                                            Queued
                                        </span>
                                    @endif
                                    @if($row->delivery_batch_id)
                                        <div class="muted" style="font-size:10px; font-family:monospace; margin-top:2px;">{{ $row->delivery_batch_id }}</div>
                                    @endif
                                @elseif($row->delivery_status === 'failed')
                                    <span class="badge" style="background:#fee2e2; color:#991b1b; font-size:10px; padding:2px 6px; font-weight:700;">
                                        Failed
                                    </span>
                                    @if($row->delivery_error)
                                        <div class="muted" style="font-size:10px; color:#dc2626; margin-top:2px;" title="{{ $row->delivery_error }}">{{ \Illuminate\Support\Str::limit($row->delivery_error, 25) }}</div>
                                    @endif
                                @else
                                    <span class="badge" style="background:#f1f5f9; color:#475569; font-size:10px; padding:2px 6px;">
                                        Ready to Send
                                    </span>
                                @endif
                            </td>
                            <td style="text-align:right;">
                                <div style="display:flex; flex-direction:column; gap:3px; align-items:flex-end;">
                                    <a href="/invoices/{{ $row->id }}/pdf" target="_blank" rel="noopener" class="btn small" style="background:#7c3aed; color:#fff; font-size:10px; padding:3px 8px; font-weight:700;">
                                        📄 Invoice PDF
                                    </a>
                                    @if(!empty($row->receipts) && count($row->receipts) > 0)
                                        <a href="{{ $row->receipts[0]['receipt_pdf_url'] }}" target="_blank" rel="noopener" class="btn small" style="background:#16a34a; color:#fff; font-size:10px; padding:2px 6px; font-weight:700;">
                                            🧾 Receipt PDF
                                        </a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" style="text-align:center; padding:30px; color:#64748b;">
                                <strong>No paid invoices found matching the current filters for {{ $dashboardStats['selected_month'] }}.</strong><br>
                                <span class="muted" style="font-size:12px;">Invoices become visible here once full parent payment is verified and recorded.</span>
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
            {{ $paidInvoicesPage ? $paidInvoicesPage->appends(request()->query())->links() : '' }}
        </div>

    {{-- TAB 2: PROCESSING --}}
    @elseif($currentTab === 'processing')
        {{-- DISPATCHER / WORKER HEALTH BANNER --}}
        @if(!empty($workerStatus))
            @if(!$workerStatus['is_running'] && $workerStatus['pending_jobs'] > 0)
                <div style="background:#fffbeb; border:1px solid #fde68a; border-left:4px solid #f59e0b; border-radius:6px; padding:14px 18px; margin-bottom:18px;">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px;">
                        <div>
                            <div style="display:flex; align-items:center; gap:8px;">
                                <span style="font-size:16px;">⚠️</span>
                                <strong style="color:#92400e; font-size:14px;">Queue Worker is Offline — {{ $workerStatus['pending_jobs'] }} Batch Dispatch Job(s) Buffered</strong>
                            </div>
                            <p style="margin:4px 0 0 24px; color:#78350f; font-size:12.5px; line-height:1.4;">
                                Approved monthly challan batches are waiting in the local queue table because no background worker is running. Notifications will not reach n8n until a worker process is running or the queue is processed.
                            </p>
                            <div style="margin:8px 0 0 24px; font-size:11.5px; color:#b45309;">
                                <strong>Persistent Worker Command:</strong> <code style="background:#fef3c7; padding:2px 6px; border-radius:4px; font-weight:700;">php artisan queue:work --queue=notifications,default --tries=3</code>
                            </div>
                        </div>
                        <form method="post" action="{{ route('invoices.notifications.process_queue') }}" style="margin:0;">
                            @csrf
                            <button type="submit" class="btn" style="background:#d97706; color:#fff; font-weight:700; font-size:12px; padding:8px 14px; border:none; border-radius:5px; cursor:pointer; display:flex; align-items:center; gap:6px; box-shadow:0 1px 2px rgba(0,0,0,0.1);">
                                <span>⚡</span> Process Pending Queue Now
                            </button>
                        </form>
                    </div>
                </div>
            @elseif($workerStatus['is_running'])
                <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-left:4px solid #16a34a; border-radius:6px; padding:10px 16px; margin-bottom:18px; display:flex; justify-content:space-between; align-items:center;">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span style="display:inline-block; width:10px; height:10px; background:#16a34a; border-radius:50%; box-shadow:0 0 0 3px rgba(22,163,74,0.2);"></span>
                        <strong style="color:#166534; font-size:13px;">Background Queue Worker Active</strong>
                        <span style="color:#15803d; font-size:12px;">— Dispatches are being processed automatically in chunks.</span>
                    </div>
                    <span class="muted" style="font-size:11px;">{{ $workerStatus['pending_jobs'] }} job(s) in queue</span>
                </div>
            @else
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:10px 16px; margin-bottom:18px; display:flex; justify-content:space-between; align-items:center;">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span style="display:inline-block; width:10px; height:10px; background:#94a3b8; border-radius:50%;"></span>
                        <strong style="color:#334155; font-size:13px;">Queue Idle</strong>
                        <span class="muted" style="font-size:12px;">— All approved delivery batches have been dispatched.</span>
                    </div>
                </div>
            @endif
        @endif

        {{-- BATCH PIPELINE PROGRESS TABLE --}}
        @if(!empty($recentBatches) && $recentBatches->isNotEmpty())
            <div style="margin-bottom:22px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <h4 style="margin:0; font-size:13px; text-transform:uppercase; letter-spacing:0.05em; color:#475569;">
                        Approved Batch Delivery Pipeline
                    </h4>
                    <span class="muted" style="font-size:11.5px;">Batches for cycle: <strong>{{ $dashboardStats['selected_month'] }}</strong></span>
                </div>

                <div class="table-wrap">
                    <table class="table" style="font-size:12.5px;">
                        <thead>
                            <tr style="background:#f8fafc;">
                                <th>Batch ID</th>
                                <th>Period</th>
                                <th>Recipients</th>
                                <th>Total Chunks</th>
                                <th>Submitted</th>
                                <th>Pending</th>
                                <th>Failed</th>
                                <th>Status</th>
                                <th>Last Attempt</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach($recentBatches as $b)
                            <tr>
                                <td>
                                    <a href="{{ route('invoices.batch_details', $b->batch_id) }}" style="color:#7c3aed; font-weight:700; text-decoration:none;">
                                        <code>{{ $b->batch_id }}</code>
                                    </a>
                                    @if(!empty($b->approver_name))
                                        <div class="muted" style="font-size:10.5px;">By: {{ $b->approver_name }}</div>
                                    @endif
                                </td>
                                <td><strong>{{ $b->billing_period ?: '—' }}</strong></td>
                                <td>
                                    <strong>{{ number_format($b->total_recipients) }}</strong>
                                    <div class="muted" style="font-size:10.5px;">{{ $b->queued_recipients }} queued</div>
                                </td>
                                <td><span style="font-weight:700;">{{ $b->chunks_total }}</span></td>
                                <td>
                                    <span style="color:#16a34a; font-weight:700;">{{ $b->chunks_sent }}</span>
                                </td>
                                <td>
                                    <span style="color:{{ $b->chunks_pending > 0 ? '#d97706' : '#64748b' }}; font-weight:700;">
                                        {{ $b->chunks_pending }}
                                    </span>
                                </td>
                                <td>
                                    <span style="color:{{ $b->chunks_failed > 0 ? '#dc2626' : '#64748b' }}; font-weight:700;">
                                        {{ $b->chunks_failed }}
                                    </span>
                                </td>
                                <td>
                                    @if($b->status === 'submitted')
                                        <span class="badge-status badge-paid" style="font-size:10px;">SUBMITTED</span>
                                    @elseif($b->status === 'processing')
                                        <span style="background:#dbeafe; color:#1e40af; font-size:10px; font-weight:700; padding:2px 7px; border-radius:4px; display:inline-block;">PROCESSING</span>
                                    @elseif($b->status === 'partially_submitted')
                                        <span style="background:#fef3c7; color:#92400e; font-size:10px; font-weight:700; padding:2px 7px; border-radius:4px; display:inline-block;">PARTIAL</span>
                                    @elseif($b->status === 'failed')
                                        <span style="background:#fee2e2; color:#991b1b; font-size:10px; font-weight:700; padding:2px 7px; border-radius:4px; display:inline-block;">FAILED</span>
                                    @elseif($b->status === 'blocked')
                                        <span style="background:#f1f5f9; color:#475569; font-size:10px; font-weight:700; padding:2px 7px; border-radius:4px; display:inline-block;">BLOCKED</span>
                                    @else
                                        <span class="badge-status badge-queued" style="font-size:10px;">QUEUED</span>
                                    @endif
                                </td>
                                <td>
                                    @if(!empty($b->last_attempt))
                                        <span style="font-size:11.5px;">{{ substr($b->last_attempt, 0, 16) }}</span>
                                        @if(!empty($b->response_code))
                                            <span class="muted" style="font-size:10.5px;">(HTTP {{ $b->response_code }})</span>
                                        @endif
                                    @else
                                        <span class="muted" style="font-size:11.5px;">Not yet attempted</span>
                                    @endif

                                    @if(!empty($b->display_error))
                                        <div style="color:#dc2626; font-size:10.5px; max-width:200px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="{{ $b->display_error }}">
                                            ⚠️ {{ $b->display_error }}
                                        </div>
                                    @endif
                                </td>
                                <td style="text-align:right;">
                                    <div style="display:flex; justify-content:flex-end; gap:4px;">
                                        @if(in_array($b->status, ['failed', 'partially_submitted'], true) || $b->chunks_failed > 0)
                                            <form method="post" action="{{ route('invoices.batches.retry_failed', $b->batch_id) }}" style="margin:0;">
                                                @csrf
                                                <button type="submit" class="btn small" style="background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; font-size:10.5px; padding:3px 7px;">
                                                    ↻ Retry Failed
                                                </button>
                                            </form>
                                        @endif
                                        <a href="{{ route('invoices.batch_details', $b->batch_id) }}" class="btn small gray" style="font-size:10.5px; padding:3px 7px;">
                                            Details ↗
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- ITEMIZED NOTIFICATIONS QUEUE --}}
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <div>
                <h3 style="margin:0; color:#0f172a;">Itemized Notification Queue</h3>
                <p class="muted" style="margin:2px 0 0 0; font-size:12px;">
                    {{ number_format($tabQueuePage->total()) }} individual recipient notification(s) assigned to chunks.
                </p>
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
                        <td>{{ (!empty($qRow->created_at) || !empty($qRow->notification_created_at)) ? substr($qRow->created_at ?? $qRow->notification_created_at, 0, 16) : '—' }}</td>
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
                        <td>{{ !empty($sRow->submitted_at) ? substr($sRow->submitted_at, 0, 16) : '—' }}</td>
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
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:8px;">
            <div>
                <h3 style="margin:0; color:#b91c1c;">Needs Attention · Delivery Failures &amp; Contact Issues</h3>
                <p class="muted" style="margin:2px 0 0 0; font-size:12px;">Contact-ineligible families (missing WhatsApp, unverified, or no consent) and webhook delivery failures for <strong>{{ $dashboardStats['selected_month'] }}</strong>.</p>
            </div>
            @if(session('notice'))
                <div style="background:#dcfce7; color:#166534; font-size:12px; padding:6px 12px; border-radius:4px; font-weight:700;">
                    ✓ {{ session('notice') }}
                </div>
            @endif
        </div>

        {{-- ATTENTION FILTERS --}}
        <form method="get" class="iq-filter-row" style="display:flex; flex-wrap:wrap; gap:8px; align-items:center; background:#f8fafc; padding:12px; border-radius:6px; border:1px solid #e2e8f0; margin-bottom:14px;">
            <input type="hidden" name="tab" value="attention">
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

            <input type="text" name="search" class="input" value="{{ request('search') }}" placeholder="Search family, student, phone..." style="min-width:200px;">

            <select class="input" name="per_page" style="width:90px;" title="Rows per page">
                <option value="25" @selected($perPage == 25)>25 / page</option>
                <option value="50" @selected($perPage == 50)>50 / page</option>
                <option value="100" @selected($perPage == 100)>100 / page</option>
            </select>

            <button type="submit" class="btn small" style="background:#7c3aed; color:#fff; font-weight:600; padding:6px 14px;">Filter</button>
            <a href="?tab=attention&period={{ $selectedPeriod }}" class="btn small gray" style="padding:6px 12px;">Reset</a>
        </form>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width:110px;">Family Code</th>
                        <th style="width:200px;">Guardian / Student</th>
                        <th style="width:170px;">WhatsApp Contact</th>
                        <th style="width:120px;">Billing Month</th>
                        <th>Exact Problem</th>
                        <th style="width:140px; text-align:right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($tabQueuePage as $aRow)
                    <tr>
                        <td>
                            <strong>{{ $aRow->family_code }}</strong>
                            @if(!empty($aRow->invoice_number))
                                <div class="muted" style="font-size:10px; margin-top:2px;" title="{{ implode(', ', $aRow->invoice_numbers ?? []) }}">
                                    {{ $aRow->invoice_number }}
                                    @if(count($aRow->invoice_numbers ?? []) > 1)
                                        <span class="badge" style="font-size:9px; padding:0 4px;">+{{ count($aRow->invoice_numbers) - 1 }} more</span>
                                    @endif
                                </div>
                            @endif
                        </td>
                        <td>
                            <div style="font-weight:600; color:#1e293b;">
                                {{ $aRow->guardian_name }}
                            </div>
                            <div style="margin-top:3px;">
                                @forelse($aRow->students as $st)
                                    <span style="display:inline-block; background:#f1f5f9; color:#334155; font-size:10px; padding:1px 6px; border-radius:3px; margin-right:3px; margin-bottom:2px;" title="GR: {{ $st->gr_number }}">
                                        <strong>{{ $st->name }}</strong> ({{ $st->grade_label ?: 'No Grade' }})
                                    </span>
                                @empty
                                    <span class="muted" style="font-size:11px;">No active students</span>
                                @endforelse
                            </div>
                        </td>
                        <td>
                            <div style="font-family:monospace; font-weight:600; color:{{ $aRow->whatsapp ? '#0f172a' : '#94a3b8' }};">
                                {{ $aRow->whatsapp ?: 'Not provided' }}
                            </div>
                            <div style="display:flex; flex-wrap:wrap; gap:3px; margin-top:3px;">
                                @if($aRow->contact_verified)
                                    <span style="background:#dcfce7; color:#166534; font-size:9.5px; padding:1px 5px; border-radius:3px; font-weight:700;">Verified</span>
                                @else
                                    <span style="background:#fee2e2; color:#991b1b; font-size:9.5px; padding:1px 5px; border-radius:3px; font-weight:700;">Unverified</span>
                                @endif

                                @if($aRow->messaging_opt_in)
                                    <span style="background:#ede9fe; color:#6d28d9; font-size:9.5px; padding:1px 5px; border-radius:3px; font-weight:700;">Opted In</span>
                                @else
                                    <span style="background:#ffedd5; color:#9a3412; font-size:9.5px; padding:1px 5px; border-radius:3px; font-weight:700;">No Consent</span>
                                @endif
                            </div>
                        </td>
                        <td>
                            <span style="font-size:12px; font-weight:600; color:#334155;">
                                {{ $aRow->billing_month_formatted }}
                            </span>
                        </td>
                        <td>
                            @if(!empty($aRow->notification_status))
                                <div style="margin-bottom:3px;">
                                    <span class="badge-status {{ $aRow->notification_status === 'failed' ? 'badge-failed' : 'badge-blocked' }}">
                                        {{ strtoupper($aRow->notification_status) }}
                                    </span>
                                    @if($aRow->notification_error)
                                        <span style="font-size:11px; color:#991b1b; font-weight:600; margin-left:4px;">{{ $aRow->notification_error }}</span>
                                    @endif
                                </div>
                            @endif

                            <div style="display:flex; flex-direction:column; gap:2px;">
                                @foreach($aRow->problem_descriptions as $pDesc)
                                    <div style="color:#b91c1c; font-size:11.5px; line-height:1.3;">
                                        &bull; {{ $pDesc }}
                                    </div>
                                @endforeach
                            </div>
                        </td>
                        <td style="text-align:right;">
                            <div style="display:flex; flex-direction:column; gap:4px; align-items:flex-end;">
                                <button type="button" class="btn small" style="background:#7c3aed; color:#fff; font-weight:700; font-size:11px; padding:4px 8px; border:none; border-radius:4px; cursor:pointer;" onclick='openFixContactModal(@json($aRow->contact_modal_data))'>
                                    ✏️ Fix Contact
                                </button>

                                @if(!empty($aRow->notification_id) && $aRow->notification_status === 'failed')
                                    <form method="post" action="{{ route('invoices.notifications.retry', $aRow->notification_id) }}" style="margin:0;">
                                        @csrf
                                        <button class="btn small" type="submit" style="background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; font-weight:700; font-size:10px; padding:2px 6px;">
                                            ↻ Retry Send
                                        </button>
                                    </form>
                                @endif

                                @if(!empty($aRow->primary_student_id))
                                    <a href="/manage-student/{{ $aRow->primary_student_id }}" target="_blank" rel="noopener" class="btn small gray" style="font-size:10px; padding:2px 6px;">
                                        View Student ↗
                                    </a>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="text-align:center; padding:30px; color:#166534;">
                            <strong>🎉 Zero delivery failures or contact issues for {{ $dashboardStats['selected_month'] }}!</strong><br>
                            <span class="muted" style="font-size:12px;">All families for this billing cycle have verified WhatsApp contacts and valid messaging consent.</span>
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

    const isPaidTab = {{ $currentTab === 'paid' ? 'true' : 'false' }};
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
            if (isPaidTab) {
                summary.textContent = `All Matching Filters selected: ${serverTotalEligible} eligible paid invoice(s) ready for batch delivery to Paid Webhook.`;
            } else {
                let gradeLabel = targetGrade ? `Target Grade: ${targetGrade}` : 'All Grades';
                if (siblingCount > 0) gradeLabel += ` (+ ${siblingCount} siblings across other grades)`;
                summary.textContent = `All Matching Filters selected: ${serverTotalEligible} eligible families ready for batch delivery (${gradeLabel}).`;
            }
            return;
        }

        modeInput.value = 'page';
        btnScopePage?.classList.add('active');
        btnScopeAll?.classList.remove('active');

        const chosen = boxes.filter(b => b.checked);
        if (chosen.length) {
            summary.textContent = isPaidTab
                ? `${chosen.length} paid invoice(s) selected on this page.`
                : `${chosen.length} family challan(s) selected on this page.`;
        } else {
            summary.textContent = '0 invoice(s) selected on this page. Select invoices or choose "Select All Matching Filters" to approve batch delivery.';
        }
    }

    btnScopePage?.addEventListener('click', () => {
        selectionMode = 'page';
        boxes.forEach(b => { if (!b.disabled) b.checked = true; });
        if (all) all.checked = true;
        update();
    });

    btnScopeAll?.addEventListener('click', () => {
        selectionMode = 'all_matching';
        boxes.forEach(b => { if (!b.disabled) b.checked = true; });
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
        boxes.forEach(b => { if (!b.disabled) b.checked = all.checked; });
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
            alert(isPaidTab
                ? 'Please acknowledge: "I have reviewed the selected paid invoices & payment receipts, and approve this delivery batch."'
                : 'Please acknowledge: "I have reviewed the selected families & approve this delivery batch."'
            );
            reviewCheckbox?.focus();
            return;
        }

        if (selectionMode === 'all_matching') {
            if (serverTotalEligible === 0) {
                e.preventDefault();
                alert('No eligible recipients match the applied filters.');
                return;
            }
            const confirmMsg = isPaidTab
                ? `Queue approved Paid Batch for ${serverTotalEligible} eligible paid invoices across ALL matching pages to the Paid Webhook?\n\nChannels: ${channels.join(' and ')}\n\nEvent: invoice.paid.batch`
                : `Queue approved Monthly Batch for ${serverTotalEligible} eligible families across ALL matching pages to the Monthly Webhook?\n\nChannels: ${channels.join(' and ')}\n\nOne latest consolidated challan per family.`;
            if (!confirm(confirmMsg)) {
                e.preventDefault();
            }
            return;
        }

        const chosen = boxes.filter(b => b.checked);
        if (!chosen.length) {
            e.preventDefault();
            alert(isPaidTab
                ? 'Please select at least one paid invoice on the current page, or choose "Select All Matching Filters".'
                : 'Please select at least one family challan on the current page, or choose "Select All Matching Filters".'
            );
            return;
        }

        const confirmMsg = isPaidTab
            ? `Queue ${chosen.length} selected paid invoices on this page to the Paid Webhook?\n\nChannels: ${channels.join(' and ')}\n\nEvent: invoice.paid.batch`
            : `Queue ${chosen.length} selected family challans on this page to the Monthly Webhook?\n\nChannels: ${channels.join(' and ')}`;
        if (!confirm(confirmMsg)) {
            e.preventDefault();
        }
    });

    update();
})();
</script>

{{-- FIX CONTACT MODAL DIALOG --}}
<div id="fix-contact-backdrop" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.6); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#ffffff; border-radius:8px; width:95%; max-width:480px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.2); overflow:hidden; border:1px solid #cbd5e1; margin:20px;">
        <div style="padding:16px 20px; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
            <div>
                <h3 style="margin:0; font-size:16px; color:#0f172a;">Update Guardian Contact</h3>
                <div class="muted" style="font-size:12px; margin-top:2px;" id="modal-subtitle">Family Code</div>
            </div>
            <button type="button" onclick="closeFixContactModal()" style="background:none; border:none; font-size:24px; line-height:1; color:#64748b; cursor:pointer; padding:0 4px;">&times;</button>
        </div>

        <form method="post" id="fix-contact-form" action="" style="padding:20px; margin:0;">
            @csrf
            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Guardian Name</label>
                <input type="text" name="guardian_name" id="modal-guardian-name" class="input" style="width:100%; box-sizing:border-box;" placeholder="e.g. Muhammad Usman">
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">
                    WhatsApp Number <span style="color:#dc2626;">*</span>
                </label>
                <input type="text" name="whatsapp" id="modal-whatsapp" class="input" style="width:100%; font-family:monospace; box-sizing:border-box;" placeholder="+923001234567" required>
                <div class="muted" style="font-size:11px; margin-top:3px;">Must include country code in international format (e.g. <code>+92...</code>, <code>+966...</code>, <code>+971...</code>).</div>
            </div>

            <div style="background:#f1f5f9; padding:12px 14px; border-radius:6px; margin-bottom:16px; border:1px solid #e2e8f0;">
                <div style="font-size:12px; font-weight:700; color:#0f172a; margin-bottom:8px;">Compliance &amp; Verification Checks</div>

                <label style="display:flex; align-items:flex-start; gap:8px; font-size:12px; color:#334155; cursor:pointer; margin-bottom:8px;">
                    <input type="checkbox" name="contact_verified" id="modal-contact-verified" value="1" style="margin-top:2px;">
                    <div>
                        <strong>Verified Contact</strong>
                        <div class="muted" style="font-size:11px;">Phone number has been actively confirmed by school staff.</div>
                    </div>
                </label>

                <label style="display:flex; align-items:flex-start; gap:8px; font-size:12px; color:#334155; cursor:pointer;">
                    <input type="checkbox" name="messaging_opt_in" id="modal-messaging-opt-in" value="1" style="margin-top:2px;">
                    <div>
                        <strong>Messaging Permission (Opt-In)</strong>
                        <div class="muted" style="font-size:11px;">Parent has consented to receive invoices and school alerts via WhatsApp.</div>
                    </div>
                </label>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" class="btn gray" onclick="closeFixContactModal()" style="font-size:12px; cursor:pointer;">Cancel</button>
                <button type="submit" class="btn" style="background:#7c3aed; color:#fff; font-weight:700; font-size:12px; padding:6px 16px; border:none; border-radius:4px; cursor:pointer;">Save &amp; Verify Contact</button>
            </div>
        </form>
    </div>
</div>

<script>
function openFixContactModal(data) {
    const backdrop = document.getElementById('fix-contact-backdrop');
    const form = document.getElementById('fix-contact-form');
    if (!backdrop || !form) return;

    form.action = '/families/' + data.family_id + '/contact';
    document.getElementById('modal-subtitle').textContent = 'Family Code: ' + data.family_code + ' (ID #' + data.family_id + ')';
    document.getElementById('modal-guardian-name').value = data.guardian_name || '';
    document.getElementById('modal-whatsapp').value = data.whatsapp || '';
    document.getElementById('modal-contact-verified').checked = !!data.contact_verified;
    document.getElementById('modal-messaging-opt-in').checked = !!data.messaging_opt_in;

    backdrop.style.display = 'flex';
}

function closeFixContactModal() {
    const backdrop = document.getElementById('fix-contact-backdrop');
    if (backdrop) backdrop.style.display = 'none';
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeFixContactModal();
});
document.getElementById('fix-contact-backdrop')?.addEventListener('click', e => {
    if (e.target.id === 'fix-contact-backdrop') closeFixContactModal();
});
</script>
@endsection
