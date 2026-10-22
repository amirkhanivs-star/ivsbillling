@extends('layouts.app')
@section('title', 'Financial Exceptions — Held Dues Lines')
@section('content')
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;">
    <div>
        <h2 style="font-size:22px;font-weight:700;color:#2c2c2c;margin:0;">Financial Exceptions — Held Dues Lines</h2>
        <p style="color:#6e6b7b;font-size:13px;margin:4px 0 0;">Staff-review screen identifying all 790 held dues lines by source row, reason, and required correction.</p>
    </div>
    <div style="display:flex;gap:10px;">
        <a href="{{ route('migration.held_dues.export', ['category' => request('category'), 'q' => request('q')]) }}" class="btn" style="background:#28c76f;color:#fff;text-decoration:none;font-size:12px;padding:7px 16px;border-radius:4px;font-weight:600;display:inline-flex;align-items:center;gap:6px;">
            ⇪ Export Held Dues (CSV)
        </a>
        <a href="{{ route('migration.opening_balances_preview') }}" class="btn" style="background:#7367f0;color:#fff;text-decoration:none;font-size:12px;padding:7px 16px;border-radius:4px;">
            ← Opening Balances Preview
        </a>
    </div>
</div>

<!-- Safeguard Notice -->
<div style="background:#fff4e5;border-left:4px solid #ea5455;padding:14px 18px;border-radius:4px;margin-bottom:20px;">
    <strong style="color:#b91c1c;font-size:14px;">🔒 STRICT AUDIT POLICY: REVIEW-ONLY EXCEPTION SCREEN</strong>
    <p style="margin:4px 0 0;font-size:13px;color:#5e5873;">
        These 790 dues lines were <strong>held from opening-balance invoice posting</strong> to protect ledger integrity. They cannot be automatically approved or silently billed. Staff must audit physical vouchers and resolve student/currency records before any held item can be converted into a valid invoice.
    </p>
</div>

<!-- Summary Metric Cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(210px, 1fr));gap:15px;margin-bottom:25px;">
    <div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
        <small style="color:#b9b9c3;text-transform:uppercase;font-size:11px;font-weight:600;">Total Held Lines</small>
        <h3 style="font-size:24px;margin:6px 0 0;color:#ea5455;">{{ number_format($counts['total']) }}</h3>
        <small style="color:#6e6b7b;">Excluded from ledger</small>
    </div>

    <div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
        <small style="color:#b9b9c3;text-transform:uppercase;font-size:11px;font-weight:600;">Student Review Hold</small>
        <h3 style="font-size:24px;margin:6px 0 0;color:#d9480f;">{{ number_format($counts['review_hold_student']) }}</h3>
        <small style="color:#d9480f;">72 students pending currency audit</small>
    </div>

    <div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
        <small style="color:#b9b9c3;text-transform:uppercase;font-size:11px;font-weight:600;">Unknown / Missing Period</small>
        <h3 style="font-size:24px;margin:6px 0 0;color:#7367f0;">{{ number_format($counts['unknown_period']) }}</h3>
        <small style="color:#7367f0;">Lacks verified calendar month/year</small>
    </div>

    <div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
        <small style="color:#b9b9c3;text-transform:uppercase;font-size:11px;font-weight:600;">Unresolved Currency</small>
        <h3 style="font-size:24px;margin:6px 0 0;color:#ff9f43;">{{ number_format($counts['unresolved_currency']) }}</h3>
        <small style="color:#ff9f43;">Blank or ambiguous currency label</small>
    </div>
</div>

<!-- Filter Toolbar -->
<div class="panel" style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:16px;margin-bottom:20px;">
    <form method="get" action="{{ route('migration.held_dues') }}" style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px;">
        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
            <a href="{{ route('migration.held_dues', ['q' => request('q')]) }}" 
               class="btn" style="font-size:12px;padding:6px 14px;border-radius:20px;text-decoration:none;{{ $currentCategory === 'all' ? 'background:#7367f0;color:#fff;' : 'background:#f8f7fa;color:#6e6b7b;border:1px solid #d8d6de;' }}">
                All Exceptions ({{ $counts['total'] }})
            </a>
            <a href="{{ route('migration.held_dues', ['category' => 'review_hold_student', 'q' => request('q')]) }}" 
               class="btn" style="font-size:12px;padding:6px 14px;border-radius:20px;text-decoration:none;{{ $currentCategory === 'review_hold_student' ? 'background:#d9480f;color:#fff;' : 'background:#f8f7fa;color:#6e6b7b;border:1px solid #d8d6de;' }}">
                Review-Hold Students ({{ $counts['review_hold_student'] }})
            </a>
            <a href="{{ route('migration.held_dues', ['category' => 'unknown_period', 'q' => request('q')]) }}" 
               class="btn" style="font-size:12px;padding:6px 14px;border-radius:20px;text-decoration:none;{{ $currentCategory === 'unknown_period' ? 'background:#7367f0;color:#fff;' : 'background:#f8f7fa;color:#6e6b7b;border:1px solid #d8d6de;' }}">
                Unknown Period ({{ $counts['unknown_period'] }})
            </a>
            <a href="{{ route('migration.held_dues', ['category' => 'unresolved_currency', 'q' => request('q')]) }}" 
               class="btn" style="font-size:12px;padding:6px 14px;border-radius:20px;text-decoration:none;{{ $currentCategory === 'unresolved_currency' ? 'background:#ff9f43;color:#fff;' : 'background:#f8f7fa;color:#6e6b7b;border:1px solid #d8d6de;' }}">
                Unresolved Currency ({{ $counts['unresolved_currency'] }})
            </a>
        </div>

        <div style="display:flex;gap:8px;align-items:center;">
            @if($currentCategory !== 'all')
                <input type="hidden" name="category" value="{{ $currentCategory }}">
            @endif
            <input type="text" name="q" value="{{ $searchTerm }}" class="input" placeholder="Search GR, name, particular, or row #" style="width:260px;height:34px;font-size:12px;">
            <button type="submit" class="btn purple" style="height:34px;padding:0 16px;font-size:12px;">Filter</button>
            @if($searchTerm || $currentCategory !== 'all')
                <a href="{{ route('migration.held_dues') }}" class="btn gray" style="height:34px;padding:7px 12px;font-size:12px;text-decoration:none;">Reset</a>
            @endif
        </div>
    </form>
</div>

<!-- Held Dues Table -->
<div class="panel wide" style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:18px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
        <h3 style="font-size:15px;font-weight:700;margin:0;color:#2c2c2c;">
            Held Dues Detail (Showing {{ $records->firstItem() ?? 0 }} to {{ $records->lastItem() ?? 0 }} of {{ $records->total() }})
        </h3>
        <span style="font-size:11px;background:#fef2f2;color:#991b1b;padding:3px 10px;border-radius:12px;font-weight:700;border:1px solid #fecaca;">
            Batch: BATCH-20261022-PORTAL-V2-REVISED
        </span>
    </div>

    <div class="table-wrap" style="overflow-x:auto;">
        <table class="table" style="width:100%;border-collapse:collapse;font-size:12px;">
            <thead>
                <tr style="border-bottom:2px solid #ebe9f1;background:#f8f8f8;text-align:left;">
                    <th style="padding:10px 8px;width:70px;">Source Row</th>
                    <th style="padding:10px 8px;width:130px;">GR / Student</th>
                    <th style="padding:10px 8px;width:150px;">Fee Particular</th>
                    <th style="padding:10px 8px;width:100px;">Amount</th>
                    <th style="padding:10px 8px;width:140px;">Hold Category</th>
                    <th style="padding:10px 8px;min-width:200px;">Hold Reason</th>
                    <th style="padding:10px 8px;min-width:220px;">Required Staff Correction</th>
                    <th style="padding:10px 8px;width:90px;text-align:center;">Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($records as $row)
                <tr style="border-bottom:1px solid #ebe9f1;">
                    <td style="padding:8px;">
                        <span class="iq-pill" style="background:#f1f5f9;color:#475569;font-weight:700;">#{{ $row['source_row'] }}</span>
                        @if($row['source_pdf_page'])
                            <div style="font-size:10px;color:#94a3b8;margin-top:2px;">p. {{ $row['source_pdf_page'] }}</div>
                        @endif
                    </td>
                    <td style="padding:8px;">
                        <strong style="color:#7367f0;">GR {{ $row['gr_number'] }}</strong>
                        <div style="font-size:11px;color:#2c2c2c;font-weight:500;">{{ $row['student_name'] }}</div>
                        <div style="font-size:10px;color:#64748b;">Class: {{ $row['class_label'] }}</div>
                    </td>
                    <td style="padding:8px;">
                        <span style="font-weight:500;color:#1e293b;">{{ $row['fee_particular'] }}</span>
                    </td>
                    <td style="padding:8px;">
                        <strong style="color:#0f172a;">{{ $row['currency'] }} {{ $row['amount_decimal'] }}</strong>
                    </td>
                    <td style="padding:8px;">
                        @if($row['category'] === 'review_hold_student')
                            <span style="background:#fff4e5;color:#d9480f;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;display:inline-block;">Review-Hold</span>
                        @elseif($row['category'] === 'unknown_period')
                            <span style="background:#f3e8ff;color:#7e22ce;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;display:inline-block;">Unknown Period</span>
                        @elseif($row['category'] === 'unresolved_currency')
                            <span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;display:inline-block;">Unresolved Currency</span>
                        @else
                            <span style="background:#f1f5f9;color:#475569;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;display:inline-block;">{{ $row['category_label'] }}</span>
                        @endif
                    </td>
                    <td style="padding:8px;color:#475569;font-size:11px;line-height:1.4;">
                        {{ $row['reason'] }}
                    </td>
                    <td style="padding:8px;color:#047857;background:#f0fdf4;font-size:11px;line-height:1.4;border-radius:4px;">
                        <strong>Action:</strong> {{ $row['required_correction'] }}
                    </td>
                    <td style="padding:8px;text-align:center;">
                        <span style="background:#fee2e2;color:#991b1b;padding:3px 8px;border-radius:4px;font-size:10px;font-weight:700;letter-spacing:0.3px;">
                            HELD
                        </span>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" style="text-align:center;padding:30px;color:#94a3b8;">
                        No held dues lines match your selected category or search filter.
                    </td>
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
