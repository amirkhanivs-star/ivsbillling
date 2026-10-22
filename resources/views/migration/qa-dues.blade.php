@extends('layouts.app')
@section('title', 'QA Staged Dues Review')
@section('content')
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
    <div>
        <h2 style="font-size:22px;font-weight:700;color:#2c2c2c;margin:0;">QA Staged Dues / Outstanding Review</h2>
        <p style="color:#6e6b7b;font-size:13px;margin:4px 0 0;">Inspect 11,473 historical outstanding balance lines from October 22 reviewed workbook (Sheet: <code>05_OUTSTANDING_PRESENT_ONLY</code>).</p>
    </div>
    <div style="display:flex;gap:10px;">
        <span style="background:#e8fadf;color:#28c76f;padding:6px 12px;border-radius:20px;font-size:12px;font-weight:600;">
            Total Staged: {{ number_format($totalCount) }}
        </span>
        <span style="background:{{ $unresolvedCount > 0 ? '#fff3cd' : '#e8fadf' }};color:{{ $unresolvedCount > 0 ? '#856404' : '#28c76f' }};padding:6px 12px;border-radius:20px;font-size:12px;font-weight:600;">
            Unresolved Currencies: {{ $unresolvedCount }}
        </span>
        <a href="/migration" class="btn" style="background:#6c757d;color:#fff;text-decoration:none;font-size:12px;padding:6px 14px;border-radius:4px;">← Back to Migration</a>
    </div>
</div>

<div class="panel wide" style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:16px;margin-bottom:20px;">
    <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <input class="input" name="q" value="{{ request('q') }}" placeholder="Search GR, Student, Family, Particular, Class..." style="flex:1;min-width:220px;">
        
        <select class="input" name="currency">
            <option value="">All Currencies</option>
            @foreach($currencies as $curr)
                <option value="{{ $curr }}" @selected(request('currency') === $curr)>{{ $curr }}</option>
            @endforeach
        </select>

        <select class="input" name="unresolved">
            <option value="">All Statuses</option>
            <option value="1" @selected(request('unresolved') === '1')>⚠️ Unresolved Currencies (70)</option>
            <option value="0" @selected(request('unresolved') === '0')>✓ Resolved Only</option>
        </select>

        <button type="submit" class="btn purple">Search</button>
        @if(request()->hasAny(['q', 'currency', 'unresolved']))
            <a href="{{ route('migration.qa_dues') }}" class="btn" style="background:#ebe9f1;color:#5e5873;text-decoration:none;">Reset</a>
        @endif
    </form>
</div>

<div class="panel wide" style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:16px;">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Row</th>
                    <th>GR</th>
                    <th>Student Name</th>
                    <th>Family Code</th>
                    <th>Class in Report</th>
                    <th>Fee Particular</th>
                    <th>Currency</th>
                    <th>Outstanding Amount</th>
                    <th>Reg Curr</th>
                    <th>PDF Page</th>
                    <th>Flags</th>
                </tr>
            </thead>
            <tbody>
                @forelse($records as $r)
                <tr style="{{ $r->is_currency_unresolved ? 'background:#fff9e6;' : '' }}">
                    <td><small style="color:#b9b9c3;">#{{ $r->source_row }}</small></td>
                    <td><a href="/manage-student/{{ \Illuminate\Support\Facades\DB::table('students')->where('gr_number', $r->gr_number)->value('id') }}" style="color:#7367f0;font-weight:600;text-decoration:none;">{{ $r->gr_number }}</a></td>
                    <td>{{ $r->student_name ?: $r->registration_name ?: '—' }}</td>
                    <td><small>{{ $r->family_code_final ?: $r->family_code_registration ?: '—' }}</small></td>
                    <td><small>{{ $r->class_in_dues_report ?: '—' }}</small></td>
                    <td><small>{{ $r->fee_particular }}</small></td>
                    <td>
                        @if($r->is_currency_unresolved)
                            <span style="background:#ffefe8;color:#ea5455;padding:2px 6px;border-radius:4px;font-size:11px;font-weight:700;">UNRESOLVED</span>
                        @else
                            <span style="font-weight:600;">{{ $r->currency }}</span>
                        @endif
                    </td>
                    <td><strong>{{ number_format($r->outstanding_amount, 2) }}</strong></td>
                    <td><small>{{ $r->registration_currency ?: '—' }}</small></td>
                    <td><small>{{ $r->source_pdf_page ? 'p.' . $r->source_pdf_page : '—' }}</small></td>
                    <td>
                        @if($r->review_flags)
                            <span style="background:#fceaea;color:#ea5455;font-size:10px;padding:2px 5px;border-radius:4px;">{{ $r->review_flags }}</span>
                        @elseif($r->has_currency_conflict)
                            <span style="background:#fff0f0;color:#d9534f;font-size:10px;padding:2px 5px;border-radius:4px;">CONFLICT</span>
                        @else
                            <span style="color:#28c76f;font-size:11px;">✓</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="11" class="center" style="padding:24px;color:#b9b9c3;">No outstanding dues records match your search criteria.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div style="margin-top:16px;">
        {{ $records->links() }}
    </div>
</div>
@endsection
