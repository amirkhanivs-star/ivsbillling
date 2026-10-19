@extends('layouts.app')
@section('title','Challan Creator')
@section('content')
<link rel="stylesheet" href="{{ asset('assets/iqra-challan.css') }}">
@php($selectedMonths=array_map('intval',old('months',[(int)now()->month])))
<div class="iq-create">
  <div class="iq-create-head"><h2>Create Fee</h2><div class="iq-create-tools"><a class="iq-outline" href="/invoices">Review Invoices</a><button type="button" class="iq-hold" id="iq-hold">Hold</button><button type="button" class="iq-hold" id="iq-unhold">Unhold</button></div></div>
  <form method="post" action="/fee-creator" id="invoice-creator">@csrf
    <input type="hidden" name="frequency" value="monthly"><input type="hidden" name="monthly_selection" value="1">
    <label class="iq-label">Select Months</label>
    <div class="iq-chip-border iq-months">@foreach(range(1,12) as $m)<label class="iq-chip"><input type="checkbox" name="months[]" value="{{ $m }}" @checked(in_array($m,$selectedMonths))><span>{{ \Carbon\Carbon::create(2026,$m,1)->format('M') }}</span></label>@endforeach</div>
    <div class="iq-between"><label class="iq-label">Select Fee Type</label><button type="button" id="fee-more" class="iq-text-btn">Show More</button></div>
    <div class="iq-chip-border iq-types" id="iq-types">@foreach($types as $type)<label class="iq-chip"><input type="checkbox" name="fee_type_ids[]" value="{{ $type->id }}" @checked(in_array($type->id,old('fee_type_ids',[])))><span>{{ $type->name }}</span></label>@endforeach</div>
    <div class="iq-create-filters">
      <div><label class="iq-label" for="campus_id">Campus</label><select class="input" id="campus_id" name="campus_id" required>@foreach($campuses as $campus)<option value="{{ $campus->id }}" @selected(old('campus_id')==$campus->id)>{{ $campus->name }}</option>@endforeach</select></div>
      <div><label class="iq-label" for="department_id">Department</label><select class="input" id="department_id" name="department_id" required>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(old('department_id')==$department->id)>{{ $department->name }}</option>@endforeach</select></div>
      <div><label class="iq-label" for="year">Select Year</label><select id="year" name="year" class="input" required>@foreach(range(now()->year-2,now()->year+2) as $year)<option value="{{ $year }}" @selected((int)old('year',now()->year)===$year)>{{ $year }}</option>@endforeach</select></div>
      <div><label class="iq-label" for="due_day">Due Day</label><select id="due_day" name="due_day" class="input">@foreach(range(1,28) as $day)<option value="{{ $day }}" @selected((int)old('due_day',10)===$day)>{{ $day }}</option>@endforeach</select></div>
    </div>
    <p class="iq-note">Class/grade confirmation is provided during invoice review. Family invoices include every sibling in the same department, campus, currency, and month.</p>
    <div class="iq-create-actions"><button type="submit" class="iq-green">Create Fee</button><a class="iq-outline" href="/invoices">Review / Print Invoices</a><a class="iq-whatsapp" href="{{ url('/invoices?send_mode=whatsapp') }}">WhatsApp Invoices</a><a class="iq-cyan" href="{{ url('/invoices?send_mode=email') }}">Email Invoices</a></div>
  </form>
</div>
<script>
(()=>{const months=[...document.querySelectorAll('input[name="months[]"]')];const types=document.getElementById('iq-types');const toggle=document.getElementById('fee-more');toggle.addEventListener('click',()=>{types.classList.toggle('expanded');toggle.textContent=types.classList.contains('expanded')?'Show Less':'Show More'});document.getElementById('iq-hold').addEventListener('click',()=>{months.forEach(x=>x.checked=false)});document.getElementById('iq-unhold').addEventListener('click',()=>{months.forEach(x=>x.checked=true)});document.getElementById('invoice-creator').addEventListener('submit',e=>{if(!months.some(x=>x.checked)){e.preventDefault();alert('Select at least one month.')}})})();
</script>
@endsection
