@extends('layouts.app')
@section('title','Students')
@section('content')
<link rel="stylesheet" href="{{ asset('assets/iqra-student-list.css') }}?v={{ filemtime(public_path('assets/iqra-student-list.css')) }}">
<div class="panel wide iqra-list">
 <form method="get" class="iqra-list-toolbar">
  <a href="{{ route('student.admission.create') }}" class="btn purple">♙ Registration</a>
  <a href="{{ route('student.admission.create') }}" class="btn green">＋ Admission</a>
  <button type="button" class="btn" onclick="document.getElementById('import-dialog').showModal()" style="min-width:115px;background:#7367f0;color:#fff;display:inline-flex;align-items:center;justify-content:center;gap:6px;font-weight:600;cursor:pointer;">⇪ Import</button>
  <input class="input iqra-search" name="q" value="{{ request('q') }}" placeholder="Search by name, father, GR or family number">
  <select class="input" name="class"><option value="">All classes</option>@foreach(($classOptions ?? []) as $cls)<option value="{{ $cls }}" @selected(request('class', request('grade'))===$cls)>{{ $cls }}</option>@endforeach</select>
  <select class="input" name="status"><option value="">All statuses</option>@foreach(['present'=>'Present','review_hold'=>'Review Hold (72)','historical_hold'=>'Historical Review (18)','suspended'=>'Suspended','left'=>'Left'] as $key=>$label)<option value="{{ $key }}" @selected(request('status')===$key)>{{ $label }}</option>@endforeach</select>
  <button class="btn">Filter</button>
 </form>
 <div class="table-wrap"><table class="table iqra-student-table"><thead><tr><th>S.NO</th><th>NAME</th><th>FATHER NAME</th><th>CONTACT</th><th>CLASS</th><th>FEES</th><th>STATUS</th><th>ACTIONS</th></tr></thead><tbody>
 @forelse($students as $student)
 <tr id="student-row-{{ $student->id }}"><td>{{ $loop->iteration+($students->currentPage()-1)*$students->perPage() }}</td>
 <td><div class="iqra-name"><span class="iqra-avatar">♙</span><div><a class="iqra-highlight" href="/manage-student/{{ $student->id }}">{{ $student->name }}</a><div class="iqra-sub">GR: {{ $student->gr_number }} @if($student->family_code) · Family: {{ $student->family_code }} @endif</div>@if($student->date_of_admission)<div class="iqra-sub">{{ \Carbon\Carbon::parse($student->date_of_admission)->format('d M Y') }}</div>@endif</div></div></td>
 <td><span class="iqra-highlight">{{ \App\Services\GuardianNameResolver::forStudent($student) }}</span></td>
 <td>{{ $student->whatsapp ?: '—' }}</td><td><span class="iqra-highlight">{{ $student->current_class ?: '—' }}</span></td>
 <td>@if($student->monthly_fee_minor!==null){{ $student->fee_currency }} {{ \App\Services\Money::format((int)$student->monthly_fee_minor) }}@else — @endif</td>
 <td class="student-status-cell"><span class="iqra-status {{ $student->status }}">{{ ucfirst($student->status) }}</span></td>
 <td><div class="iqra-actions" id="student-actions-{{ $student->id }}"><a class="iqra-icon purple" href="/manage-student/{{ $student->id }}" title="View student fees and history" aria-label="View {{ $student->name }}">◉</a>
 <a class="iqra-icon purple" href="{{ route('student.admission.edit',$student->id) }}" title="Edit registration" aria-label="Edit {{ $student->name }}">✎</a>
 @if(in_array(auth()->user()->role,['super_admin','finance_admin','department_manager'],true))
 @if($student->status === 'suspended')
 <form method="post" action="{{ route('student.status',$student->id) }}" onsubmit="return confirm('Mark this student as present?');">@csrf<input type="hidden" name="status" value="present"><button class="iqra-icon red" title="Restore" aria-label="Restore">↺</button></form>
 @elseif($student->status === 'present')
 <form method="post" action="{{ route('student.status',$student->id) }}" onsubmit="return confirm('Mark this student as suspended?');">@csrf<input type="hidden" name="status" value="suspended"><button class="iqra-icon red" title="Suspend" aria-label="Suspend">×</button></form>
 @endif
 @if($student->status === 'left')
 <button type="button" class="iqra-icon green btn-readmit-student" data-id="{{ $student->id }}" data-name="{{ $student->name }}" data-gr="{{ $student->gr_number }}" title="Readmit" aria-label="Readmit">✓</button>
 <button type="button" class="iqra-icon gray btn-open-leave-drawer" data-id="{{ $student->id }}" data-name="{{ $student->name }}" data-gr="{{ $student->gr_number }}" data-status="{{ $student->status }}" title="View Leave Details" aria-label="View Leave Details">ⓘ</button>
 @else
 <button type="button" class="iqra-icon red btn-open-leave-drawer" data-id="{{ $student->id }}" data-name="{{ $student->name }}" data-gr="{{ $student->gr_number }}" data-status="{{ $student->status }}" title="Mark Left" aria-label="Mark Left">ⓘ</button>
 @endif
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

{{-- MYSKOOL STUDENT LEAVE DRAWER --}}
<div class="myskool-drawer-overlay" id="myskoolLeaveDrawer" hidden>
    <div class="myskool-drawer">
        <div class="myskool-drawer-header">
            <div class="myskool-drawer-title">
                <span style="font-size: 15px; font-weight: 700; color: #5e5873;">Student Leave</span>
                <div style="font-size: 12px; font-weight: 600; color: #7367f0; margin-top: 3px;" id="leaveDrawerStudentName"></div>
            </div>
            <button type="button" class="myskool-drawer-close" id="leaveDrawerCloseBtn" aria-label="Close">&times;</button>
        </div>

        <form method="post" id="leaveDrawerForm" action="">
            @csrf
            <input type="hidden" name="student_id" id="leave_student_id">

            {{-- 1. Date of Leaving --}}
            <div class="myskool-drawer-field">
                <label for="leave_date">Date of Leaving <span style="color:#ea5455;">*</span></label>
                <input type="date" class="myskool-drawer-input" id="leave_date" name="date_of_leaving" value="{{ date('Y-m-d') }}" required>
            </div>

            {{-- 2. Leaving Category Dropdown --}}
            <div class="myskool-drawer-field">
                <label for="leave_category">Leaving Category <span style="color:#ea5455;">*</span></label>
                <select class="myskool-drawer-input" id="leave_category" name="leaving_category" required>
                    <option value="">-- Select Category --</option>
                    <option value="Transfer / Relocation">Transfer / Relocation</option>
                    <option value="Graduated / Completed Studies">Graduated / Completed Studies</option>
                    <option value="Financial Difficulties">Financial Difficulties</option>
                    <option value="Switched to Another School">Switched to Another School</option>
                    <option value="Personal / Family Reasons">Personal / Family Reasons</option>
                    <option value="Disciplinary Action">Disciplinary Action</option>
                    <option value="Medical / Health Reasons">Medical / Health Reasons</option>
                    <option value="Non-Attendance / Prolonged Absence">Non-Attendance / Prolonged Absence</option>
                    <option value="Other">Other</option>
                </select>
            </div>

            {{-- 3. Remaining Dues (Read-Only from Ledger) --}}
            <div class="myskool-drawer-field">
                <label for="leave_remaining_dues">Remaining Dues <span style="color:#6e6b7b;font-weight:400;">(Read-Only)</span></label>
                <input type="text" class="myskool-drawer-input disabled" id="leave_remaining_dues" readonly value="SAR 0.00" style="background:#f8f7fa; color:#ea5455; font-weight:700; cursor:not-allowed;">
                <small style="font-size: 11px; color: #82868b; display: block; margin-top: 3px;">Retrieved from existing financial ledger.</small>
            </div>

            {{-- 4. Reason for Leaving --}}
            <div class="myskool-drawer-field">
                <label for="leave_reason">Reason for Leaving <span style="color:#ea5455;">*</span></label>
                <textarea class="myskool-drawer-input" id="leave_reason" name="leaving_reason" rows="3" placeholder="Enter reason for student leaving..." required style="resize:vertical; min-height:80px;"></textarea>
            </div>

            <div id="leaveDrawerErrors" style="display:none; background:#fff0f0; color:#ea5455; padding:8px 12px; border-radius:4px; font-size:12px; margin-bottom:12px;"></div>

            {{-- 5. Green Save Button --}}
            <button type="submit" class="btn-ms-save" id="leaveSaveBtn">
                Save
            </button>
            <button type="button" class="btn-ms-cancel" id="leaveCancelBtn" style="background:transparent; border:1px solid #d8d6de; color:#6e6b7b; border-radius:5px; height:36px; width:100%; font-size:13px; font-weight:600; cursor:pointer; margin-top:8px;">
                Cancel
            </button>
        </form>
    </div>
</div>

{{-- READMISSION CONFIRMATION MODAL --}}
<div class="myskool-drawer-overlay" id="readmitConfirmModal" hidden style="align-items:center; justify-content:center;">
    <div style="background:#ffffff; border-radius:8px; box-shadow:0 12px 40px rgba(0,0,0,0.25); max-width:480px; width:92%; overflow:hidden; animation:slideDrawer .2s ease;">
        <div style="padding:16px 20px; border-bottom:1px solid #ebe9f1; display:flex; justify-content:space-between; align-items:center; background:#fafafc;">
            <strong style="font-size:16px; color:#5e5873;">Readmit Student</strong>
            <button type="button" id="readmitModalCloseBtn" style="background:none; border:none; font-size:22px; color:#b9b9c3; cursor:pointer; line-height:1;">&times;</button>
        </div>
        <div style="padding:22px 20px; font-size:14px; color:#5e5873; line-height:1.5;">
            <div id="readmitModalError" style="display:none; margin-bottom:14px; padding:10px 14px; background:#fceaea; border-left:4px solid #ea5455; color:#ea5455; border-radius:4px; font-size:13px;"></div>
            <p style="margin:0 0 10px 0; font-size:14px;">Are you sure you want to readmit <strong id="readmitStudentName" style="color:#5e5873;"></strong> (GR: <span id="readmitStudentGr" style="font-weight:600; color:#7367f0;"></span>)?</p>
            <p style="margin:0; font-size:12.5px; color:#6e6b7b;">This will reactivate the student and allow future fees to be generated.</p>
        </div>
        <div style="padding:14px 20px; border-top:1px solid #ebe9f1; display:flex; justify-content:flex-end; gap:10px; background:#fafafc;">
            <button type="button" id="readmitCancelBtn" style="background:#f8f7fa; border:1px solid #d8d6de; color:#5e5873; padding:8px 18px; border-radius:5px; font-size:13px; font-weight:600; cursor:pointer;">Cancel</button>
            <button type="button" id="readmitConfirmBtn" style="background:#28c76f; border:none; color:#fff; padding:8px 20px; border-radius:5px; font-size:13px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">Confirm Readmission</button>
        </div>
    </div>
</div>

<div id="studentLeaveToast" style="display:none; position:fixed; bottom:24px; right:24px; z-index:2100; background:#28c76f; color:#fff; padding:12px 20px; border-radius:6px; box-shadow:0 4px 18px rgba(0,0,0,0.18); font-size:13px; font-weight:600; max-width:400px; animation:slideDrawer .2s ease;"></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const drawerOverlay = document.getElementById('myskoolLeaveDrawer');
    const drawerForm = document.getElementById('leaveDrawerForm');
    const studentNameEl = document.getElementById('leaveDrawerStudentName');
    const studentIdInput = document.getElementById('leave_student_id');
    const leaveDateInput = document.getElementById('leave_date');
    const leaveCategorySelect = document.getElementById('leave_category');
    const remainingDuesInput = document.getElementById('leave_remaining_dues');
    const leaveReasonTextarea = document.getElementById('leave_reason');
    const saveBtn = document.getElementById('leaveSaveBtn');
    const closeBtn = document.getElementById('leaveDrawerCloseBtn');
    const cancelBtn = document.getElementById('leaveCancelBtn');
    const errorBox = document.getElementById('leaveDrawerErrors');
    const toastBox = document.getElementById('studentLeaveToast');

    // Readmit modal elements
    const readmitModal = document.getElementById('readmitConfirmModal');
    const readmitStudentNameEl = document.getElementById('readmitStudentName');
    const readmitStudentGrEl = document.getElementById('readmitStudentGr');
    const readmitModalError = document.getElementById('readmitModalError');
    const readmitCloseBtn = document.getElementById('readmitModalCloseBtn');
    const readmitCancelBtn = document.getElementById('readmitCancelBtn');
    const readmitConfirmBtn = document.getElementById('readmitConfirmBtn');
    let currentReadmitStudentId = null;

    function showToast(message) {
        if (!toastBox) return;
        toastBox.textContent = message;
        toastBox.style.display = 'block';
        setTimeout(function () {
            toastBox.style.display = 'none';
        }, 5000);
    }

    function openReadmitModal(id, name, gr) {
        currentReadmitStudentId = id;
        readmitStudentNameEl.textContent = name;
        readmitStudentGrEl.textContent = gr;
        readmitModalError.style.display = 'none';
        readmitModalError.textContent = '';
        readmitConfirmBtn.disabled = false;
        readmitConfirmBtn.textContent = 'Confirm Readmission';
        readmitModal.removeAttribute('hidden');
    }

    function closeReadmitModal() {
        readmitModal.setAttribute('hidden', '');
        currentReadmitStudentId = null;
    }

    if (readmitCloseBtn) readmitCloseBtn.addEventListener('click', closeReadmitModal);
    if (readmitCancelBtn) readmitCancelBtn.addEventListener('click', closeReadmitModal);

    readmitModal.addEventListener('click', function (e) {
        if (e.target === readmitModal) closeReadmitModal();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !readmitModal.hidden) closeReadmitModal();
    });

    function bindReadmitButtons() {
        document.querySelectorAll('.btn-readmit-student').forEach(function (btn) {
            if (btn.getAttribute('data-readmit-bound')) return;
            btn.setAttribute('data-readmit-bound', 'true');
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                const sid = this.getAttribute('data-id');
                const name = this.getAttribute('data-name');
                const gr = this.getAttribute('data-gr');
                openReadmitModal(sid, name, gr);
            });
        });
    }

    bindReadmitButtons();

    readmitConfirmBtn.addEventListener('click', function () {
        if (!currentReadmitStudentId) return;
        readmitConfirmBtn.disabled = true;
        readmitConfirmBtn.textContent = 'Confirming...';
        readmitModalError.style.display = 'none';

        const sid = currentReadmitStudentId;
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        fetch('/manage-student/' + sid + '/readmit', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({})
        })
        .then(function (res) {
            return res.json().then(function (data) {
                return { status: res.status, ok: res.ok, body: data };
            });
        })
        .then(function (result) {
            if (result.ok && result.body.success) {
                closeReadmitModal();
                showToast(result.body.message || 'Student readmitted successfully.');

                // Step 7: UI Refresh
                const row = document.getElementById('student-row-' + sid);
                if (row) {
                    const statusCell = row.querySelector('.student-status-cell');
                    if (statusCell) {
                        statusCell.innerHTML = '<span class="iqra-status present">Present</span>';
                    }
                    const actionsBox = document.getElementById('student-actions-' + sid);
                    if (actionsBox) {
                        // Remove Readmit button
                        const rBtn = actionsBox.querySelector('.btn-readmit-student');
                        if (rBtn) rBtn.remove();

                        // Change Leave button from View Leave Details to Mark Left
                        const leaveBtn = actionsBox.querySelector('.btn-open-leave-drawer');
                        if (leaveBtn) {
                            leaveBtn.className = 'iqra-icon red btn-open-leave-drawer';
                            leaveBtn.setAttribute('title', 'Mark Left');
                            leaveBtn.setAttribute('aria-label', 'Mark Left');
                            leaveBtn.setAttribute('data-status', 'present');
                        }

                        // Add Suspend button if not already present
                        const existingSuspend = actionsBox.querySelector('button[title="Suspend"], button[title="Restore"]');
                        if (!existingSuspend) {
                            const suspendForm = document.createElement('form');
                            suspendForm.method = 'post';
                            suspendForm.action = '/manage-student/' + sid + '/status';
                            suspendForm.onsubmit = function () { return confirm('Mark this student as suspended?'); };
                            suspendForm.innerHTML = '<input type="hidden" name="_token" value="' + csrfToken + '"><input type="hidden" name="status" value="suspended"><button class="iqra-icon red" title="Suspend" aria-label="Suspend">×</button>';
                            if (leaveBtn) {
                                actionsBox.insertBefore(suspendForm, leaveBtn);
                            } else {
                                actionsBox.appendChild(suspendForm);
                            }
                        }
                    }
                }
            } else {
                readmitConfirmBtn.disabled = false;
                readmitConfirmBtn.textContent = 'Confirm Readmission';
                readmitModalError.textContent = result.body.message || 'Readmission failed. Please try again.';
                readmitModalError.style.display = 'block';
            }
        })
        .catch(function (err) {
            readmitConfirmBtn.disabled = false;
            readmitConfirmBtn.textContent = 'Confirm Readmission';
            readmitModalError.textContent = 'Network or server error occurred. Please try again.';
            readmitModalError.style.display = 'block';
        });
    });

    function openLeaveDrawer(studentId, studentName, studentGr, currentStatus) {
        studentIdInput.value = studentId;
        studentNameEl.textContent = studentName + ' (GR: ' + studentGr + ')';
        drawerForm.action = '/manage-student/' + studentId + '/leave';

        leaveDateInput.value = new Date().toISOString().split('T')[0];
        leaveCategorySelect.value = '';
        leaveReasonTextarea.value = '';
        remainingDuesInput.value = 'Calculating ledger dues...';
        errorBox.style.display = 'none';
        errorBox.textContent = '';
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save';

        drawerOverlay.hidden = false;

        fetch('/manage-student/' + studentId + '/leave-info', {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.success) {
                remainingDuesInput.value = data.remaining_dues || 'SAR 0.00';
                if (data.date_of_leaving) leaveDateInput.value = data.date_of_leaving;
                if (data.leaving_category) leaveCategorySelect.value = data.leaving_category;
                if (data.leaving_reason) leaveReasonTextarea.value = data.leaving_reason;
            } else {
                remainingDuesInput.value = 'SAR 0.00';
            }
        })
        .catch(function () {
            remainingDuesInput.value = 'SAR 0.00';
        });
    }

    function closeLeaveDrawer() {
        drawerOverlay.hidden = true;
    }

    function bindLeaveButtons() {
        document.querySelectorAll('.btn-open-leave-drawer').forEach(function (btn) {
            if (btn.getAttribute('data-bound')) return;
            btn.setAttribute('data-bound', 'true');
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                const sid = this.getAttribute('data-id');
                const name = this.getAttribute('data-name');
                const gr = this.getAttribute('data-gr');
                const status = this.getAttribute('data-status');
                openLeaveDrawer(sid, name, gr, status);
            });
        });
    }

    bindLeaveButtons();

    if (closeBtn) closeBtn.addEventListener('click', closeLeaveDrawer);
    if (cancelBtn) cancelBtn.addEventListener('click', closeLeaveDrawer);

    drawerOverlay.addEventListener('click', function (e) {
        if (e.target === drawerOverlay) closeLeaveDrawer();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !drawerOverlay.hidden) closeLeaveDrawer();
    });

    drawerForm.addEventListener('submit', function (e) {
        e.preventDefault();
        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving...';
        errorBox.style.display = 'none';
        errorBox.textContent = '';

        const formData = new FormData(drawerForm);
        const sid = studentIdInput.value;

        fetch(drawerForm.action, {
            method: 'POST',
            body: formData,
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function (res) {
            return res.json().then(function (data) {
                return { status: res.status, ok: res.ok, body: data };
            });
        })
        .then(function (result) {
            if (result.ok && result.body.success) {
                closeLeaveDrawer();
                showToast(result.body.message || 'Student marked as Left successfully.');

                // Refresh the relevant student row in-place
                const row = document.getElementById('student-row-' + sid);
                if (row) {
                    const statusCell = row.querySelector('.student-status-cell');
                    if (statusCell) {
                        statusCell.innerHTML = '<span class="iqra-status left">Left</span>';
                    }
                    const actionsBox = document.getElementById('student-actions-' + sid);
                    if (actionsBox) {
                        const suspendBtn = actionsBox.querySelector('button[title="Suspend"]');
                        if (suspendBtn && suspendBtn.closest('form')) {
                            suspendBtn.closest('form').remove();
                        }
                        const existingLeaveBtn = actionsBox.querySelector('.btn-open-leave-drawer');
                        if (existingLeaveBtn) {
                            const sName = result.body.student.name || '';
                            const sGr = result.body.student.gr_number || '';

                            const readmitBtn = document.createElement('button');
                            readmitBtn.type = 'button';
                            readmitBtn.className = 'iqra-icon green btn-readmit-student';
                            readmitBtn.setAttribute('data-id', sid);
                            readmitBtn.setAttribute('data-name', sName);
                            readmitBtn.setAttribute('data-gr', sGr);
                            readmitBtn.setAttribute('title', 'Readmit');
                            readmitBtn.setAttribute('aria-label', 'Readmit');
                            readmitBtn.textContent = '✓';

                            const detailsBtn = document.createElement('button');
                            detailsBtn.type = 'button';
                            detailsBtn.className = 'iqra-icon gray btn-open-leave-drawer';
                            detailsBtn.setAttribute('data-id', sid);
                            detailsBtn.setAttribute('data-name', sName);
                            detailsBtn.setAttribute('data-gr', sGr);
                            detailsBtn.setAttribute('data-status', 'left');
                            detailsBtn.setAttribute('title', 'View Leave Details');
                            detailsBtn.setAttribute('aria-label', 'View Leave Details');
                            detailsBtn.textContent = 'ⓘ';

                            existingLeaveBtn.replaceWith(readmitBtn, detailsBtn);
                            bindLeaveButtons();
                            bindReadmitButtons();
                        }
                    }
                }
            } else {
                saveBtn.disabled = false;
                saveBtn.textContent = 'Save';
                let errText = '';
                if (result.body.errors) {
                    const msgs = [];
                    for (const k in result.body.errors) {
                        msgs.push(result.body.errors[k].join(' '));
                    }
                    errText = msgs.join('\n');
                } else if (result.body.message) {
                    errText = result.body.message;
                } else {
                    errText = 'An unexpected error occurred. Please try again.';
                }
                errorBox.textContent = errText;
                errorBox.style.whiteSpace = 'pre-line';
                errorBox.style.display = 'block';
            }
        })
        .catch(function (err) {
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save';
            errorBox.textContent = 'Network or server error occurred. Please try again.';
            errorBox.style.display = 'block';
        });
    });
});
</script>
@endsection
