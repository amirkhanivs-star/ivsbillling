@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
@php
    $isSuperAdmin = auth()->user()?->role === 'super_admin';

    $collectionToday = $todayCollections->count() === 1
        ? $todayCollections->first()->currency.' '.\App\Services\Money::format((int)$todayCollections->first()->amount)
        : ($todayCollections->isEmpty() ? 'No payments' : 'See reports');
    
    // Financial vs Non-financial metrics
    $metrics = [
        ["Today's Collection", $collectionToday, '↗', true],
        ['Receipts Today', number_format($stats['receipts_today']), '▤', true],
        ['Students', number_format($stats['students']), '♙', false],
        ['Family Accounts', number_format($stats['families']), '♧', false],
        ['Invoices', number_format($stats['invoices']), '▣', true],
    ];
@endphp

<div class="section-heading">
    <div>
        <p class="eyebrow">Billing Overview</p>
        <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
            <h2 class="form-title" style="margin:0;">Dashboard</h2>
            @if($isSuperAdmin)
                <button type="button" id="btnToggleEye" class="btn gray small" style="display:inline-flex; align-items:center; gap:6px; font-weight:600; font-size:12px; padding:5px 11px; border-radius:4px; cursor:pointer;" title="Click to hide or unhide confidential financial figures">
                    <span id="eyeGlyph" style="font-size:14px; line-height:1;">👁️</span>
                    <span id="eyeText">Hide Details</span>
                </button>
            @endif
        </div>
    </div>
    @if($isSuperAdmin || in_array(auth()->user()?->role, ['finance_admin', 'cashier'], true))
        <a class="btn purple" href="/fee-creator">+ Create Invoices</a>
    @endif
</div>

<div class="grid kpi">
    @foreach($metrics as $metric)
        @php
            $isSensitive = $metric[3];
        @endphp
        <div class="metric">
            <div>
                <div class="metric-label">{{ $metric[0] }}</div>
                <div class="metric-value">
                    @if($isSuperAdmin && $isSensitive)
                        <span class="confidential-val" data-raw="{{ $metric[1] }}">{{ $metric[1] }}</span>
                    @elseif(!$isSuperAdmin && $isSensitive)
                        <span style="letter-spacing:2px; color:#a4a1ad; font-family:monospace;" title="Protected: Only Super Administrator can unhide">••••••</span>
                    @else
                        {{ $metric[1] }}
                    @endif
                </div>
            </div>
            <div class="metric-ico">{{ $metric[2] }}</div>
        </div>
    @endforeach
</div>

<div class="grid twocol">
    <div class="panel">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:8px;">
            <h2 style="margin:0;">Department Collections & Outstanding Dues</h2>
        </div>
        <div class="table-wrap"><table class="table"><thead><tr><th>Department</th><th>Currency</th><th>Billed</th><th>Allocated</th><th>Outstanding</th></tr></thead><tbody>
            @forelse($totals as $row)
                @php
                    $billedVal = \App\Services\Money::format($row->total);
                    $paidVal = \App\Services\Money::format($row->paid);
                    $outstandingVal = \App\Services\Money::format($row->total - $row->paid);
                @endphp
                <tr>
                    <td><span class="chip">{{ $departments->firstWhere('id', $row->department_id)?->name ?? $row->department_id }}</span></td>
                    <td>{{ $row->currency }}</td>
                    <td>
                        @if($isSuperAdmin)
                            <span class="confidential-val" data-raw="{{ $billedVal }}">{{ $billedVal }}</span>
                        @else
                            <span style="letter-spacing:2px; color:#a4a1ad; font-family:monospace;">••••••</span>
                        @endif
                    </td>
                    <td>
                        @if($isSuperAdmin)
                            <span class="confidential-val" data-raw="{{ $paidVal }}">{{ $paidVal }}</span>
                        @else
                            <span style="letter-spacing:2px; color:#a4a1ad; font-family:monospace;">••••••</span>
                        @endif
                    </td>
                    <td>
                        @if($isSuperAdmin)
                            <span class="confidential-val" data-raw="{{ $outstandingVal }}">{{ $outstandingVal }}</span>
                        @else
                            <span style="letter-spacing:2px; color:#a4a1ad; font-family:monospace;">••••••</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="center muted">Import verified students, assign fees, and generate invoices to see results.</td></tr>
            @endforelse
        </tbody></table></div>

        <p class="muted small" style="margin-top:10px;">Amounts are grouped by currency. Allocated payments exclude unapplied advance credits.</p>
    </div>
    <div class="panel">
        <h2>Billing Actions</h2>
        <div class="stack">
            <a class="btn purple" href="/fee-creator">Create Fee Invoices</a>
            <a class="btn" href="/invoices">Review & Send Invoices</a>
            <a class="btn" href="/challan">Receive Payment</a>
            <a class="btn" href="/reports">Department Reports</a>
        </div>
    </div>
</div>

<div class="panel">
    <h2>Departments</h2>
    <div class="department-summary">
        @foreach($departments as $department)
            <div class="card-band">{{ $department->name }} Department</div>
        @endforeach
    </div>
</div>

@if($isSuperAdmin)
<script>
document.addEventListener('DOMContentLoaded', function () {
    const btnToggle = document.getElementById('btnToggleEye');
    const eyeGlyph = document.getElementById('eyeGlyph');
    const eyeText = document.getElementById('eyeText');
    const confidentialEls = document.querySelectorAll('.confidential-val');

    const STORAGE_KEY = 'iqra_superadmin_dashboard_privacy';

    function setPrivacy(hidden) {
        confidentialEls.forEach(function (el) {
            if (hidden) {
                el.textContent = '••••••';
                el.style.letterSpacing = '2px';
                el.style.color = '#a4a1ad';
                el.style.fontFamily = 'monospace';
            } else {
                el.textContent = el.getAttribute('data-raw');
                el.style.letterSpacing = 'normal';
                el.style.color = '';
                el.style.fontFamily = '';
            }
        });

        if (hidden) {
            eyeGlyph.textContent = '👁️‍🗨️';
            eyeText.textContent = 'Unhide Details';
            btnToggle.classList.add('purple');
            btnToggle.classList.remove('gray');
            btnToggle.title = 'Confidential figures are hidden. Click to unhide.';
        } else {
            eyeGlyph.textContent = '👁️';
            eyeText.textContent = 'Hide Details';
            btnToggle.classList.remove('purple');
            btnToggle.classList.add('gray');
            btnToggle.title = 'Confidential figures are visible. Click to hide.';
        }

        try {
            localStorage.setItem(STORAGE_KEY, hidden ? '1' : '0');
        } catch (e) {}
    }

    // Default state: check localStorage
    let isCurrentlyHidden = false;
    try {
        isCurrentlyHidden = localStorage.getItem(STORAGE_KEY) === '1';
    } catch (e) {}

    setPrivacy(isCurrentlyHidden);

    btnToggle.addEventListener('click', function () {
        isCurrentlyHidden = !isCurrentlyHidden;
        setPrivacy(isCurrentlyHidden);
    });
});
</script>
@endif
@endsection
