@extends('layouts.app')
@section('title', 'Staging Exceptions')
@section('content')
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
    <div>
        <h2 style="font-size:22px;font-weight:700;color:#2c2c2c;margin:0;">Staging Exceptions Review</h2>
        <p style="color:#6e6b7b;font-size:13px;margin:4px 0 0;">16,727 pre-computed Google Sheets data quality findings preserved for administrative review.</p>
    </div>
    <div style="display:flex;gap:10px;">
        <a href="/migration/exceptions/export{{ $currentIssueCode ? '?issue_code='.$currentIssueCode : '' }}" style="background:#28c76f;color:#fff;padding:8px 16px;border-radius:4px;font-size:13px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px;">
            Download CSV Export ⤓
        </a>
        <a href="/migration" style="color:var(--ms-purple,#7367f0);font-size:13px;text-decoration:none;font-weight:600;display:inline-flex;align-items:center;">← Dashboard</a>
    </div>
</div>

<!-- Issue Code Pills -->
<div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:16px;margin-bottom:20px;">
    <span style="font-size:12px;font-weight:600;color:#b9b9c3;text-transform:uppercase;display:block;margin-bottom:10px;">Filter by Issue Finding</span>
    <div style="display:flex;flex-wrap:wrap;gap:8px;">
        <a href="/migration/exceptions" style="padding:4px 10px;border-radius:15px;font-size:11px;font-weight:600;text-decoration:none;background:{{ empty($currentIssueCode)?'#7367f0':'#f8f8f8' }};color:{{ empty($currentIssueCode)?'#fff':'#5e5873' }};">
            All (16,727)
        </a>
        @foreach($issueCodes as $ic)
            <a href="/migration/exceptions?issue_code={{ $ic->issue_code }}" style="padding:4px 10px;border-radius:15px;font-size:11px;font-weight:600;text-decoration:none;background:{{ $currentIssueCode===$ic->issue_code?'#7367f0':'#f8f8f8' }};color:{{ $currentIssueCode===$ic->issue_code?'#fff':'#5e5873' }};border:1px solid #ebe9f1;">
                {{ $ic->issue_code }} ({{ number_format($ic->cnt) }})
            </a>
        @endforeach
    </div>
</div>

<!-- Table -->
<div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:20px;">
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead>
            <tr style="border-bottom:2px solid #ebe9f1;text-align:left;color:#6e6b7b;">
                <th style="padding:10px 8px;">ID</th>
                <th style="padding:10px 8px;">Source Sheet</th>
                <th style="padding:10px 8px;">Row</th>
                <th style="padding:10px 8px;">Source Key</th>
                <th style="padding:10px 8px;">GR Number</th>
                <th style="padding:10px 8px;">Issue Code</th>
                <th style="padding:10px 8px;">Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($records as $rec)
            <tr style="border-bottom:1px solid #ebe9f1;">
                <td style="padding:10px 8px;font-weight:600;">#{{ $rec->id }}</td>
                <td style="padding:10px 8px;color:#6e6b7b;">{{ $rec->source_sheet }}</td>
                <td style="padding:10px 8px;">{{ $rec->source_row }}</td>
                <td style="padding:10px 8px;font-weight:600;">{{ $rec->source_key }}</td>
                <td style="padding:10px 8px;">{{ $rec->gr_number ?: '—' }}</td>
                <td style="padding:10px 8px;">
                    <span style="background:#fff0e1;color:#ff9f43;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">
                        {{ $rec->issue_code }}
                    </span>
                </td>
                <td style="padding:10px 8px;">
                    <span style="background:#e8f0fe;color:#1a73e8;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">
                        {{ $rec->resolution_status }}
                    </span>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="7" style="padding:20px;text-align:center;color:#b9b9c3;">No exceptions found for this filter.</td>
            </tr>
            @endforelse
        </tbody>
    </table>

    <div style="margin-top:15px;">
        {{ $records->links() }}
    </div>
</div>
@endsection
