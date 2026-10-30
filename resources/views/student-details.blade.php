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
    <div class="iq-fact"><span class="iq-fact-icon">♧</span><span><small>GUARDIAN NAME</small><strong>{{ $student->resolved_guardian['name'] ?? ($student->guardian_name ?: 'Not provided') }}</strong>
      @if(!empty($student->resolved_guardian['is_override']))
        <span style="background:#ede9fe; color:#6d28d9; font-size:10px; padding:1px 6px; border-radius:4px; font-weight:600; margin-left:4px;">Individual Override</span>
      @endif
    </span></div>
    <div class="iq-fact"><span class="iq-fact-icon">▦</span><span><small>ADMISSION DATE</small><strong>{{ !empty($student->date_of_admission) ? \Carbon\Carbon::parse($student->date_of_admission)->format('d M Y') : 'Not provided' }}</strong></span></div>
    <div class="iq-fact"><span class="iq-fact-icon">▣</span><span><small>ROLE</small><strong>Student</strong></span></div>
    <div class="iq-fact"><span class="iq-fact-icon">♧</span><span><small>GUARDIAN CONTACT</small><strong>{{ $student->whatsapp ?: 'Not provided' }}</strong></span></div>
    <div class="iq-fact"><span class="iq-fact-icon">✓</span><span><small>MESSAGING ELIGIBILITY</small>
      <div style="display:flex; flex-wrap:wrap; gap:4px; margin-top:3px;">
        @if($student->contact_verified)
          <span style="background:#dcfce7; color:#166534; font-size:10px; padding:1px 6px; border-radius:4px; font-weight:600;">Contact Verified</span>
        @else
          <span style="background:#fee2e2; color:#991b1b; font-size:10px; padding:1px 6px; border-radius:4px; font-weight:600;">Unverified Contact</span>
        @endif
        @if($student->messaging_opt_in)
          <span style="background:#ede9fe; color:#6d28d9; font-size:10px; padding:1px 6px; border-radius:4px; font-weight:600;">WhatsApp Consent: Yes</span>
        @else
          <span style="background:#ffedd5; color:#9a3412; font-size:10px; padding:1px 6px; border-radius:4px; font-weight:600;">Consent: Absent</span>
        @endif
      </div>
    </span></div>
   </div>
   <a class="iq-edit" href="{{ route('student.admission.edit',$student->id) }}">Edit</a>
  </aside>
  <div class="iq-main">
   <nav class="iq-tabs" aria-label="Student profile sections"><span class="active">♧ &nbsp; Fee</span><span title="Future module">◉ &nbsp; Attendance</span><span title="Future module">ⓘ &nbsp; Complaints</span><span title="Future module">◇ &nbsp; Assessment</span><span title="Future module">✎ &nbsp; PTM</span><span title="Future module">♧ &nbsp; Siblings</span><span title="Future module">⇄ &nbsp; Transfer History</span></nav>
   <section class="iq-dues-card">
    <div class="iq-card-actions" style="justify-content:flex-end;">
      <a href="{{ route('students.challan.pdf', $student->id) }}" target="_blank" class="iq-print-challan-btn">Print Challan</a>
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
          <td><span class="iq-amount">{{ \App\Services\Money::format($balance) }} {{ $item->currency }}</span></td>
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
      <div style="text-align:right;">
        @forelse($totals as $curr => $cItems)
          @php $cUnpaid = $cItems->sum(fn ($item) => max(0, (int) $item->net_minor - (int) $item->paid_minor)); @endphp
          @if($cUnpaid > 0)
            <strong style="display:block;">{{ \App\Services\Money::format($cUnpaid) }} {{ $curr }}</strong>
          @endif
        @empty
          <strong>0.00</strong>
        @endforelse
      </div>
    </div>
   </section>
   {{-- UNIFIED FEE HISTORY SECTION FOR STUDENT --}}
   <div class="iq-history-title" style="display: flex; justify-content: space-between; align-items: center; margin-top: 24px;">
     <div style="display: flex; align-items: center; gap: 8px;">
       <strong>◷ &nbsp; Fee History</strong>
       @php
         $totalFeeRecords = $feeHistory->total();
       @endphp
       <span class="iq-pill" style="background: #e0f2fe; color: #0369a1; font-weight: 600;">
         {{ $totalFeeRecords }} {{ \Illuminate\Support\Str::plural('record', $totalFeeRecords) }} (Unified Portal &amp; MySkool Receipts)
       </span>
     </div>
   </div>

   {{-- FILTER BAR --}}
   <form method="get" action="{{ url()->current() }}" style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center; padding: 10px 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; margin: 12px 0;">
     <div style="flex: 1; min-width: 160px;">
       <input type="text" name="search" value="{{ request('search') }}" placeholder="🔍 Search Slip #, Month..." style="height: 30px; font-size: 12px; width: 100%; border: 1px solid #cbd5e1; border-radius: 4px; padding: 0 8px;">
     </div>
     <div>
       <select name="source" style="height: 30px; font-size: 12px; border: 1px solid #cbd5e1; border-radius: 4px; padding: 0 8px; background: #fff;" onchange="this.form.submit()">
         <option value="all" {{ request('source') === 'all' || !request('source') ? 'selected' : '' }}>All Sources</option>
         <option value="portal" {{ request('source') === 'portal' ? 'selected' : '' }}>New Portal Only</option>
         <option value="myskool" {{ request('source') === 'myskool' ? 'selected' : '' }}>Historical MySkool Only</option>
       </select>
     </div>
     <button type="submit" class="btn purple" style="height: 30px; padding: 0 12px; font-size: 12px; border-radius: 4px; cursor: pointer;">Filter</button>
     @if(request()->hasAny(['search', 'source']))
       <a href="{{ url()->current() }}" style="font-size: 11px; color: #7367f0; text-decoration: none; padding: 4px 6px;">Clear</a>
     @endif
   </form>

   {{-- UNIFIED RECEIPT TABLE --}}
   <div style="overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 6px; background: #fff;">
     <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
       <thead>
         <tr style="background: #f1f5f9; color: #475569; text-align: left; border-bottom: 2px solid #e2e8f0;">
           <th style="padding: 8px 10px;">SLIP NO#</th>
           <th style="padding: 8px 10px;">FEE TYPE</th>
           <th style="padding: 8px 10px;">DETAILS</th>
           <th style="padding: 8px 10px;">TOTAL</th>
           <th style="padding: 8px 10px;">RECEIVING DATE</th>
           <th style="padding: 8px 10px;">RECEIVED BY</th>
           <th style="padding: 8px 10px; text-align: right;">ACTIONS</th>
         </tr>
       </thead>
       <tbody>
         @forelse($feeHistory as $receipt)
           <tr style="border-bottom: 1px solid #e2e8f0; {{ $receipt->is_reversed ? 'opacity: 0.6; background: #fff5f5;' : '' }}">
             <td style="padding: 8px 10px;">
               <span style="background: #ede9fe; color: #6d28d9; padding: 2px 8px; border-radius: 4px; font-weight: 700; cursor: pointer;" onclick='showReceiptDetails(@json($receipt->details_payload))' title="Click to view full receipt details">
                 {{ $receipt->slip_number }}
               </span>
               @if($receipt->source === 'portal')
                 <span style="font-size: 9px; background: #e0f2fe; color: #0369a1; padding: 1px 6px; border-radius: 10px; font-weight: 700; display: inline-block; margin-left: 2px;">Portal</span>
               @else
                 <span style="font-size: 9px; background: #fef3c7; color: #92400e; padding: 1px 6px; border-radius: 10px; font-weight: 700; display: inline-block; margin-left: 2px;">MySkool</span>
               @endif
               @if($receipt->is_reversed)
                 <span style="font-size: 9px; background: #fee2e2; color: #991b1b; padding: 1px 6px; border-radius: 10px; font-weight: 700; display: inline-block; margin-left: 2px;">REVERSED</span>
               @elseif(!empty($receipt->is_partially_reversed))
                 <span style="font-size: 9px; background: #fef3c7; color: #92400e; padding: 1px 6px; border-radius: 10px; font-weight: 700; display: inline-block; margin-left: 2px;">PARTIALLY REVERSED</span>
               @endif
             </td>
             <td style="padding: 8px 10px;">{{ $receipt->fee_types_str }}</td>
             <td style="padding: 8px 10px;"><span style="background: #ede9fe; color: #6d28d9; padding: 1px 6px; border-radius: 4px; font-size: 11px;">{{ $receipt->months_str }}</span></td>
             <td style="padding: 8px 10px; font-weight: bold; color: #1e293b;">
               {{ $receipt->currency }} {{ number_format($receipt->amount, 2) }}
               @if((int) ($receipt->reversed_amount_minor ?? 0) > 0)
                 <div style="font-size: 10px; font-weight: normal; color: #b45309;">
                   Orig: {{ $receipt->currency }} {{ number_format((float) ($receipt->original_amount ?? $receipt->amount), 2) }} &bull; Rev: {{ number_format((float) ($receipt->reversed_amount ?? 0), 2) }}
                 </div>
               @endif
             </td>
             <td style="padding: 8px 10px;">{{ $receipt->received_on_formatted }}</td>
             <td style="padding: 8px 10px;">
               <div style="font-size: 11px; color: #475569;">{{ $receipt->received_by_name ?? '—' }}</div>
               <div style="font-size: 10px; color: #94a3b8;">{{ $receipt->payment_method ?? 'Cash' }}</div>
             </td>
             <td style="padding: 8px 10px; text-align: right;">
               <div style="display: inline-flex; gap: 4px; align-items: center;">
                 {{-- Print Action --}}
                 <a href="{{ $receipt->print_url }}" target="_blank" style="padding: 4px 8px; background: #7367f0; color: #fff; text-decoration: none; border-radius: 4px; font-size: 11px; display: inline-flex; align-items: center;" title="{{ $receipt->is_historical ? 'Print Historical Payment Record' : 'Print Official Receipt' }}">
                   &#128438;
                 </a>
                 {{-- View Details Modal Action --}}
                 <button type="button" onclick='showReceiptDetails(@json($receipt->details_payload))' style="padding: 4px 8px; background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 11px; cursor: pointer;" title="View Complete Itemized Breakdown">
                   &#128065;
                 </button>
               </div>
             </td>
           </tr>
         @empty
           <tr>
             <td colspan="7" style="text-align: center; padding: 22px; color: #94a3b8;">
               No payment history records found for this student.
             </td>
           </tr>
         @endforelse
       </tbody>
     </table>
   </div>

   {{-- PAGINATION --}}
   @if($feeHistory->hasPages())
     <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; border: 1px solid #e2e8f0; border-top: none; background: #f8fafc; border-radius: 0 0 6px 6px;">
       <div style="font-size: 12px; color: #64748b;">
         Showing <b>{{ $feeHistory->firstItem() }}</b> to <b>{{ $feeHistory->lastItem() }}</b> of <b>{{ $feeHistory->total() }}</b> receipts
       </div>
       <div>
         {{ $feeHistory->links() }}
       </div>
     </div>
   @endif
  </div>
 </div>
 <details class="iq-admin-details"><summary>Additional student management and enrollment</summary>
  <div class="iq-admin-grid"><section class="panel"><h3>Enrolled departments and fee plans</h3><div class="table-wrap"><table class="table"><thead><tr><th>Department</th><th>Class</th><th>Currency</th><th>Monthly plan</th></tr></thead><tbody>@foreach($enrollments as $e)<tr><td>{{ $e->department_name }}</td><td>{{ $e->grade_label }}</td><td>{{ $e->currency }}</td><td>{{ \App\Services\Money::format($e->monthly_fee_minor) }} {{ $e->currency }}</td></tr>@endforeach</tbody></table></div></section>
  <section class="panel"><h3>Family contact and messaging permissions</h3><form method="post" action="/families/{{ $student->family_id }}/contact">@csrf<div class="formgrid"><div><label>Guardian Name</label><input class="input" name="guardian_name" value="{{ \App\Services\GuardianNameResolver::isValid($student->guardian_name) ? $student->guardian_name : '' }}"></div><div><label>Email</label><input class="input" name="email" type="email" value="{{ $student->email }}"></div><div><label>WhatsApp</label><input class="input" name="whatsapp" value="{{ $student->whatsapp }}"></div><div class="stack"><label><input type="checkbox" name="contact_verified" value="1" {{ $student->contact_verified?'checked':'' }}> Contacts verified</label><label><input type="checkbox" name="messaging_opt_in" value="1" {{ $student->messaging_opt_in?'checked':'' }}> WhatsApp permission obtained</label></div></div><button class="btn purple">Save Family Contact</button></form></section>
  <section class="panel"><h3>Add department enrollment</h3><form method="post" action="/manage-student/{{ $student->id }}/enrollments">@csrf<div class="formgrid"><div><label>Department</label><select class="input" name="department_id">@foreach(\Illuminate\Support\Facades\DB::table('departments')->get() as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></div><div><label>Campus</label><select class="input" name="campus_id">@foreach(\Illuminate\Support\Facades\DB::table('campuses')->get() as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div><div><label>Grade / Class</label><input class="input" name="grade_label" required></div><div><label>Subject (Tuition)</label><input class="input" name="subject_name"></div><div><label>Quran class days</label><input class="input" type="number" min="1" max="7" name="class_days_per_week"></div><div><label>Currency</label><input class="input" name="currency" maxlength="3" value="SAR" required></div><div><label>Monthly Fee</label><input class="input" name="monthly_fee" type="number" min="0" step=".01"></div></div><button class="btn green">Save Enrollment and Fee</button></form></section></div>
 </details>
</div>

{{-- RECEIPT ITEMIZATION DETAILS DIALOG --}}
<dialog id="receipt-details-dialog" style="border:none;border-radius:8px;box-shadow:0 12px 40px rgba(0,0,0,0.3);padding:0;max-width:820px;width:96%;margin:auto;font-family:inherit;">
 <div style="background:#7367f0;color:#fff;padding:16px 20px;display:flex;align-items:center;justify-content:space-between;border-top-left-radius:8px;border-top-right-radius:8px;">
  <strong style="font-size:16px;display:flex;align-items:center;gap:8px;">
    <span>Receipt Slip #<span id="rd-slip"></span></span>
    <span id="rd-source-badge" style="font-size:10px;padding:2px 8px;border-radius:12px;font-weight:700;"></span>
  </strong>
  <button type="button" onclick="document.getElementById('receipt-details-dialog').close()" style="background:none;border:none;color:#fff;font-size:24px;line-height:1;cursor:pointer;">&times;</button>
 </div>
 <div style="padding:20px;font-size:13px;color:#5e5873;max-height:82vh;overflow-y:auto;">
  
  {{-- META GRID --}}
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:10px;background:#f8f7fa;border:1px solid #ebe9f1;border-radius:6px;padding:12px;margin-bottom:14px;">
    <div><small style="color:#64748b;display:block;">Payment Date</small><strong id="rd-date">—</strong></div>
    <div><small style="color:#64748b;display:block;">Original Receipt Total</small><strong id="rd-total" style="color:#7367f0;">—</strong></div>
    <div><small style="color:#64748b;display:block;">Total Amount Reversed</small><strong id="rd-reversed-total" style="color:#b45309;">—</strong></div>
    <div><small style="color:#64748b;display:block;">Remaining Effective Paid</small><strong id="rd-effective-total" style="color:#166534;">—</strong></div>
    <div><small style="color:#64748b;display:block;">Discount</small><strong id="rd-discount">0</strong></div>
    <div><small style="color:#64748b;display:block;">Method</small><strong id="rd-method">Cash</strong></div>
    <div><small style="color:#64748b;display:block;">Received By</small><strong id="rd-received-by">—</strong></div>
    <div><small style="color:#64748b;display:block;">Status</small><strong id="rd-status">Active</strong></div>
  </div>

  <div id="rd-notice-container"></div>

  {{-- ITEMS TABLE --}}
  <div style="font-weight:600;margin:12px 0 6px;color:#334155;">Underlying Item Allocations:</div>
  <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:12px;border:1px solid #ebe9f1;border-radius:4px;overflow:hidden;">
      <thead>
        <tr style="background:#f1f5f9;color:#475569;text-align:left;">
          <th style="padding:8px 10px;">Student</th>
          <th style="padding:8px 10px;">Fee Particular</th>
          <th style="padding:8px 10px;">Month</th>
          <th style="padding:8px 10px;text-align:right;">Original Paid</th>
          <th style="padding:8px 10px;text-align:right;">Reversed</th>
          <th style="padding:8px 10px;text-align:right;">Effective Paid</th>
          <th style="padding:8px 10px;">Status</th>
        </tr>
      </thead>
      <tbody id="rd-items-tbody">
      </tbody>
    </table>
  </div>

  {{-- FOOTER BUTTONS --}}
  <div style="display:flex;justify-content:space-between;align-items:center;margin-top:18px;padding-top:14px;border-top:1px solid #ebe9f1;">
    <a id="rd-print-btn" href="#" target="_blank" class="btn purple" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:4px;font-size:12px;color:#fff;">
      &#128438; Print Slip
    </a>
    <button type="button" onclick="document.getElementById('receipt-details-dialog').close()" style="background:#f1f5f9;border:1px solid #cbd5e1;padding:7px 16px;border-radius:4px;font-size:12px;cursor:pointer;color:#475569;font-weight:600;">
      Close
    </button>
  </div>
 </div>
</dialog>

<script>
window.showReceiptDetails = function(payload) {
    if (!payload) return;
    document.getElementById('rd-slip').textContent = payload.slip_number || '—';
    document.getElementById('rd-date').textContent = payload.received_on || '—';
    document.getElementById('rd-total').textContent = payload.original_total_amount || payload.total_amount || '—';
    document.getElementById('rd-reversed-total').textContent = payload.total_reversed_amount || ((payload.currency ? payload.currency + ' ' : '') + '0.00');
    document.getElementById('rd-effective-total').textContent = payload.remaining_effective_amount || payload.total_amount || '—';
    document.getElementById('rd-discount').textContent = payload.discount || '0';
    document.getElementById('rd-method').textContent = payload.payment_method || 'Cash';
    document.getElementById('rd-received-by').textContent = payload.received_by || '—';
    document.getElementById('rd-status').textContent = payload.status || 'Active';
    
    var badge = document.getElementById('rd-source-badge');
    if (payload.source === 'portal') {
        badge.textContent = 'Live Portal Receipt';
        badge.style.background = '#e0f2fe';
        badge.style.color = '#0369a1';
    } else {
        badge.textContent = 'Historical MySkool Record';
        badge.style.background = '#fef3c7';
        badge.style.color = '#92400e';
    }

    var noticeBox = document.getElementById('rd-notice-container');
    if (payload.is_historical) {
        noticeBox.innerHTML = '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:4px;padding:8px 12px;margin:10px 0;font-size:11px;color:#92400e;line-height:1.4;"><strong>Historical MySkool Payment Record</strong><br>Original imported MySkool source records are permanently preserved while any individual payment restorations are reflected in the effective paid totals and student obligations.</div>';
    } else {
        noticeBox.innerHTML = '';
    }

    var tbody = document.getElementById('rd-items-tbody');
    tbody.innerHTML = '';
    if (payload.items && payload.items.length > 0) {
        payload.items.forEach(function(it) {
            var tr = document.createElement('tr');
            var currPrefix = it.currency ? it.currency + ' ' : '';
            var origPaid = it.original_amount || it.amount || '0.00';
            var revAmt = it.reversed_amount || '0.00';
            var effPaid = it.effective_paid_amount !== undefined ? it.effective_paid_amount : (it.amount || '0.00');
            var stLabel = it.allocation_status_label || 'Paid';
            tr.innerHTML = '<td style="padding:8px 10px;border-bottom:1px solid #ebe9f1;"><strong>' + (it.student_name || '—') + '</strong><br><small style="color:#64748b;">GR: ' + (it.gr_number || '—') + '</small></td>' +
                '<td style="padding:8px 10px;border-bottom:1px solid #ebe9f1;">' + (it.fee_particular || 'Monthly Fee') + '</td>' +
                '<td style="padding:8px 10px;border-bottom:1px solid #ebe9f1;"><span style="background:#ede9fe;color:#6d28d9;padding:1px 6px;border-radius:4px;font-size:11px;">' + (it.period || '—') + '</span></td>' +
                '<td style="padding:8px 10px;border-bottom:1px solid #ebe9f1;text-align:right;font-weight:600;">' + currPrefix + origPaid + '</td>' +
                '<td style="padding:8px 10px;border-bottom:1px solid #ebe9f1;text-align:right;color:#b45309;font-weight:600;">' + (Number(it.reversed_amount_minor || 0) > 0 ? currPrefix + revAmt : '—') + '</td>' +
                '<td style="padding:8px 10px;border-bottom:1px solid #ebe9f1;text-align:right;font-weight:700;color:#166534;">' + currPrefix + effPaid + '</td>' +
                '<td style="padding:8px 10px;border-bottom:1px solid #ebe9f1;"><span style="background:#f1f5f9;color:#334155;padding:2px 6px;border-radius:8px;font-size:10px;font-weight:700;">' + stLabel + '</span></td>';
            tbody.appendChild(tr);
        });
    } else {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:12px;color:#94a3b8;">No itemized details recorded.</td></tr>';
    }

    var printBtn = document.getElementById('rd-print-btn');
    if (payload.print_url) {
        printBtn.href = payload.print_url;
        printBtn.style.display = 'inline-flex';
    } else {
        printBtn.style.display = 'none';
    }

    document.getElementById('receipt-details-dialog').showModal();
};
</script>

@endsection
