<?php
namespace App\Http\Controllers;

use App\Services\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StudentAdmissionController
{
    private function authorizeWrite(Request $request): void
    {
        abort_unless(in_array($request->user()->role, ['super_admin', 'finance_admin', 'department_manager'], true), 403);
    }

    public function create(Request $request)
    {
        $this->authorizeWrite($request);
        $user = $request->user();
        $global = in_array($user->role, ['super_admin', 'finance_admin'], true);
        return view('student-admission', [
            'campuses' => DB::table('campuses')->when(!$global, fn($q) => $q->where('id', $user->campus_id))->orderBy('name')->get(),
            'departments' => DB::table('departments')->when(!$global && $user->department_id, fn($q) => $q->where('id', $user->department_id))->orderBy('id')->get(),
            'defaultSession' => now()->year.'-'.(now()->year+1),
            'sessions' => collect(range(now()->year-2, now()->year+3))->map(fn ($y) => $y.'-'.($y+1))->all(),
            'classOptions' => collect(['Playgroup','Nursery','KG','Prep','Grade 1','Grade 2','Grade 3','Grade 4','Grade 5','Grade 6','Grade 7','Grade 8','Grade 9','Grade 10','9th (FED)','10th (FED)','11th (FED)','12th (FED)','Quran','Hifz'])
                ->merge(DB::table('programs')->pluck('name'))->unique()->values()->all(),
            'sectionOptions' => collect(['A','B','C','D','E','F'])
                ->merge(DB::table('sections')->pluck('name'))->unique()->values()->all(),
        ]);
    }

    private function assertVisible(Request $request,int $student): object
    {
        $this->authorizeWrite($request);
        $s=DB::table('students')->where('id',$student)->first();abort_unless($s,404);
        if(!in_array($request->user()->role,['super_admin','finance_admin'],true)){
            abort_unless(DB::table('enrollments')->where('student_id',$student)->where('campus_id',$request->user()->campus_id)->when($request->user()->department_id,fn($q)=>$q->where('department_id',$request->user()->department_id))->exists(),403);
        }
        return $s;
    }
    public function edit(Request $request,int $student)
    {
        $s=$this->assertVisible($request,$student);
        $en=DB::table('enrollments')->where('student_id',$student)->orderBy('id')->first();
        $details=DB::table('student_registration_details')->where('student_id',$student)->first();
        $family=DB::table('families')->where('id',$s->family_id)->first();
        $initial=array_merge((array)$details,(array)$s,[
            'registration_number'=>$s->gr_number,'family_code'=>$family->family_code,
            'family_email'=>$family->email,'family_whatsapp'=>$family->whatsapp,
            'guardian_name'=>$family->guardian_name,'campus_id'=>$en?->campus_id,
            'department_id'=>$en?->department_id,'grade_label'=>$en?->grade_label,
            'subject_name'=>$en?->subject_name,'class_days_per_week'=>$en?->class_days_per_week,
            'currency'=>$en?->currency,
            'monthly_fee'=>$en?->monthly_fee_minor===null?'':number_format($en->monthly_fee_minor/100,2,'.',''),
        ]);
        $view=$this->create($request);
        return $view->with(['editingStudent'=>$student,'initial'=>$initial]);
    }
    public function update(Request $request,int $student)
    {
        $s=$this->assertVisible($request,$student);
        $whatsapp=preg_replace('/[\s()\-]+/','',trim((string)$request->input('family_whatsapp','')));
        if(str_starts_with($whatsapp,'00'))$whatsapp='+'.substr($whatsapp,2);
        $request->merge(['family_whatsapp'=>$whatsapp]);
        $d=$request->validate([
            'name'=>'required|string|max:255','father_name'=>'required|string|max:255',
            'registration_number'=>['required','string','max:80',Rule::unique('students','gr_number')->ignore($student)],
            'family_code'=>'nullable|string|max:80','family_email'=>'required|email|max:255',
            'family_whatsapp'=>['required','regex:/^\+[1-9][0-9]{7,14}$/'],
            'guardian_name'=>'nullable|string|max:255', 'gender'=>'nullable|string|max:30',
            'date_of_admission'=>'nullable|date','dob'=>'nullable|date',
            'surname'=>'nullable|string|max:120','religion'=>'nullable|string|max:100',
            'last_institute'=>'nullable|string|max:255','dob_words'=>'nullable|string|max:255',
            'place_of_birth'=>'nullable|string|max:255','mother_tongue'=>'nullable|string|max:120',
            'nationality'=>'nullable|string|max:120','area'=>'nullable|string|max:120',
            'current_address'=>'nullable|string|max:1000','permanent_address'=>'nullable|string|max:1000',
            'physical_handicap'=>'nullable|string|max:255','student_email'=>'nullable|email|max:255',
            'registration_date'=>'nullable|date','session'=>'nullable|string|max:40',
            'class_admitted'=>'nullable|string|max:200','section_admitted'=>'nullable|string|max:120',
            'section_current'=>'nullable|string|max:120','grade_label'=>'required|string|max:200',
            'challan_due_date'=>'nullable|date','b_form'=>'nullable|string|max:80',
            'roll_number'=>'nullable|string|max:80','house'=>'nullable|string|max:120',
            'phone_number'=>'nullable|string|max:30','readmission_date'=>'nullable|date',
            'last_class'=>'nullable|string|max:120','last_session'=>'nullable|string|max:40',
            'last_result'=>'nullable|string|max:120',
        ]);
        DB::transaction(function() use($s,$student,$d,$request){
            $curFamily=DB::table('families')->where('id',$s->family_id)->lockForUpdate()->first();
            $code=trim($d['family_code']??'')?:null;
            $targetFamily=$code?DB::table('families')->where('family_code',$code)->lockForUpdate()->first():null;

            if($targetFamily && $targetFamily->id !== $s->family_id){
                $targetFamilyId=$targetFamily->id;
                $oldFamilyId=$s->family_id;

                DB::table('students')->where('id',$student)->update(['family_id'=>$targetFamilyId]);

                $otherStudentsCount=DB::table('students')->where('family_id',$oldFamilyId)->where('id','!=',$student)->count();

                if($otherStudentsCount===0){
                    $oldInvoices=DB::table('invoices')->where('family_id',$oldFamilyId)->get();
                    foreach($oldInvoices as $oldInv){
                        $existingTargetInv=DB::table('invoices')
                            ->where('billing_run_id',$oldInv->billing_run_id)
                            ->where('family_id',$targetFamilyId)
                            ->where('currency',$oldInv->currency)
                            ->first();

                        if($existingTargetInv){
                            DB::table('invoice_items')->where('invoice_id',$oldInv->id)->update([
                                'invoice_id'=>$existingTargetInv->id,
                                'updated_at'=>now(),
                            ]);
                            DB::table('notifications')->where('invoice_id',$oldInv->id)->update([
                                'invoice_id'=>$existingTargetInv->id,
                                'updated_at'=>now(),
                            ]);
                            (new \App\Services\InvoiceService())->refreshInvoice($existingTargetInv->id);
                            DB::table('invoices')->where('id',$oldInv->id)->delete();
                        }else{
                            DB::table('invoices')->where('id',$oldInv->id)->update([
                                'family_id'=>$targetFamilyId,
                                'updated_at'=>now(),
                            ]);
                        }
                    }

                    DB::table('payments')->where('family_id',$oldFamilyId)->update([
                        'family_id'=>$targetFamilyId,
                        'updated_at'=>now(),
                    ]);

                    DB::table('families')->where('id',$oldFamilyId)->delete();
                }else{
                    $studentItemInvoiceIds=DB::table('invoice_items')
                        ->where('student_id',$student)
                        ->pluck('invoice_id')
                        ->unique()
                        ->all();

                    foreach($studentItemInvoiceIds as $oldInvId){
                        $oldInv=DB::table('invoices')->where('id',$oldInvId)->first();
                        if(!$oldInv) continue;

                        $existingTargetInv=DB::table('invoices')
                            ->where('billing_run_id',$oldInv->billing_run_id)
                            ->where('family_id',$targetFamilyId)
                            ->where('currency',$oldInv->currency)
                            ->first();

                        if($existingTargetInv){
                            DB::table('invoice_items')->where('invoice_id',$oldInv->id)->where('student_id',$student)->update([
                                'invoice_id'=>$existingTargetInv->id,
                                'updated_at'=>now(),
                            ]);
                            (new \App\Services\InvoiceService())->refreshInvoice($existingTargetInv->id);
                            (new \App\Services\InvoiceService())->refreshInvoice($oldInv->id);
                        }else{
                            $remainingItems=DB::table('invoice_items')->where('invoice_id',$oldInv->id)->where('student_id','!=',$student)->count();
                            if($remainingItems===0){
                                DB::table('invoices')->where('id',$oldInv->id)->update(['family_id'=>$targetFamilyId,'updated_at'=>now()]);
                            }else{
                                $deptCode=DB::table('departments')->where('id',$oldInv->department_id)->value('code')??'SCH';
                                $newInvId=DB::table('invoices')->insertGetId([
                                    'billing_run_id'=>$oldInv->billing_run_id,
                                    'family_id'=>$targetFamilyId,
                                    'campus_id'=>$oldInv->campus_id,
                                    'department_id'=>$oldInv->department_id,
                                    'currency'=>$oldInv->currency,
                                    'invoice_number'=>'PENDING-'.\Illuminate\Support\Str::uuid(),
                                    'issued_on'=>$oldInv->issued_on,
                                    'due_date'=>$oldInv->due_date,
                                    'total_minor'=>0,
                                    'paid_minor'=>0,
                                    'previous_dues_snapshot_minor'=>0,
                                    'status'=>$oldInv->status,
                                    'approved_to_send'=>$oldInv->approved_to_send,
                                    'created_at'=>now(),
                                    'updated_at'=>now(),
                                ]);
                                DB::table('invoices')->where('id',$newInvId)->update([
                                    'invoice_number'=>strtoupper(substr($deptCode,0,3)).'-'.date('Y').'-'.str_pad((string)$newInvId,7,'0',STR_PAD_LEFT),
                                ]);
                                DB::table('invoice_items')->where('invoice_id',$oldInv->id)->where('student_id',$student)->update([
                                    'invoice_id'=>$newInvId,
                                    'updated_at'=>now(),
                                ]);
                                (new \App\Services\InvoiceService())->refreshInvoice($newInvId);
                                (new \App\Services\InvoiceService())->refreshInvoice($oldInv->id);
                            }
                        }
                    }
                }

                if(empty($targetFamily->email)||empty($targetFamily->whatsapp)){
                    DB::table('families')->where('id',$targetFamilyId)->update([
                        'email'=>$targetFamily->email?:$d['family_email'],
                        'whatsapp'=>$targetFamily->whatsapp?:$d['family_whatsapp'],
                        'updated_at'=>now(),
                    ]);
                }
            }else{
                DB::table('families')->where('id',$s->family_id)->update([
                    'family_code'=>$code,
                    'guardian_name'=>$d['guardian_name']??null,
                    'email'=>$d['family_email'],
                    'whatsapp'=>$d['family_whatsapp'],
                    'contact_verified'=>($curFamily->email===$d['family_email']&&$curFamily->whatsapp===$d['family_whatsapp'])?$curFamily->contact_verified:false,
                    'messaging_opt_in'=>($curFamily->whatsapp===$d['family_whatsapp'])?$curFamily->messaging_opt_in:false,
                    'updated_at'=>now(),
                ]);
            }

            DB::table('students')->where('id',$student)->update(['name'=>$d['name'],'gr_number'=>$d['registration_number'],'gender'=>$d['gender']??null,'date_of_admission'=>$d['date_of_admission']??null,'updated_at'=>now()]);
            $fields=['father_name','surname','dob','religion','last_institute','dob_words','place_of_birth','mother_tongue','nationality','area','current_address','permanent_address','physical_handicap','student_email','registration_date','session','class_admitted','section_admitted','section_current','challan_due_date','b_form','roll_number','house','phone_number','readmission_date','last_class','last_session','last_result'];
            $details=[];foreach($fields as $field)$details[$field]=$d[$field]??null;
            $details['registration_number']=$d['registration_number'];$details['updated_at']=now();
            DB::table('student_registration_details')->where('student_id',$student)->update($details);

            DB::table('audit_logs')->insert(['actor_id'=>$request->user()->id,'action'=>'student_updated','subject_type'=>'student','subject_id'=>$student,'metadata'=>json_encode(['gr_number'=>$d['registration_number'],'family_code'=>$code]),'created_at'=>now(),'updated_at'=>now()]);
        });
        return redirect('/manage-student/'.$student)->with('notice','Student details and family assignment updated successfully.');
    }

    public function checkRegistrationNumber(Request $request)
    {
        $this->authorizeWrite($request);
        $number = trim((string) $request->query('number', ''));
        $except=$request->integer('except');
        if($except) $this->assertVisible($request,$except);
        if ($number === '') return response()->json(['exists' => false]);
        abort_if(mb_strlen($number) > 80, 422);
        return response()->json(['exists' => DB::table('students')->where('gr_number', $number)->when($except,fn($q)=>$q->where('id','!=',$except))->exists()
            || DB::table('student_registration_details')->where('registration_number', $number)->when($except,fn($q)=>$q->where('student_id','!=',$except))->exists()]);
    }

    public function store(Request $request)
    {
        $this->authorizeWrite($request);
        // Normalize formatting before validating and storing. Require an explicit country code.
        $whatsapp = preg_replace('/[\s()\-]+/', '', trim((string) $request->input('family_whatsapp', '')));
        if (str_starts_with($whatsapp, '00')) $whatsapp = '+'.substr($whatsapp, 2);
        $request->merge(['family_whatsapp' => $whatsapp]);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'gender' => 'nullable|string|max:30',
            'date_of_admission' => 'nullable|date',
            'family_code' => 'nullable|string|max:80',
            'phone_number' => 'nullable|string|max:30',
            'family_email' => 'required|email|max:255',
            'family_whatsapp' => ['required','regex:/^\+[1-9][0-9]{7,14}$/'],
            'guardian_name' => 'nullable|string|max:255',
            'campus_id' => 'required|integer|exists:campuses,id',
            'department_id' => 'required|integer|exists:departments,id',
            'grade_label' => 'required|string|max:200',
            'section_admitted' => 'nullable|string|max:120',
            'section_current' => 'nullable|string|max:120',
            'class_admitted' => 'nullable|string|max:200',
            'session' => 'nullable|string|max:40',
            'registration_number' => ['required','string','max:80',Rule::unique('student_registration_details', 'registration_number'),Rule::unique('students', 'gr_number')],
            'registration_date' => 'nullable|date',
            'dob' => 'nullable|date',
            'father_name' => 'required|string|max:255',
            'surname' => 'nullable|string|max:120',
            'religion' => 'nullable|string|max:100',
            'last_institute' => 'nullable|string|max:255',
            'dob_words' => 'nullable|string|max:255',
            'place_of_birth' => 'nullable|string|max:255',
            'mother_tongue' => 'nullable|string|max:120',
            'nationality' => 'nullable|string|max:120',
            'area' => 'nullable|string|max:120',
            'current_address' => 'nullable|string|max:1000',
            'permanent_address' => 'nullable|string|max:1000',
            'physical_handicap' => 'nullable|string|max:255',
            'student_email' => 'nullable|email|max:255',
            'challan_due_date' => 'nullable|date',
            'b_form' => 'nullable|string|max:80',
            'roll_number' => 'nullable|string|max:80',
            'house' => 'nullable|string|max:120',
            'readmission_date' => 'nullable|date',
            'last_class' => 'nullable|string|max:120',
            'last_session' => 'nullable|string|max:40',
            'last_result' => 'nullable|string|max:120',
            'subject_name' => 'nullable|string|max:150',
            'class_days_per_week' => 'nullable|integer|between:1,7',
            'currency' => ['required','string','size:3','regex:/^[A-Za-z]{3}$/'],
            'monthly_fee' => ['nullable','regex:/^\d{1,13}(?:\.\d{1,2})?$/'],
        ]);
        abort_unless($request->user()->canAccessDepartment((int)$data['department_id'], (int)$data['campus_id']), 403);
        $deptCode = DB::table('departments')->where('id', $data['department_id'])->value('code');
        if ($deptCode !== 'tuition') $data['subject_name'] = null;
        if ($deptCode !== 'quran') $data['class_days_per_week'] = null;
        $fee = Money::minor((string)($data['monthly_fee'] ?? '0'));
        $studentId = DB::transaction(function () use ($data, $fee) {
            $now = now();
            $code = trim($data['family_code'] ?? '') ?: null;
            $existingFamily = $code === null ? null : DB::table('families')->where('family_code', $code)->lockForUpdate()->first();
            if ($existingFamily && filled($data['guardian_name'] ?? null) && filled($existingFamily->guardian_name)
                && strcasecmp(trim($data['guardian_name']), trim($existingFamily->guardian_name)) !== 0) {
                throw ValidationException::withMessages(['family_code' => 'This family code belongs to another guardian. Verify the family before using it.']);
            }
            if ($existingFamily) {
                if (filled($existingFamily->email) && strcasecmp($existingFamily->email, $data['family_email']) !== 0) {
                    throw ValidationException::withMessages(['family_email' => 'This family number has a different email. Confirm the existing family before proceeding.']);
                }
                if (filled($existingFamily->whatsapp) && $existingFamily->whatsapp !== $data['family_whatsapp']) {
                    throw ValidationException::withMessages(['family_whatsapp' => 'This family number has a different WhatsApp number. Confirm the existing family before proceeding.']);
                }
                DB::table('families')->where('id', $existingFamily->id)->update([
                    'email' => $existingFamily->email ?: $data['family_email'],
                    'whatsapp' => $existingFamily->whatsapp ?: $data['family_whatsapp'],
                    'updated_at' => $now,
                ]);
            }
            $familyId = $existingFamily?->id ?? DB::table('families')->insertGetId([
                'family_code' => $code, 'guardian_name' => $data['guardian_name'] ?? ($data['father_name'] ?? null),
                'email' => $data['family_email'], 'whatsapp' => $data['family_whatsapp'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $studentId = DB::table('students')->insertGetId([
                'name' => trim($data['name']), 'gr_number' => trim($data['registration_number']), 'family_id' => $familyId,
                'gender' => $data['gender'] ?? null, 'date_of_admission' => $data['date_of_admission'] ?? now()->toDateString(),
                'status' => 'present', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $programId = DB::table('programs')->where('department_id', $data['department_id'])->where('name', $data['grade_label'])->value('id');
            if (!$programId) {
                $programId = DB::table('programs')->insertGetId([
                    'department_id' => $data['department_id'], 'name' => $data['grade_label'], 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $enrollmentId = DB::table('enrollments')->insertGetId([
                'student_id' => $studentId, 'campus_id' => $data['campus_id'], 'department_id' => $data['department_id'],
                'program_id' => $programId, 'grade_label' => $data['grade_label'],
                'subject_name' => $data['subject_name'] ?? null, 'class_days_per_week' => $data['class_days_per_week'] ?? null,
                'currency' => strtoupper($data['currency']), 'monthly_fee_minor' => $fee,
                'started_on' => $data['date_of_admission'] ?? now()->toDateString(),
                'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $extraFields = [
                'father_name','surname','dob','religion','last_institute','dob_words','place_of_birth',
                'mother_tongue','nationality','area','current_address','permanent_address','physical_handicap',
                'student_email','registration_number','session','registration_date','class_admitted',
                'section_admitted','section_current','challan_due_date','b_form','roll_number','house',
                'readmission_date','last_class','last_session','last_result','phone_number',
            ];
            $details = ['student_id' => $studentId, 'created_at' => $now, 'updated_at' => $now];
            foreach ($extraFields as $key) {
                $details[$key] = $data[$key] ?? null;
            }
            DB::table('student_registration_details')->insert($details);
            if ($fee > 0) {
                $feeType = DB::table('fee_types')->where('code', 'monthly')->first();
                if (!$feeType) throw ValidationException::withMessages(['monthly_fee' => 'Monthly Fee type is missing. Check your setup before saving a fee.']);
                DB::table('fee_plan_items')->insert([
                    'enrollment_id' => $enrollmentId, 'fee_type_id' => $feeType->id,
                    'description' => $data['subject_name'] ?? 'Monthly Fee', 'frequency' => 'monthly',
                    'subject_name' => $data['subject_name'] ?? null, 'class_days_per_week' => $data['class_days_per_week'] ?? null,
                    'currency' => strtoupper($data['currency']), 'amount_minor' => $fee,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            return $studentId;
        });
        return redirect('/manage-student')->with('notice', 'Student registered and enrolled successfully. No invoice has been generated.');
    }

    public function import(Request $request)
    {
        $this->authorizeWrite($request);
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:51200',
        ]);

        $file = $request->file('csv_file');
        $service = new \App\Services\StudentImportService();
        $result = $service->importFromFile($file->getRealPath());

        return redirect('/manage-student')->with('notice', "Successfully processed {$result['total']} students ({$result['imported']} new, {$result['updated']} updated across {$result['families_count']} families).");
    }

    public function importPreloaded(Request $request)
    {
        $this->authorizeWrite($request);
        $path = storage_path('app/imports/students.csv');
        if (!file_exists($path)) {
            return back()->withErrors(['error' => 'Preloaded import file not found. Please upload a CSV.']);
        }

        $service = new \App\Services\StudentImportService();
        $result = $service->importFromFile($path);

        return redirect('/manage-student')->with('notice', "Successfully imported preloaded school dataset: {$result['total']} students ({$result['imported']} new, {$result['updated']} updated across {$result['families_count']} families).");
    }
}

