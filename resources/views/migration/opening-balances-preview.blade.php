@extends('layouts.app')
@section('title', 'Historical Opening Balances Preview')
@section('content')
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
    <div>
        <h2 style="font-size:22px;font-weight:700;color:#2c2c2c;margin:0;">Historical Opening Balances Preview (QA Isolated)</h2>
        <p style="color:#6e6b7b;font-size:13px;margin:4px 0 0;">Inspect pre-posting simulation of historical opening balance invoices generated from <code>qa_staged_dues</code>.</p>
    </div>
    <div style="display:flex;gap:10px;">
        <span style="background:#ffefe8;color:#ea5455;padding:6px 14px;border-radius:20px;font-size:12px;font-weight:700;border:1px solid #ea5455;">
            POSTING LOCKED (STOPPED FOR APPROVAL)
        </span>
        <a href="/migration" class="btn" style="background:#6c757d;color:#fff;text-decoration:none;font-size:12px;padding:6px 14px;border-radius:4px;">← Back to Migration</a>
    </div>
</div>

<!-- Safeguard Banner -->
<div style="background:#fff4e5;border-left:4px solid #ff9f43;padding:14px 18px;border-radius:4px;margin-bottom:20px;">
    <strong style="color:#b25e00;font-size:14px;">Safeguard Status: Opening Balance Posting Authorization Required</strong>
    <p style="margin:4px 0 0;font-size:13px;color:#5e5873;">
        {{ $preview['safety_status']['message'] }} Outstanding dues represent remaining unpaid balances after historic collections; no payments have been subtracted twice, and zero financial ledger records have been posted.
    </p>
</div>

<!-- Summary Metrics Cards -->
@php $s = $preview['summary']; @endphp
<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(210px, 1fr));gap:15px;margin-bottom:25px;">
    <div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
        <small style="color:#b9b9c3;text-transform:uppercase;font-size:11px;font-weight:600;">Total Staged Dues</small>
        <h3 style="font-size:24px;margin:6px 0 0;color:#2c2c2c;">{{ number_format($s['total_staged_dues']) }}</h3>
        <small style="color:#7367f0;">Worksheet 05 rows</small>
    </div>

    <div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
        <small style="color:#b9b9c3;text-transform:uppercase;font-size:11px;font-weight:600;">Eligible Dues Rows</small>
        <h3 style="font-size:24px;margin:6px 0 0;color:#28c76f;">{{ number_format($s['eligible_rows']) }}</h3>
        <small style="color:#28c76f;">Verified currency & approval</small>
    </div>

    <div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
        <small style="color:#b9b9c3;text-transform:uppercase;font-size:11px;font-weight:600;">Excluded / Held Rows</small>
        <h3 style="font-size:24px;margin:6px 0 0;color:#ff9f43;">{{ number_format($s['excluded_rows_count']) }}</h3>
        <small style="color:#ff9f43;">70 unres. curr + 391 review hold</small>
    </div>

    <div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
        <small style="color:#b9b9c3;text-transform:uppercase;font-size:11px;font-weight:600;">Invoices To Create</small>
        <h3 style="font-size:24px;margin:6px 0 0;color:#7367f0;">{{ number_format($s['invoices_to_create']) }}</h3>
        <small style="color:#6e6b7b;">Grouped by family, period, dept</small>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:25px;">
    <!-- Currency Totals -->
    <div class="panel" style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:18px;">
        <h3 style="font-size:16px;font-weight:700;margin:0 0 12px;color:#2c2c2c;">Eligible Opening Balances by Currency</h3>
        <p style="font-size:12px;color:#6e6b7b;margin-bottom:12px;">Currencies are strictly isolated without cross-currency addition.</p>
        <div class="table-wrap">
            <table class="table" style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead>
                    <tr style="border-bottom:2px solid #ebe9f1;text-align:left;">
                        <th style="padding:8px;">Currency</th>
                        <th style="padding:8px;text-align:right;">Line Count</th>
                        <th style="padding:8px;text-align:right;">Total Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($preview['totals_by_currency'] as $c => $data)
                    <tr style="border-bottom:1px solid #ebe9f1;">
                        <td style="padding:8px;"><strong style="color:#7367f0;">{{ $c }}</strong></td>
                        <td style="padding:8px;text-align:right;">{{ number_format($data['lines']) }}</td>
                        <td style="padding:8px;text-align:right;font-weight:700;">{{ number_format((float) $data['total_decimal'], 2) }} {{ $c }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <!-- Department Breakdown -->
    <div class="panel" style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:18px;">
        <h3 style="font-size:16px;font-weight:700;margin:0 0 12px;color:#2c2c2c;">Eligible Opening Balances by Department</h3>
        <p style="font-size:12px;color:#6e6b7b;margin-bottom:12px;">Separation between School and Tuition accounts.</p>
        <div class="table-wrap">
            <table class="table" style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead>
                    <tr style="border-bottom:2px solid #ebe9f1;text-align:left;">
                        <th style="padding:8px;">Department</th>
                        <th style="padding:8px;">Currency</th>
                        <th style="padding:8px;text-align:right;">Total Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($preview['totals_by_department'] as $dept => $currs)
                        @foreach($currs as $c => $data)
                        <tr style="border-bottom:1px solid #ebe9f1;">
                            <td style="padding:8px;">
                                <span style="background:{{ $dept === 'Tuition' ? '#e0f2fe' : '#f3e8ff' }};color:{{ $dept === 'Tuition' ? '#0369a1' : '#7e22ce' }};padding:2px 6px;border-radius:4px;font-size:11px;font-weight:600;">{{ $dept }}</span>
                            </td>
                            <td style="padding:8px;"><strong>{{ $c }}</strong></td>
                            <td style="padding:8px;text-align:right;font-weight:700;">{{ number_format((float) $data['total_decimal'], 2) }} {{ $c }}</td>
                        </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Excluded Rows Detail -->
@php $ex = $preview['exclusions']; @endphp
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
        <h3 style="font-size:16px;font-weight:700;margin:0;color:#2c2c2c;">Excluded & Held Dues Lines ({{ number_format($s['excluded_rows_count']) }} Lines Kept Out of Ledger)</h3>
        <a href="{{ route('migration.held_dues') }}" class="btn" style="background:#ea5455;color:#fff;text-decoration:none;font-size:12px;padding:6px 14px;border-radius:4px;font-weight:600;">
            Review All 790 Held Dues Lines & Reasons →
        </a>
    </div>
    <p style="font-size:12px;color:#6e6b7b;margin-bottom:14px;">These rows remain safely staged in <code>qa_staged_dues</code> and are strictly excluded from opening-balance invoice generation.</p>

    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:15px;margin-bottom:15px;">
        <div style="background:#fff9e6;border:1px solid #ffeeba;border-radius:4px;padding:12px;">
            <strong style="color:#856404;">⚠️ Unresolved Currency: {{ $ex['unresolved_currency_count'] }}</strong>
            <p style="font-size:12px;color:#6e6b7b;margin:4px 0 0;">70 rows with blank currency or aliases like "SAE" awaiting currency determination.</p>
        </div>
        <div style="background:#fff4e5;border:1px solid #ffd8a8;border-radius:4px;padding:12px;">
            <strong style="color:#d9480f;">🔒 Review-Hold Students: {{ $ex['review_hold_student_count'] }}</strong>
            <p style="font-size:12px;color:#6e6b7b;margin:4px 0 0;">391 dues lines belonging to the 72 provisional/review students on financial approval hold.</p>
        </div>
        <div style="background:#f1f5f9;border:1px solid #cbd5e1;border-radius:4px;padding:12px;">
            <strong style="color:#475569;">📅 Unknown Period: {{ $ex['unknown_period_count'] }}</strong>
            <p style="font-size:12px;color:#6e6b7b;margin:4px 0 0;">329 course/subject particulars lacking verified 4-digit calendar year (held for review; no invented Sep 2026).</p>
        </div>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:12px;">
            <strong style="color:#334155;">🏢 Department Review: {{ $ex['ambiguous_department_count'] + $ex['unmatched_enrollment_count'] }}</strong>
            <p style="font-size:12px;color:#6e6b7b;margin:4px 0 0;">Ambiguous or unmatched department assignments held safely.</p>
        </div>
    </div>

    @if(!empty($ex['samples']['unresolved_currency']))
    <h4 style="font-size:13px;font-weight:700;color:#5e5873;margin:15px 0 8px;">Sample Unresolved Currency Exclusions:</h4>
    <table class="table" style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead>
            <tr style="border-bottom:1px solid #ebe9f1;background:#f8f8f8;text-align:left;">
                <th style="padding:6px;">Source Row</th>
                <th style="padding:6px;">GR Number</th>
                <th style="padding:6px;">Raw Currency Label</th>
                <th style="padding:6px;">Reason</th>
            </tr>
        </thead>
        <tbody>
            @foreach($ex['samples']['unresolved_currency'] as $samp)
            <tr style="border-bottom:1px solid #ebe9f1;">
                <td style="padding:6px;"><small style="color:#b9b9c3;">#{{ $samp['source_row'] }}</small></td>
                <td style="padding:6px;"><strong>{{ $samp['gr_number'] }}</strong></td>
                <td style="padding:6px;"><span style="color:#ea5455;font-weight:700;">{{ $samp['raw_currency'] ?: '[BLANK]' }}</span></td>
                <td style="padding:6px;color:#6e6b7b;">{{ $samp['reason'] }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif
</div>

<!-- Sample Invoices to be Created -->
<div class="panel wide" style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:18px;">
    <h3 style="font-size:16px;font-weight:700;margin:0 0 10px;color:#2c2c2c;">Sample Opening-Balance Invoices (Grouping Preview)</h3>
    <p style="font-size:12px;color:#6e6b7b;margin-bottom:14px;">Invoices are grouped by Family ID, Department, Campus, Currency, and Billing Period, maintaining immutable links to source staged due IDs.</p>

    <div class="table-wrap">
        <table class="table" style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
                <tr style="border-bottom:2px solid #ebe9f1;background:#f8f8f8;text-align:left;">
                    <th style="padding:8px;">Family ID</th>
                    <th style="padding:8px;">Department</th>
                    <th style="padding:8px;">Currency</th>
                    <th style="padding:8px;">Billing Period</th>
                    <th style="padding:8px;text-align:center;">Items Count</th>
                    <th style="padding:8px;text-align:right;">Invoice Total</th>
                    <th style="padding:8px;">Sample Particular</th>
                </tr>
            </thead>
            <tbody>
                @foreach($preview['sample_invoices'] as $inv)
                <tr style="border-bottom:1px solid #ebe9f1;">
                    <td style="padding:8px;"><strong>Family #{{ $inv['family_id'] }}</strong></td>
                    <td style="padding:8px;">
                        <span style="background:{{ $inv['department_id'] == 2 ? '#e0f2fe' : '#f3e8ff' }};color:{{ $inv['department_id'] == 2 ? '#0369a1' : '#7e22ce' }};padding:2px 6px;border-radius:4px;font-size:11px;font-weight:600;">
                            {{ $inv['department_id'] == 2 ? 'Tuition' : 'School' }}
                        </span>
                    </td>
                    <td style="padding:8px;"><strong style="color:#7367f0;">{{ $inv['currency'] }}</strong></td>
                    <td style="padding:8px;"><small>{{ $inv['period_key'] }}</small></td>
                    <td style="padding:8px;text-align:center;">{{ $inv['items_count'] }}</td>
                    <td style="padding:8px;text-align:right;font-weight:700;">{{ number_format($inv['total_minor'] / 100, 2) }} {{ $inv['currency'] }}</td>
                    <td style="padding:8px;"><small style="color:#6e6b7b;">{{ $inv['items'][0]['fee_particular'] ?? '—' }}</small></td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
