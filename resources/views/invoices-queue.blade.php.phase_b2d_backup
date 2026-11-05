@extends('layouts.app')
@section('title', $selectedBatch ? 'Batch Details: ' . $selectedBatch->batch_id : 'Invoice Delivery Queue')
@section('content')
<link rel="stylesheet" href="{{ asset('assets/iqra-challan.css') }}">
<style>
.queue-hero-card {
    background: linear-gradient(135deg, #f8fafc 0%, #ede9fe 100%);
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 18px 22px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}
.queue-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 12px;
    margin-bottom: 20px;
}
.queue-stat-box {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 12px 14px;
    box-shadow: 0 1px 2px rgba(0,0,0,0.02);
}
.queue-stat-val {
    font-size: 22px;
    font-weight: 700;
    color: #1e293b;
    line-height: 1.2;
}
.queue-stat-label {
    font-size: 11px;
    text-transform: uppercase;
    font-weight: 600;
    color: #64748b;
    margin-top: 4px;
}
.badge-status {
    font-size: 11px;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 12px;
    text-transform: uppercase;
    display: inline-block;
}
.badge-queued { background: #e0f2fe; color: #0369a1; }
.badge-submitted { background: #ede9fe; color: #6d28d9; }
.badge-delivered { background: #dcfce7; color: #166534; }
.badge-read { background: #f3e8ff; color: #7e22ce; }
.badge-failed { background: #fee2e2; color: #991b1b; }
.badge-blocked { background: #ffedd5; color: #9a3412; }
.badge-pending { background: #f1f5f9; color: #475569; }
.badge-superseded { background: #fef3c7; color: #92400e; }

.filter-panel {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 14px;
    margin-bottom: 16px;
}
.filter-row {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: flex-end;
}
.filter-col {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.filter-col label {
    font-size: 11px;
    font-weight: 600;
    color: #475569;
}
.filter-col select, .filter-col input {
    height: 32px;
    padding: 4px 8px;
    border: 1px solid #cbd5e1;
    border-radius: 4px;
    font-size: 12px;
}
.table td {
    vertical-align: top;
    padding: 8px 10px;
    font-size: 12px;
}
.table th {
    font-size: 11px;
    text-transform: uppercase;
    color: #475569;
    background: #f8fafc;
    padding: 8px 10px;
}
.student-pill {
    display: inline-block;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    padding: 2px 6px;
    font-size: 11px;
    margin: 2px 2px 2px 0;
}
.sibling-pill {
    display: inline-block;
    background: #ede9fe;
    color: #5b21b6;
    border: 1px solid #ddd6fe;
    border-radius: 4px;
    padding: 2px 6px;
    font-size: 11px;
    margin: 2px 2px 2px 0;
}
</style>

<div class="card" style="margin-bottom:15px; padding:16px 20px;">
    {{-- BREADCRUMB & HEADER --}}
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:12px;">
        <div>
            <div style="font-size:12px; color:#64748b; margin-bottom:4px;">
                <a href="/invoices" style="color:#7367f0; text-decoration:none;">Invoices</a>
                &nbsp;›&nbsp;
                <a href="{{ route('invoices.delivery_queue') }}" style="color:#7367f0; text-decoration:none;">Delivery Queue</a>
                @if($selectedBatch)
                    &nbsp;›&nbsp;
                    <span style="color:#1e293b; font-weight:600;">Batch {{ $selectedBatch->batch_id }}</span>
                @endif
            </div>
            <h2 style="margin:0; font-size:20px; color:#1e293b; font-weight:700;">
                @if($selectedBatch)
                    Batch Details · <code>{{ $selectedBatch->batch_id }}</code>
                @else
                    Invoice Delivery Queue
                @endif
            </h2>
        </div>

        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            @if($selectedBatch && $stats['failed'] > 0)
                <form method="post" action="{{ route('invoices.batches.retry_failed', $selectedBatch->batch_id) }}" style="margin:0;" onsubmit="return confirm('Authorize retry for {{ $stats['failed'] }} failed recipient(s) in batch {{ $selectedBatch->batch_id }}? Successful and already-submitted recipients will NOT be resent.');">
                    @csrf
                    <button class="btn" type="submit" style="background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; font-weight:700; padding:6px 14px; border-radius:4px; font-size:12px; cursor:pointer;">
                        ⚡ Retry Failed Only ({{ $stats['failed'] }})
                    </button>
                </form>
            @endif

            <a href="{{ route('invoices.delivery_queue.export', request()->all()) }}" class="btn" style="background:#f8fafc; color:#1e293b; border:1px solid #cbd5e1; font-weight:600; padding:6px 14px; border-radius:4px; font-size:12px; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                📥 Export CSV
            </a>

            @if($selectedBatch)
                <a href="{{ route('invoices.delivery_queue') }}" class="btn" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; font-weight:600; padding:6px 14px; border-radius:4px; font-size:12px; text-decoration:none;">
                    View All Batches
                </a>
            @endif

            <a href="/invoices" class="btn" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; font-weight:600; padding:6px 14px; border-radius:4px; font-size:12px; text-decoration:none;">
                ← Back to Invoices
            </a>
        </div>
    </div>

    {{-- SELECTED BATCH HERO CARD (IF VIEWING DEDICATED BATCH) --}}
    @if($selectedBatch)
        <div class="queue-hero-card">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:10px;">
                <div>
                    <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                        <span style="font-size:16px; font-weight:700; color:#1e293b;">
                            {{ ucfirst($selectedBatch->webhook_type) }} Batch Campaign
                        </span>
                        <span class="badge-status {{ $selectedBatch->status === 'submitted' ? 'badge-submitted' : ($selectedBatch->status === 'failed' ? 'badge-failed' : 'badge-queued') }}">
                            {{ strtoupper($selectedBatch->status) }}
                        </span>
                    </div>
                    <div style="font-size:12px; color:#475569;">
                        Event: <code>{{ $selectedBatch->event_type }}</code>
                        &nbsp;·&nbsp;
                        Cycle: <strong>{{ $selectedBatch->billing_period ?: 'Ad-hoc' }}</strong>
                        &nbsp;·&nbsp;
                        Approved by: <strong>{{ $selectedBatch->approver_name ?: 'Authorized Staff' }}</strong>
                        &nbsp;·&nbsp;
                        Created: <strong>{{ $selectedBatch->created_at }}</strong>
                    </div>
                </div>

                <div style="text-align:right; font-size:12px; color:#475569;">
                    <div><strong>{{ number_format($selectedBatch->total_recipients) }}</strong> Recipients &nbsp;·&nbsp; <strong>{{ number_format($selectedBatch->total_invoices) }}</strong> Invoices</div>
                    <div style="margin-top:4px;">
                        Chunks: <strong>{{ $selectedBatch->chunks_sent }} / {{ $selectedBatch->chunks_total }}</strong> sent
                        @if($selectedBatch->response_code)
                            &nbsp;·&nbsp; HTTP <strong>{{ $selectedBatch->response_code }}</strong>
                        @endif
                    </div>
                </div>
            </div>

            @if($selectedBatch->error)
                <div style="margin-top:10px; background:#fff1f2; border:1px solid #fecdd3; border-radius:4px; padding:8px 12px; color:#9f1239; font-size:12px;">
                    <strong>Diagnostic Error:</strong> {{ $selectedBatch->error }}
                </div>
            @endif
        </div>
    @endif

    {{-- SUMMARY KPI CARDS (REQUIREMENT 7) --}}
    <div class="queue-stats-grid">
        <div class="queue-stat-box">
            <div class="queue-stat-val">{{ number_format($stats['total']) }}</div>
            <div class="queue-stat-label">Total Selected</div>
        </div>
        <div class="queue-stat-box" style="border-left:4px solid #7367f0;">
            <div class="queue-stat-val" style="color:#6d28d9;">{{ number_format($stats['submitted']) }}</div>
            <div class="queue-stat-label">Webhook Submitted</div>
        </div>
        <div class="queue-stat-box" style="border-left:4px solid #16a34a;">
            <div class="queue-stat-val" style="color:#166534;">{{ number_format($stats['delivered']) }}</div>
            <div class="queue-stat-label">WhatsApp Delivered</div>
        </div>
        <div class="queue-stat-box" style="border-left:4px solid #9333ea;">
            <div class="queue-stat-val" style="color:#7e22ce;">{{ number_format($stats['read']) }}</div>
            <div class="queue-stat-label">WhatsApp Read</div>
        </div>
        <div class="queue-stat-box" style="border-left:4px solid #0284c7;">
            <div class="queue-stat-val" style="color:#0369a1;">{{ number_format($stats['queued']) }}</div>
            <div class="queue-stat-label">Queued for Dispatch</div>
        </div>
        <div class="queue-stat-box" style="border-left:4px solid #dc2626;">
            <div class="queue-stat-val" style="color:#991b1b;">{{ number_format($stats['failed']) }}</div>
            <div class="queue-stat-label">Failed / Retriable</div>
        </div>
        <div class="queue-stat-box" style="border-left:4px solid #d97706;">
            <div class="queue-stat-val" style="color:#92400e;">{{ number_format($stats['superseded']) }}</div>
            <div class="queue-stat-label">Superseded Delivery</div>
        </div>
    </div>

    {{-- FILTER PANEL --}}
    <form method="get" action="{{ $selectedBatch ? route('invoices.batch_details', $selectedBatch->batch_id) : route('invoices.delivery_queue') }}" class="filter-panel">
        @if($selectedBatch)
            <input type="hidden" name="batch_id" value="{{ $selectedBatch->batch_id }}">
        @endif

        <div class="filter-row">
            {{-- Universal Search --}}
            <div class="filter-col" style="flex:1; min-width:220px;">
                <label>Search (Student, GR, Family, Phone, Invoice, Batch, Notification ID)</label>
                <input type="text" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Type search term...">
            </div>

            @if(!$selectedBatch)
                {{-- Batch Dropdown --}}
                <div class="filter-col" style="min-width:180px;">
                    <label>Batch Campaign</label>
                    <select name="batch_id">
                        <option value="">All Batches</option>
                        @foreach($batches as $b)
                            <option value="{{ $b->batch_id }}" {{ ($filters['batch_id'] ?? '') === $b->batch_id ? 'selected' : '' }}>
                                {{ $b->batch_id }} ({{ $b->billing_period ?: 'Ad-hoc' }})
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif

            {{-- Period Filter --}}
            <div class="filter-col" style="width:110px;">
                <label>Billing Period</label>
                <input type="text" name="period" value="{{ $filters['period'] ?? '' }}" placeholder="YYYY-MM">
            </div>

            {{-- Department --}}
            <div class="filter-col" style="min-width:140px;">
                <label>Department</label>
                <select name="department_id">
                    <option value="">All Departments</option>
                    @foreach($departments as $d)
                        <option value="{{ $d->id }}" {{ (string)($filters['department_id'] ?? '') === (string)$d->id ? 'selected' : '' }}>
                            {{ $d->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            {{-- Grade --}}
            <div class="filter-col" style="min-width:120px;">
                <label>Grade / Class</label>
                <select name="grade">
                    <option value="">All Grades</option>
                    @foreach($grades as $g)
                        <option value="{{ $g }}" {{ ($filters['grade'] ?? '') === $g ? 'selected' : '' }}>
                            {{ $g }}
                        </option>
                    @endforeach
                </select>
            </div>

            {{-- Currency --}}
            <div class="filter-col" style="width:90px;">
                <label>Currency</label>
                <select name="currency">
                    <option value="">All</option>
                    @foreach($currencies as $c)
                        <option value="{{ $c }}" {{ ($filters['currency'] ?? '') === $c ? 'selected' : '' }}>
                            {{ $c }}
                        </option>
                    @endforeach
                </select>
            </div>

            {{-- Webhook Submission Status --}}
            <div class="filter-col" style="min-width:130px;">
                <label>Webhook Submission</label>
                <select name="submission_status">
                    <option value="all" {{ ($filters['submission_status'] ?? 'all') === 'all' ? 'selected' : '' }}>All Submission</option>
                    <option value="queued" {{ ($filters['submission_status'] ?? '') === 'queued' ? 'selected' : '' }}>Queued</option>
                    <option value="submitted" {{ ($filters['submission_status'] ?? '') === 'submitted' ? 'selected' : '' }}>Submitted (200/202)</option>
                    <option value="failed" {{ ($filters['submission_status'] ?? '') === 'failed' ? 'selected' : '' }}>Submission Failed</option>
                    <option value="blocked" {{ ($filters['submission_status'] ?? '') === 'blocked' ? 'selected' : '' }}>Blocked / Unverified</option>
                </select>
            </div>

            {{-- Actual WhatsApp Delivery Status --}}
            <div class="filter-col" style="min-width:130px;">
                <label>WhatsApp Delivery</label>
                <select name="delivery_status">
                    <option value="all" {{ ($filters['delivery_status'] ?? 'all') === 'all' ? 'selected' : '' }}>All Delivery</option>
                    <option value="pending" {{ ($filters['delivery_status'] ?? '') === 'pending' ? 'selected' : '' }}>Pending / In-Transit</option>
                    <option value="delivered" {{ ($filters['delivery_status'] ?? '') === 'delivered' ? 'selected' : '' }}>Delivered</option>
                    <option value="read" {{ ($filters['delivery_status'] ?? '') === 'read' ? 'selected' : '' }}>Read</option>
                    <option value="failed" {{ ($filters['delivery_status'] ?? '') === 'failed' ? 'selected' : '' }}>Delivery Failed</option>
                </select>
            </div>

            {{-- Superseded Filter --}}
            <div class="filter-col" style="width:110px;">
                <label>Superseded</label>
                <select name="is_superseded">
                    <option value="all" {{ ($filters['is_superseded'] ?? 'all') === 'all' ? 'selected' : '' }}>All</option>
                    <option value="active" {{ ($filters['is_superseded'] ?? '') === 'active' ? 'selected' : '' }}>Active Only</option>
                    <option value="superseded" {{ ($filters['is_superseded'] ?? '') === 'superseded' ? 'selected' : '' }}>Superseded Only</option>
                </select>
            </div>

            {{-- Rows Per Page --}}
            <div class="filter-col" style="width:90px;">
                <label>Per Page</label>
                <select name="per_page">
                    <option value="25" {{ (int)($perPage ?? 25) === 25 ? 'selected' : '' }}>25</option>
                    <option value="50" {{ (int)($perPage ?? 25) === 50 ? 'selected' : '' }}>50</option>
                    <option value="100" {{ (int)($perPage ?? 25) === 100 ? 'selected' : '' }}>100</option>
                </select>
            </div>

            <div class="filter-col" style="display:flex; flex-direction:row; gap:6px;">
                <button type="submit" class="btn small" style="background:#7367f0; color:#fff; border:none; height:32px; padding:0 14px; font-weight:600; border-radius:4px;">
                    Apply
                </button>
                <a href="{{ $selectedBatch ? route('invoices.batch_details', $selectedBatch->batch_id) : route('invoices.delivery_queue') }}" class="btn small" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; height:32px; line-height:30px; padding:0 12px; font-weight:600; border-radius:4px; text-decoration:none;">
                    Reset
                </a>
            </div>
        </div>
    </form>

    {{-- PAGINATED QUEUE TABLE --}}
    <div class="table-wrap" style="margin-top:10px;">
        <table class="table" style="font-size:12px; width:100%;">
            <thead>
                <tr>
                    <th style="width:110px;">Notification / Batch</th>
                    <th style="width:110px;">Invoice / Cycle</th>
                    <th style="width:180px;">Guardian Contact</th>
                    <th>Students & Siblings</th>
                    <th style="width:140px;">Amounts & PDFs</th>
                    <th style="width:120px;">Webhook Submission</th>
                    <th style="width:120px;">WhatsApp Delivery</th>
                    <th style="width:150px;">Audit & Diagnostic</th>
                    <th style="width:80px;">Action</th>
                </tr>
            </thead>
            <tbody>
            @forelse($notifications as $row)
                <?php
                    $whBadge = match($row->webhook_submission_status) {
                        'submitted' => 'badge-submitted',
                        'failed' => 'badge-failed',
                        'blocked' => 'badge-blocked',
                        default => 'badge-queued',
                    };

                    $waBadge = match($row->whatsapp_delivery_status) {
                        'delivered' => 'badge-delivered',
                        'read' => 'badge-read',
                        'failed' => 'badge-failed',
                        'blocked' => 'badge-blocked',
                        default => 'badge-pending',
                    };
                ?>
                <tr style="{{ $row->is_superseded ? 'background:#fffbeb;' : '' }}">
                    {{-- 1. Notification ID & Batch ID --}}
                    <td>
                        <div><strong>#{{ $row->notification_id }}</strong></div>
                        @if($row->batch_id)
                            <div style="margin-top:3px;">
                                <a href="{{ route('invoices.batch_details', $row->batch_id) }}" style="color:#7367f0; text-decoration:none; font-weight:600;" title="View Batch">
                                    <code>{{ Str::limit($row->batch_id, 16) }}</code>
                                </a>
                            </div>
                            <div class="muted" style="font-size:10px; margin-top:2px;">
                                Chunk {{ $row->chunk_index ?: 1 }}
                                @if($row->chunks_total)
                                    / {{ $row->chunks_total }}
                                @endif
                            </div>
                        @else
                            <div class="muted" style="font-size:10px;">Single Send</div>
                        @endif

                        @if($row->is_superseded)
                            <div style="margin-top:4px;">
                                <span class="badge-status badge-superseded" title="Dues rolled into consolidated challan">Superseded</span>
                            </div>
                        @endif
                    </td>

                    {{-- 2. Invoice Number & Cycle --}}
                    <td>
                        <div>
                            <a href="{{ $row->invoice_pdf_url }}" target="_blank" style="font-weight:700; color:#1e293b; text-decoration:none;" title="View Invoice PDF">
                                {{ $row->invoice_number }} 📄
                            </a>
                        </div>
                        <div class="muted" style="font-size:11px; margin-top:2px;">
                            {{ $row->billing_period ?: '—' }}
                        </div>
                        <div class="muted" style="font-size:10px;">
                            {{ $row->department_name ?: 'General' }}
                        </div>
                    </td>

                    {{-- 3. Guardian Contact --}}
                    <td>
                        <div style="font-weight:600; color:#1e293b;">
                            {{ $row->guardian_name ?: 'Guardian' }}
                        </div>
                        <div style="margin-top:2px; font-family:monospace; color:#334155;">
                            {{ $row->whatsapp ?: 'No phone' }}
                            @if($row->whatsapp_verified)
                                <span title="WhatsApp Verified" style="color:#16a34a; font-weight:700;">✓</span>
                            @else
                                <span title="Unverified" style="color:#d97706; font-size:10px;">(unverified)</span>
                            @endif
                        </div>
                        <div class="muted" style="font-size:10px; margin-top:2px;">
                            Family: <code>{{ $row->family_code ?: 'N/A' }}</code>
                        </div>
                    </td>

                    {{-- 4. Students & Siblings --}}
                    <td>
                        <div>
                            @forelse($row->students_list as $st)
                                <span class="student-pill" title="Invoice Student">
                                    <strong>{{ $st->name }}</strong> ({{ $st->gr_number ?: 'No GR' }} · {{ $st->grade_label ?: 'No Grade' }})
                                </span>
                            @empty
                                <span class="muted">No student linked</span>
                            @endforelse
                        </div>

                        {{-- Siblings in family --}}
                        @if($row->siblings_list->count() > $row->students_list->count())
                            <div style="margin-top:4px;">
                                <span class="muted" style="font-size:10px;">Siblings:</span>
                                @foreach($row->siblings_list as $sib)
                                    @if(!$row->students_list->contains('student_id', $sib->student_id))
                                        <span class="sibling-pill" title="Sibling in same family">
                                            {{ $sib->name }} ({{ $sib->grade_label ?: 'N/A' }})
                                        </span>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    </td>

                    {{-- 5. Financial Amounts & PDF Links --}}
                    <td>
                        @if($row->has_data_integrity_issue ?? false)
                            <div style="font-size:11px; font-weight:700; color:#991b1b; background:#fee2e2; border:1px solid #fca5a5; padding:2px 6px; border-radius:4px;">
                                ⚠ UNVERIFIED — Data Integrity Hold
                            </div>
                        @elseif(($row->is_multi_currency ?? false) && !empty($row->currencies_map))
                            @foreach($row->currencies_map as $cCode => $cInfo)
                                <div style="font-weight:700; color:#1e293b; font-size:11px;">
                                    {{ $cInfo['formatted_total_payable'] ?? ($cCode . ' ' . $row->formatted_total_payable) }}
                                </div>
                            @endforeach
                        @else
                            <div style="font-weight:700; color:#1e293b;">
                                {{ $row->currency }} {{ $row->formatted_balance }}
                            </div>
                            @if($row->previous_dues_minor > 0)
                                <div style="font-size:11px; color:#b45309;" title="Unpaid items from previous months">
                                    + Prev: {{ $row->currency }} {{ $row->formatted_previous_dues }}
                                </div>
                            @endif
                            @if(($row->later_period_minor ?? 0) > 0)
                                <div style="font-size:11px; color:#0369a1;" title="Already-generated later-period unpaid charges">
                                    + Later: {{ $row->currency }} {{ $row->formatted_later_period }}
                                </div>
                            @endif
                            @if($row->previous_dues_minor > 0 || ($row->later_period_minor ?? 0) > 0)
                                <div style="font-size:11px; font-weight:700; color:#1e293b; border-top:1px dashed #cbd5e1; margin-top:2px; padding-top:2px;">
                                    Total: {{ $row->currency }} {{ $row->formatted_total_payable }}
                                </div>
                            @endif
                        @endif

                        @if(!empty($row->dispatched_totals_by_currency) && is_array($row->dispatched_totals_by_currency))
                            <div style="font-size:10px; color:#475569; margin-top:3px;" title="Exact balance calculated at preparation/dispatch time">
                                Dispatched:
                                @foreach($row->dispatched_totals_by_currency as $dt)
                                    <span>{{ $dt['currency'] ?? '' }} {{ $dt['amount'] ?? '' }}</span>@if(!$loop->last), @endif
                                @endforeach
                            </div>
                        @endif

                        <div style="margin-top:4px; font-size:11px;">
                            @if($row->consolidated_challan_url)
                                <a href="{{ $row->consolidated_challan_url }}" target="_blank" rel="noopener" style="color:#7367f0; text-decoration:none; font-weight:600;" title="Live Consolidated Fee Invoice PDF (renders current ledger balance)">
                                    Consolidated Fee Invoice (Live) ↗
                                </a>
                            @endif
                        </div>
                    </td>

                    {{-- 6. Webhook Submission Status --}}
                    <td>
                        <span class="badge-status {{ $whBadge }}">
                            {{ strtoupper($row->webhook_submission_status) }}
                        </span>
                        @if($row->submitted_at)
                            <div class="muted" style="font-size:10px; margin-top:2px;">
                                {{ substr($row->submitted_at, 0, 16) }}
                            </div>
                        @endif
                    </td>

                    {{-- 7. WhatsApp Delivery Status --}}
                    <td>
                        <span class="badge-status {{ $waBadge }}">
                            {{ strtoupper($row->whatsapp_delivery_status) }}
                        </span>
                        @if($row->delivered_at)
                            <div class="muted" style="font-size:10px; margin-top:2px;">
                                {{ substr($row->delivered_at, 0, 16) }}
                            </div>
                        @endif
                    </td>

                    {{-- 8. Audit & Diagnostic Details --}}
                    <td style="max-width:180px; word-break:break-all;">
                        <div class="muted" style="font-size:11px;">
                            Attempts: <strong>{{ $row->attempts }}</strong>
                            @if($row->last_attempt_at)
                                <div style="font-size:10px;">{{ substr($row->last_attempt_at, 0, 16) }}</div>
                            @endif
                        </div>

                        @if($row->provider_message_id)
                            <div style="margin-top:2px; font-size:10px; color:#166534;" title="WhatsApp Provider Message ID">
                                ID: <code>{{ Str::limit($row->provider_message_id, 14) }}</code>
                            </div>
                        @endif

                        @if($row->notification_error)
                            <div style="margin-top:2px; color:#b91c1c; font-size:10px; font-weight:600;" title="{{ $row->notification_error }}">
                                {{ Str::limit($row->notification_error, 60) }}
                            </div>
                        @endif
                    </td>

                    {{-- 9. Action --}}
                    <td>
                        @if(in_array($row->notification_status, ['failed', 'blocked'], true))
                            <form method="post" action="{{ route('invoices.notifications.retry', $row->notification_id) }}" style="margin:0;">
                                @csrf
                                <button class="btn small" type="submit" style="background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; font-weight:700; padding:2px 8px; font-size:11px; border-radius:3px;">
                                    Retry
                                </button>
                            </form>
                        @else
                            <span class="muted">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" style="text-align:center; padding:30px; color:#64748b;">
                        <div style="font-size:16px; margin-bottom:6px;">📭 No delivery notifications found.</div>
                        <div style="font-size:12px;">No records matched your search and filter criteria. Try adjusting your filters.</div>
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{-- PAGINATION LINKS & STATS FOOTER --}}
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-top:15px; border-top:1px solid #e2e8f0; padding-top:12px;">
        <div style="font-size:12px; color:#64748b;">
            Showing {{ $notifications->firstItem() ?? 0 }} to {{ $notifications->lastItem() ?? 0 }} of {{ number_format($notifications->total()) }} total notification records
        </div>
        <div>
            {{ $notifications->links() }}
        </div>
    </div>
</div>
@endsection
