@extends('layouts.app')
@section('title', 'Fee Structure')
@section('content')
<link rel="stylesheet" href="{{ asset('assets/iqra-fee-catalog.css') }}">
<div class="fc-shell">
  @if(session('notice'))<div class="fc-notice">{{session('notice')}}</div>@endif
  @if($errors->any())<div class="fc-error" role="alert">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
  <section class="fc-panel">
    <div class="fc-chips">
      @foreach($types as $type)
        <a class="fc-chip {{ (int)$selectedType === (int)$type->id ? 'active' : '' }}" href="{{ url('/manage-fee') }}?type={{ $type->id }}">
          {{ $type->name }}
          @if(!in_array($type->code, ['monthly', 'registration', 'admission', 'books', 'exam', 'slc_charges', 'custom'], true))
            <form method="POST" action="{{ route('fee.types.destroy', $type->id) }}" style="display:inline;margin:0;padding:0;" onsubmit="return confirm('Delete fee type \'{{ $type->name }}\' and all its rates?');">
              @csrf
              @method('DELETE')
              <button type="submit" style="background:none;border:none;color:#ea5455;cursor:pointer;padding:0 0 0 6px;font-size:12px;font-weight:bold;" title="Delete this fee type">×</button>
            </form>
          @else
            <span>⌄</span>
          @endif
        </a>
      @endforeach
      <button class="fc-round" type="button" data-open="fc-add" title="Add Fee" aria-label="Add fee">+</button>
      <button class="fc-round" type="button" onclick="location.reload()" title="Refresh">⟳</button>
      <button class="fc-round" type="button" data-open="fc-increment" title="Fee Increment">↗</button>
    </div>
    <div class="fc-toolbar"><form method="GET" action="{{ url('/manage-fee') }}"><input type="hidden" name="type" value="{{ $selectedType }}"><input name="search" placeholder="⌕   Search class..." value="{{ request('search') }}"><button type="submit">Search</button></form></div>
    <div class="fc-tablewrap"><table class="fc-table"><thead><tr><th>CLASS</th><th>FEE TYPE</th><th>AMOUNT</th><th>CURRENCY</th><th>RECURRENCE</th><th>ACTIONS</th></tr></thead><tbody>
      @forelse($rates as $rate)
      <tr>
        <td><span class="fc-tag">{{ $rate->class_label }}</span></td>
        <td><span class="fc-tag">{{ $rate->type_name }}</span></td>
        <td><span class="fc-tag">{{ \App\Services\Money::format($rate->amount_minor) }}</span></td>
        <td>{{ $rate->currency }}</td>
        <td>{{ ucfirst(str_replace('_',' ',$rate->frequency)) }}</td>
        <td style="white-space: nowrap;">
          <button class="fc-edit" type="button" data-edit-id="{{ $rate->id }}" data-edit-label="{{ $rate->class_label }}" data-edit-amount="{{ \App\Services\Money::format($rate->amount_minor) }}" title="Edit class fee">✎</button>
          <form method="POST" action="{{ route('fee.catalog.destroy', $rate->id) }}" style="display:inline-block; margin-left:6px;" onsubmit="return confirm('Delete / Reset fee rate for {{ $rate->class_label }} to 0.00?');">
            @csrf
            @method('DELETE')
            <button class="fc-delete" type="submit" title="Delete / Reset to 0">🗑</button>
          </form>
        </td>
      </tr>
      @empty<tr><td colspan="6" class="fc-empty">No class fee rates configured yet. Select + to add one. Existing student fee plans remain available below.</td></tr>
      @endforelse
    </tbody></table></div>
    <div class="fc-pagination">{{ $rates->links() }}</div>
  </section>
  <details class="fc-existing"><summary>Existing student fee plans ({{ $plans->count() }} shown)</summary><table class="fc-table"><thead><tr><th>STUDENT</th><th>CLASS</th><th>AMOUNT</th></tr></thead><tbody>@foreach($plans as $plan)<tr><td>{{ $plan->name }} · GR {{ $plan->gr_number }}</td><td>{{ $plan->grade_label ?? '—' }}</td><td>{{ $plan->currency }} {{ \App\Services\Money::format($plan->amount_minor) }}</td></tr>@endforeach</tbody></table><p>These are individually assigned fees. Adding a class rate will not overwrite them.</p></details>
</div>
<div class="fc-overlay" id="fc-overlay" hidden></div>
<aside class="fc-drawer" id="fc-add" role="dialog" aria-modal="true" aria-labelledby="fc-add-title" hidden>
  <header><strong id="fc-add-title">Add Fee</strong><button type="button" data-close>×</button></header>
  <form method="POST" action="{{ url('/manage-fee/catalog') }}">@csrf
    <label>Fee Title *<input type="text" name="title" maxlength="180" placeholder="Enter fee title" required></label>
    <label>Fee amount *<input type="number" name="amount" min="0" step="0.01" value="0.00" required></label>
    <label>Recurrence *<select name="frequency" required><option value="one_time">One Time</option><option value="monthly" selected>Monthly</option><option value="weekly">Weekly</option><option value="quarterly">Quarterly</option><option value="annual">Yearly</option><option value="custom">Custom</option></select></label>
    <label>Department *<select name="department_id" required>@foreach($departments as $department)<option value="{{ $department->id }}">{{ $department->name }}</option>@endforeach</select></label>
    <label>Class *<select name="class_label" required><option value="">Select class</option>@foreach($classes as $class)<option value="{{ $class }}">{{ $class }}</option>@endforeach</select></label>
    <label>Currency *<select name="currency"><option value="SAR">SAR</option><option value="PKR">PKR</option><option value="USD">USD</option><option value="AED">AED</option><option value="GBP">GBP</option><option value="EUR">EUR</option></select></label>
    <button class="fc-save" type="submit">Save</button>
  </form>
</aside>
<div class="fc-modal" id="fc-increment" role="dialog" aria-modal="true" aria-labelledby="fc-increment-title" hidden>
  <header><strong id="fc-increment-title">Fee Increment</strong><button type="button" data-close>×</button></header>
  <form method="POST" action="{{ url('/manage-fee/increment') }}">@csrf
    <label>Fee Type *<select name="fee_type_id" required>@foreach($types as $type)<option value="{{ $type->id }}" @selected($type->id == $selectedType)>{{ $type->name }}</option>@endforeach</select></label>
    <label>Classes *<select name="class_labels[]" multiple size="8" required>@foreach($classes as $class)<option value="{{ $class }}">{{ $class }}</option>@endforeach</select><small>Use Ctrl (or Command) to select multiple classes.</small></label>
    <label>Increment amount per student *<input type="number" name="amount" min="0.01" step="0.01" required></label>
    <label>Reason (optional)<input type="text" name="reason" maxlength="255" placeholder="Fee adjustment reason"></label>
    <p class="fc-warning">Updates active student fee plans in the chosen classes. Previously issued invoices are unchanged. An audit record is kept.</p>
    <button class="fc-save" type="submit" onclick="return confirm('Apply this fee increase to the selected active student plans?')">Apply</button>
  </form>
</div>
<div class="fc-modal" id="fc-edit" role="dialog" aria-modal="true" aria-labelledby="fc-edit-title" hidden>
  <header><strong id="fc-edit-title">Edit Class Fee</strong><button type="button" data-close>×</button></header>
  <form id="fc-edit-form" method="POST" action="">@csrf @method('PUT')
    <p id="fc-edit-class"></p><label>Fee amount *<input type="number" name="amount" id="fc-edit-amount" min="0" step="0.01" required></label><label>Reason (optional)<input type="text" name="reason" maxlength="255"></label><button class="fc-save">Save</button>
  </form>
</div>
<script>
(() => {
  const overlay=document.getElementById('fc-overlay');
  const dialogs=[...document.querySelectorAll('.fc-drawer,.fc-modal')];
  const close=()=>{dialogs.forEach(d=>d.hidden=true);overlay.hidden=true;};
  const open=id=>{close();document.getElementById(id).hidden=false;overlay.hidden=false;};
  document.querySelectorAll('[data-open]').forEach(b=>b.addEventListener('click',()=>open(b.dataset.open)));
  document.querySelectorAll('[data-close]').forEach(b=>b.addEventListener('click',close));
  overlay.addEventListener('click',close);
  document.addEventListener('keydown',e=>{if(e.key==='Escape')close();});
  document.querySelectorAll('[data-edit-id]').forEach(b=>b.addEventListener('click',()=>{
    document.getElementById('fc-edit-form').action='/manage-fee/catalog/'+encodeURIComponent(b.dataset.editId);
    document.getElementById('fc-edit-class').textContent=b.dataset.editLabel;
    document.getElementById('fc-edit-amount').value=b.dataset.editAmount.replace(/,/g,'');open('fc-edit');
  }));
})();
</script>
@endsection
