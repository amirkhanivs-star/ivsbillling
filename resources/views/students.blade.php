@extends('layouts.app')
@section('title','Students')
@section('content')
<link rel="stylesheet" href="{{ asset('assets/iqra-student-list.css') }}">
<div class="panel wide iqra-list">
 <form method="get" class="iqra-list-toolbar">
  <a href="{{ route('student.admission.create') }}" class="btn purple">♙ Registration</a>
  <a href="{{ route('student.admission.create') }}" class="btn green">＋ Admission</a>
  <button type="button" class="btn" onclick="document.getElementById('import-dialog').showModal()" style="min-width:115px;background:#7367f0;color:#fff;display:inline-flex;align-items:center;justify-content:center;gap:6px;font-weight:600;cursor:pointer;">⇪ Import</button>
  <input class="input iqra-search" name="q" value="{{ request('q') }}" placeholder="Search by name, father, GR or family number">
  <select class="input" name="status"><option value="">All statuses</option>@foreach(['present'=>'Present','review_hold'=>'Review Hold (72)','historical_hold'=>'Historical Review (18)','suspended'=>'Suspended','left'=>'Left'] as $key=>$label)<option value="{{ $key }}" @selected(request('status')===$key)>{{ $label }}</option>@endforeach</select>
  <button class="btn">Filter</button>
 </form>
 <div class="table-wrap"><table class="table iqra-student-table"><thead><tr><th>S.NO</th><th>NAME</th><th>FATHER NAME</th><th>CONTACT</th><th>CLASS</th><th>FEES</th><th>STATUS</th><th>ACTIONS</th></tr></thead><tbody>
 @forelse($students as $student)
 <tr><td>{{ $loop->iteration+($students->currentPage()-1)*$students->perPage() }}</td>
 <td><div class="iqra-name"><span class="iqra-avatar">♙</span><div><a class="iqra-highlight" href="/manage-student/{{ $student->id }}">{{ $student->name }}</a><div class="iqra-sub">GR: {{ $student->gr_number }} @if($student->family_code) · Family: {{ $student->family_code }} @endif</div>@if($student->date_of_admission)<div class="iqra-sub">{{ \Carbon\Carbon::parse($student->date_of_admission)->format('d M Y') }}</div>@endif</div></div></td>
 <td><span class="iqra-highlight">{{ $student->father_name ?: $student->guardian_name ?: '—' }}</span></td>
 <td>{{ $student->whatsapp ?: '—' }}</td><td><span class="iqra-highlight">{{ $student->current_class ?: '—' }}</span></td>
 <td>@if($student->monthly_fee_minor!==null){{ $student->fee_currency }} {{ \App\Services\Money::format((int)$student->monthly_fee_minor) }}@else — @endif</td>
 <td><span class="iqra-status {{ $student->status }}">{{ ucfirst($student->status) }}</span></td>
 <td><div class="iqra-actions"><a class="iqra-icon purple" href="/manage-student/{{ $student->id }}" title="View student fees and history" aria-label="View {{ $student->name }}">◉</a>
 <a class="iqra-icon purple" href="{{ route('student.admission.edit',$student->id) }}" title="Edit registration" aria-label="Edit {{ $student->name }}">✎</a>
 @if(in_array(auth()->user()->role,['super_admin','finance_admin','department_manager'],true))
 <form method="post" action="{{ route('student.status',$student->id) }}" onsubmit="return confirm('Mark this student as {{ $student->status === 'suspended' ? 'present' : 'suspended' }}?');">@csrf<input type="hidden" name="status" value="{{ $student->status==='suspended'?'present':'suspended' }}"><button class="iqra-icon red" title="{{ $student->status==='suspended'?'Restore':'Suspend' }}" aria-label="Suspend or restore">{{ $student->status==='suspended'?'↺':'×' }}</button></form>
 <form method="post" action="{{ route('student.status',$student->id) }}" onsubmit="return confirm('Change this student to {{ $student->status==='left'?'present':'left' }}?');">@csrf<input type="hidden" name="status" value="{{ $student->status==='left'?'present':'left' }}"><button class="iqra-icon red" title="{{ $student->status==='left'?'Restore':'Mark Left' }}" aria-label="Mark left or restore">ⓘ</button></form>
 @if(auth()->user()->role==='super_admin')<form method="post" action="{{ route('student.delete',$student->id) }}" onsubmit="return confirm('Delete this student? This is only allowed when no family ledger exists.');">@csrf @method('DELETE')<button class="iqra-icon red" title="Delete unbilled student" aria-label="Delete">♜</button></form>@endif
 @endif</div></td></tr>
 @empty<tr><td colspan="8" class="center">No students found. Add a student using Registration or Admission.</td></tr>@endforelse
 </tbody></table></div>
 <div style="margin-top:16px;">{{ $students->links() }}</div>
</div>

<dialog id="import-dialog" style="border:none;border-radius:8px;box-shadow:0 12px 40px rgba(0,0,0,0.3);padding:0;max-width:560px;width:95%;margin:auto;font-family:inherit;">
 <div style="background:#7367f0;color:#fff;padding:16px 20px;display:flex;align-items:center;justify-content:space-between;border-top-left-radius:8px;border-top-right-radius:8px;">
  <strong style="font-size:16px;display:flex;align-items:center;gap:8px;">⇪ Import Students (CSV)</strong>
  <button type="button" onclick="document.getElementById('import-dialog').close()" style="background:none;border:none;color:#fff;font-size:24px;line-height:1;cursor:pointer;">&times;</button>
 </div>
 <div style="padding:22px;display:flex;flex-direction:column;gap:18px;font-size:13px;color:#5e5873;">
  <div style="padding:8px 12px;background:#fff3cd;color:#856404;border-radius:4px;font-size:12px;margin-bottom:10px;font-weight:600;">🔒 Direct student bulk import is disabled for Phase 1 Recovery Protection. Migration will proceed via verified staging tables in Phase 2.</div>

  <div style="background:#f8f7fa;border:1px solid #ebe9f1;border-radius:6px;padding:14px;">
   <div style="font-weight:600;margin-bottom:6px;color:#7367f0;">Option 1: Quick-Import Preloaded School Data</div>
   <p style="margin:0 0 10px;font-size:12px;color:#6e6b7b;">Directly import/sync the 4,330 students, sibling family linkages, enrollments, and monthly fee plans from the uploaded export file.</p>
   <form method="post" action="{{ route('student.import.preloaded') }}">
    @csrf
    <button type="submit" disabled style="width:100%;font-weight:600;padding:10px;display:flex;align-items:center;justify-content:center;gap:6px;background:#a3e0be;color:#fff;border:none;border-radius:4px;cursor:not-allowed;">⚡ Run Preloaded Import (Locked)</button>
   </form>
  </div>

  <div style="background:#f8f7fa;border:1px solid #ebe9f1;border-radius:6px;padding:14px;">
   <div style="font-weight:600;margin-bottom:6px;color:#7367f0;">Option 2: Upload Custom CSV File</div>
   <p style="margin:0 0 10px;font-size:12px;color:#6e6b7b;">Upload any custom student CSV export. Supported columns include <code>GR No</code>, <code>Family Code</code>, <code>Student Name</code>, <code>Gender</code>, <code>Class</code>, <code>Monthly Fee</code>, <code>Status</code>, <code>Guardian Name</code>, <code>Contact</code>, etc.</p>
   <form method="post" action="{{ route('student.import') }}" enctype="multipart/form-data">
    @csrf
    <div style="margin-bottom:12px;">
     <input type="file" name="csv_file" accept=".csv,text/csv,text/plain" disabled style="width:100%;padding:8px;border:1px solid #d8d6de;border-radius:4px;background:#eee;box-sizing:border-box;cursor:not-allowed;">
    </div>
    <button type="submit" disabled style="width:100%;font-weight:600;padding:10px;background:#c8c4f0;color:#fff;border:none;border-radius:4px;cursor:not-allowed;">Upload & Import CSV (Locked)</button>
   </form>
  </div>
 </div>
</dialog>
@endsection
