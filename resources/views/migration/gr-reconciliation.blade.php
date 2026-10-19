@extends('layouts.app')
@section('title', 'Historical GR Reconciliation')
@section('content')
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
    <div>
        <h2 style="font-size:22px;font-weight:700;color:#2c2c2c;margin:0;">Historical GR Reconciliation Tool</h2>
        <p style="color:#6e6b7b;font-size:13px;margin:4px 0 0;">Review unknown and unlinked GR numbers from historical dues and collection records. Deterministic suggestions provided.</p>
    </div>
    <a href="/migration" style="color:var(--ms-purple,#7367f0);font-size:13px;text-decoration:none;font-weight:600;">← Back to Migration Dashboard</a>
</div>

<!-- Stats Strip -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:15px;margin-bottom:20px;">
    <div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:14px;">
        <span style="font-size:12px;color:#b9b9c3;font-weight:600;text-transform:uppercase;">Total Cases</span>
        <div style="font-size:20px;font-weight:700;color:#5e5873;">{{ number_format($stats['total']) }}</div>
    </div>
    <div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:14px;">
        <span style="font-size:12px;color:#b9b9c3;font-weight:600;text-transform:uppercase;">Unknown Dues</span>
        <div style="font-size:20px;font-weight:700;color:#ea5455;">{{ number_format($stats['dues']) }}</div>
    </div>
    <div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:14px;">
        <span style="font-size:12px;color:#b9b9c3;font-weight:600;text-transform:uppercase;">Unknown Payments</span>
        <div style="font-size:20px;font-weight:700;color:#ea5455;">{{ number_format($stats['payments']) }}</div>
    </div>
    <div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:14px;">
        <span style="font-size:12px;color:#b9b9c3;font-weight:600;text-transform:uppercase;">Unlinked Payments</span>
        <div style="font-size:20px;font-weight:700;color:#ff9f43;">{{ number_format($stats['unlinked']) }}</div>
    </div>
    <div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:14px;">
        <span style="font-size:12px;color:#b9b9c3;font-weight:600;text-transform:uppercase;">Exact Name Matches</span>
        <div style="font-size:20px;font-weight:700;color:#28c76f;">{{ number_format($stats['exact_matches']) }}</div>
    </div>
</div>

<!-- Search & Filter -->
<div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:16px;margin-bottom:20px;">
    <form action="/migration/gr-reconciliation" method="get" style="display:flex;gap:12px;align-items:center;">
        <input type="text" name="search" value="{{ $search }}" placeholder="Search by GR Number, Student Name, or Slip #" style="flex:1;border:1px solid #d8d6de;border-radius:4px;padding:8px 12px;font-size:13px;">
        <select name="source_type" style="border:1px solid #d8d6de;border-radius:4px;padding:8px;font-size:13px;">
            <option value="">-- All Types --</option>
            <option value="due" {{ $currentSourceType==='due'?'selected':'' }}>Dues</option>
            <option value="payment" {{ $currentSourceType==='payment'?'selected':'' }}>Payments</option>
        </select>
        <select name="confidence" style="border:1px solid #d8d6de;border-radius:4px;padding:8px;font-size:13px;">
            <option value="">-- All Confidence --</option>
            <option value="EXACT_NAME" {{ $currentConfidence==='EXACT_NAME'?'selected':'' }}>Exact Name Match</option>
            <option value="UNLINKED" {{ $currentConfidence==='UNLINKED'?'selected':'' }}>Unlinked (Blank GR)</option>
            <option value="NO_MATCH" {{ $currentConfidence==='NO_MATCH'?'selected':'' }}>No Match</option>
        </select>
        <button type="submit" style="background:var(--ms-purple,#7367f0);color:#fff;border:none;border-radius:4px;padding:8px 16px;font-size:13px;font-weight:600;cursor:pointer;">Filter</button>
        @if($search || $currentSourceType || $currentConfidence)
            <a href="/migration/gr-reconciliation" style="color:#6e6b7b;font-size:13px;text-decoration:none;padding:8px;">Reset</a>
        @endif
    </form>
</div>

<!-- Table -->
<div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:20px;">
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead>
            <tr style="border-bottom:2px solid #ebe9f1;text-align:left;color:#6e6b7b;">
                <th style="padding:10px 8px;">Type</th>
                <th style="padding:10px 8px;">Source Key</th>
                <th style="padding:10px 8px;">Raw GR</th>
                <th style="padding:10px 8px;">Raw Student Name</th>
                <th style="padding:10px 8px;">Amount</th>
                <th style="padding:10px 8px;">Slip # / Period</th>
                <th style="padding:10px 8px;">Suggested Match</th>
                <th style="padding:10px 8px;">Confidence</th>
                <th style="padding:10px 8px;text-align:right;">Resolve / Link</th>
            </tr>
        </thead>
        <tbody>
            @forelse($records as $rec)
            <tr style="border-bottom:1px solid #ebe9f1;">
                <td style="padding:10px 8px;">
                    <span style="font-size:10px;font-weight:700;padding:2px 6px;border-radius:4px;background:{{ $rec->source_type==='due'?'#fff0e1':'#e8f0fe' }};color:{{ $rec->source_type==='due'?'#ff9f43':'#1a73e8' }};">
                        {{ strtoupper($rec->source_type) }}
                    </span>
                </td>
                <td style="padding:10px 8px;font-weight:600;">{{ $rec->source_key }}</td>
                <td style="padding:10px 8px;">{{ $rec->raw_gr_number ?: '— (Unlinked)' }}</td>
                <td style="padding:10px 8px;">{{ $rec->raw_student_name }}</td>
                <td style="padding:10px 8px;font-weight:600;">{{ number_format($rec->amount, 2) }} {{ $rec->currency_iso }}</td>
                <td style="padding:10px 8px;color:#6e6b7b;font-size:12px;">{{ $rec->slip_number ?: $rec->transaction_date }}</td>
                <td style="padding:10px 8px;">
                    @if($rec->suggested_match_gr)
                        <span style="color:#28c76f;font-weight:600;">GR {{ $rec->suggested_match_gr }}: {{ $rec->suggested_match_name }}</span>
                    @else
                        <span style="color:#b9b9c3;">None</span>
                    @endif
                </td>
                <td style="padding:10px 8px;">
                    <span style="font-size:11px;font-weight:600;padding:2px 6px;border-radius:4px;background:{{ $rec->match_confidence==='EXACT_NAME'?'#e8fadf':($rec->match_confidence==='UNLINKED'?'#fff0e1':'#fceaea') }};color:{{ $rec->match_confidence==='EXACT_NAME'?'#28c76f':($rec->match_confidence==='UNLINKED'?'#ff9f43':'#ea5455') }};">
                        {{ $rec->match_confidence }}
                    </span>
                </td>
                <td style="padding:10px 8px;text-align:right;">
                    @if($rec->reconciliation_status === 'UNRESOLVED')
                        <form action="/migration/gr-reconciliation/resolve" method="post" style="display:inline-flex;gap:4px;margin:0;">
                            @csrf
                            <input type="hidden" name="source_key" value="{{ $rec->source_key }}">
                            <input type="text" name="resolved_gr_number" value="{{ $rec->suggested_match_gr }}" placeholder="Enter GR" required style="width:80px;border:1px solid #d8d6de;border-radius:4px;padding:3px 6px;font-size:11px;">
                            <button type="submit" style="background:#28c76f;color:#fff;border:none;border-radius:4px;padding:3px 8px;font-size:11px;font-weight:600;cursor:pointer;">
                                Link
                            </button>
                        </form>
                    @else
                        <span style="color:#28c76f;font-weight:600;font-size:11px;">Linked to GR {{ $rec->resolved_gr_number }}</span>
                    @endif
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="9" style="padding:20px;text-align:center;color:#b9b9c3;">No reconciliation records found.</td>
            </tr>
            @endforelse
        </tbody>
    </table>

    <div style="margin-top:15px;">
        {{ $records->links() }}
    </div>
</div>
@endsection
