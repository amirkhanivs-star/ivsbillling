@extends('layouts.app')
@section('title','Registration / Admission')
@section('content')
<link rel="stylesheet" href="{{ asset('assets/student-admission.css') }}">
<div class="adm-container">
  <a class="btn gray adm-back" href="/manage-student">← Back</a>
  <nav class="adm-steps" aria-label="Student registration sections"><span class="on">▣ Student</span><span>♧ Family</span><span>▣ Info</span><span>◉ Admission</span><span>♢ Documents</span><span>▤ WhatsApp</span><span>▣ Custom Fees</span><span>▤ Subjects</span></nav>
  @if($errors->any())<div class="adm-errors" role="alert"><strong>Please correct these fields:</strong><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif
  <form method="post" action="{{ isset($editingStudent) ? route('student.admission.update',$editingStudent) : route('student.admission.store') }}">@csrf
    @if(isset($editingStudent)) @method('PUT')<div class="adm-errors" style="background:#f5f3ff;color:#4d467c;border-color:#e4dfff">Editing saved registration. Fees, departments and currency are read-only here to protect existing invoices. Manage fee plan changes separately.</div>@endif
    <div class="adm-columns">
      <section class="adm-panel"><div class="adm-upload" aria-label="Student photo upload is not yet enabled">▧<small>Photo upload in a later phase</small></div><h3>Student Information</h3><div class="adm-fields"><div class="adm-field"><label for="name">Name *</label><input id="name" name="name" type="text" value="{{ old('name', $initial['name'] ?? null) }}" placeholder="Enter name" required></div>
<div class="adm-field"><label for="father_name">Father name *</label><input id="father_name" name="father_name" type="text" value="{{ old('father_name', $initial['father_name'] ?? null) }}" placeholder="Enter father name" required></div>
<div class="adm-field"><label for="gender">Gender</label><select id="gender" name="gender"><option value="">Select gender</option><option value="Male" @selected(old('gender', $initial['gender'] ?? null)==='Male')>Male</option><option value="Female" @selected(old('gender', $initial['gender'] ?? null)==='Female')>Female</option><option value="Other" @selected(old('gender', $initial['gender'] ?? null)==='Other')>Other</option></select></div>
<div class="adm-field"><label for="dob">Date of Birth</label><input id="dob" name="dob" type="date" value="{{ old('dob', $initial['dob'] ?? null) }}" placeholder=""></div>
<div class="adm-field"><label for="surname">Surname</label><input id="surname" name="surname" type="text" value="{{ old('surname', $initial['surname'] ?? null) }}" placeholder=""></div>
<div class="adm-field"><label for="religion">Religion</label><select id="religion" name="religion"><option value="">Select religion</option><option value="Islam" @selected(old('religion', $initial['religion'] ?? null)==='Islam')>Islam</option><option value="Christianity" @selected(old('religion', $initial['religion'] ?? null)==='Christianity')>Christianity</option><option value="Hinduism" @selected(old('religion', $initial['religion'] ?? null)==='Hinduism')>Hinduism</option><option value="Other" @selected(old('religion', $initial['religion'] ?? null)==='Other')>Other</option></select></div>
<div class="adm-field">
  <label for="guardian_name">
    Guardian Name
    @if(!empty($initial['is_guardian_override']))
      <span style="font-size:11px;color:#6366f1;font-weight:600;">(Individual Override)</span>
    @elseif(!empty($initial['family_guardian_name']))
      <span style="font-size:11px;color:#64748b;font-weight:normal;">(Shared Family: {{ $initial['family_guardian_name'] }})</span>
    @endif
  </label>
  <input id="guardian_name" name="guardian_name" value="{{ old('guardian_name', $initial['guardian_name'] ?? null) }}" placeholder="Enter guardian name">
</div>
<div class="adm-field"><label for="family_email">Guardian / Family Email *</label><input id="family_email" name="family_email" type="email" value="{{ old('family_email', $initial['family_email'] ?? null) }}" placeholder="Parent/guardian email" required></div>
<div class="adm-field adm-wide"><label for="family_whatsapp">Guardian WhatsApp *</label><input id="family_whatsapp" name="family_whatsapp" type="tel" inputmode="tel" value="{{ old('family_whatsapp', $initial['family_whatsapp'] ?? null) }}" placeholder="+923001234567" pattern="\+[1-9][0-9]{7,14}" title="International format with +, without spaces" required><small class="adm-help">International format, such as +923001234567 (no spaces).</small></div>
<div class="adm-field adm-wide" style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:6px; padding:12px; margin-top:6px;">
  <div style="font-weight:700; font-size:12px; color:#1e293b; margin-bottom:8px; text-transform:uppercase; letter-spacing:0.5px;">Family Contact Verification &amp; Outbound Messaging Eligibility</div>
  <div style="display:flex; flex-direction:column; gap:8px;">
    <label style="display:inline-flex; align-items:flex-start; gap:8px; font-size:13px; font-weight:normal; cursor:pointer;">
      <input type="checkbox" name="contact_verified" value="1" @checked(old('contact_verified', $initial['contact_verified'] ?? false)) style="margin-top:3px;">
      <span><strong>Contacts Verified</strong> — Staff confirms this phone number and email have been verified directly with the student's authorized guardian.</span>
    </label>
    <label style="display:inline-flex; align-items:flex-start; gap:8px; font-size:13px; font-weight:normal; cursor:pointer;">
      <input type="checkbox" name="messaging_opt_in" value="1" @checked(old('messaging_opt_in', $initial['messaging_opt_in'] ?? false)) style="margin-top:3px;">
      <span><strong>WhatsApp Consent Recorded</strong> — Guardian explicitly consented to receive invoices, fee demands, and payment receipts via WhatsApp.</span>
    </label>
  </div>
  <small class="adm-help" style="margin-top:8px; display:block; color:#64748b; font-size:11px;">
    Note: Both verification and WhatsApp consent are required before outbound webhook notifications can be dispatched. When phone or email is changed, reverification is enforced.
  </small>
</div>
<div class="adm-field adm-wide"><label for="last_institute">Last Institute</label><input id="last_institute" name="last_institute" type="text" value="{{ old('last_institute', $initial['last_institute'] ?? null) }}" placeholder=""></div>
<div class="adm-field adm-wide"><label for="dob_words">Date of birth in words</label><input id="dob_words" name="dob_words" type="text" value="{{ old('dob_words', $initial['dob_words'] ?? null) }}" placeholder=""></div>
<div class="adm-field adm-wide"><label for="place_of_birth">Place of birth</label><input id="place_of_birth" name="place_of_birth" type="text" value="{{ old('place_of_birth', $initial['place_of_birth'] ?? null) }}" placeholder=""></div>
<div class="adm-field"><label for="mother_tongue">Mother Tongue</label><input id="mother_tongue" name="mother_tongue" type="text" value="{{ old('mother_tongue', $initial['mother_tongue'] ?? null) }}" placeholder=""></div>
<div class="adm-field"><label for="nationality">Nationality</label><input id="nationality" name="nationality" type="text" value="{{ old('nationality', $initial['nationality'] ?? null) }}" placeholder=""></div>
<div class="adm-field adm-wide"><label for="area">Area</label><input id="area" name="area" type="text" value="{{ old('area', $initial['area'] ?? null) }}" placeholder=""></div>
<div class="adm-field adm-wide"><label for="current_address">Current Address</label><input id="current_address" name="current_address" type="text" value="{{ old('current_address', $initial['current_address'] ?? null) }}" placeholder=""></div>
<div class="adm-field adm-wide"><label for="permanent_address">Permanent Address</label><input id="permanent_address" name="permanent_address" type="text" value="{{ old('permanent_address', $initial['permanent_address'] ?? null) }}" placeholder=""></div>
<div class="adm-field adm-wide"><label for="physical_handicap">Physical Handicap</label><input id="physical_handicap" name="physical_handicap" type="text" value="{{ old('physical_handicap', $initial['physical_handicap'] ?? null) }}" placeholder=""></div>
<div class="adm-field adm-wide"><label for="student_email">Student Email Address</label><input id="student_email" name="student_email" type="email" value="{{ old('student_email', $initial['student_email'] ?? null) }}" placeholder=""></div></div></section>
      <section class="adm-panel"><h3>Registration Information</h3><div class="adm-fields"><div class="adm-field"><label for="registration_number">Registration number *</label><input id="registration_number" name="registration_number" type="text" value="{{ old('registration_number', $initial['registration_number'] ?? null) }}" placeholder="Unique GR / registration number" required aria-describedby="reg-number-feedback" class="@error('registration_number') adm-invalid @enderror"><small id="reg-number-feedback" class="adm-feedback" aria-live="polite">@error('registration_number'){{ $message }}@enderror</small></div>
<div class="adm-field"><label for="family_code">Family Number (optional)</label><input id="family_code" name="family_code" type="text" value="{{ old('family_code', $initial['family_code'] ?? null) }}" placeholder="Leave empty if not assigned"></div>

<div class="adm-field"><label for="campus_id">Campus *</label><select id="campus_id" name="campus_id" required><option value="">Select campus</option>@foreach($campuses as $campus)<option value="{{ $campus->id }}" @selected(old('campus_id', $initial['campus_id'] ?? null)==$campus->id)>{{ $campus->name }}</option>@endforeach</select></div>
<div class="adm-field"><label for="department_id">Department *</label><select id="department_id" name="department_id" required><option value="">Select department</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(old('department_id', $initial['department_id'] ?? null)==$department->id)>{{ $department->name }}</option>@endforeach</select></div>
<div class="adm-field" id="tuition-subject-row" hidden><label for="subject_name">Tuition subject (optional)</label><input id="subject_name" name="subject_name" value="{{ old('subject_name', $initial['subject_name'] ?? null) }}"></div>
<div class="adm-field" id="quran-days-row" hidden><label for="class_days_per_week">Quran class days/week (optional)</label><input type="number" min="1" max="7" id="class_days_per_week" name="class_days_per_week" value="{{ old('class_days_per_week', $initial['class_days_per_week'] ?? null) }}"></div>
<div class="adm-field"><label for="session">Session</label><select id="session" name="session"><option value="">Select session</option>@foreach($sessions as $session)<option value="{{ $session }}" @selected(old('session', $initial['session'] ?? $defaultSession)===$session)>{{ $session }}</option>@endforeach</select></div>
<div class="adm-field"><label for="registration_date">Date of Registration</label><input id="registration_date" name="registration_date" type="date" value="{{ old('registration_date', $initial['registration_date'] ?? null) }}" placeholder=""></div>
<div class="adm-field"><label for="date_of_admission">Date of Admission</label><input id="date_of_admission" name="date_of_admission" type="date" value="{{ old('date_of_admission', $initial['date_of_admission'] ?? null) }}" placeholder=""></div>
<div class="adm-field"><label for="class_admitted">Class (admitted)</label><select id="class_admitted" name="class_admitted"><option value="">Select class</option>@foreach($classOptions as $cls)<option value="{{ $cls }}" @selected(old('class_admitted', $initial['class_admitted'] ?? null)===$cls)>{{ $cls }}</option>@endforeach</select></div>
<div class="adm-field"><label for="section_admitted">Section (admitted)</label><select id="section_admitted" name="section_admitted"><option value="">Select section</option>@foreach($sectionOptions as $section)<option value="{{ $section }}" @selected(old('section_admitted', $initial['section_admitted'] ?? null)===$section)>{{ $section }}</option>@endforeach</select></div>
<div class="adm-field"><label for="grade_label">Class (current) *</label><select id="grade_label" name="grade_label" required><option value="">Select class</option>@foreach($classOptions as $cls)<option value="{{ $cls }}" @selected(old('grade_label', $initial['grade_label'] ?? null)===$cls)>{{ $cls }}</option>@endforeach</select></div>
<div class="adm-field"><label for="section_current">Section (current)</label><select id="section_current" name="section_current"><option value="">Select section</option>@foreach($sectionOptions as $section)<option value="{{ $section }}" @selected(old('section_current', $initial['section_current'] ?? null)===$section)>{{ $section }}</option>@endforeach</select></div>
<div class="adm-field adm-wide"><label for="monthly_fee">Monthly Fees</label><div class="adm-fee-input"><div class="adm-field"><label for="currency">Currency *</label><select id="currency" name="currency" required>@foreach(['SAR','PKR','USD','AED','GBP','EUR'] as $currency)<option value="{{ $currency }}" @selected(old('currency', $initial['currency'] ?? 'SAR')===$currency)>{{ $currency }}</option>@endforeach<option value="__other__">+ Add currency…</option></select><input id="custom_currency" class="adm-custom-currency" type="text" maxlength="3" pattern="[A-Za-z]{3}" placeholder="3-letter code" aria-label="Custom currency code" hidden></div><input id="monthly_fee" name="monthly_fee" type="text" inputmode="decimal" value="{{ old('monthly_fee', $initial['monthly_fee'] ?? null) }}" placeholder="0.00"></div></div>
<div class="adm-field"><label for="challan_due_date">Challan Due date</label><input id="challan_due_date" name="challan_due_date" type="date" value="{{ old('challan_due_date', $initial['challan_due_date'] ?? null) }}" placeholder=""></div>
<div class="adm-field"><label for="b_form">B-Form</label><input id="b_form" name="b_form" type="text" value="{{ old('b_form', $initial['b_form'] ?? null) }}" placeholder=""></div>
<div class="adm-field"><label for="phone_number">Phone number (optional)</label><input id="phone_number" name="phone_number" type="tel" value="{{ old('phone_number', $initial['phone_number'] ?? null) }}" placeholder="Enter phone number"></div>
<div class="adm-field"><label for="roll_number">Roll No</label><input id="roll_number" name="roll_number" type="text" value="{{ old('roll_number', $initial['roll_number'] ?? null) }}" placeholder=""></div>
<div class="adm-field"><label for="house">House</label><input id="house" name="house" type="text" value="{{ old('house', $initial['house'] ?? null) }}" placeholder=""></div>
<div class="adm-field"><label for="readmission_date">Re-admission date</label><input id="readmission_date" name="readmission_date" type="date" value="{{ old('readmission_date', $initial['readmission_date'] ?? null) }}" placeholder=""></div>
<div class="adm-field"><label for="last_class">Last class</label><select id="last_class" name="last_class"><option value="">Select class</option>@foreach($classOptions as $cls)<option value="{{ $cls }}" @selected(old('last_class', $initial['last_class'] ?? null)===$cls)>{{ $cls }}</option>@endforeach</select></div>
<div class="adm-field"><label for="last_session">Last Session</label><input id="last_session" name="last_session" type="text" value="{{ old('last_session', $initial['last_session'] ?? null) }}" placeholder=""></div>
<div class="adm-field"><label for="last_result">Last Result</label><input id="last_result" name="last_result" type="text" value="{{ old('last_result', $initial['last_result'] ?? null) }}" placeholder=""></div></div></section>
    </div>
    <button type="submit" class="adm-submit">{{ isset($editingStudent) ? 'Save Student Changes' : 'Register Now' }}</button>
  </form>
</div>
<script>
(() => {
  const department = document.getElementById('department_id');
  const labels = @json($departments->pluck('code','id'));
  const tuitionRow = document.getElementById('tuition-subject-row');
  const quranRow = document.getElementById('quran-days-row');
  function updateDepartmentFields() {
    const code = labels[department.value] || '';
    tuitionRow.hidden = code !== 'tuition';
    quranRow.hidden = code !== 'quran';
    if (tuitionRow.hidden) document.getElementById('subject_name').value = '';
    if (quranRow.hidden) document.getElementById('class_days_per_week').value = '';
  }
  department.addEventListener('change', updateDepartmentFields);
  updateDepartmentFields();
  const currencySelect = document.getElementById('currency');
  const customCurrency = document.getElementById('custom_currency');
  function syncCurrency() {
    const adding = currencySelect.value === '__other__';
    customCurrency.hidden = !adding;
    customCurrency.required = adding;
    customCurrency.name = adding ? 'currency' : '';
    currencySelect.name = adding ? '' : 'currency';
    if (adding) { customCurrency.focus(); }
  }
  currencySelect.addEventListener('change', syncCurrency);
  // If a custom code is chosen, leave its selector visible for correction.
  customCurrency.addEventListener('input', () => { customCurrency.value = customCurrency.value.replace(/[^a-z]/gi, '').toUpperCase(); });
  const whatsApp = document.getElementById('family_whatsapp');
  whatsApp.addEventListener('blur', () => {
    let number = whatsApp.value.trim().replace(/[\s()\-]/g, '');
    if (number.startsWith('00')) number = '+' + number.substring(2);
    whatsApp.value = number;
  });
  const input = document.getElementById('registration_number');
  const feedback = document.getElementById('reg-number-feedback');
  const url = @json(route('student.admission.check-number'));
  const currentStudentId = @json($editingStudent ?? null);
  let controller;
  let timer;
  input.addEventListener('input', () => {
    clearTimeout(timer);
    if (controller) controller.abort();
    input.classList.remove('adm-invalid','adm-valid');
    feedback.textContent = '';
    input.setCustomValidity('');
    const number = input.value.trim();
    if (!number) return;
    timer = setTimeout(async () => {
      controller = new AbortController();
      try {
        const response = await fetch(url + '?number=' + encodeURIComponent(number) + (currentStudentId ? '&except=' + currentStudentId : ''), {signal: controller.signal, headers: {'Accept': 'application/json'}});
        if (!response.ok) return;
        const result = await response.json();
        if (input.value.trim() !== number) return;
        input.classList.toggle('adm-invalid', result.exists);
        input.classList.toggle('adm-valid', !result.exists);
        input.setCustomValidity(result.exists ? 'This registration / GR number already exists.' : '');
        feedback.textContent = result.exists ? 'This registration / GR number already exists.' : 'Number available';
      } catch(e) { if (e.name !== 'AbortError') feedback.textContent = 'Availability will be checked when you submit.'; }
    }, 350);
  });
})();
</script>
@endsection
