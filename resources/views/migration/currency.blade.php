@extends('layouts.app')
@section('title', 'Currency Review')
@section('content')
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
    <div>
        <h2 style="font-size:22px;font-weight:700;color:#2c2c2c;margin:0;">Student Currency Review & Approvals</h2>
        <p style="color:#6e6b7b;font-size:13px;margin:4px 0 0;">Review individual student currencies. 4,202 currencies pre-approved; 138 cases require manual administrative sign-off.</p>
    </div>
    <a href="/migration" style="color:var(--ms-purple,#7367f0);font-size:13px;text-decoration:none;font-weight:600;">← Back to Migration Dashboard</a>
</div>

<!-- Stats Strip -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;margin-bottom:20px;">
    <div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:14px;">
        <span style="font-size:12px;color:#b9b9c3;font-weight:600;text-transform:uppercase;">Total Evaluated</span>
        <div style="font-size:20px;font-weight:700;color:#5e5873;">{{ number_format($stats['total']) }}</div>
    </div>
    <div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:14px;">
        <span style="font-size:12px;color:#b9b9c3;font-weight:600;text-transform:uppercase;">Approved</span>
        <div style="font-size:20px;font-weight:700;color:#28c76f;">{{ number_format($stats['approved']) }}</div>
    </div>
    <div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:14px;">
        <span style="font-size:12px;color:#b9b9c3;font-weight:600;text-transform:uppercase;">Pending Review</span>
        <div style="font-size:20px;font-weight:700;color:#ea5455;">{{ number_format($stats['pending']) }}</div>
    </div>
    <div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:14px;">
        <span style="font-size:12px;color:#b9b9c3;font-weight:600;text-transform:uppercase;">No Financial Evidence</span>
        <div style="font-size:20px;font-weight:700;color:#ff9f43;">{{ $stats['no_financial'] }}</div>
    </div>
    <div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:14px;">
        <span style="font-size:12px;color:#b9b9c3;font-weight:600;text-transform:uppercase;">Unrecognized Currency</span>
        <div style="font-size:20px;font-weight:700;color:#ea5455;">{{ $stats['unrecognized'] }}</div>
    </div>
</div>

<!-- Records List -->
<div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:20px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
        <h3 style="font-size:16px;font-weight:700;color:#2c2c2c;margin:0;">Currency Review Queue</h3>
        <div style="display:flex;gap:10px;">
            <a href="/migration/currency?status=PENDING_REVIEW" style="padding:4px 10px;border-radius:4px;font-size:12px;text-decoration:none;font-weight:600;background:{{ $currentStatus==='PENDING_REVIEW'?'#ea5455':'#f8f8f8' }};color:{{ $currentStatus==='PENDING_REVIEW'?'#fff':'#6e6b7b' }};">Pending Review ({{ $stats['pending'] }})</a>
            <a href="/migration/currency?status=APPROVED" style="padding:4px 10px;border-radius:4px;font-size:12px;text-decoration:none;font-weight:600;background:{{ $currentStatus==='APPROVED'?'#28c76f':'#f8f8f8' }};color:{{ $currentStatus==='APPROVED'?'#fff':'#6e6b7b' }};">Approved ({{ $stats['approved'] }})</a>
            <a href="/migration/currency" style="padding:4px 10px;border-radius:4px;font-size:12px;text-decoration:none;font-weight:600;background:{{ empty($currentStatus)?'#333':'#f8f8f8' }};color:{{ empty($currentStatus)?'#fff':'#6e6b7b' }};">All</a>
        </div>
    </div>

    <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead>
            <tr style="border-bottom:2px solid #ebe9f1;text-align:left;color:#6e6b7b;">
                <th style="padding:10px 8px;">GR Number</th>
                <th style="padding:10px 8px;">Student Name</th>
                <th style="padding:10px 8px;">Family Code</th>
                <th style="padding:10px 8px;">Match Status</th>
                <th style="padding:10px 8px;">Dues/Paid Lines</th>
                <th style="padding:10px 8px;">Evidence / Counts</th>
                <th style="padding:10px 8px;">Approved Currency</th>
                <th style="padding:10px 8px;text-align:right;">Action</th>
            </tr>
        </thead>
        <tbody>
            @forelse($records as $rec)
            <tr style="border-bottom:1px solid #ebe9f1;">
                <td style="padding:10px 8px;font-weight:600;">{{ $rec->gr_number }}</td>
                <td style="padding:10px 8px;">{{ $rec->student_name }}</td>
                <td style="padding:10px 8px;">{{ $rec->family_code ?: '—' }}</td>
                <td style="padding:10px 8px;">
                    <span style="font-size:11px;font-weight:600;padding:2px 8px;border-radius:4px;background:{{ $rec->match_status==='UNIQUE_CURRENCY'?'#e8fadf':($rec->match_status==='NO_FINANCIAL_EVIDENCE'?'#fff0e1':'#fceaea') }};color:{{ $rec->match_status==='UNIQUE_CURRENCY'?'#28c76f':($rec->match_status==='NO_FINANCIAL_EVIDENCE'?'#ff9f43':'#ea5455') }};">
                        {{ $rec->match_status }}
                    </span>
                </td>
                <td style="padding:10px 8px;color:#6e6b7b;">
                    Dues: {{ $rec->dues_detail_lines }} | Paid: {{ $rec->paid_detail_lines }}
                </td>
                <td style="padding:10px 8px;font-size:12px;color:#6e6b7b;">
                    {{ $rec->recognized_currency_counts ?: ($rec->unrecognized_source_currency_labels ?: 'No transaction evidence') }}
                </td>
                <td style="padding:10px 8px;">
                    @if($rec->currency_approved_manual)
                        <strong style="color:#28c76f;background:#e8fadf;padding:2px 6px;border-radius:4px;">{{ $rec->currency_approved_manual }}</strong>
                    @else
                        <span style="color:#ea5455;font-weight:600;">Pending</span>
                    @endif
                </td>
                <td style="padding:10px 8px;text-align:right;">
                    @if(!$rec->currency_approved_manual)
                        <form action="/migration/currency/approve" method="post" style="display:inline-flex;gap:4px;margin:0;">
                            @csrf
                            <input type="hidden" name="gr_number" value="{{ $rec->gr_number }}">
                            <select name="currency_iso" required style="border:1px solid #d8d6de;border-radius:4px;padding:3px 6px;font-size:11px;">
                                <option value="SAR">SAR</option>
                                <option value="AED">AED</option>
                                <option value="PKR">PKR</option>
                                <option value="QAR">QAR</option>
                                <option value="OMR">OMR</option>
                                <option value="BHD">BHD</option>
                                <option value="USD">USD</option>
                            </select>
                            <button type="submit" style="background:#28c76f;color:#fff;border:none;border-radius:4px;padding:3px 8px;font-size:11px;font-weight:600;cursor:pointer;">
                                Approve
                            </button>
                        </form>
                    @else
                        <span style="color:#b9b9c3;font-size:12px;">Approved</span>
                    @endif
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="8" style="padding:20px;text-align:center;color:#b9b9c3;">No currency review records found.</td>
            </tr>
            @endforelse
        </tbody>
    </table>

    <div style="margin-top:15px;">
        {{ $records->links() }}
    </div>
</div>
@endsection
