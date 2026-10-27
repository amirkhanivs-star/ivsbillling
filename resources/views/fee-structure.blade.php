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
      <button class="fc-round" type="button" data-open="fc-add" title="Add Fee Rate" aria-label="Add fee">+</button>
      <button class="fc-round" type="button" onclick="location.reload()" title="Refresh">⟳</button>
      <button class="fc-round" type="button" data-open="fc-increment" title="Fee Increment">↗</button>
    </div>

    <div class="fc-toolbar" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
      <form method="GET" action="{{ url('/manage-fee') }}" style="flex:1;min-width:240px;display:flex;gap:6px;">
        <input type="hidden" name="type" value="{{ $selectedType }}">
        <input name="search" placeholder="⌕   Search class..." value="{{ request('search') }}">
        <button type="submit">Search</button>
      </form>
      <button type="button" data-open="fc-add" style="background:#7e6ae1;color:#fff;border:none;border-radius:4px;padding:10px 18px;font-size:12px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
        + Add Fee to {{ $currentType->name ?? 'Portion' }}
      </button>
    </div>

    <div class="fc-tablewrap">
      <table class="fc-table">
        <thead>
          <tr>
            <th>CLASS</th>
            <th>FEETYPE</th>
            <th>AMOUNT</th>
            <th>ACTIONS</th>
          </tr>
        </thead>
        <tbody>
          @forelse($rates as $rate)
          <tr>
            <td><strong style="color:#2f2c4a;">{{ $rate->class_label }}</strong></td>
            <td><span class="fc-tag">{{ $rate->type_name }}</span></td>
            <td>
              <strong style="color:#2d294a;font-size:13px;">{{ \App\Services\Money::format($rate->amount_minor) }}</strong>
              <small style="color:#7869db;font-weight:600;margin-left:4px;">{{ $rate->currency }}</small>
            </td>
            <td style="white-space: nowrap;">
              <button class="fc-edit" type="button" data-edit-id="{{ $rate->id }}" data-edit-label="{{ $rate->class_label }}" data-edit-amount="{{ \App\Services\Money::format($rate->amount_minor) }}" title="Edit class fee">✎</button>
              <form method="POST" action="{{ route('fee.catalog.destroy', $rate->id) }}" style="display:inline-block; margin-left:6px;" onsubmit="return confirm('Delete / Reset fee rate for {{ $rate->class_label }} to 0.00?');">
                @csrf
                @method('DELETE')
                <button class="fc-delete" type="submit" title="Delete / Reset to 0">🗑</button>
              </form>
            </td>
          </tr>
          @empty
          <tr>
            <td colspan="4" class="fc-empty">
              No class fee rates configured yet for <strong>{{ $currentType->name ?? 'this portion' }}</strong>.
              Click <strong>+ Add Fee to {{ $currentType->name ?? 'Portion' }}</strong> to configure admission or class rates.
            </td>
          </tr>
          @endforelse
        </tbody>
      </table>
    </div>
    <div class="fc-pagination">{{ $rates->links() }}</div>
  </section>
  <details class="fc-existing"><summary>Existing student fee plans ({{ $plans->count() }} shown)</summary><table class="fc-table"><thead><tr><th>STUDENT</th><th>CLASS</th><th>AMOUNT</th></tr></thead><tbody>@foreach($plans as $plan)<tr><td>{{ $plan->name }} · GR {{ $plan->gr_number }}</td><td>{{ $plan->grade_label ?? '—' }}</td><td>{{ $plan->currency }} {{ \App\Services\Money::format($plan->amount_minor) }}</td></tr>@endforeach</tbody></table><p>These are individually assigned fees. Adding a class rate will not overwrite them.</p></details>
</div>

<div class="fc-overlay" id="fc-overlay" hidden></div>

<aside class="fc-drawer" id="fc-add" role="dialog" aria-modal="true" aria-labelledby="fc-add-title" hidden>
  <header>
    <strong id="fc-add-title">Add Fee to {{ $currentType->name ?? 'Portion' }}</strong>
    <button type="button" data-close>×</button>
  </header>
  <form method="POST" action="{{ url('/manage-fee/catalog') }}">
    @csrf
    <label>Fee Type (Portion) *
      <select name="fee_type_id" id="fc-fee-type-select" required>
        @foreach($types as $type)
          <option value="{{ $type->id }}" data-code="{{ $type->code }}" @selected((int)$type->id === (int)$selectedType)>{{ $type->name }}</option>
        @endforeach
        <option value="new">+ Create New Fee Type...</option>
      </select>
    </label>

    <div id="fc-new-type-wrap" style="display:none;">
      <label>New Fee Type Title *
        <input type="text" name="title" id="fc-new-title" maxlength="180" placeholder="Enter new fee portion name">
      </label>
    </div>

    <!-- Grade / Class selection -->
    <div style="background:#f7f6fd;border:1px solid #e1ddf6;border-radius:6px;padding:12px;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
        <span style="font-size:12px;font-weight:600;color:#5a5477;">Grade / Class</span>
        <label style="font-size:11px;font-weight:600;color:#7e6ae1;cursor:pointer;display:inline-flex;align-items:center;gap:4px;">
          <input type="checkbox" id="fc-multi-toggle" name="multi_mode" value="1"> Apply to Multiple Grades
        </label>
      </div>

      <!-- Single Class Input with Autocomplete / Datalist -->
      <div id="fc-single-class-wrap">
        <label style="margin:0;font-size:11px;color:#6d6787;">Select or Type Grade *
          <input type="text" name="class_label" id="fc-class-input" list="fc-classes-datalist" placeholder="e.g. FS - 1, FS - 1 - A, 2 A, GED..." style="margin-top:4px;" required>
        </label>
        <datalist id="fc-classes-datalist">
          @foreach($classes as $c)
            <option value="{{ $c }}">{{ $c }}</option>
          @endforeach
        </datalist>
      </div>

      <!-- Multi Class Checkbox List -->
      <div id="fc-multi-class-wrap" style="display:none;margin-top:6px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
          <span style="font-size:11px;color:#777;">Check grades to apply:</span>
          <div>
            <button type="button" id="fc-btn-select-all" style="font-size:11px;background:none;border:none;color:#7e6ae1;cursor:pointer;text-decoration:underline;">Select All</button>
            <span style="color:#ccc;">|</span>
            <button type="button" id="fc-btn-deselect-all" style="font-size:11px;background:none;border:none;color:#7e6ae1;cursor:pointer;text-decoration:underline;">Deselect</button>
          </div>
        </div>
        <div style="max-height:160px;overflow-y:auto;background:#fff;border:1px solid #dcd7ee;border-radius:4px;padding:8px;display:grid;grid-template-columns:1fr 1fr;gap:4px;">
          @foreach($classes as $c)
            <label style="font-size:11px;display:flex;align-items:center;gap:5px;cursor:pointer;margin:0;">
              <input type="checkbox" name="class_labels[]" value="{{ $c }}" class="fc-class-chk"> {{ $c }}
            </label>
          @endforeach
        </div>
      </div>
    </div>

    <label>Fee Amount *
      <input type="number" name="amount" min="0" step="0.01" value="0.00" placeholder="e.g. 250.00" required>
    </label>

    <label>Recurrence *
      <select name="frequency" id="fc-frequency-select" required>
        <option value="one_time" @selected(optional($currentType)->code !== 'monthly')>One Time</option>
        <option value="monthly" @selected(optional($currentType)->code === 'monthly')>Monthly</option>
        <option value="weekly">Weekly</option>
        <option value="quarterly">Quarterly</option>
        <option value="annual">Yearly</option>
        <option value="custom">Custom</option>
      </select>
    </label>

    <label>Department *
      <select name="department_id" required>
        @foreach($departments as $department)
          <option value="{{ $department->id }}">{{ $department->name }}</option>
        @endforeach
      </select>
    </label>

    <label>Currency *
      <select name="currency">
        <option value="SAR" selected>SAR</option>
        <option value="PKR">PKR</option>
        <option value="USD">USD</option>
        <option value="AED">AED</option>
        <option value="GBP">GBP</option>
        <option value="EUR">EUR</option>
      </select>
    </label>

    <button class="fc-save" type="submit">Save Fee Rate</button>
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
    <p id="fc-edit-class" style="font-weight:600;color:#39364f;margin:0 0 10px;"></p>
    <label>Fee amount *<input type="number" name="amount" id="fc-edit-amount" min="0" step="0.01" required></label>
    <label>Reason (optional)<input type="text" name="reason" maxlength="255"></label>
    <button class="fc-save">Save</button>
  </form>
</div>

<script>
(() => {
  const overlay = document.getElementById('fc-overlay');
  const dialogs = [...document.querySelectorAll('.fc-drawer,.fc-modal')];
  const close = () => { dialogs.forEach(d => d.hidden = true); overlay.hidden = true; };
  const open = id => { close(); document.getElementById(id).hidden = false; overlay.hidden = false; };
  document.querySelectorAll('[data-open]').forEach(b => b.addEventListener('click', () => open(b.dataset.open)));
  document.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', close));
  overlay.addEventListener('click', close);
  document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });

  // Edit modal
  document.querySelectorAll('[data-edit-id]').forEach(b => b.addEventListener('click', () => {
    document.getElementById('fc-edit-form').action = '/manage-fee/catalog/' + encodeURIComponent(b.dataset.editId);
    document.getElementById('fc-edit-class').textContent = b.dataset.editLabel;
    document.getElementById('fc-edit-amount').value = b.dataset.editAmount.replace(/,/g, '');
    open('fc-edit');
  }));

  // Fee Type selector handling
  const feeTypeSelect = document.getElementById('fc-fee-type-select');
  const newTypeWrap = document.getElementById('fc-new-type-wrap');
  const newTitleInput = document.getElementById('fc-new-title');
  const freqSelect = document.getElementById('fc-frequency-select');

  if (feeTypeSelect) {
    feeTypeSelect.addEventListener('change', () => {
      if (feeTypeSelect.value === 'new') {
        newTypeWrap.style.display = 'block';
        newTitleInput.required = true;
      } else {
        newTypeWrap.style.display = 'none';
        newTitleInput.required = false;
        const opt = feeTypeSelect.options[feeTypeSelect.selectedIndex];
        if (opt && opt.dataset.code === 'monthly') {
          freqSelect.value = 'monthly';
        } else {
          freqSelect.value = 'one_time';
        }
      }
    });
  }

  // Multi-grade toggle handling
  const multiToggle = document.getElementById('fc-multi-toggle');
  const singleWrap = document.getElementById('fc-single-class-wrap');
  const multiWrap = document.getElementById('fc-multi-class-wrap');
  const classInput = document.getElementById('fc-class-input');

  if (multiToggle) {
    multiToggle.addEventListener('change', () => {
      if (multiToggle.checked) {
        singleWrap.style.display = 'none';
        multiWrap.style.display = 'block';
        classInput.required = false;
      } else {
        singleWrap.style.display = 'block';
        multiWrap.style.display = 'none';
        classInput.required = true;
      }
    });
  }

  // Select all / deselect all
  const btnSelectAll = document.getElementById('fc-btn-select-all');
  const btnDeselectAll = document.getElementById('fc-btn-deselect-all');
  if (btnSelectAll && btnDeselectAll) {
    btnSelectAll.addEventListener('click', () => {
      document.querySelectorAll('.fc-class-chk').forEach(c => c.checked = true);
    });
    btnDeselectAll.addEventListener('click', () => {
      document.querySelectorAll('.fc-class-chk').forEach(c => c.checked = false);
    });
  }
})();
</script>
@endsection
