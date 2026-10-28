<?php
namespace App\Http\Controllers;

use App\Services\Money;
use App\Services\GuardianNameResolver;
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
        $resolvedGuardian = GuardianNameResolver::resolveStudent($s, $family, $details);
        $hasSiblings = DB::table('students')->where('family_id', $s->family_id)->where('id', '!=', $student)->exists();
        $monthlyFeeType = DB::table('fee_types')->where('code', 'monthly')->first();
        $latestPlan = null;
        if ($en && $monthlyFeeType) {
            $latestPlan = DB::table('fee_plan_items')
                ->where('enrollment_id', $en->id)
                ->where('fee_type_id', $monthlyFeeType->id)
                ->where('is_active', true)
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->first();
        }
        $currentMonthlyMinor = $latestPlan ? (int)$latestPlan->amount_minor : (int)($en?->monthly_fee_minor ?? 0);
        $monthlyFeeFormatted = $currentMonthlyMinor > 0 ? number_format($currentMonthlyMinor / 100, 2, '.', '') : '';

        $initial=array_merge((array)$details,(array)$s,[
            'registration_number'=>$s->gr_number,'family_code'=>$family?->family_code,
            'family_email'=>$family?->email,'family_whatsapp'=>$family?->whatsapp,
            'guardian_name'=>$resolvedGuardian['name'] !== GuardianNameResolver::NOT_PROVIDED ? $resolvedGuardian['name'] : '',
            'guardian_name_override'=>GuardianNameResolver::clean($details?->guardian_name_override),
            'is_guardian_override'=>$resolvedGuardian['is_override'],
            'family_guardian_name'=>GuardianNameResolver::clean($family?->guardian_name),
            'has_family_siblings'=>$hasSiblings,
            'campus_id'=>$en?->campus_id,
            'department_id'=>$en?->department_id,'grade_label'=>$en?->grade_label,
            'subject_name'=>$en?->subject_name,'class_days_per_week'=>$en?->class_days_per_week,
            'currency'=>$en?->currency ?? 'SAR',
            'monthly_fee'=>$monthlyFeeFormatted,
            'contact_verified'=>(bool)($family?->contact_verified ?? false),
            'messaging_opt_in'=>(bool)($family?->messaging_opt_in ?? false),
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
            'contact_verified'=>'nullable|boolean',
            'messaging_opt_in'=>'nullable|boolean',
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
            'monthly_fee'=>['nullable','regex:/^\d{1,13}(?:\.\d{1,2})?$/'],
            'currency'=>['nullable','string','max:10'],
        ]);

        $en = DB::table('enrollments')->where('student_id', $student)->where('status', 'active')->orderBy('id')->first()
            ?? DB::table('enrollments')->where('student_id', $student)->orderBy('id')->first();
        $monthlyFeeType = DB::table('fee_types')->where('code', 'monthly')->first();
        $latestPlan = null;
        if ($en && $monthlyFeeType) {
            $latestPlan = DB::table('fee_plan_items')
                ->where('enrollment_id', $en->id)
                ->where('fee_type_id', $monthlyFeeType->id)
                ->where('is_active', true)
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->first();
        }
        $oldMonthlyFeeMinor = $latestPlan ? (int)$latestPlan->amount_minor : (int)($en?->monthly_fee_minor ?? 0);
        $hasFeeInput = $request->has('monthly_fee') && $request->input('monthly_fee') !== null && trim((string)$request->input('monthly_fee')) !== '';
        $newMonthlyFeeMinor = $hasFeeInput ? Money::minor((string)$request->input('monthly_fee')) : $oldMonthlyFeeMinor;
        $feeChanged = ($hasFeeInput && $newMonthlyFeeMinor !== $oldMonthlyFeeMinor);

        $oldCurrency = $latestPlan ? (string)$latestPlan->currency : (string)($en?->currency ?? 'SAR');
        $hasCurrencyInput = $request->filled('currency');
        $newCurrency = $hasCurrencyInput ? strtoupper(trim((string)$request->input('currency'))) : $oldCurrency;
        $currencyChanged = ($hasCurrencyInput && $newCurrency !== $oldCurrency);
        $feePlanChanged = ($feeChanged || $currencyChanged);

        if ($feePlanChanged) {
            abort_unless(
                in_array($request->user()->role, ['super_admin', 'finance_admin'], true),
                403,
                'Changing student monthly fee or currency requires Super Admin or Finance Admin privileges.'
            );
        }

        $notice = null;
        DB::transaction(function() use($s,$student,$d,$request,$en,$monthlyFeeType,$latestPlan,$oldMonthlyFeeMinor,$newMonthlyFeeMinor,$feeChanged,$oldCurrency,$newCurrency,$currencyChanged,$feePlanChanged,&$notice){
            $curFamily=DB::table('families')->where('id',$s->family_id)->lockForUpdate()->first();
            $code=trim($d['family_code']??'')?:null;
            $targetFamily=$code?DB::table('families')->where('family_code',$code)->lockForUpdate()->first():null;

            if($targetFamily && $targetFamily->id !== $s->family_id){
                abort_unless(
                    in_array($request->user()->role, ['super_admin', 'finance_admin'], true),
                    403,
                    'Reassigning a student to an existing family requires Super Admin or Finance Admin privileges.'
                );
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
                $emailChanged = ($curFamily->email !== $d['family_email']);
                $phoneChanged = ($curFamily->whatsapp !== $d['family_whatsapp']);
                $newVerified = $request->boolean('contact_verified');
                $newOptIn = $request->boolean('messaging_opt_in');

                $finalVerified = (($emailChanged || $phoneChanged) && !$newVerified) ? false : $newVerified;
                $finalOptIn = ($phoneChanged && !$newOptIn) ? false : $newOptIn;

                $finalWhatsappVerified = $phoneChanged ? ($newVerified && $newOptIn) : ($newVerified ?: (bool)($curFamily->whatsapp_verified ?? false));
                $finalEmailVerified = $emailChanged ? $newVerified : ($newVerified ?: (bool)($curFamily->email_verified ?? false));

                $submittedGuardian = GuardianNameResolver::clean($d['guardian_name'] ?? null);
                $hasSiblings = DB::table('students')->where('family_id', $s->family_id)->where('id', '!=', $student)->exists();

                $famUpdate = [
                    'family_code'=>$code,
                    'email'=>$d['family_email'],
                    'whatsapp'=>$d['family_whatsapp'],
                    'contact_verified'=>$finalVerified,
                    'whatsapp_verified'=>$finalWhatsappVerified,
                    'email_verified'=>$finalEmailVerified,
                    'messaging_opt_in'=>$finalOptIn,
                    'updated_at'=>now(),
                ];
                if (!$hasSiblings && $submittedGuardian !== null) {
                    $famUpdate['guardian_name'] = $submittedGuardian;
                }
                DB::table('families')->where('id',$s->family_id)->update($famUpdate);

                if ($finalVerified !== (bool)$curFamily->contact_verified || $finalOptIn !== (bool)$curFamily->messaging_opt_in) {
                    DB::table('audit_logs')->insert([
                        'actor_id'=>$request->user()->id,
                        'action'=>'family_contact_verification_updated',
                        'subject_type'=>'family',
                        'subject_id'=>$s->family_id,
                        'metadata'=>json_encode([
                            'contact_verified'=>$finalVerified,
                            'messaging_opt_in'=>$finalOptIn,
                            'email_changed'=>$emailChanged,
                            'phone_changed'=>$phoneChanged,
                            'student_id'=>$student,
                        ]),
                        'created_at'=>now(),
                        'updated_at'=>now(),
                    ]);
                }
            }

            $submittedGuardian = GuardianNameResolver::clean($d['guardian_name'] ?? null);
            DB::table('students')->where('id',$student)->update(['name'=>$d['name'],'gr_number'=>$d['registration_number'],'gender'=>$d['gender']??null,'date_of_admission'=>$d['date_of_admission']??null,'updated_at'=>now()]);
            $fields=['father_name','surname','dob','religion','last_institute','dob_words','place_of_birth','mother_tongue','nationality','area','current_address','permanent_address','physical_handicap','student_email','registration_date','session','class_admitted','section_admitted','section_current','challan_due_date','b_form','roll_number','house','phone_number','readmission_date','last_class','last_session','last_result'];
            $details=[];foreach($fields as $field)$details[$field]=$d[$field]??null;
            $details['guardian_name_override'] = $submittedGuardian;
            $details['registration_number']=$d['registration_number'];$details['updated_at']=now();
            DB::table('student_registration_details')->where('student_id',$student)->update($details);

            if ($feePlanChanged && $en) {
                $latestItem = $monthlyFeeType ? DB::table('invoice_items as ii')
                    ->join('invoices as inv', 'inv.id', '=', 'ii.invoice_id')
                    ->where('ii.student_id', $student)
                    ->where('ii.fee_type_id', $monthlyFeeType->id)
                    ->where('ii.status', '!=', 'voided')
                    ->whereNotIn('inv.status', ['void', 'voided'])
                    ->orderByDesc('ii.period_end')
                    ->first() : null;

                if ($latestItem && $latestItem->period_end) {
                    $effectiveFrom = \Carbon\Carbon::parse($latestItem->period_end)->addDay()->startOfMonth();
                } else {
                    $effectiveFrom = now()->startOfMonth();
                }
                $effectivePeriodLabel = $effectiveFrom->format('F Y');
                $effectiveFromDate = $effectiveFrom->toDateString();
                $priorDay = $effectiveFrom->copy()->subDay()->toDateString();

                $targetPlanId = null;
                if ($latestPlan) {
                    if ($latestPlan->effective_from && $latestPlan->effective_from >= $effectiveFromDate) {
                        DB::table('fee_plan_items')->where('id', $latestPlan->id)->update([
                            'amount_minor' => $newMonthlyFeeMinor,
                            'currency' => $newCurrency,
                            'updated_at' => now(),
                        ]);
                        $targetPlanId = $latestPlan->id;
                    } else {
                        DB::table('fee_plan_items')->where('id', $latestPlan->id)->update([
                            'effective_to' => $priorDay,
                            'updated_at' => now(),
                        ]);
                        $targetPlanId = DB::table('fee_plan_items')->insertGetId([
                            'enrollment_id' => $en->id,
                            'fee_type_id' => $monthlyFeeType->id,
                            'description' => $latestPlan->description ?: 'Monthly Fee',
                            'frequency' => 'monthly',
                            'subject_name' => $latestPlan->subject_name,
                            'class_days_per_week' => $latestPlan->class_days_per_week,
                            'currency' => $newCurrency,
                            'amount_minor' => $newMonthlyFeeMinor,
                            'discount_kind' => $latestPlan->discount_kind,
                            'discount_value' => $latestPlan->discount_value,
                            'effective_from' => $effectiveFromDate,
                            'effective_to' => null,
                            'is_active' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                } else {
                    $targetPlanId = DB::table('fee_plan_items')->insertGetId([
                        'enrollment_id' => $en->id,
                        'fee_type_id' => $monthlyFeeType ? $monthlyFeeType->id : 1,
                        'description' => 'Monthly Fee',
                        'frequency' => 'monthly',
                        'subject_name' => null,
                        'class_days_per_week' => null,
                        'currency' => $newCurrency,
                        'amount_minor' => $newMonthlyFeeMinor,
                        'discount_kind' => null,
                        'discount_value' => 0,
                        'effective_from' => $effectiveFromDate,
                        'effective_to' => null,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $history = json_decode($en->fee_history ?: '[]', true) ?: [];
                $history[] = [
                    'previous_minor' => $oldMonthlyFeeMinor,
                    'new_minor' => $newMonthlyFeeMinor,
                    'previous_currency' => $oldCurrency,
                    'new_currency' => $newCurrency,
                    'currency' => $newCurrency,
                    'effective_period' => $effectivePeriodLabel,
                    'effective_from' => $effectiveFromDate,
                    'user_id' => $request->user()->id,
                    'administrator' => $request->user()->name,
                    'reason' => "Monthly fee and currency updated via registration edit form. Effective from {$effectivePeriodLabel}.",
                    'timestamp' => now()->toDateTimeString(),
                ];

                DB::table('enrollments')->where('id', $en->id)->update([
                    'monthly_fee_minor' => $newMonthlyFeeMinor,
                    'currency' => $newCurrency,
                    'fee_history' => json_encode($history),
                    'updated_at' => now(),
                ]);

                if ($monthlyFeeType) {
                    DB::table('fee_rate_changes')->insert([
                        'fee_type_id' => $monthlyFeeType->id,
                        'user_id' => $request->user()->id,
                        'target_kind' => 'student_plan',
                        'target_id' => $targetPlanId,
                        'currency' => $newCurrency,
                        'before_minor' => $oldMonthlyFeeMinor,
                        'after_minor' => $newMonthlyFeeMinor,
                        'reason' => "Monthly fee updated from {$oldCurrency} " . ($oldMonthlyFeeMinor / 100) . " to {$newCurrency} " . ($newMonthlyFeeMinor / 100) . " via registration edit form. Effective from {$effectivePeriodLabel}.",
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('audit_logs')->insert([
                    'actor_id' => $request->user()->id,
                    'action' => 'student_monthly_fee_updated',
                    'subject_type' => 'student',
                    'subject_id' => $student,
                    'metadata' => json_encode([
                        'previous_minor' => $oldMonthlyFeeMinor,
                        'new_minor' => $newMonthlyFeeMinor,
                        'previous_currency' => $oldCurrency,
                        'new_currency' => $newCurrency,
                        'currency' => $newCurrency,
                        'effective_period' => $effectivePeriodLabel,
                        'effective_from' => $effectiveFromDate,
                        'administrator' => $request->user()->name,
                        'administrator_id' => $request->user()->id,
                        'reason' => "Monthly fee and currency updated via registration edit form. Effective from {$effectivePeriodLabel}.",
                        'enrollment_id' => $en->id,
                        'fee_plan_item_id' => $targetPlanId,
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $oldFmt = ($oldMonthlyFeeMinor % 100 === 0) ? (string)($oldMonthlyFeeMinor / 100) : Money::format($oldMonthlyFeeMinor);
                $newFmt = ($newMonthlyFeeMinor % 100 === 0) ? (string)($newMonthlyFeeMinor / 100) : Money::format($newMonthlyFeeMinor);
                $notice = "Previous monthly fee: {$oldCurrency} {$oldFmt}. New monthly fee: {$newCurrency} {$newFmt}. Applies from {$effectivePeriodLabel}. Existing invoices remain unchanged.";
            }

            DB::table('audit_logs')->insert(['actor_id'=>$request->user()->id,'action'=>'student_updated','subject_type'=>'student','subject_id'=>$student,'metadata'=>json_encode(['gr_number'=>$d['registration_number'],'family_code'=>$code]),'created_at'=>now(),'updated_at'=>now()]);
        });
        return redirect('/manage-student/'.$student)->with('notice', $notice ?: 'Student details and family assignment updated successfully.');
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
            'contact_verified' => 'nullable|boolean',
            'messaging_opt_in' => 'nullable|boolean',
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
        $studentId = DB::transaction(function () use ($data, $fee, $request) {
            $now = now();
            $code = trim($data['family_code'] ?? '') ?: null;
            $existingFamily = $code === null ? null : DB::table('families')->where('family_code', $code)->lockForUpdate()->first();
            $submittedGuardian = GuardianNameResolver::clean($data['guardian_name'] ?? null);
            $submittedFather = GuardianNameResolver::clean($data['father_name'] ?? null);

            if ($existingFamily) {
                if (filled($existingFamily->email) && strcasecmp($existingFamily->email, $data['family_email']) !== 0) {
                    throw ValidationException::withMessages(['family_email' => 'This family number has a different email. Confirm the existing family before proceeding.']);
                }
                if (filled($existingFamily->whatsapp) && $existingFamily->whatsapp !== $data['family_whatsapp']) {
                    throw ValidationException::withMessages(['family_whatsapp' => 'This family number has a different WhatsApp number. Confirm the existing family before proceeding.']);
                }
                $verified = $request->boolean('contact_verified');
                $optIn = $request->boolean('messaging_opt_in');
                DB::table('families')->where('id', $existingFamily->id)->update([
                    'email' => $existingFamily->email ?: $data['family_email'],
                    'whatsapp' => $existingFamily->whatsapp ?: $data['family_whatsapp'],
                    'contact_verified' => $existingFamily->contact_verified ?: $verified,
                    'whatsapp_verified' => $existingFamily->whatsapp_verified ?: $verified,
                    'email_verified' => $existingFamily->email_verified ?: $verified,
                    'messaging_opt_in' => $existingFamily->messaging_opt_in ?: $optIn,
                    'updated_at' => $now,
                ]);
            }
            $verified = $request->boolean('contact_verified');
            $optIn = $request->boolean('messaging_opt_in');
            $familyId = $existingFamily?->id ?? DB::table('families')->insertGetId([
                'family_code' => $code,
                'guardian_name' => $submittedGuardian ?: ($submittedFather ?: null),
                'email' => $data['family_email'],
                'whatsapp' => $data['family_whatsapp'],
                'contact_verified' => $verified,
                'whatsapp_verified' => $verified,
                'email_verified' => $verified,
                'messaging_opt_in' => $optIn,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($verified || $optIn) {
                DB::table('audit_logs')->insert([
                    'actor_id' => $request->user()->id,
                    'action' => 'family_contact_verified',
                    'subject_type' => 'family',
                    'subject_id' => $familyId,
                    'metadata' => json_encode([
                        'contact_verified' => $verified,
                        'messaging_opt_in' => $optIn,
                        'source' => 'student_admission_store',
                    ]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
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
            if ($submittedGuardian !== null) {
                $details['guardian_name_override'] = $submittedGuardian;
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
        return redirect('/manage-student')->withErrors(['error' => 'Direct student bulk imports are disabled during Phase 1 Recovery Protection until the staging and reconciliation system is implemented in Phase 2.']);
    }

    public function importPreloaded(Request $request)
    {
        $this->authorizeWrite($request);
        return redirect('/manage-student')->withErrors(['error' => 'Direct student bulk imports are disabled during Phase 1 Recovery Protection until the staging and reconciliation system is implemented in Phase 2.']);
    }
}

