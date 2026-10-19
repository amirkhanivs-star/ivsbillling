@extends('layouts.app')
@section('title', 'Campus Assignment Review')
@section('content')
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
    <div>
        <h2 style="font-size:22px;font-weight:700;color:#2c2c2c;margin:0;">Administrative Campus Mapping Review</h2>
        <p style="color:#6e6b7b;font-size:13px;margin:4px 0 0;">Review class-level campus proposals, preview affected student counts, and execute bulk approvals.</p>
    </div>
    <a href="/migration" style="color:var(--ms-purple,#7367f0);font-size:13px;text-decoration:none;font-weight:600;">← Back to Migration Dashboard</a>
</div>

<!-- Bulk Approval Card -->
<div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:20px;margin-bottom:20px;">
    <h3 style="font-size:16px;font-weight:700;color:#2c2c2c;margin:0 0 15px;">Bulk Campus Approval & Confirmation</h3>
    <form action="/migration/campus/approve" method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;align-items:end;">
        @csrf
        <div>
            <label style="display:block;font-size:12px;font-weight:600;color:#5e5873;margin-bottom:4px;">Filter by Class (Optional)</label>
            <select name="class_name" style="width:100%;border:1px solid #d8d6de;border-radius:4px;padding:8px;font-size:13px;">
                <option value="">-- All Pending Classes --</option>
                @foreach($classSummary as $cs)
                    @if($cs->status === 'PENDING')
                        <option value="{{ $cs->class_name }}" {{ $currentClass === $cs->class_name ? 'selected' : '' }}>{{ $cs->class_name }} ({{ $cs->total }} students)</option>
                    @endif
                @endforeach
            </select>
        </div>
        <div>
            <label style="display:block;font-size:12px;font-weight:600;color:#5e5873;margin-bottom:4px;">Target Campus</label>
            <select name="target_campus" required style="width:100%;border:1px solid #d8d6de;border-radius:4px;padding:8px;font-size:13px;">
                @foreach($campuses as $camp)
                    <option value="{{ $camp->code }}">{{ $camp->name }} ({{ $camp->code }})</option>
                @endforeach
            </select>
        </div>
        <div>
            <label style="display:block;font-size:12px;font-weight:600;color:#5e5873;margin-bottom:4px;">Approval Notes / Reason</label>
            <input type="text" name="notes" placeholder="e.g. Approved Main Campus for Academic Year 2026-2027" style="width:100%;border:1px solid #d8d6de;border-radius:4px;padding:8px;font-size:13px;">
        </div>
        <div>
            <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#2c2c2c;font-weight:600;cursor:pointer;margin-bottom:10px;">
                <input type="checkbox" name="confirm_approval" value="1" required> Confirm explicit bulk approval
            </label>
            <button type="submit" style="width:100%;background:var(--ms-purple,#7367f0);color:#fff;border:none;border-radius:4px;padding:9px;font-size:13px;font-weight:600;cursor:pointer;">
                Approve Campus Mapping
            </button>
        </div>
    </form>
</div>

<!-- Records List -->
<div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:20px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
        <h3 style="font-size:16px;font-weight:700;color:#2c2c2c;margin:0;">Staged Campus Assignments</h3>
        <div style="display:flex;gap:10px;">
            <a href="/migration/campus?status=PENDING" style="padding:4px 10px;border-radius:4px;font-size:12px;text-decoration:none;font-weight:600;background:{{ $currentStatus==='PENDING'?'#7367f0':'#f8f8f8' }};color:{{ $currentStatus==='PENDING'?'#fff':'#6e6b7b' }};">Pending</a>
            <a href="/migration/campus?status=APPROVED" style="padding:4px 10px;border-radius:4px;font-size:12px;text-decoration:none;font-weight:600;background:{{ $currentStatus==='APPROVED'?'#28c76f':'#f8f8f8' }};color:{{ $currentStatus==='APPROVED'?'#fff':'#6e6b7b' }};">Approved</a>
            <a href="/migration/campus" style="padding:4px 10px;border-radius:4px;font-size:12px;text-decoration:none;font-weight:600;background:{{ empty($currentStatus)?'#333':'#f8f8f8' }};color:{{ empty($currentStatus)?'#fff':'#6e6b7b' }};">All</a>
        </div>
    </div>

    <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead>
            <tr style="border-bottom:2px solid #ebe9f1;text-align:left;color:#6e6b7b;">
                <th style="padding:10px 8px;">Source Key</th>
                <th style="padding:10px 8px;">GR Number</th>
                <th style="padding:10px 8px;">Class Name</th>
                <th style="padding:10px 8px;">Proposed Campus</th>
                <th style="padding:10px 8px;">Approved Campus</th>
                <th style="padding:10px 8px;">Status</th>
                <th style="padding:10px 8px;">Approved By</th>
                <th style="padding:10px 8px;">Approved At</th>
            </tr>
        </thead>
        <tbody>
            @forelse($records as $rec)
            <tr style="border-bottom:1px solid #ebe9f1;">
                <td style="padding:10px 8px;font-weight:600;">{{ $rec->source_key }}</td>
                <td style="padding:10px 8px;">{{ $rec->gr_number }}</td>
                <td style="padding:10px 8px;">{{ $rec->class_name ?: '—' }}</td>
                <td style="padding:10px 8px;">{{ $rec->proposed_campus }}</td>
                <td style="padding:10px 8px;"><strong>{{ $rec->approved_campus ?: '—' }}</strong></td>
                <td style="padding:10px 8px;">
                    <span style="background:{{ $rec->status === 'APPROVED' ? '#e8fadf' : '#fff0e1' }};color:{{ $rec->status === 'APPROVED' ? '#28c76f' : '#ff9f43' }};padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">
                        {{ $rec->status }}
                    </span>
                </td>
                <td style="padding:10px 8px;">{{ $rec->approved_by ?: '—' }}</td>
                <td style="padding:10px 8px;color:#b9b9c3;font-size:12px;">{{ $rec->approved_at ?: '—' }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="8" style="padding:20px;text-align:center;color:#b9b9c3;">No records found matching filter.</td>
            </tr>
            @endforelse
        </tbody>
    </table>

    <div style="margin-top:15px;">
        {{ $records->links() }}
    </div>
</div>
@endsection
