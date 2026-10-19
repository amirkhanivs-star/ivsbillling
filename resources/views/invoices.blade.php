@extends('layouts.app')
@section('title','Invoice Review & Sending')
@section('content')
<link rel="stylesheet" href="{{ asset('assets/iqra-challan.css') }}">
<div class="panel">
<h2>Generated Invoices · Review Before Sending</h2>
<p class="muted">Generating an invoice does not send it. Select invoices, review all included grades and recipients, then approve WhatsApp or email delivery through n8n.</p>
@if(in_array(request('send_mode'),['whatsapp','email'],true))
<div class="iq-confirm"><strong>{{ request('send_mode')==='email' ? 'Email' : 'WhatsApp' }} invoice sending:</strong> Filter by grade, department, or month, then review the selected family invoices. Paid invoices are excluded from sending. Confirm any sibling grades in the selected family invoices before queuing delivery.</div>
@endif
<form method="get" class="iq-filter-row">
@if(in_array(request('send_mode'),['whatsapp','email'],true))<input type="hidden" name="send_mode" value="{{ request('send_mode') }}">@endif
<select class="input" name="department_id"><option value="">All Departments</option>@foreach($departments as $d)<option value="{{ $d->id }}" @selected(request('department_id')==$d->id)>{{ $d->name }}</option>@endforeach</select>
<select class="input" name="grade"><option value="">All Grades</option>@foreach($gradeOptions as $grade)<option value="{{ $grade }}" @selected(request('grade')===$grade)>{{ $grade }}</option>@endforeach</select>
<input class="input" type="month" name="period" value="{{ request('period') }}">
@if(!in_array(request('send_mode'),['whatsapp','email'],true))<label><input type="checkbox" name="paid_only" value="1" @checked(request('paid_only'))> Paid invoices only</label>@endif
<button class="btn">Apply Filter</button><a href="{{ in_array(request('send_mode'),['whatsapp','email'],true) ? '/invoices?send_mode='.request('send_mode') : '/invoices' }}" class="btn gray">Reset</a>
</form>
<form method="post" action="/invoices/send" id="iq-send-form">@csrf
@if(in_array(request('send_mode'),['whatsapp','email'],true))<input type="hidden" name="send_mode" value="{{ request('send_mode') }}"><input type="hidden" name="selected_grade" value="{{ request('grade') }}">@endif
<div class="iq-send-controls">
@if(request('send_mode')==='whatsapp')
<label><input type="checkbox" name="channels[]" value="whatsapp" checked> WhatsApp via n8n</label>
@elseif(request('send_mode')==='email')
<label><input type="checkbox" name="channels[]" value="email" checked> Email via n8n</label>
@else
<label><input type="checkbox" name="channels[]" value="whatsapp"> WhatsApp via n8n</label>
<label><input type="checkbox" name="channels[]" value="email"> Email via n8n</label>
@endif
<button class="btn purple" type="submit">Confirm Selected Invoices</button><span class="muted">Verified contact is required; WhatsApp also requires opt-in.</span></div>
<div class="table-wrap"><table class="table iq-invoice-review"><thead><tr><th><input id="invoice-select-all" type="checkbox" aria-label="Select all invoices"></th><th>Invoice</th><th>Family</th><th>Department</th><th>Grade(s) included</th><th>Registration / GR No.</th><th>Period</th><th>Amount</th><th>Paid</th><th>Status</th><th>WhatsApp</th><th>PDF</th></tr></thead><tbody>
@forelse($invoices as $i)
@php($grades=$gradesByInvoice->get($i->id,[]))
<tr><td><input type="checkbox" class="invoice-check" name="invoice_ids[]" checked value="{{ $i->id }}" data-invoice="{{ $i->invoice_number }}" data-grades="{{ implode(' | ',$grades) }}" data-status="{{ $i->status }}"></td><td>{{ $i->invoice_number }}</td><td>{{ $i->family_code ?: 'Unnumbered family #'.$i->family_id }}</td><td>{{ $i->department_name }}</td><td><span class="iq-invoice-grades">{{ count($grades)?implode(', ',$grades):'Not assigned' }}</span></td><td>{{ implode(', ', $grByInvoice->get($i->id,[])) ?: '—' }}</td><td>{{ $i->period_start }}</td><td>{{ $i->currency }} {{ \App\Services\Money::format($i->total_minor) }}</td><td>{{ \App\Services\Money::format($i->paid_minor) }}</td><td>{{ ucfirst($i->status) }}</td><td>{{ $i->whatsapp_status??'Not sent' }}</td><td><a href="/invoices/{{ $i->id }}/pdf" target="_blank" rel="noopener" class="btn small">View PDF</a></td></tr>
@empty<tr><td colspan="12">No eligible invoices match these filters. Paid invoices remain available in the general review page, but are not sent as payment demands.</td></tr>@endforelse
</tbody></table></div>
<div class="iq-confirm" id="send-preview" role="status" aria-live="polite">Selected invoices and all included grades appear here. Deselect any invoice you do not want to send.</div>
<div id="grade-confirmations"></div>
<label style="display:block;margin:12px 0"><input type="checkbox" name="review_confirmed" value="1" required> I reviewed the invoice PDFs, all included grades, and recipients, and approve sending the selected invoices.</label>
<button class="btn purple" type="submit">Confirm & Queue Selected</button>
</form>
{{ $invoices->links() }}
</div>
<script>
(()=>{const filter=document.querySelector('.iq-filter-row');filter.querySelectorAll('select,input[type=month],input[name=paid_only]').forEach(control=>control.addEventListener('change',()=>filter.requestSubmit()));const boxes=[...document.querySelectorAll('.invoice-check')],all=document.getElementById('invoice-select-all'),summary=document.getElementById('send-preview'),out=document.getElementById('grade-confirmations'),form=document.getElementById('iq-send-form');function update(){const chosen=boxes.filter(b=>b.checked),grades=[...new Set(chosen.flatMap(b=>b.dataset.grades.split(' | ').filter(Boolean)))].sort();summary.textContent=chosen.length?`${chosen.length} invoice(s) selected: ${chosen.map(b=>b.dataset.invoice+' ['+(b.dataset.grades||'unassigned grade')+']').join(', ')}`:'Select invoices to preview the grades to be sent.';out.replaceChildren();if(grades.length){const heading=document.createElement('strong');heading.textContent='Confirm every grade in the selected family invoices:';out.append(heading);grades.forEach(g=>{const label=document.createElement('label');label.style.cssText='display:inline-flex;align-items:center;gap:5px;margin:8px 12px 4px 0';const checkbox=document.createElement('input');checkbox.type='checkbox';checkbox.name='confirmed_grades[]';checkbox.value=g;label.append(checkbox,document.createTextNode(g));checkbox.checked=true;out.append(label)})}else if(chosen.length){const label=document.createElement('label');const cb=document.createElement('input');cb.type='checkbox';cb.name='confirmed_grades[]';cb.value='Not assigned';cb.checked=true;label.append(cb,document.createTextNode(' Confirm invoices without assigned grade'));out.append(label)}}boxes.forEach(b=>b.addEventListener('change',update));all.checked=boxes.length>0;all.addEventListener('change',()=>{boxes.forEach(b=>b.checked=all.checked);update()});form.addEventListener('submit',e=>{const chosen=boxes.filter(b=>b.checked),grades=[...out.querySelectorAll('input[name="confirmed_grades[]"]')];if(!chosen.length||!grades.length||grades.some(b=>!b.checked)){e.preventDefault();alert('Select invoices and confirm every included grade before sending.');return}const channels=[...form.querySelectorAll('input[name="channels[]"]')].filter(c=>c.checked).map(c=>c.value);if(!channels.length||!confirm(`Queue ${chosen.length} selected invoice(s) via ${channels.join(' and ')}?\nGrades: ${grades.map(g=>g.value).join(', ')}\nReview the PDFs first. Sending requires verified recipients and consent.`)){e.preventDefault()}});update()})();
</script>
@endsection
