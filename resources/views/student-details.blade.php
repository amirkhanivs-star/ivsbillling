@extends('layouts.app')
@section('title','Student Profile')
@section('content')
<link rel="stylesheet" href="{{ asset('assets/iqra-student-profile.css') }}">
@php
  $mainEnrollment = $enrollments->first();
  $status = strtolower((string) ($student->status ?? 'present'));
  $totals = $feeItems->groupBy('currency');
  $admission = \Illuminate\Support\Facades\DB::table('student_registration_details')->where('student_id',$student->id)->first();
  $unpaidTotal = $feeItems->sum(fn ($item) => max(0, (int) $item->net_minor - (int) $item->paid_minor));
@endphp
<div class="iq-profile">
 <div class="iq-profile-top"><a href="/manage-student" class="iq-back">← &nbsp; Back</a></div>
 <div class="iq-profile-columns">
  <aside class="iq-identity">
   <div class="iq-avatar"><span>♙</span></div>
   <div class="iq-student-name">{{ $student->name }}</div>
   <div class="iq-class-label">{{ $mainEnrollment->grade_label ?? 'Class not assigned' }}@if(!empty($admission?->section_current)) - {{ $admission->section_current }}@endif</div>
   <div class="iq-status {{ $status==='present'?'is-present':'is-inactive' }}">{{ ucfirst($status) }}</div>
   <div class="iq-facts">
    <div class="iq-fact"><span class="iq-fact-icon">#</span><span><small>GR #</small><strong>{{ $student->gr_number }}</strong></span></div>
    <div class="iq-fact"><span class="iq-fact-icon">♧</span><span><small>FAMILY CODE</small><strong>{{ $student->family_code ?: 'Not assigned' }}</strong></span></div>
    <div class="iq-fact"><span class="iq-fact-icon">♙</span><span><small>FATHER NAME</small><strong>{{ $admission?->father_name ?: 'Not provided' }}</strong></span></div>
    <div class="iq-fact"><span class="iq-fact-icon">▦</span><span><small>ADMISSION DATE</small><strong>{{ !empty($student->date_of_admission) ? \Carbon\Carbon::parse($student->date_of_admission)->format('d M Y') : 'Not provided' }}</strong></span></div>
    <div class="iq-fact"><span class="iq-fact-icon">▣</span><span><small>ROLE</small><strong>Student</strong></span></div>
    <div class="iq-fact"><span class="iq-fact-icon">♧</span><span><small>GUARDIAN CONTACT</small><strong>{{ $student->whatsapp ?: 'Not provided' }}</strong></span></div>
   </div>
   <a class="iq-edit" href="{{ route('student.admission.edit',$student->id) }}">Edit</a>
  </aside>
  <div class="iq-main">
   <nav class="iq-tabs" aria-label="Student profile sections"><span class="active">♧ &nbsp; Fee</span><span title="Future module">◉ &nbsp; Attendance</span><span title="Future module">ⓘ &nbsp; Complaints</span><span title="Future module">◇ &nbsp; Assessment</span><span title="Future module">✎ &nbsp; PTM</span><span title="Future module">♧ &nbsp; Siblings</span><span title="Future module">⇄ &nbsp; Transfer History</span></nav>
   <section class="iq-dues-card">
    <div class="iq-card-actions" style="justify-content:flex-end;">
      <a href="{{ $feeItems->isNotEmpty() ? route('invoices.pdf', $feeItems->first()->invoice_id) : route('challan.index', ['search' => $student->gr_number]) }}" target="_blank" class="iq-print-challan-btn">Print Challan</a>
    </div>
    <div class="iq-scroll"><table class="iq-due-table"><thead><tr><th style="width:36px;"><input type="checkbox" checked disabled></th><th>FEE TYPE</th><th>DETAILS</th><th>BALANCE</th></tr></thead><tbody>
    @php $unpaidCount = 0; @endphp
    @forelse($feeItems as $item)
      @php $balance = max(0, (int)$item->net_minor - (int)$item->paid_minor); @endphp
      @if($balance > 0)
        @php $unpaidCount++; @endphp
        <tr>
          <td><input type="checkbox" checked disabled></td>
          <td>{{ $item->description ?: 'Monthly Fee' }}@if(($item->status ?? '') === 'exempted') <span class="iq-pill" style="background:#dcfce7;color:#166534;font-size:10px;padding:2px 6px;">Exempted</span>@elseif((int)($item->discount_minor ?? 0) > 0) <span class="iq-pill" style="background:#e0e7ff;color:#3730a3;font-size:10px;padding:2px 6px;">Discounted</span>@endif</td>
          <td><span class="iq-pill">{{ \Carbon\Carbon::parse($item->period_start)->format('M-Y') }}</span></td>
          <td><span class="iq-amount">{{ \App\Services\Money::format($balance) }}</span></td>
        </tr>
      @endif
    @empty
      <tr><td colspan="4" class="iq-empty">No unpaid fees. All billed months are clear.</td></tr>
    @endforelse
    @if($unpaidCount === 0 && $feeItems->isNotEmpty())
      <tr><td colspan="4" class="iq-empty">No unpaid fees. All billed months are clear.</td></tr>
    @endif
    </tbody></table></div>
    <div class="iq-total">
      <div style="display:flex;align-items:center;gap:10px;">
        <input type="checkbox" checked disabled>
        <span>Total Dues</span>
      </div>
      <strong>{{ \App\Services\Money::format($unpaidTotal) }}</strong>
    </div>
   </section>
   <div class="iq-history-title"><strong>◷ &nbsp; Payment History</strong><span class="iq-pill">{{ $receipts->count() }} records</span></div>
   <div class="iq-receipt-timeline">
    @forelse($receipts as $receipt)
     <div class="iq-receipt-entry">
       <div class="iq-timeline-dot">✓</div>
       <div class="iq-receipt-card">
         <div class="iq-receipt-heading">
           <a href="/receipts/{{ $receipt->id }}/pdf" target="_blank" @if(($receipt->status ?? '') === 'reversed') style="text-decoration:line-through;color:#991b1b;" @endif>
             Slip No# {{ $receipt->receipt_number }} <span style="font-size:13px;margin-left:4px;">⎙</span>
           </a>
           @if(($receipt->status ?? '') === 'reversed')
             <span class="iq-pill" style="background:#fee2e2;color:#991b1b;font-weight:bold;font-size:10px;padding:2px 6px;">REVERSED</span>
           @endif
           <span class="iq-receipt-date">▦ {{ \Carbon\Carbon::parse($receipt->received_on)->format('d M Y') }}</span>
         </div>
         <div class="iq-receipt-boxes">
           <div class="iq-receipt-type">
             <small>FEE TYPE</small>
             <strong>{{ $receipt->fee_type_name ?: 'Monthly Fee' }}</strong>
           </div>
           <div class="iq-received">
             <small>RECEIVED</small>
             <strong>{{ \App\Services\Money::format($receipt->amount_minor) }}</strong>
           </div>
         </div>
       </div>
     </div>
    @empty
     <div class="iq-empty iq-empty-history">No allocated payments recorded for this student yet.</div>
    @endforelse
   </div>
   @if(($stagedCollections ?? collect())->isNotEmpty() || ($stagedDues ?? collect())->isNotEmpty())
   <div style="margin-top:24px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
      <strong style="color:#1e293b;font-size:14px;">📑 Historical Reviewed Staging (October 22 Workbook — Read-Only)</strong>
      <span style="background:#e0e7ff;color:#3730a3;font-size:11px;font-weight:600;padding:2px 8px;border-radius:12px;">QA Staging Audit</span>
    </div>

    @if(($stagedDues ?? collect())->isNotEmpty())
    <div style="margin-bottom:16px;">
      <div style="font-size:12px;font-weight:600;color:#64748b;margin-bottom:6px;">Outstanding Dues Lines ({{ $stagedDues->count() }}):</div>
      <table style="width:100%;font-size:12px;border-collapse:collapse;background:#fff;border:1px solid #e2e8f0;border-radius:4px;">
        <thead>
          <tr style="background:#f1f5f9;text-align:left;color:#475569;">
            <th style="padding:6px 8px;">Fee Particular</th>
            <th style="padding:6px 8px;">Currency</th>
            <th style="padding:6px 8px;">Amount</th>
            <th style="padding:6px 8px;">PDF Page</th>
          </tr>
        </thead>
        <tbody>
          @foreach($stagedDues as $due)
          <tr style="border-top:1px solid #e2e8f0;">
            <td style="padding:6px 8px;">{{ $due->fee_particular }}</td>
            <td style="padding:6px 8px;">{{ $due->currency ?: 'UNRESOLVED' }}</td>
            <td style="padding:6px 8px;font-weight:600;">{{ number_format($due->outstanding_amount, 2) }}</td>
            <td style="padding:6px 8px;color:#64748b;">{{ $due->source_pdf_page ? 'p.' . $due->source_pdf_page : '—' }}</td>
          </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    @endif

    @if(($stagedCollections ?? collect())->isNotEmpty())
    <div>
      <div style="font-size:12px;font-weight:600;color:#64748b;margin-bottom:6px;">Receipt Collection Lines ({{ $stagedCollections->count() }}):</div>
      <table style="width:100%;font-size:12px;border-collapse:collapse;background:#fff;border:1px solid #e2e8f0;border-radius:4px;">
        <thead>
          <tr style="background:#f1f5f9;text-align:left;color:#475569;">
            <th style="padding:6px 8px;">Slip No</th>
            <th style="padding:6px 8px;">Particular</th>
            <th style="padding:6px 8px;">Date</th>
            <th style="padding:6px 8px;">Currency</th>
            <th style="padding:6px 8px;">Amount</th>
            <th style="padding:6px 8px;">PDF Page</th>
          </tr>
        </thead>
        <tbody>
          @foreach($stagedCollections as $col)
          <tr style="border-top:1px solid #e2e8f0;">
            <td style="padding:6px 8px;font-weight:600;">{{ $col->slip_number ?: '—' }}</td>
            <td style="padding:6px 8px;">{{ $col->fee_particular }}</td>
            <td style="padding:6px 8px;color:#64748b;">{{ $col->payment_date_parsed ?: $col->payment_date_raw }}</td>
            <td style="padding:6px 8px;">{{ $col->currency ?: 'UNRESOLVED' }}</td>
            <td style="padding:6px 8px;font-weight:600;">{{ number_format($col->amount, 2) }}</td>
            <td style="padding:6px 8px;color:#64748b;">{{ $col->source_pdf_page ? 'p.' . $col->source_pdf_page : '—' }}</td>
          </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    @endif
   </div>
   @endif
  </div>
 </div>
 <details class="iq-admin-details"><summary>Additional student management and enrollment</summary>
  <div class="iq-admin-grid"><section class="panel"><h3>Enrolled departments and fee plans</h3><div class="table-wrap"><table class="table"><thead><tr><th>Department</th><th>Class</th><th>Currency</th><th>Monthly plan</th></tr></thead><tbody>@foreach($enrollments as $e)<tr><td>{{ $e->department_name }}</td><td>{{ $e->grade_label }}</td><td>{{ $e->currency }}</td><td>{{ \App\Services\Money::format($e->monthly_fee_minor) }}</td></tr>@endforeach</tbody></table></div></section>
  <section class="panel"><h3>Family contact and messaging permissions</h3><form method="post" action="/families/{{ $student->family_id }}/contact">@csrf<div class="formgrid"><div><label>Guardian Name</label><input class="input" name="guardian_name" value="{{ $student->guardian_name }}"></div><div><label>Email</label><input class="input" name="email" type="email" value="{{ $student->email }}"></div><div><label>WhatsApp</label><input class="input" name="whatsapp" value="{{ $student->whatsapp }}"></div><div class="stack"><label><input type="checkbox" name="contact_verified" value="1" {{ $student->contact_verified?'checked':'' }}> Contacts verified</label><label><input type="checkbox" name="messaging_opt_in" value="1" {{ $student->messaging_opt_in?'checked':'' }}> WhatsApp permission obtained</label></div></div><button class="btn purple">Save Family Contact</button></form></section>
  <section class="panel"><h3>Add department enrollment</h3><form method="post" action="/manage-student/{{ $student->id }}/enrollments">@csrf<div class="formgrid"><div><label>Department</label><select class="input" name="department_id">@foreach(\Illuminate\Support\Facades\DB::table('departments')->get() as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></div><div><label>Campus</label><select class="input" name="campus_id">@foreach(\Illuminate\Support\Facades\DB::table('campuses')->get() as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div><div><label>Grade / Class</label><input class="input" name="grade_label" required></div><div><label>Subject (Tuition)</label><input class="input" name="subject_name"></div><div><label>Quran class days</label><input class="input" type="number" min="1" max="7" name="class_days_per_week"></div><div><label>Currency</label><input class="input" name="currency" maxlength="3" value="SAR" required></div><div><label>Monthly Fee</label><input class="input" name="monthly_fee" type="number" min="0" step=".01"></div></div><button class="btn green">Save Enrollment and Fee</button></form></section></div>
 </details>
</div>
@endsection
