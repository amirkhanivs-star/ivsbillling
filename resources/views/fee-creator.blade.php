@extends('layouts.app')
@section('title','Challan Creator')
@section('content')
<link rel="stylesheet" href="{{ asset('assets/iqra-challan.css') }}">
@php
  $schoolNow = \Carbon\Carbon::now(\App\Services\BillingPeriod::schoolTimezone());
  $selectedYear = isset($selectedYear) ? (int) $selectedYear : (int) old('year', $schoolNow->year);
  $selectedMonths = isset($selectedMonths) && is_array($selectedMonths) && count($selectedMonths) > 0
    ? array_map('intval', $selectedMonths)
    : array_map('intval', old('months', [(int) $schoolNow->month]));
  $issueDate = isset($issueDate) && is_string($issueDate) && $issueDate !== ''
    ? $issueDate
    : (string) old('issue_date', \App\Services\BillingPeriod::resolveIssueDate());
  $dueDay = isset($dueDay) ? (int) $dueDay : (int) old('due_day', \App\Services\BillingPeriod::DEFAULT_DUE_DAY);
  $minimumPaymentDays = isset($minimumPaymentDays)
    ? (int) $minimumPaymentDays
    : (int) old('minimum_payment_days', \App\Services\BillingPeriod::DEFAULT_MINIMUM_PAYMENT_DAYS);
  $dueDatePreview = $dueDatePreview ?? \App\Services\BillingPeriod::previewSchedule(
    $selectedYear,
    $selectedMonths,
    $dueDay,
    $issueDate,
    $minimumPaymentDays
  );
  $previewPeriods = $dueDatePreview['periods'] ?? [];
@endphp
<style>
  .iq-create-filters-6 { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:14px 18px; margin-top:6px; }
  .iq-due-preview-box { margin:16px 0 12px; padding:12px 14px; background:#f8f7ff; border:1px solid #d9d2ff; border-left:4px solid #7565ec; border-radius:5px; }
  .iq-due-preview-header { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:8px; }
  .iq-due-preview-title { font-size:13px; font-weight:600; color:#3d3560; }
  .iq-due-preview-meta { font-size:11.5px; color:#696182; }
  .iq-due-preview-table { width:100%; border-collapse:collapse; font-size:12px; background:#fff; border:1px solid #e5e0f8; border-radius:4px; overflow:hidden; }
  .iq-due-preview-table th { background:#efeaff; color:#4b3f72; text-align:left; padding:6px 10px; font-size:11.5px; font-weight:600; border-bottom:1px solid #dcd4f7; }
  .iq-due-preview-table td { padding:6px 10px; border-bottom:1px solid #f0edf9; color:#4c466c; }
  .iq-due-preview-table tr:last-child td { border-bottom:0; }
  .iq-final-due-pill { display:inline-block; padding:2px 8px; border-radius:3px; background:#e8f8ef; color:#137a45; font-weight:700; border:1px solid #bce8cf; }
  .iq-window-badge { display:inline-block; margin-left:6px; padding:1px 6px; border-radius:3px; background:#fff4e5; color:#9a5b13; font-size:10.5px; font-weight:600; border:1px solid #f5d6a7; }
  @media(max-width:750px){ .iq-create-filters-6 { grid-template-columns:repeat(2,minmax(0,1fr)); } }
</style>
<div class="iq-create">
  <div class="iq-create-head"><h2>Create Fee</h2><div class="iq-create-tools"><a class="iq-outline" href="/invoices">Review Invoices</a><button type="button" class="iq-hold" id="iq-hold">Hold</button><button type="button" class="iq-hold" id="iq-unhold">Unhold</button></div></div>
  <form method="post" action="/fee-creator" id="invoice-creator">@csrf
    <input type="hidden" name="frequency" value="monthly"><input type="hidden" name="monthly_selection" value="1">
    <label class="iq-label">Select Months</label>
    <div class="iq-chip-border iq-months">@foreach(range(1,12) as $m)<label class="iq-chip"><input type="checkbox" name="months[]" value="{{ $m }}" @checked(in_array($m,$selectedMonths))><span>{{ \Carbon\Carbon::create($selectedYear,$m,1,0,0,0,\App\Services\BillingPeriod::schoolTimezone())->format('M') }}</span></label>@endforeach</div>
    <div class="iq-between"><label class="iq-label">Select Fee Type</label><button type="button" id="fee-more" class="iq-text-btn">Show More</button></div>
    <div class="iq-chip-border iq-types" id="iq-types">@foreach($types as $type)<label class="iq-chip"><input type="checkbox" name="fee_type_ids[]" value="{{ $type->id }}" @checked(in_array($type->id,old('fee_type_ids',[])))><span>{{ $type->name }}</span></label>@endforeach</div>
    <div class="iq-create-filters iq-create-filters-6">
      <div><label class="iq-label" for="campus_id">Campus</label><select class="input" id="campus_id" name="campus_id" required>@foreach($campuses as $campus)<option value="{{ $campus->id }}" @selected(old('campus_id')==$campus->id)>{{ $campus->name }}</option>@endforeach</select></div>
      <div><label class="iq-label" for="department_id">Department</label><select class="input" id="department_id" name="department_id" required>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(old('department_id')==$department->id)>{{ $department->name }}</option>@endforeach</select></div>
      <div><label class="iq-label" for="year">Select Year</label><select id="year" name="year" class="input" required>@foreach(range($schoolNow->year-2,$schoolNow->year+2) as $year)<option value="{{ $year }}" @selected($selectedYear===$year)>{{ $year }}</option>@endforeach</select></div>
      <div><label class="iq-label" for="issue_date">Invoice Issue Date</label><input type="date" id="issue_date" name="issue_date" class="input" value="{{ $issueDate }}" required></div>
      <div><label class="iq-label" for="due_day">Standard Monthly Due Day</label><select id="due_day" name="due_day" class="input">@foreach(range(1,28) as $day)<option value="{{ $day }}" @selected($dueDay===$day)>{{ $day }}</option>@endforeach</select></div>
      <div><label class="iq-label" for="minimum_payment_days">Min. Days After Issuance</label><input type="number" id="minimum_payment_days" name="minimum_payment_days" class="input" min="0" max="90" value="{{ $minimumPaymentDays }}" required></div>
    </div>

    <div class="iq-due-preview-box" id="iq-due-preview" aria-live="polite">
      <div class="iq-due-preview-header">
        <span class="iq-due-preview-title">Calculated Final Due Date Preview (Authoritative Server Schedule)</span>
        <span class="iq-due-preview-meta" id="iq-due-preview-summary">
          Issue Date: <strong id="iq-preview-issue-date">{{ $dueDatePreview['issue_date'] ?? $issueDate }}</strong> ({{ $dueDatePreview['issue_date_formatted'] ?? $issueDate }}) &bull;
          Standard Due Day: <strong id="iq-preview-due-day">{{ $dueDay }}</strong> &bull;
          Min. Window: <strong id="iq-preview-min-days">+{{ $minimumPaymentDays }} days</strong> (earliest <strong id="iq-preview-min-date">{{ $dueDatePreview['minimum_due_date'] ?? '' }}</strong>)
        </span>
      </div>
      <table class="iq-due-preview-table">
        <thead>
          <tr>
            <th>Fee Month</th>
            <th>Invoice Issue Date</th>
            <th>Standard Monthly Due</th>
            <th>Minimum Window Date</th>
            <th>Calculated Final Due Date</th>
          </tr>
        </thead>
        <tbody id="iq-due-preview-tbody">
          @forelse($previewPeriods as $pRow)
            <tr>
              <td><strong>{{ $pRow['period_label'] }}</strong> ({{ $pRow['start'] }} .. {{ $pRow['end'] }})</td>
              <td>{{ $dueDatePreview['issue_date'] }} ({{ $dueDatePreview['issue_date_formatted'] }})</td>
              <td>{{ $pRow['standard_due'] }} ({{ $pRow['standard_due_formatted'] }})</td>
              <td>{{ $pRow['minimum_due'] }} ({{ $pRow['minimum_due_formatted'] }})</td>
              <td>
                <span class="iq-final-due-pill">{{ $pRow['due'] }} ({{ $pRow['due_formatted'] }})</span>
                @if(!empty($pRow['window_applied']))
                  <span class="iq-window-badge">+{{ $minimumPaymentDays }}d window enforced</span>
                @else
                  <span class="iq-window-badge" style="background:#eef2ff;color:#4338ca;border-color:#c7d2fe;">Standard day {{ $dueDay }}</span>
                @endif
              </td>
            </tr>
          @empty
            <tr><td colspan="5" style="text-align:center;color:#857e97;">Select at least one fee month above to see calculated due dates.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>

    <p class="iq-note">Class/grade confirmation is provided during invoice review. Family invoices include every sibling in the same department, campus, currency, and month.</p>
    <div class="iq-create-actions"><button type="submit" class="iq-green">Create Fee</button><a class="iq-outline" href="/invoices">Review / Print Invoices</a><a class="iq-whatsapp" href="{{ url('/invoices?send_mode=whatsapp') }}">WhatsApp Invoices</a><a class="iq-cyan" href="{{ url('/invoices?send_mode=email') }}">Email Invoices</a></div>
  </form>
</div>
<script>
(()=>{
  const months=[...document.querySelectorAll('input[name="months[]"]')];
  const types=document.getElementById('iq-types');
  const toggle=document.getElementById('fee-more');
  const yearEl=document.getElementById('year');
  const issueDateEl=document.getElementById('issue_date');
  const dueDayEl=document.getElementById('due_day');
  const minDaysEl=document.getElementById('minimum_payment_days');
  const tbody=document.getElementById('iq-due-preview-tbody');
  const summaryEl=document.getElementById('iq-due-preview-summary');

  toggle.addEventListener('click',()=>{
    types.classList.toggle('expanded');
    toggle.textContent=types.classList.contains('expanded')?'Show Less':'Show More';
  });

  function renderSchedule(data){
    if(!data || !Array.isArray(data.periods) || data.periods.length===0){
      tbody.innerHTML='<tr><td colspan="5" style="text-align:center;color:#857e97;">Select at least one fee month above to see calculated due dates.</td></tr>';
      return;
    }
    summaryEl.innerHTML=`Issue Date: <strong>${data.issue_date}</strong> (${data.issue_date_formatted}) &bull; Standard Due Day: <strong>${data.due_day}</strong> &bull; Min. Window: <strong>+${data.minimum_payment_days} days</strong> (earliest <strong>${data.minimum_due_date}</strong>)`;
    tbody.innerHTML=data.periods.map(p=>{
      const badge=p.window_applied
        ? `<span class="iq-window-badge">+${data.minimum_payment_days}d window enforced</span>`
        : `<span class="iq-window-badge" style="background:#eef2ff;color:#4338ca;border-color:#c7d2fe;">Standard day ${data.due_day}</span>`;
      return `<tr>
        <td><strong>${p.period_label}</strong> (${p.start} .. ${p.end})</td>
        <td>${data.issue_date} (${data.issue_date_formatted})</td>
        <td>${p.standard_due} (${p.standard_due_formatted})</td>
        <td>${p.minimum_due} (${p.minimum_due_formatted})</td>
        <td><span class="iq-final-due-pill">${p.due} (${p.due_formatted})</span>${badge}</td>
      </tr>`;
    }).join('');
  }

  let fetchController=null;
  async function refreshAuthoritativeDueDates(){
    const checkedMonths=months.filter(x=>x.checked).map(x=>parseInt(x.value,10)).filter(n=>n>=1&&n<=12);
    if(checkedMonths.length===0){
      renderSchedule({periods:[]});
      return;
    }
    const params=new URLSearchParams();
    params.set('preview_due_dates','1');
    params.set('year',yearEl.value);
    params.set('issue_date',issueDateEl.value);
    params.set('due_day',dueDayEl.value);
    params.set('minimum_payment_days',minDaysEl.value);
    checkedMonths.forEach(m=>params.append('months[]',String(m)));

    if(fetchController) fetchController.abort();
    fetchController=new AbortController();
    try {
      const res=await fetch(`/fee-creator?${params.toString()}`,{
        headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'},
        signal:fetchController.signal
      });
      if(res.ok){
        const data=await res.json();
        renderSchedule(data);
      }
    } catch (err) {
      if(err && err.name==='AbortError') return;
    }
  }

  months.forEach(x=>x.addEventListener('change',refreshAuthoritativeDueDates));
  [yearEl,issueDateEl,dueDayEl,minDaysEl].forEach(el=>{
    el.addEventListener('change',refreshAuthoritativeDueDates);
    el.addEventListener('input',refreshAuthoritativeDueDates);
  });

  document.getElementById('iq-hold').addEventListener('click',()=>{
    months.forEach(x=>x.checked=false);
    refreshAuthoritativeDueDates();
  });
  document.getElementById('iq-unhold').addEventListener('click',()=>{
    months.forEach(x=>x.checked=true);
    refreshAuthoritativeDueDates();
  });
  document.getElementById('invoice-creator').addEventListener('submit',e=>{
    if(!months.some(x=>x.checked)){
      e.preventDefault();
      alert('Select at least one month.');
    }
  });
})();
</script>
@endsection
