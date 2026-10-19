@extends('layouts.app')
@section('title', 'Department Classification Review')
@section('content')
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
    <div>
        <h2 style="font-size:22px;font-weight:700;color:#2c2c2c;margin:0;">Department Classification Review</h2>
        <p style="color:#6e6b7b;font-size:13px;margin:4px 0 0;">Review School, Quran, and Tuition classification proposals, analyze class evidence, and govern approvals.</p>
    </div>
    <a href="/migration" style="color:var(--ms-purple,#7367f0);font-size:13px;text-decoration:none;font-weight:600;">← Back to Migration Dashboard</a>
</div>

<!-- KPI Summary Cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:15px;margin-bottom:20px;">
    <div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:15px;">
        <span style="font-size:11px;font-weight:700;text-transform:uppercase;color:#b9b9c3;">Total Staged</span>
        <div style="font-size:24px;font-weight:700;color:#2c2c2c;margin-top:4px;">{{ number_format($metrics['total'] ?? 4340) }}</div>
        <div style="font-size:12px;color:#7367f0;margin-top:2px;">Across 43 classes</div>
    </div>
    <div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:15px;">
        <span style="font-size:11px;font-weight:700;text-transform:uppercase;color:#b9b9c3;">School (Regular)</span>
        <div style="font-size:24px;font-weight:700;color:#2c2c2c;margin-top:4px;">{{ number_format($metrics['school'] ?? 3969) }}</div>
        <div style="font-size:12px;color:#28c76f;margin-top:2px;">41 Regular Classes</div>
    </div>
    <div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:15px;">
        <span style="font-size:11px;font-weight:700;text-transform:uppercase;color:#b9b9c3;">Tuition Program</span>
        <div style="font-size:24px;font-weight:700;color:#2c2c2c;margin-top:4px;">{{ number_format($metrics['tuition'] ?? 371) }}</div>
        <div style="font-size:12px;color:#ff9f43;margin-top:2px;">2 Tuition Classes</div>
    </div>
    <div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:15px;">
        <span style="font-size:11px;font-weight:700;text-transform:uppercase;color:#b9b9c3;">Quran Program</span>
        <div style="font-size:24px;font-weight:700;color:#2c2c2c;margin-top:4px;">{{ number_format($metrics['quran'] ?? 0) }}</div>
        <div style="font-size:12px;color:#00cfe8;margin-top:2px;">Subject-level coaching</div>
    </div>
    <div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:15px;">
        <span style="font-size:11px;font-weight:700;text-transform:uppercase;color:#b9b9c3;">Approval Status</span>
        <div style="font-size:24px;font-weight:700;color:{{ ($metrics['approved'] ?? 0) > 0 ? '#28c76f' : '#ff9f43' }};margin-top:4px;">
            {{ number_format($metrics['approved'] ?? 0) }} Approved
        </div>
        <div style="font-size:12px;color:#ff9f43;margin-top:2px;">{{ number_format($metrics['proposed'] ?? 4340) }} Pending</div>
    </div>
</div>

<!-- Class-to-Department Mapping Summary (Grouped by Class) -->
<div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:20px;margin-bottom:20px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
        <div>
            <h3 style="font-size:16px;font-weight:700;color:#2c2c2c;margin:0;">Class-to-Department Proposal Summary</h3>
            <p style="color:#6e6b7b;font-size:12px;margin:2px 0 0;">Complete breakdown of all 43 source classes, student counts, and classification evidence.</p>
        </div>
        @if(!empty($currentClass))
            <a href="/migration/department" style="font-size:12px;color:#ea5455;text-decoration:none;font-weight:600;">✕ Clear Class Filter (Showing: {{ $currentClass }})</a>
        @endif
    </div>

    <div style="max-height:320px;overflow-y:auto;border:1px solid #ebe9f1;border-radius:4px;">
        <table style="width:100%;border-collapse:collapse;font-size:12px;">
            <thead style="position:sticky;top:0;background:#f8f8f8;border-bottom:2px solid #ebe9f1;">
                <tr style="text-align:left;color:#6e6b7b;">
                    <th style="padding:8px 10px;">Source Class Name</th>
                    <th style="padding:8px 10px;">Proposed Department</th>
                    <th style="padding:8px 10px;text-align:center;">Students</th>
                    <th style="padding:8px 10px;">Classification Evidence</th>
                    <th style="padding:8px 10px;text-align:center;">Status</th>
                    <th style="padding:8px 10px;text-align:right;">Action</th>
                </tr>
            </thead>
            <tbody>
                @foreach($classSummary as $cs)
                @php
                    $isTuition = str_contains(strtolower($cs->class_name), 'tuition');
                    $isQuran = str_contains(strtolower($cs->class_name), 'quran');
                    $deptBadge = $isTuition ? '#ff9f43' : ($isQuran ? '#00cfe8' : '#7367f0');
                    $evidenceText = $isTuition ? 'Dedicated Tuition / Coaching Section' : 'Regular Grade Curriculum (School)';
                @endphp
                <tr style="border-bottom:1px solid #ebe9f1;background:{{ $currentClass === $cs->class_name ? '#f0f0ff' : 'transparent' }};">
                    <td style="padding:8px 10px;font-weight:600;">
                        {{ $cs->class_name ?: '[BLANK CLASS]' }}
                    </td>
                    <td style="padding:8px 10px;">
                        <span style="background:{{ $deptBadge }}20;color:{{ $deptBadge }};padding:2px 8px;border-radius:4px;font-weight:700;font-size:11px;">
                            {{ strtoupper($cs->department_code) }}
                        </span>
                    </td>
                    <td style="padding:8px 10px;text-align:center;font-weight:700;">
                        {{ $cs->total }}
                    </td>
                    <td style="padding:8px 10px;color:#6e6b7b;font-size:11px;">
                        {{ $evidenceText }}
                    </td>
                    <td style="padding:8px 10px;text-align:center;">
                        <span style="background:{{ $cs->status === 'APPROVED' ? '#e8fadf' : '#fff0e1' }};color:{{ $cs->status === 'APPROVED' ? '#28c76f' : '#ff9f43' }};padding:2px 6px;border-radius:4px;font-size:10px;font-weight:600;">
                            {{ $cs->status }}
                        </span>
                    </td>
                    <td style="padding:8px 10px;text-align:right;">
                        <a href="/migration/department?class_name={{ urlencode($cs->class_name) }}" style="color:#7367f0;text-decoration:none;font-weight:600;font-size:11px;margin-right:8px;">
                            View Students →
                        </a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<!-- Bulk Department Approval & Reassignment Workbench -->
<div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:20px;margin-bottom:20px;">
    <h3 style="font-size:16px;font-weight:700;color:#2c2c2c;margin:0 0 15px;">Controlled Department Approval Workbench</h3>
    <form action="/migration/department/approve" method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;align-items:end;">
        @csrf
        <div>
            <label style="display:block;font-size:12px;font-weight:600;color:#5e5873;margin-bottom:4px;">Filter by Class (Optional)</label>
            <select name="class_name" style="width:100%;border:1px solid #d8d6de;border-radius:4px;padding:8px;font-size:13px;">
                <option value="">-- All 43 Classes --</option>
                @foreach($classSummary as $cs)
                    <option value="{{ $cs->class_name }}" {{ $currentClass === $cs->class_name ? 'selected' : '' }}>
                        {{ $cs->class_name }} [{{ strtoupper($cs->department_code) }}] ({{ $cs->total }} students)
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <label style="display:block;font-size:12px;font-weight:600;color:#5e5873;margin-bottom:4px;">Filter by Proposed Dept</label>
            <select name="department_code" style="width:100%;border:1px solid #d8d6de;border-radius:4px;padding:8px;font-size:13px;">
                <option value="">-- Any Proposed Dept --</option>
                <option value="school" {{ $currentDept==='school'?'selected':'' }}>School (3,969)</option>
                <option value="tuition" {{ $currentDept==='tuition'?'selected':'' }}>Tuition (371)</option>
                <option value="quran" {{ $currentDept==='quran'?'selected':'' }}>Quran (0)</option>
            </select>
        </div>
        <div>
            <label style="display:block;font-size:12px;font-weight:600;color:#5e5873;margin-bottom:4px;">Approved Target Department</label>
            <select name="target_department" required style="width:100%;border:1px solid #d8d6de;border-radius:4px;padding:8px;font-size:13px;">
                @foreach($departments as $dept)
                    <option value="{{ $dept->code }}">{{ $dept->name }} ({{ $dept->code }})</option>
                @endforeach
            </select>
        </div>
        <div>
            <label style="display:block;font-size:12px;font-weight:600;color:#5e5873;margin-bottom:4px;">Approval Notes</label>
            <input type="text" name="notes" placeholder="e.g. Validated regular curriculum enrollment" style="width:100%;border:1px solid #d8d6de;border-radius:4px;padding:8px;font-size:13px;">
        </div>
        <div style="grid-column:1 / -1;display:flex;justify-content:space-between;align-items:center;padding-top:10px;border-top:1px solid #ebe9f1;">
            <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#2c2c2c;font-weight:600;cursor:pointer;">
                <input type="checkbox" name="confirm_approval" value="1" required> Confirm explicit administrative department approval
            </label>
            <button type="submit" style="background:var(--ms-purple,#7367f0);color:#fff;border:none;border-radius:4px;padding:9px 20px;font-size:13px;font-weight:600;cursor:pointer;">
                Execute Department Approval
            </button>
        </div>
    </form>
</div>

<!-- Detailed Student Assignments List -->
<div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:20px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;flex-wrap:wrap;gap:10px;">
        <div>
            <h3 style="font-size:16px;font-weight:700;color:#2c2c2c;margin:0;">Student Department Assignments & Evidence</h3>
            <span style="font-size:12px;color:#6e6b7b;">
                Showing individual student mappings
                @if(!empty($currentClass)) for class <strong>{{ $currentClass }}</strong> @endif
                @if(!empty($currentDept)) in department <strong>{{ strtoupper($currentDept) }}</strong> @endif
            </span>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
            <a href="/migration/department" style="padding:5px 12px;border-radius:4px;font-size:12px;text-decoration:none;font-weight:600;background:{{ empty($currentDept)?'#333':'#f8f8f8' }};color:{{ empty($currentDept)?'#fff':'#6e6b7b' }};">All</a>
            <a href="/migration/department?department_code=school" style="padding:5px 12px;border-radius:4px;font-size:12px;text-decoration:none;font-weight:600;background:{{ $currentDept==='school'?'#7367f0':'#f8f8f8' }};color:{{ $currentDept==='school'?'#fff':'#6e6b7b' }};">School</a>
            <a href="/migration/department?department_code=tuition" style="padding:5px 12px;border-radius:4px;font-size:12px;text-decoration:none;font-weight:600;background:{{ $currentDept==='tuition'?'#ff9f43':'#f8f8f8' }};color:{{ $currentDept==='tuition'?'#fff':'#6e6b7b' }};">Tuition</a>
            <a href="/migration/department?department_code=quran" style="padding:5px 12px;border-radius:4px;font-size:12px;text-decoration:none;font-weight:600;background:{{ $currentDept==='quran'?'#00cfe8':'#f8f8f8' }};color:{{ $currentDept==='quran'?'#fff':'#6e6b7b' }};">Quran</a>
        </div>
    </div>

    <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead>
            <tr style="border-bottom:2px solid #ebe9f1;text-align:left;color:#6e6b7b;">
                <th style="padding:10px 8px;">Source Key</th>
                <th style="padding:10px 8px;">GR Number</th>
                <th style="padding:10px 8px;">Student Name</th>
                <th style="padding:10px 8px;">Class Name</th>
                <th style="padding:10px 8px;">Department</th>
                <th style="padding:10px 8px;">Evidence Source</th>
                <th style="padding:10px 8px;text-align:center;">Status</th>
                <th style="padding:10px 8px;">Action / Reassign</th>
            </tr>
        </thead>
        <tbody>
            @forelse($records as $rec)
            @php
                $deptColor = $rec->department_code === 'tuition' ? '#ff9f43' : ($rec->department_code === 'quran' ? '#00cfe8' : '#7367f0');
            @endphp
            <tr style="border-bottom:1px solid #ebe9f1;">
                <td style="padding:10px 8px;font-weight:600;font-size:12px;color:#5e5873;">{{ $rec->source_key }}</td>
                <td style="padding:10px 8px;font-weight:700;">{{ $rec->gr_number }}</td>
                <td style="padding:10px 8px;">
                    <div style="font-weight:600;color:#2c2c2c;">{{ $rec->student_name ?: '—' }}</div>
                    @if(!empty($rec->father_name))
                        <div style="font-size:11px;color:#b9b9c3;">F: {{ $rec->father_name }}</div>
                    @endif
                </td>
                <td style="padding:10px 8px;">{{ $rec->class_name ?: '—' }}</td>
                <td style="padding:10px 8px;">
                    <span style="background:{{ $deptColor }}20;color:{{ $deptColor }};padding:2px 8px;border-radius:4px;font-weight:700;font-size:11px;">
                        {{ strtoupper($rec->department_code) }}
                    </span>
                    @if(!$rec->is_primary)
                        <span style="background:#f8d7da;color:#721c24;padding:1px 4px;border-radius:3px;font-size:9px;font-weight:700;margin-left:4px;">2nd</span>
                    @endif
                </td>
                <td style="padding:10px 8px;font-size:11px;color:#6e6b7b;">{{ $rec->evidence_source }}</td>
                <td style="padding:10px 8px;text-align:center;">
                    <span style="background:{{ $rec->status === 'APPROVED' ? '#e8fadf' : '#fff0e1' }};color:{{ $rec->status === 'APPROVED' ? '#28c76f' : '#ff9f43' }};padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">
                        {{ $rec->status }}
                    </span>
                </td>
                <td style="padding:10px 8px;">
                    <form action="/migration/department/reassign" method="post" style="display:flex;gap:4px;align-items:center;">
                        @csrf
                        <input type="hidden" name="gr_number" value="{{ $rec->gr_number }}">
                        <select name="department_code" style="border:1px solid #d8d6de;border-radius:3px;padding:4px;font-size:11px;">
                            <option value="school" {{ $rec->department_code==='school'?'selected':'' }}>School</option>
                            <option value="tuition" {{ $rec->department_code==='tuition'?'selected':'' }}>Tuition</option>
                            <option value="quran" {{ $rec->department_code==='quran'?'selected':'' }}>Quran</option>
                        </select>
                        <select name="is_additional" style="border:1px solid #d8d6de;border-radius:3px;padding:4px;font-size:11px;">
                            <option value="0">Switch</option>
                            <option value="1">+ Add 2nd</option>
                        </select>
                        <button type="submit" style="background:#5e5873;color:#fff;border:none;border-radius:3px;padding:4px 8px;font-size:11px;cursor:pointer;">
                            Set
                        </button>
                    </form>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="8" style="padding:20px;text-align:center;color:#b9b9c3;">No department records found matching criteria.</td>
            </tr>
            @endforelse
        </tbody>
    </table>

    <div style="margin-top:15px;">
        {{ $records->links() }}
    </div>
</div>
@endsection
