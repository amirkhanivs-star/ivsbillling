<?php
namespace App\Http\Controllers;
use App\Jobs\DispatchInvoiceToN8n;
use App\Services\InvoiceService;
use App\Services\BillingPeriod;
use App\Services\Money;
use App\Services\ReceivePaymentService;
use App\Services\FeeAdjustmentService;
use App\Services\ReceiptReversalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
class BillingController {
 private function assertAccess(Request $r,int $department,int $campus):void{abort_unless($r->user()->canAccessDepartment($department,$campus),403);}
 private function basics(?Request $r=null):array {
  $user=($r??request())->user();
  $global=in_array($user->role,['super_admin','finance_admin'],true);
  return [
   'campuses'=>DB::table('campuses')->when(!$global,fn($q)=>$q->where('id',$user->campus_id))->orderBy('name')->get(),
   'departments'=>DB::table('departments')->when(!$global && $user->department_id,fn($q)=>$q->where('id',$user->department_id))->orderBy('id')->get(),
  ];
 }
 public function dashboard(Request $r){$base=$this->basics($r);$myEnrollments=DB::table('enrollments')->when(!in_array($r->user()->role,['super_admin','finance_admin'],true),fn($q)=>$q->where('campus_id',$r->user()->campus_id)->when($r->user()->department_id,fn($v)=>$v->where('department_id',$r->user()->department_id)));$studentIds=(clone $myEnrollments)->select('student_id')->distinct();$base['stats']=['students'=>DB::query()->fromSub($studentIds,'students_visible')->count(),'families'=>DB::table('families as f')->whereExists(fn($q)=>$q->selectRaw('1')->from('students as s')->whereColumn('s.family_id','f.id')->whereIn('s.id',(clone $myEnrollments)->select('student_id')))->count(),'invoices'=>DB::table('invoices')->when(!in_array($r->user()->role,['super_admin','finance_admin'],true),fn($q)=>$q->where('campus_id',$r->user()->campus_id)->when($r->user()->department_id,fn($v)=>$v->where('department_id',$r->user()->department_id)))->count()];
  $base['todayCollections']=DB::table('payments')->select('currency')->selectRaw('SUM(amount_minor) AS amount')
   ->where('received_on',now()->toDateString())
   ->when(!in_array($r->user()->role,['super_admin','finance_admin'],true),fn($q)=>$q->where('campus_id',$r->user()->campus_id)->when($r->user()->department_id,fn($v)=>$v->where('department_id',$r->user()->department_id)))
   ->groupBy('currency')->get();
  $base['stats']['receipts_today']=DB::table('payments')->where('received_on',now()->toDateString())
   ->when(!in_array($r->user()->role,['super_admin','finance_admin'],true),fn($q)=>$q->where('campus_id',$r->user()->campus_id)->when($r->user()->department_id,fn($v)=>$v->where('department_id',$r->user()->department_id)))->count();
  $base['totals']=DB::table('invoices')->select('department_id','currency')->selectRaw('SUM(total_minor) AS total,SUM(paid_minor) AS paid')->when(!in_array($r->user()->role,['super_admin','finance_admin'],true),fn($q)=>$q->where('campus_id',$r->user()->campus_id)->when($r->user()->department_id,fn($v)=>$v->where('department_id',$r->user()->department_id)))->groupBy('department_id','currency')->get();return view('dashboard',$base);}
 public function campuses(Request $r){return view('campuses',['campuses'=>$this->basics($r)['campuses']]);}
 public function addCampus(Request $r){abort_unless($r->user()->role==='super_admin',403);$d=$r->validate(['name'=>'required|string|max:200','code'=>'required|alpha_dash|max:24|unique:campuses,code']);DB::table('campuses')->insert([...$d,'created_at'=>now(),'updated_at'=>now()]);return back()->with('notice','Campus created.');}
 public function classes(Request $r){$global=in_array($r->user()->role,['super_admin','finance_admin'],true);return view('classes',[
  'rows'=>DB::table('programs as p')->join('departments as d','p.department_id','=','d.id')
   ->when(!$global && $r->user()->department_id,fn($q)=>$q->where('p.department_id',$r->user()->department_id))
   ->select('p.*','d.name as department_name')->orderBy('d.id')->orderBy('p.name')->get(),
  'departments'=>$this->basics($r)['departments'],
 ]);}
 public function addClass(Request $r){$d=$r->validate(['name'=>'required|string|max:190','department_id'=>'required|exists:departments,id']);abort_unless(in_array($r->user()->role,['super_admin','finance_admin'],true),403);DB::table('programs')->updateOrInsert($d,['updated_at'=>now(),'created_at'=>now()]);return back()->with('notice','Program saved.');}
 public function students(Request $r){
  $q=DB::table('students as s')->join('families as f','f.id','=','s.family_id')
   ->leftJoin('student_registration_details as sd','sd.student_id','=','s.id')
   ->select('s.*','f.family_code','f.guardian_name','f.whatsapp','sd.father_name','sd.registration_date')
   ->selectSub(DB::table('enrollments as e')->whereColumn('e.student_id','s.id')->orderByDesc('e.id')->select('grade_label')->limit(1),'current_class')
   ->selectSub(DB::table('enrollments as e')->whereColumn('e.student_id','s.id')->orderByDesc('e.id')->select('monthly_fee_minor')->limit(1),'monthly_fee_minor')
   ->selectSub(DB::table('enrollments as e')->whereColumn('e.student_id','s.id')->orderByDesc('e.id')->select('currency')->limit(1),'fee_currency');
  if(!in_array($r->user()->role,['super_admin','finance_admin'],true)){
   $q->whereExists(function($sub)use($r){$sub->selectRaw('1')->from('enrollments as e')->whereColumn('e.student_id','s.id')->where('e.campus_id',$r->user()->campus_id)->when($r->user()->department_id,fn($x)=>$x->where('e.department_id',$r->user()->department_id));});
  }
  if($term=trim((string)$r->query('q',''))){$q->where(fn($w)=>$w->where('s.name','like','%'.$term.'%')->orWhere('s.gr_number','like','%'.$term.'%')->orWhere('f.family_code','like','%'.$term.'%')->orWhere('sd.father_name','like','%'.$term.'%'));}
  if($status=$r->query('status')){if(in_array($status,['present','suspended','left','review_hold','historical_hold'],true))$q->where('s.status',$status);}
  return view('students',['students'=>$q->orderBy('s.id')->paginate(25)->withQueryString()]);
 }
 public function showStudent(int $student){
  $s=DB::table('students as s')->join('families as f','f.id','=','s.family_id')->where('s.id',$student)->select('s.*','f.family_code','f.guardian_name','f.email','f.whatsapp','f.contact_verified','f.messaging_opt_in')->first();
  abort_unless($s,404);
  if(!in_array(auth()->user()->role,['super_admin','finance_admin'],true)){
   abort_unless(DB::table('enrollments')->where('student_id',$student)->where('campus_id',auth()->user()->campus_id)->when(auth()->user()->department_id,fn($q)=>$q->where('department_id',auth()->user()->department_id))->exists(),403);
  }
  $scope=fn($q)=>$q->when(!in_array(auth()->user()->role,['super_admin','finance_admin'],true),fn($v)=>$v->where('e.campus_id',auth()->user()->campus_id)->when(auth()->user()->department_id,fn($x)=>$x->where('e.department_id',auth()->user()->department_id)));
  $enrollments=$scope(DB::table('enrollments as e')->join('departments as d','d.id','=','e.department_id')->where('e.student_id',$student))->select('e.*','d.name as department_name')->get();
  $feeItems=DB::table('invoice_items as ii')
   ->join('invoices as i','i.id','=','ii.invoice_id')
   ->join('billing_runs as b','b.id','=','i.billing_run_id')
   ->where('ii.student_id',$student)
   ->whereRaw('ii.net_minor > ii.paid_minor')
   ->whereNotIn('ii.status',['voided','exempted','paid'])
   ->whereNotIn('i.status',['void','voided','paid'])
   ->when(!in_array(auth()->user()->role,['super_admin','finance_admin'],true),fn($q)=>$q->where('i.campus_id',auth()->user()->campus_id)->when(auth()->user()->department_id,fn($v)=>$v->where('i.department_id',auth()->user()->department_id)))
   ->select('ii.*','i.invoice_number','b.period_start as bill_month')
   ->orderBy('ii.period_start')
   ->get();
  $receipts=DB::table('payment_allocations as pa')
   ->join('invoice_items as ii','ii.id','=','pa.invoice_item_id')
   ->join('payments as p','p.id','=','pa.payment_id')
   ->leftJoin('fee_types as ft','ft.id','=','ii.fee_type_id')
   ->where('ii.student_id',$student)
   ->when(!in_array(auth()->user()->role,['super_admin','finance_admin'],true),fn($q)=>$q->where('p.campus_id',auth()->user()->campus_id)->when(auth()->user()->department_id,fn($v)=>$v->where('p.department_id',auth()->user()->department_id)))
   ->select('p.id','p.receipt_number','p.received_on','p.currency','p.status','pa.amount_minor',\Illuminate\Support\Facades\DB::raw("COALESCE(ft.name, ii.description, 'Monthly Fee') as fee_type_name"),'ii.period_start as item_period')
   ->orderByDesc('p.received_on')
   ->limit(100)->get();
  $activeBatch = (string) config('imports.active_batch_id', 'BATCH-20261022-PORTAL-V2-REVISED');
  $stagedCollections = \Illuminate\Support\Facades\Schema::hasTable('qa_staged_collections')
   ? DB::table('qa_staged_collections')->where('batch_id', $activeBatch)->where('gr_number', $s->gr_number)->orderBy('source_row')->get()
   : collect();
  $stagedDues = \Illuminate\Support\Facades\Schema::hasTable('qa_staged_dues')
   ? DB::table('qa_staged_dues')->where('batch_id', $activeBatch)->where('gr_number', $s->gr_number)->orderBy('source_row')->get()
   : collect();
  $feeHistoryService = app(\App\Services\FeeHistoryService::class);
  $feeHistory = $feeHistoryService->getStudentFeeHistory($student, request()->all(), auth()->user());
  return view('student-details', [
   'student' => $s,
   'enrollments' => $enrollments,
   'feeItems' => $feeItems,
   'receipts' => $receipts,
   'feeHistory' => $feeHistory,
   'stagedCollections' => $stagedCollections,
   'stagedDues' => $stagedDues,
  ]);
 }
 private function authorizeStudentWrite(Request $r,int $student):object{
  abort_unless(in_array($r->user()->role,['super_admin','finance_admin','department_manager'],true),403);
  $s=DB::table('students')->where('id',$student)->first();abort_unless($s,404);
  if(!in_array($r->user()->role,['super_admin','finance_admin'],true)){
   abort_unless(DB::table('enrollments')->where('student_id',$student)->where('campus_id',$r->user()->campus_id)->when($r->user()->department_id,fn($q)=>$q->where('department_id',$r->user()->department_id))->exists(),403);
  }return $s;
 }
 public function studentStatus(Request $r,int $student){
  $s=$this->authorizeStudentWrite($r,$student);
  $d=$r->validate(['status'=>'required|in:present,suspended,left']);
  DB::transaction(function()use($r,$student,$s,$d){
   DB::table('students')->where('id',$student)->update(['status'=>$d['status'],'updated_at'=>now()]);
   DB::table('enrollments')->where('student_id',$student)->update(['status'=>$d['status']==='present'?'active':'inactive','updated_at'=>now()]);
   DB::table('audit_logs')->insert(['actor_id'=>$r->user()->id,'action'=>'student_status_changed','subject_type'=>'student','subject_id'=>$student,'metadata'=>json_encode(['from'=>$s->status,'to'=>$d['status']]),'created_at'=>now(),'updated_at'=>now()]);
  });
  return back()->with('notice','Student status changed. Existing invoices and receipts remain unchanged.');
 }
 public function deleteStudent(Request $r,int $student){
  $this->authorizeStudentWrite($r,$student);
  abort_unless($r->user()->role==='super_admin',403);
  DB::transaction(function()use($student,$r){
   $s=DB::table('students')->where('id',$student)->lockForUpdate()->first();abort_unless($s,404);
   $hasLedger=DB::table('invoice_items')->where('student_id',$student)->exists()
    || DB::table('invoices')->where('family_id',$s->family_id)->exists()
    || DB::table('payments')->where('family_id',$s->family_id)->exists();
   if($hasLedger)throw \Illuminate\Validation\ValidationException::withMessages(['student'=>'Cannot delete a student whose family has invoices or payments. Mark Left instead.']);
   $ids=DB::table('enrollments')->where('student_id',$student)->pluck('id');
   DB::table('fee_plan_items')->whereIn('enrollment_id',$ids)->delete();
   DB::table('student_registration_details')->where('student_id',$student)->delete();
   DB::table('enrollments')->where('student_id',$student)->delete();
   DB::table('students')->where('id',$student)->delete();
   if(!DB::table('students')->where('family_id',$s->family_id)->exists()) DB::table('families')->where('id',$s->family_id)->delete();
   DB::table('audit_logs')->insert(['actor_id'=>$r->user()->id,'action'=>'student_deleted','subject_type'=>'student','subject_id'=>$student,'metadata'=>json_encode(['gr_number'=>$s->gr_number]),'created_at'=>now(),'updated_at'=>now()]);
  });
  return redirect('/manage-student')->with('notice','Unbilled student removed.');
 }
 public function fees(){return view('fee-structure',['types'=>DB::table('fee_types')->orderBy('name')->get(),'plans'=>DB::table('fee_plan_items as p')->join('enrollments as e','e.id','=','p.enrollment_id')->join('students as s','s.id','=','e.student_id')->when(!in_array(auth()->user()->role,['super_admin','finance_admin'],true),fn($q)=>$q->where('e.campus_id',auth()->user()->campus_id)->when(auth()->user()->department_id,fn($v)=>$v->where('e.department_id',auth()->user()->department_id)))->select('p.*','s.name as student_name','s.gr_number')->orderByDesc('p.id')->paginate(25),'enrollments'=>DB::table('enrollments as e')->join('students as s','s.id','=','e.student_id')->when(!in_array(auth()->user()->role,['super_admin','finance_admin'],true),fn($q)=>$q->where('e.campus_id',auth()->user()->campus_id)->when(auth()->user()->department_id,fn($v)=>$v->where('e.department_id',auth()->user()->department_id)))->select('e.id','s.name','s.gr_number','e.currency')->orderBy('s.name')->limit(200)->get()]);}
 public function addFeePlan(Request $r){$d=$r->validate(['enrollment_id'=>'required|exists:enrollments,id','fee_type_id'=>'required|exists:fee_types,id','description'=>'required|string|max:255','amount'=>['required','regex:/^\d{1,13}(?:\.\d{1,2})?$/'],'frequency'=>['required',Rule::in(['monthly','weekly','quarterly','term','annual','one_time','custom'])],'discount_kind'=>'nullable|in:fixed,percent','discount_value'=>'nullable|numeric|min:0','subject_name'=>'nullable|string|max:120','class_days_per_week'=>'nullable|integer|min:1|max:7']);$en=DB::table('enrollments')->where('id',$d['enrollment_id'])->first();abort_if($r->user()->role==='auditor',403);$this->assertAccess($r,$en->department_id,$en->campus_id);
  $discountKind=$d['discount_kind']??null;$discountValue=$discountKind==='percent'?(int)round(((float)($d['discount_value']??0))*100):($discountKind==='fixed'?Money::minor((string)($d['discount_value']??0)):0);abort_if($discountKind==='percent'&&$discountValue>10000,422);
  DB::table('fee_plan_items')->insert(['enrollment_id'=>$d['enrollment_id'],'fee_type_id'=>$d['fee_type_id'],'description'=>$d['description'],'frequency'=>$d['frequency'],'currency'=>$en->currency,'amount_minor'=>Money::minor((string)$d['amount']),'discount_kind'=>$discountKind,'discount_value'=>$discountValue,'subject_name'=>$d['subject_name']??null,'class_days_per_week'=>$d['class_days_per_week']??null,'created_at'=>now(),'updated_at'=>now()]);return back()->with('notice','Fee plan saved.');}
 public function creator(Request $r){
  $grades=DB::table('enrollments')->where('status','active')->when(!in_array($r->user()->role,['super_admin','finance_admin'],true),fn($q)=>$q->where('campus_id',$r->user()->campus_id)->when($r->user()->department_id,fn($v)=>$v->where('department_id',$r->user()->department_id)))->whereNotNull('grade_label')->distinct()->orderBy('grade_label')->pluck('grade_label');
  return view('fee-creator',$this->basics($r)+['types'=>DB::table('fee_types')->get(),'grades'=>$grades]);
 }
 public function generate(Request $r,InvoiceService $svc){
  abort_if($r->user()->role==='auditor',403);
  $monthlySelection=$r->input('frequency')==='monthly' && ($r->boolean('monthly_selection') || $r->has('months'));
  $rules=[
   'campus_id'=>'required|integer|exists:campuses,id',
   'department_id'=>'required|integer|exists:departments,id',
   'frequency'=>'required|in:monthly,weekly,quarterly,term,annual,one_time,custom',
   'fee_type_ids'=>'nullable|array','fee_type_ids.*'=>'integer|exists:fee_types,id',
  ];
  if($monthlySelection){
   $rules+=[
    'year'=>'required|integer|between:2000,2100','months'=>'required|array|min:1|max:12',
    'months.*'=>'required|integer|between:1,12|distinct','due_day'=>'required|integer|between:1,28',
   ];
  }else{
   $rules+=[
    'period_start'=>'required|date','period_end'=>'required|date|after_or_equal:period_start',
    'due_date'=>'required|date',
   ];
  }
  $d=$r->validate($rules);
  $this->assertAccess($r,$d['department_id'],$d['campus_id']);
  if($monthlySelection){
   $periods=BillingPeriod::selectedMonths((int)$d['year'],$d['months'],(int)$d['due_day']);
  }else{
   $from=\Carbon\Carbon::parse($d['period_start']);$to=\Carbon\Carbon::parse($d['period_end']);
   if($d['frequency']==='monthly' && ($from->format('Y-m')!==$to->format('Y-m')||$from->day!==1||$to->day!==$to->daysInMonth))
    throw \Illuminate\Validation\ValidationException::withMessages(['period_end'=>'Monthly billing must cover a full calendar month.']);
   $periods=[['start'=>$from->toDateString(),'end'=>$to->toDateString(),'due'=>$d['due_date']]];
  }
  // All selected months either finish together or are rolled back together.
  $result=DB::transaction(function()use($d,$r,$svc,$periods){
   $total=['created'=>0,'skipped'=>0,'invoices'=>[]];
   foreach($periods as $period){
    $key=$d['frequency'].':'.$period['start'].'..'.$period['end'];
    $runId=DB::table('billing_runs')->insertGetId([
     'campus_id'=>$d['campus_id'],'department_id'=>$d['department_id'],
     'frequency'=>$d['frequency'],'period_key'=>$key,
     'period_start'=>$period['start'],'period_end'=>$period['end'],'due_date'=>$period['due'],
     'fee_type_ids'=>json_encode($d['fee_type_ids']??[]),
     'created_by'=>$r->user()->id,'created_at'=>now(),'updated_at'=>now(),
    ]);
    $one=$svc->generate($runId);
    $total['created']+=$one['created'];$total['skipped']+=$one['skipped'];
    array_push($total['invoices'],...$one['invoices']);
   }
   return $total;
  });
  return redirect('/invoices')->with('notice',"Created {$result['created']} invoices across ".count($periods)." period(s); skipped {$result['skipped']} previously billed items. Nothing has been sent.");
 }
 public function invoices(Request $r){
  $q=DB::table('invoices as i')->join('families as f','f.id','=','i.family_id')->join('departments as d','d.id','=','i.department_id')->join('billing_runs as b','b.id','=','i.billing_run_id')
   ->select('i.*','f.family_code','f.whatsapp','f.email','d.name as department_name','b.period_start')
   ->selectSub(DB::table('notifications')->select('status')->whereColumn('invoice_id','i.id')->where('channel','whatsapp')->orderByDesc('id')->limit(1),'whatsapp_status')
   ->selectSub(DB::table('notifications')->select('status')->whereColumn('invoice_id','i.id')->where('channel','email')->orderByDesc('id')->limit(1),'email_status');
  if($dep=$r->query('department_id'))$q->where('i.department_id',$dep);
  if($period=$r->query('period'))$q->where('b.period_start','like',$period.'%');
  if($r->boolean('paid_only'))$q->where('i.status','paid');
  // Paid invoices stay in the ledger but are not eligible for demand delivery.
  if(in_array($r->query('send_mode'),['whatsapp','email'],true)){
   $q->where('i.status','!=','paid')->whereColumn('i.paid_minor','<','i.total_minor');
  }
  if($grade=$r->query('grade'))$q->whereExists(fn($sub)=>$sub->selectRaw('1')->from('invoice_items as fi')->join('enrollments as fe','fe.id','=','fi.enrollment_id')->whereColumn('fi.invoice_id','i.id')->where('fe.grade_label',$grade));
  if(!in_array($r->user()->role,['super_admin','finance_admin'],true)){$q->where('i.campus_id',$r->user()->campus_id)->when($r->user()->department_id,fn($v)=>$v->where('i.department_id',$r->user()->department_id));}
  $page=$q->orderByDesc('i.id')->paginate(30)->withQueryString();
  $ids=$page->getCollection()->pluck('id')->all();
  $gradesByInvoice=collect();
  if($ids)$gradesByInvoice=DB::table('invoice_items as item')->join('enrollments as en','en.id','=','item.enrollment_id')->whereIn('item.invoice_id',$ids)->whereNotNull('en.grade_label')->select('item.invoice_id','en.grade_label')->distinct()->get()->groupBy('invoice_id')->map(fn($rows)=>$rows->pluck('grade_label')->unique()->sort()->values()->all());
  $grByInvoice=collect();
  if($ids)$grByInvoice=DB::table('invoice_items as item')->join('students as s','s.id','=','item.student_id')
   ->whereIn('item.invoice_id',$ids)->select('item.invoice_id','s.gr_number')->distinct()->get()
   ->groupBy('invoice_id')->map(fn($rows)=>$rows->pluck('gr_number')->filter()->unique()->sort()->values()->all());
  $gradeOptions=DB::table('enrollments')->whereNotNull('grade_label')->distinct()->orderBy('grade_label')->pluck('grade_label');
  return view('invoices',['invoices'=>$page,'departments'=>$this->basics($r)['departments'],'gradesByInvoice'=>$gradesByInvoice,'grByInvoice'=>$grByInvoice,'gradeOptions'=>$gradeOptions]);
 }
 public function approveSend(Request $r){
  abort_if($r->user()->role==='auditor',403);
  $d=$r->validate(['invoice_ids'=>'required|array|min:1|max:50','invoice_ids.*'=>'integer|exists:invoices,id','channels'=>'required|array|min:1','channels.*'=>'in:whatsapp,email','confirmed_grades'=>'required|array|min:1','confirmed_grades.*'=>'string|max:190','review_confirmed'=>'accepted','send_mode'=>'nullable|in:whatsapp,email','selected_grade'=>'nullable|string|max:190']);
  $ids=array_values(array_unique(array_map('intval',$d['invoice_ids'])));
  $invoices=DB::table('invoices')->whereIn('id',$ids)->get();
  $allGrades=DB::table('invoice_items as ii')->join('enrollments as e','e.id','=','ii.enrollment_id')->whereIn('ii.invoice_id',$ids)->whereNotNull('e.grade_label')->distinct()->pluck('e.grade_label')->all();
  if(in_array($d['send_mode']??null,['whatsapp','email'],true)){
   $requiredChannel=$d['send_mode'];
   if($d['channels']!==[$requiredChannel])throw \Illuminate\Validation\ValidationException::withMessages(['channels'=>'Choose only the channel selected for this delivery workflow.']);
   $chosenGrade=$d['selected_grade'];
   if($chosenGrade){
    $matching=DB::table('invoice_items as ii')->join('enrollments as e','e.id','=','ii.enrollment_id')->whereIn('ii.invoice_id',$ids)->where('e.grade_label',$chosenGrade)->distinct()->pluck('ii.invoice_id')->map(fn($id)=>(int)$id)->all();
    if(array_diff($ids,$matching))throw \Illuminate\Validation\ValidationException::withMessages(['selected_grade'=>'Every selected invoice must contain the chosen grade.']);
   }
  }
  $missing=array_diff($allGrades,$d['confirmed_grades']);
  if(!$allGrades){$allGrades=['Not assigned'];$missing=array_diff($allGrades,$d['confirmed_grades']);}
  if($missing)throw \Illuminate\Validation\ValidationException::withMessages(['confirmed_grades'=>'Confirm every grade included in selected family invoices: '.implode(', ',$missing)]);
  foreach($invoices as $invoice){
   $this->assertAccess($r,$invoice->department_id,$invoice->campus_id);
   if($invoice->status==='paid'||((int)$invoice->total_minor>0&&(int)$invoice->paid_minor>=(int)$invoice->total_minor))
    throw \Illuminate\Validation\ValidationException::withMessages(['invoice_ids'=>'Paid invoices cannot be sent as fee demands. Remove settled invoices from the selection.']);
  }
  $count=0;$blocked=0;
  foreach($invoices as $invoice){
   $family=DB::table('families')->where('id',$invoice->family_id)->first();
   foreach($d['channels'] as $channel){
    if(!$family?->contact_verified||($channel==='whatsapp'&&(!$family->messaging_opt_in||!$family->whatsapp))||($channel==='email'&&!$family->email)){$blocked++;continue;}
    $existing=DB::table('notifications')->where(['invoice_id'=>$invoice->id,'channel'=>$channel,'kind'=>'invoice'])->first();
    if($existing&&in_array($existing->status,['queued','submitted','delivered','read'],true))continue;
    $notificationId=$existing?->id??DB::table('notifications')->insertGetId(['invoice_id'=>$invoice->id,'channel'=>$channel,'kind'=>'invoice','status'=>'queued','created_at'=>now(),'updated_at'=>now()]);
    DB::table('notifications')->where('id',$notificationId)->update(['status'=>'queued','error'=>null,'updated_at'=>now()]);
    DispatchInvoiceToN8n::dispatch($notificationId)->onQueue('notifications');$count++;
   }
   DB::table('invoices')->where('id',$invoice->id)->update(['approved_to_send'=>true]);
  }
  return back()->with('notice',"Queued {$count} messages; {$blocked} channels blocked pending verified contact/consent.");
 }
 public function collection(Request $r){
  $family=null;$invoices=collect();$invoiceItems=collect();$payments=collect();$dueGroups=collect();$registrationStatus=null;
  $summaryMetrics=['total_session_fee_minor'=>0,'total_discount_minor'=>0,'total_received_minor'=>0,'total_due_minor'=>0];
  $term=trim((string)$r->query('family',''));
  if($term){
   $family=DB::table('families')->where('family_code',$term)->first();
   if(!$family){$family=DB::table('families as f')->join('students as s','s.family_id','=','f.id')->where('s.gr_number',$term)->select('f.*')->first();}
   if(!$family){$family=DB::table('families as f')->join('invoices as i','i.family_id','=','f.id')->where('i.invoice_number',$term)->select('f.*')->first();}
   if($family&&!in_array($r->user()->role,['super_admin','finance_admin'],true)){
    $visible=DB::table('enrollments as e')->join('students as s','s.id','=','e.student_id')
     ->where('s.family_id',$family->id)->where('e.campus_id',$r->user()->campus_id)
     ->when($r->user()->department_id,fn($q)=>$q->where('e.department_id',$r->user()->department_id))->exists();
    $billed=DB::table('invoices')->where('family_id',$family->id)->where('campus_id',$r->user()->campus_id)
     ->when($r->user()->department_id,fn($q)=>$q->where('department_id',$r->user()->department_id))->exists();
    if(!$visible&&!$billed)$family=null;
   }
  }
  if($family){
   $invoices=DB::table('invoices as i')->join('departments as d','d.id','=','i.department_id')->join('billing_runs as b','b.id','=','i.billing_run_id')->where('i.family_id',$family->id)
    ->when(!in_array($r->user()->role,['super_admin','finance_admin'],true),fn($q)=>$q->where('i.campus_id',$r->user()->campus_id)->when($r->user()->department_id,fn($v)=>$v->where('i.department_id',$r->user()->department_id)))
    ->select('i.*','d.name as department_name','b.period_start')->orderBy('i.due_date')->get();

   $invoiceIds=$invoices->pluck('id')->all();
   if($invoiceIds){
    $invoiceItems=DB::table('invoice_items as ii')
     ->join('invoices as i','i.id','=','ii.invoice_id')
     ->join('departments as d','d.id','=','i.department_id')
     ->join('students as s','s.id','=','ii.student_id')
     ->leftJoin('enrollments as en','en.id','=','ii.enrollment_id')
     ->leftJoin('fee_types as ft','ft.id','=','ii.fee_type_id')
     ->whereIn('ii.invoice_id',$invoiceIds)
     ->where('ii.status','!=','voided')
     ->select('ii.*','i.invoice_number','i.due_date','d.name as department_name','s.name as student_name','s.gr_number','en.grade_label as class_name','ft.name as fee_type_name','ft.code as fee_type_code')
     ->orderBy('ii.period_start')->orderBy('ii.id')->get();

    $itemIds=$invoiceItems->pluck('id')->all();
    $installmentsByItem=DB::table('fee_installments')->whereIn('invoice_item_id',$itemIds)->get()->groupBy('invoice_item_id');
    $adjustmentsByItem=DB::table('fee_adjustments')->whereIn('invoice_item_id',$itemIds)->orderByDesc('id')->get()->groupBy('invoice_item_id');

    $invoiceItems=$invoiceItems->map(function($item)use($installmentsByItem,$adjustmentsByItem){
     $item->installments=$installmentsByItem->get($item->id,collect());
     $item->adjustments=$adjustmentsByItem->get($item->id,collect());
     $latestDisc=$item->adjustments->firstWhere('kind','discount');
     $item->discount_reason=$latestDisc?->reason;
     return $item;
    });
   }

   $regFeeType=DB::table('fee_types')->where('code','registration')->first();
   $regObligations=DB::table('invoice_items as ii')
    ->join('invoices as i','i.id','=','ii.invoice_id')
    ->where('i.family_id',$family->id)
    ->where('ii.fee_type_id',$regFeeType?->id)
    ->where('ii.status','!=','voided')
    ->selectRaw('COALESCE(SUM(ii.amount_minor),0) as charged, COALESCE(SUM(ii.discount_minor),0) as discount, COALESCE(SUM(ii.exemption_minor),0) as exemption, COALESCE(SUM(ii.net_minor),0) as net, COALESCE(SUM(ii.paid_minor),0) as paid')
    ->first();

   $regCharged=(int)($regObligations?->charged??0);
   $regPaid=(int)($regObligations?->paid??0);
   $regNet=(int)($regObligations?->net??0);
   $regBalance=max(0,$regNet-$regPaid);

   $registrationStatus=[
    'has_obligation'=>$regCharged>0,
    'charged_minor'=>$regCharged,
    'paid_minor'=>$regPaid,
    'balance_minor'=>$regBalance,
    'is_fully_paid'=>($regCharged>0 && $regBalance===0),
    'is_exempted'=>($regCharged>0 && (int)($regObligations?->exemption??0)>=$regCharged),
   ];

   $payments=DB::table('payments as p')
    ->leftJoin('users as u','u.id','=','p.received_by')
    ->leftJoin('users as rev','rev.id','=','p.reversed_by')
    ->where('p.family_id',$family->id)
    ->when(!in_array($r->user()->role,['super_admin','finance_admin'],true),fn($q)=>$q->where('p.campus_id',$r->user()->campus_id)->when($r->user()->department_id,fn($v)=>$v->where('p.department_id',$r->user()->department_id)))
    ->select('p.*','u.name as received_by_name','rev.name as reversed_by_name')
    ->orderByDesc('p.id')->limit(50)->get();

   $paymentIds=$payments->pluck('id')->all();
   $allocationsByPayment=collect();
   if($paymentIds){
    $allocationsByPayment=DB::table('payment_allocations as pa')
     ->join('invoice_items as ii','ii.id','=','pa.invoice_item_id')
     ->leftJoin('enrollments as en','en.id','=','ii.enrollment_id')
     ->leftJoin('fee_types as ft','ft.id','=','ii.fee_type_id')
     ->leftJoin('students as s','s.id','=','ii.student_id')
     ->whereIn('pa.payment_id',$paymentIds)
     ->select('pa.payment_id','pa.amount_minor','ii.description','ii.discount_minor','ii.period_start','en.grade_label','ft.name as fee_type_name','s.name as student_name','s.gr_number')
     ->get()->groupBy('payment_id');
   }

   $payments=$payments->map(function($payment)use($allocationsByPayment){
    $allocs=$allocationsByPayment->get($payment->id,collect());
    $payment->fee_types=$allocs->pluck('fee_type_name')->filter()->unique()->values()->all();
    if(empty($payment->fee_types))$payment->fee_types=$allocs->pluck('description')->filter()->unique()->values()->all();
    $payment->classes=$allocs->pluck('grade_label')->filter()->unique()->values()->all();
    $payment->months=$allocs->map(fn($a)=>\Carbon\Carbon::parse($a->period_start)->format('M Y'))->unique()->values()->all();
    $payment->total_discount_minor=(int)$allocs->sum('discount_minor');
    $payment->method='Cash';
    $payment->allocations=$allocs;
    return $payment;
   });

   $summaryMetrics=[
    'total_session_fee_minor'=>(int)$invoiceItems->sum('amount_minor'),
    'total_discount_minor'=>(int)$invoiceItems->sum('discount_minor'),
    'total_received_minor'=>(int)$invoiceItems->sum('paid_minor'),
    'total_due_minor'=>(int)$invoiceItems->sum(fn($item)=>max(0,(int)$item->net_minor-(int)$item->paid_minor)),
   ];

   $dueGroups=DB::table('invoices as i')->join('departments as d','d.id','=','i.department_id')->where('i.family_id',$family->id)->when(!in_array($r->user()->role,['super_admin','finance_admin'],true),fn($q)=>$q->where('i.campus_id',$r->user()->campus_id)->when($r->user()->department_id,fn($v)=>$v->where('i.department_id',$r->user()->department_id)))->select('d.name as department','i.currency')->selectRaw('SUM(i.total_minor-i.paid_minor) AS balance')->groupBy('d.name','i.currency')->get();
  }
  $scopes=collect();
  if($family){
   $active=DB::table('enrollments as e')->join('students as s','s.id','=','e.student_id')
    ->join('campuses as c','c.id','=','e.campus_id')->join('departments as d','d.id','=','e.department_id')
    ->where('s.family_id',$family->id)->where('e.status','active')
    ->select('e.campus_id','e.department_id','e.currency','c.name as campus_name','d.name as department_name')->distinct()->get();
   $billed=DB::table('invoices as i')->join('campuses as c','c.id','=','i.campus_id')
    ->join('departments as d','d.id','=','i.department_id')->where('i.family_id',$family->id)
    ->select('i.campus_id','i.department_id','i.currency','c.name as campus_name','d.name as department_name')->distinct()->get();
   $scopes=$active->concat($billed)->filter(fn($scope)=>$r->user()->canAccessDepartment((int)$scope->department_id,(int)$scope->campus_id))
    ->unique(fn($scope)=>$scope->campus_id.':'.$scope->department_id.':'.$scope->currency)->values();
  }
  $feeTypes = DB::table('fee_types')->orderBy('id')->get();
  $familyStudents = $family ? DB::table('students as s')
   ->join('enrollments as e', 'e.student_id', '=', 's.id')
   ->where('s.family_id', $family->id)
   ->where('e.status', 'active')
   ->select('s.*', 'e.id as enrollment_id', 'e.grade_label', 'e.campus_id', 'e.department_id', 'e.currency', 'e.monthly_fee_minor')
   ->get() : collect();
  $catalogRates = DB::table('fee_catalog_rates')->get();
  $studentFeePlans = $family ? DB::table('fee_plan_items as fpi')
   ->join('enrollments as e', 'e.id', '=', 'fpi.enrollment_id')
   ->join('students as s', 's.id', '=', 'e.student_id')
   ->join('fee_types as ft', 'ft.id', '=', 'fpi.fee_type_id')
   ->where('s.family_id', $family->id)
   ->where('fpi.is_active', true)
   ->select('fpi.*', 's.name as student_name', 's.gr_number', 'e.grade_label', 'ft.name as fee_type_name', 'ft.code as fee_type_code')
   ->get() : collect();
  $advanceCredits = $family ? DB::table('payments')
   ->where('family_id', $family->id)
   ->where('status', 'received')
   ->where('unallocated_minor', '>', 0)
   ->get() : collect();

  $activeBatch = (string) config('imports.active_batch_id', 'BATCH-20261022-PORTAL-V2-REVISED');
  $familyGrNumbers = $family ? DB::table('students')->where('family_id', $family->id)->pluck('gr_number')->filter()->all() : [];
  $historicalCollections = (\Illuminate\Support\Facades\Schema::hasTable('qa_staged_collections') && !empty($familyGrNumbers))
   ? DB::table('qa_staged_collections')->where('batch_id', $activeBatch)->whereIn('gr_number', $familyGrNumbers)->orderBy('source_row')->get()
   : collect();
  $historicalDues = (\Illuminate\Support\Facades\Schema::hasTable('qa_staged_dues') && !empty($familyGrNumbers))
   ? DB::table('qa_staged_dues')->where('batch_id', $activeBatch)->whereIn('gr_number', $familyGrNumbers)->orderBy('source_row')->get()
   : collect();

  $feeHistoryService = app(\App\Services\FeeHistoryService::class);
  $feeHistory = $family ? $feeHistoryService->getFamilyFeeHistory($family->id, $r->all(), $r->user()) : new \Illuminate\Pagination\LengthAwarePaginator([], 0, 20);
  return view('collection',$this->basics($r)+compact('family','invoices','invoiceItems','payments','feeHistory','dueGroups','scopes','registrationStatus','summaryMetrics','feeTypes','familyStudents','catalogRates','studentFeePlans','advanceCredits','historicalCollections','historicalDues'));
 }

 public function createChallanFee(Request $r){
  abort_if($r->user()->role==='auditor',403);
  $d=$r->validate([
   'family_id'=>'required|exists:families,id',
   'student_ids'=>'nullable|array',
   'student_ids.*'=>'integer|exists:students,id',
   'fee_type_ids'=>'required|array|min:1',
   'fee_type_ids.*'=>'integer|exists:fee_types,id',
   'months'=>'required|array|min:1',
   'months.*'=>'integer|between:1,12',
   'year'=>'required|integer|between:2020,2050',
  ]);

  $family=DB::table('families')->where('id',$d['family_id'])->first();
  abort_unless($family,404);

  $studentsQuery=DB::table('students as s')
   ->join('enrollments as e','e.student_id','=','s.id')
   ->where('s.family_id',$family->id)
   ->where('e.status','active');

  if(!empty($d['student_ids'])){
   $held=DB::table('students')->whereIn('id',$d['student_ids'])->whereIn('status',['review_hold','historical_hold'])->first();
   if($held){
    $msg=$held->status==='review_hold'
     ?"Student GR {$held->gr_number} is on financial review hold and cannot be billed until approvals are finalized."
     :"Student GR {$held->gr_number} is a historical record and cannot receive new monthly bills.";
    throw \Illuminate\Validation\ValidationException::withMessages(['student_ids'=>$msg]);
   }
   $studentsQuery->whereIn('s.id',$d['student_ids']);
  }

  $students=$studentsQuery->whereNotIn('s.status',['review_hold','historical_hold','suspended','left'])
   ->select('s.*','e.id as enrollment_id','e.campus_id','e.department_id','e.currency','e.grade_label','e.monthly_fee_minor')->get();

  if($students->isEmpty()){
   throw \Illuminate\Validation\ValidationException::withMessages([
    'student_ids'=>'No active billable student enrollments found for this family.'
   ]);
  }

  $createdItems=0;
  $skippedItems=0;
  $affectedInvoices=[];

  DB::transaction(function() use ($d,$students,$family,$r,&$createdItems,&$skippedItems,&$affectedInvoices){
   $year=(int)$d['year'];
   $feeTypes=DB::table('fee_types')->whereIn('id',$d['fee_type_ids'])->get()->keyBy('id');

   foreach($students as $student){
    $this->assertAccess($r,$student->department_id,$student->campus_id);
    $deptName=DB::table('departments')->where('id',$student->department_id)->value('name')?:'Department';

    foreach($d['fee_type_ids'] as $ftId){
     $feeType=$feeTypes->get($ftId);
     if(!$feeType) continue;

     // Rule 4: One-time fee vs recurring monthly fee
     $isOneTime=!in_array($feeType->code,['monthly','tuition'],true);

     // Rule 1, 2, 3, 6: Rate resolution
     $amountMinor=0;
     $discountMinor=0;

     // Priority 1: Check student-specific active plan in fee_plan_items
     $planItem=DB::table('fee_plan_items')
      ->where('enrollment_id',$student->enrollment_id)
      ->where('fee_type_id',$feeType->id)
      ->where('is_active',true)
      ->where('currency',$student->currency)
      ->first();

     if($planItem){
      $amountMinor=(int)$planItem->amount_minor;
      $discountMinor=\App\Services\Money::discount($amountMinor,$planItem->discount_kind,(int)$planItem->discount_value);
     }elseif($feeType->code==='monthly' && !empty($student->monthly_fee_minor)){
      $amountMinor=(int)$student->monthly_fee_minor;
     }else{
      // Priority 2: Check approved class catalog rate
      $rate=DB::table('fee_catalog_rates')
       ->where('fee_type_id',$feeType->id)
       ->where('department_id',$student->department_id)
       ->where('class_label',$student->grade_label)
       ->where('currency',$student->currency)
       ->first();

      if($rate){
       $amountMinor=(int)$rate->amount_minor;
      }
     }

     // Rule 2 & 3: Never fall back to unrelated class/currency. Missing rate = clear error.
     if($amountMinor<=0){
      throw \Illuminate\Validation\ValidationException::withMessages([
       'fee_type_ids'=>"No approved rate found for fee '{$feeType->name}' for student {$student->name} (GR {$student->gr_number}, Class: '{$student->grade_label}', Dept: {$deptName}, Currency: {$student->currency}). Configure this class rate in Fee Structure before generating fees."
      ]);
     }

     // Determine target periods: one-time fee only bills once, monthly bills each selected month
     $monthsToBill=$isOneTime?[ (int)reset($d['months']) ]:$d['months'];

     foreach($monthsToBill as $m){
      $month=(int)$m;
      $periodStart=sprintf('%04d-%02d-01',$year,$month);
      $periodEnd=date('Y-m-t',strtotime($periodStart));
      $dueDate=sprintf('%04d-%02d-25',$year,$month);
      $periodKey=$isOneTime?"one_time:{$feeType->code}:{$periodStart}":"monthly:{$periodStart}..{$periodEnd}";

      // Rule 7: Prevent duplicate fee obligations in regular billing
      $existsQuery=DB::table('invoice_items')
       ->where('student_id',$student->id)
       ->where('fee_type_id',$feeType->id)
       ->where('status','!=','voided');

      if($isOneTime){
       $alreadyBilled=$existsQuery->exists();
      }else{
       $alreadyBilled=$existsQuery->where('period_start',$periodStart)->exists();
      }

      if($alreadyBilled){
       $skippedItems++;
       continue;
      }

      // Rule 7: Prevent duplicate fee obligations already represented in opening balances
      $obQuery=DB::table('invoice_items as ii')
       ->join('invoices as inv','inv.id','=','ii.invoice_id')
       ->join('billing_runs as br','br.id','=','inv.billing_run_id')
       ->where('br.frequency','opening_balance')
       ->where('ii.enrollment_id',$student->enrollment_id)
       ->where('ii.fee_type_id',$feeType->id);

      if($isOneTime){
       $inHistorical=$obQuery->exists();
      }else{
       $inHistorical=$obQuery->where('ii.period_start',$periodStart)->exists();
      }

      if($inHistorical){
       $skippedItems++;
       continue;
      }

      // Rule 8 & 9: Find existing regular family invoice for this period. NEVER attach to opening balance invoices!
      $invoice=DB::table('invoices as i')
       ->join('billing_runs as b','b.id','=','i.billing_run_id')
       ->where('i.family_id',$family->id)
       ->where('i.department_id',$student->department_id)
       ->where('i.campus_id',$student->campus_id)
       ->where('i.currency',$student->currency)
       ->where('b.frequency','!=','opening_balance')
       ->where('b.period_start',$periodStart)
       ->whereNotIn('i.status',['void','voided','paid'])
       ->select('i.*')
       ->lockForUpdate()
       ->first();

      if(!$invoice){
       $billingRun=DB::table('billing_runs')
        ->where('campus_id',$student->campus_id)
        ->where('department_id',$student->department_id)
        ->where('period_start',$periodStart)
        ->where('frequency','!=','opening_balance')
        ->where('frequency',$isOneTime?'one_time':'monthly')
        ->first();

       if(!$billingRun){
        $runId=DB::table('billing_runs')->insertGetId([
         'campus_id'=>$student->campus_id,
         'department_id'=>$student->department_id,
         'frequency'=>$isOneTime?'one_time':'monthly',
         'period_key'=>$periodKey,
         'period_start'=>$periodStart,
         'period_end'=>$periodEnd,
         'due_date'=>$dueDate,
         'status'=>'generated',
         'created_by'=>$r->user()->id,
         'created_at'=>now(),
         'updated_at'=>now(),
        ]);
       }else{
        $runId=$billingRun->id;
       }

       $deptCode=DB::table('departments')->where('id',$student->department_id)->value('code')?:'SCH';
       $invoiceId=DB::table('invoices')->insertGetId([
        'billing_run_id'=>$runId,
        'family_id'=>$family->id,
        'campus_id'=>$student->campus_id,
        'department_id'=>$student->department_id,
        'currency'=>$student->currency,
        'invoice_number'=>'PENDING-'.\Illuminate\Support\Str::uuid(),
        'issued_on'=>now()->toDateString(),
        'due_date'=>$dueDate,
        'total_minor'=>0,
        'paid_minor'=>0,
        'previous_dues_snapshot_minor'=>0,
        'status'=>'generated',
        'created_at'=>now(),
        'updated_at'=>now(),
       ]);
       DB::table('invoices')->where('id',$invoiceId)->update([
        'invoice_number'=>strtoupper(substr($deptCode,0,3)).'-'.date('Y').'-'.str_pad((string)$invoiceId,7,'0',STR_PAD_LEFT),
       ]);
       $invoice=DB::table('invoices')->where('id',$invoiceId)->first();
      }

      $netMinor=max(0,$amountMinor-$discountMinor);

      DB::table('invoice_items')->insert([
       'invoice_id'=>$invoice->id,
       'student_id'=>$student->id,
       'enrollment_id'=>$student->enrollment_id,
       'fee_plan_item_id'=>$planItem?->id,
       'fee_type_id'=>$feeType->id,
       'description'=>$feeType->name,
       'period_key'=>$periodKey,
       'period_start'=>$periodStart,
       'period_end'=>$periodEnd,
       'currency'=>$student->currency,
       'amount_minor'=>$amountMinor,
       'discount_minor'=>$discountMinor,
       'net_minor'=>$netMinor,
       'paid_minor'=>0,
       'status'=>'active',
       'created_at'=>now(),
       'updated_at'=>now(),
      ]);

      $createdItems++;
      $affectedInvoices[$invoice->id]=[
       'invoice_id'=>$invoice->id,
       'family_id'=>$family->id,
       'currency'=>$student->currency,
       'department_id'=>$student->department_id,
       'campus_id'=>$student->campus_id,
      ];
     }
    }
   }

   $invoiceSvc=new \App\Services\InvoiceService();
   foreach($affectedInvoices as $invData){
    $invoiceSvc->refreshInvoice($invData['invoice_id']);
    $invoiceSvc->allocatePrepayments($invData['family_id'],$invData['currency'],$invData['department_id'],$invData['campus_id']);
   }
  });

  if($r->wantsJson()){
   return response()->json([
    'success'=>true,
    'message'=>"Created {$createdItems} fee obligation(s)".($skippedItems>0?" ({$skippedItems} duplicates skipped)":"").".",
    'created'=>$createdItems,
    'skipped'=>$skippedItems,
   ]);
  }

  return redirect('/challan?family='.urlencode($family->family_code?:(string)$family->id))
   ->with('notice',"Created {$createdItems} fee obligation(s)".($skippedItems>0?" ({$skippedItems} duplicates skipped)":"").".");
 }
 public function receive(Request $r,ReceivePaymentService $svc){
  if($r->filled('ledger_scope')){
   $scope=(string)$r->input('ledger_scope');
   if(!preg_match('/^([1-9][0-9]*):([1-9][0-9]*):([A-Za-z]{3})$/D',$scope,$parts))
    throw \Illuminate\Validation\ValidationException::withMessages(['ledger_scope'=>'Invalid ledger selection.']);
   $r->merge(['campus_id'=>$parts[1],'department_id'=>$parts[2],'currency'=>strtoupper($parts[3])]);
  }
  $d=$r->validate([
   'family_id'=>'required|exists:families,id','campus_id'=>'required|exists:campuses,id',
   'department_id'=>'required|exists:departments,id','currency'=>'required|alpha|size:3',
   'receiving_amount'=>['nullable','regex:/^\d{1,13}(?:\.\d{1,2})?$/'],
   'monthly_amount'=>['nullable','regex:/^\d{1,13}(?:\.\d{1,2})?$/'],
   'registration_amount'=>['nullable','regex:/^\d{1,13}(?:\.\d{1,2})?$/'],
   'receive_registration_fee'=>'nullable|boolean',
   'create_registration_charge'=>['nullable','regex:/^\d{1,13}(?:\.\d{1,2})?$/'],
   'selected_period'=>'nullable|date_format:Y-m','receiving_date'=>'nullable|date',
   'note'=>'nullable|string|max:1000','idempotency_key'=>'required|uuid',
   'selected_item_ids'=>'nullable|array',
   'selected_item_ids.*'=>'integer|exists:invoice_items,id',
   'allow_advance_credit'=>'nullable|boolean',
  ]);
  abort_if($r->user()->role==='auditor',403);
  $this->assertAccess($r,$d['department_id'],$d['campus_id']);
  $payment=$svc->receive($d,$r->user()->id);
  $code=DB::table('families')->where('id',$d['family_id'])->value('family_code')?:$d['family_id'];
  return redirect('/challan?family='.urlencode((string)$code))
   ->with('notice','Received '.Money::format($payment->amount_minor).' '.$payment->currency.' (Receipt '.$payment->receipt_number.').');
 }

 public function discount(Request $r,FeeAdjustmentService $svc){
  abort_if($r->user()->role==='auditor',403);
  $isRemove=$r->boolean('remove_discount')||$r->input('action')==='remove'||($r->has('remove_discount')&&$r->input('amount')==0);
  if($isRemove){
   $d=$r->validate([
    'invoice_item_id'=>'required|integer|exists:invoice_items,id',
    'reason'=>'nullable|string|max:1000',
   ]);
   $item=DB::table('invoice_items as ii')->join('invoices as i','i.id','=','ii.invoice_id')->where('ii.id',$d['invoice_item_id'])->select('ii.*','i.department_id','i.campus_id')->first();
   abort_unless($item,404);
   $this->assertAccess($r,$item->department_id,$item->campus_id);
   $reason=$d['reason']?:'Discount removed';
   $updated=$svc->removeDiscount((int)$d['invoice_item_id'],$reason,$r->user()->id);
   $notice='Discount removed successfully. Original obligation restored.';
  }else{
   $d=$r->validate([
    'invoice_item_id'=>'required|integer|exists:invoice_items,id',
    'amount'=>['required','regex:/^\d{1,13}(?:\.\d{1,2})?$/','gt:0'],
    'reason'=>'required|string|max:1000',
   ]);
   $item=DB::table('invoice_items as ii')->join('invoices as i','i.id','=','ii.invoice_id')->where('ii.id',$d['invoice_item_id'])->select('ii.*','i.department_id','i.campus_id')->first();
   abort_unless($item,404);
   $this->assertAccess($r,$item->department_id,$item->campus_id);
   $updated=$svc->applyDiscount((int)$d['invoice_item_id'],Money::minor($d['amount']),$d['reason'],$r->user()->id);
   $notice='Discount of '.Money::format(Money::minor($d['amount'])).' '.$updated->currency.' applied successfully.';
  }
  if($r->wantsJson()){
   $newBalance=max(0,(int)$updated->net_minor-(int)$updated->paid_minor);
   return response()->json([
    'success'=>true,
    'notice'=>$notice,
    'item_id'=>$updated->id,
    'net_minor'=>$updated->net_minor,
    'discount_minor'=>$updated->discount_minor,
    'paid_minor'=>$updated->paid_minor,
    'balance_minor'=>$newBalance,
    'status'=>$updated->status,
   ]);
  }
  return back()->with('notice',$notice);
 }

 public function exempt(Request $r,FeeAdjustmentService $svc){
  abort_if($r->user()->role==='auditor',403);
  $d=$r->validate([
   'invoice_item_id'=>'required|integer|exists:invoice_items,id',
   'amount'=>['nullable','regex:/^\d{1,13}(?:\.\d{1,2})?$/'],
   'full_exemption'=>'nullable|boolean',
   'reason'=>'required|string|max:1000',
  ]);
  $item=DB::table('invoice_items as ii')->join('invoices as i','i.id','=','ii.invoice_id')->where('ii.id',$d['invoice_item_id'])->select('ii.*','i.department_id','i.campus_id')->first();
  abort_unless($item,404);
  $this->assertAccess($r,$item->department_id,$item->campus_id);
  $exemptMinor=!empty($d['full_exemption'])||empty($d['amount'])?null:Money::minor($d['amount']);
  $updated=$svc->applyExemption((int)$d['invoice_item_id'],$exemptMinor,$d['reason'],$r->user()->id);
  $notice='Exemption recorded for '.$updated->description.'.';
  if($r->wantsJson()){
   $newBalance=max(0,(int)$updated->net_minor-(int)$updated->paid_minor);
   return response()->json([
    'success'=>true,
    'notice'=>$notice,
    'item_id'=>$updated->id,
    'net_minor'=>$updated->net_minor,
    'exemption_minor'=>$updated->exemption_minor,
    'paid_minor'=>$updated->paid_minor,
    'balance_minor'=>$newBalance,
    'status'=>$updated->status,
   ]);
  }
  return back()->with('notice',$notice);
 }

 public function installment(Request $r,FeeAdjustmentService $svc){
  abort_if($r->user()->role==='auditor',403);
  $d=$r->validate([
   'invoice_item_id'=>'required|integer|exists:invoice_items,id',
   'installments'=>'required|array|min:2|max:12',
   'installments.*.amount'=>['required','regex:/^\d{1,13}(?:\.\d{1,2})?$/','gt:0'],
   'installments.*.due_date'=>'required|date',
  ]);
  $item=DB::table('invoice_items as ii')->join('invoices as i','i.id','=','ii.invoice_id')->where('ii.id',$d['invoice_item_id'])->select('ii.*','i.department_id','i.campus_id')->first();
  abort_unless($item,404);
  $this->assertAccess($r,$item->department_id,$item->campus_id);
  $schedule=array_map(fn($row)=>['amount_minor'=>Money::minor($row['amount']),'due_date'=>$row['due_date']],$d['installments']);
  $svc->createInstallmentPlan((int)$d['invoice_item_id'],$schedule,$r->user()->id);
  $notice='Installment schedule of '.count($schedule).' installments established.';
  if($r->wantsJson()){
   return response()->json([
    'success'=>true,
    'notice'=>$notice,
    'item_id'=>$d['invoice_item_id'],
    'installments_count'=>count($schedule),
   ]);
  }
  return back()->with('notice',$notice);
 }

 public function voidFee(Request $r,FeeAdjustmentService $svc){
  abort_if($r->user()->role==='auditor',403);
  $d=$r->validate([
   'invoice_item_id'=>'required|integer|exists:invoice_items,id',
   'reason'=>'required|string|max:1000',
  ]);
  $item=DB::table('invoice_items as ii')->join('invoices as i','i.id','=','ii.invoice_id')->where('ii.id',$d['invoice_item_id'])->select('ii.*','i.department_id','i.campus_id')->first();
  abort_unless($item,404);
  $this->assertAccess($r,$item->department_id,$item->campus_id);
  $svc->voidFee((int)$d['invoice_item_id'],$d['reason'],$r->user()->id);
  $notice='Fee obligation has been voided.';
  if($r->wantsJson()){
   return response()->json([
    'success'=>true,
    'notice'=>$notice,
    'item_id'=>$d['invoice_item_id'],
    'status'=>'voided',
   ]);
  }
  return back()->with('notice',$notice);
 }

 public function reverseReceipt(Request $r,ReceiptReversalService $svc){
  abort_if($r->user()->role==='auditor',403);
  $d=$r->validate([
   'payment_id'=>'required|integer|exists:payments,id',
   'reason'=>'required|string|max:1000',
  ]);
  $p=DB::table('payments')->where('id',$d['payment_id'])->first();
  abort_unless($p,404);
  $this->assertAccess($r,$p->department_id,$p->campus_id);
  $reversed=$svc->reverseReceipt((int)$d['payment_id'],$d['reason'],$r->user()->id);
  $notice='Receipt '.$reversed->receipt_number.' reversed and balances restored.';
  if($r->wantsJson()){
   return response()->json([
    'success'=>true,
    'notice'=>$notice,
    'payment_id'=>$reversed->id,
    'status'=>'reversed',
   ]);
  }
  return back()->with('notice',$notice);
 }
  public function receipt(int $payment,Request $r){
  $p=DB::table('payments')->where('id',$payment)->first();
  abort_unless($p,404);
  $this->assertAccess($r,$p->department_id,$p->campus_id);
  $family=DB::table('families')->where('id',$p->family_id)->first();
  $branding=DB::table('department_branding')->where('department_id',$p->department_id)->first();
  $banner=$this->bannerDataUri($branding);

  $items=DB::table('payment_allocations as a')
   ->join('invoice_items as i','i.id','=','a.invoice_item_id')
   ->join('students as s','s.id','=','i.student_id')
   ->leftJoin('fee_types as ft','ft.id','=','i.fee_type_id')
   ->where('a.payment_id',$p->id)
   ->select('a.amount_minor','i.description','i.period_key','i.period_start','i.amount_minor as original_amount_minor','i.discount_minor','i.net_minor','s.name','s.gr_number',\Illuminate\Support\Facades\DB::raw("COALESCE(ft.name, i.description, 'Monthly Fee') as fee_type_name"))
   ->orderBy('i.period_start')
   ->orderBy('a.id')
   ->get();

  $remainingBalanceMinor=(int)DB::table('invoices as inv')
   ->where('inv.family_id',$p->family_id)
   ->where('inv.currency',$p->currency)
   ->where('inv.department_id',$p->department_id)
   ->where('inv.campus_id',$p->campus_id)
   ->whereNotIn('inv.status',['void','voided','paid'])
   ->selectRaw('COALESCE(SUM(inv.total_minor - inv.paid_minor), 0) as remaining')
   ->value('remaining');

  return \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.receipt',compact('p','family','items','branding','banner','remainingBalanceMinor'))
   ->setPaper('a4')
   ->stream($p->receipt_number.'.pdf');
 }

 public function historicalReceiptPdf(int $id, Request $r){
  $feeHistoryService = app(\App\Services\FeeHistoryService::class);
  $receipt = $feeHistoryService->getHistoricalReceiptDetails($id);
  abort_unless($receipt, 404);
  if (!in_array($r->user()->role, ['super_admin', 'finance_admin'], true)) {
   $this->assertAccess($r, $receipt->department_id, $receipt->campus_id);
  }
  $branding = DB::table('department_branding')->where('department_id', $receipt->department_id)->first();
  $banner = $this->bannerDataUri($branding);
  $family = $receipt->family;

  return \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.historical-receipt', compact('receipt', 'family', 'branding', 'banner'))
   ->setPaper('a4')
   ->stream('historical-slip-' . $receipt->slip_number . '.pdf');
 }
 public function invoicePdf(int $invoice,Request $r){$i=DB::table('invoices')->where('id',$invoice)->first();abort_unless($i,404);if(!$r->hasValidSignature()){$this->assertAccess($r,$i->department_id,$i->campus_id);}return $this->renderInvoice($i);}
 private function renderInvoice(object $i){$family=DB::table('families')->where('id',$i->family_id)->first();$items=DB::table('invoice_items as item')->join('students as s','s.id','=','item.student_id')->where('item.invoice_id',$i->id)->select('item.*','s.name','s.gr_number')->get();$dept=DB::table('departments')->where('id',$i->department_id)->first();$branding=DB::table('department_branding')->where('department_id',$i->department_id)->first();$currentPeriod=DB::table('billing_runs')->where('id',$i->billing_run_id)->value('period_start');$previous=(int)DB::table('invoices as older')->join('billing_runs as b','b.id','=','older.billing_run_id')->where('older.id','<>',$i->id)->where('older.family_id',$i->family_id)->where('older.department_id',$i->department_id)->where('older.campus_id',$i->campus_id)->where('older.currency',$i->currency)->where('b.period_start','<',$currentPeriod)->selectRaw('COALESCE(SUM(older.total_minor-older.paid_minor),0) as dues')->value('dues');$history=[];$month=\Carbon\Carbon::parse($currentPeriod)->startOfMonth();
  for($back=6;$back>=1;$back--){$d=$month->copy()->subMonths($back);$stats=DB::table('invoice_items as x')->join('invoices as ix','ix.id','=','x.invoice_id')->where('ix.family_id',$i->family_id)->where('ix.department_id',$i->department_id)->where('ix.campus_id',$i->campus_id)->where('ix.currency',$i->currency)->where('x.period_start','>=',$d->toDateString())->where('x.period_start','<',$d->copy()->addMonth()->toDateString())->selectRaw('COALESCE(SUM(x.net_minor),0) AS billed,COALESCE(SUM(x.paid_minor),0) AS paid')->first();$status=!(int)$stats->billed?'Not billed':((int)$stats->paid>=(int)$stats->billed?'Paid':((int)$stats->paid>0?'Partial':'Unpaid'));$history[]=['month'=>$d->format('F'),'status'=>$status];}
  return \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.invoice',['i'=>$i,'family'=>$family,'items'=>$items,'dept'=>$dept,'branding'=>$branding,'previous'=>max(0,$previous),'history'=>$history,'banner'=>$this->bannerDataUri($branding)])->setPaper('a4')->stream($i->invoice_number.'.pdf');}
 public function callback(Request $r){$secret=config('services.n8n.callback_secret');$body=$r->getContent();$signature=$r->header('X-IQRA-Signature');abort_unless($secret&&$signature&&hash_equals(hash_hmac('sha256',$body,$secret),$signature),403);$d=$r->validate(['notification_id'=>'required|integer','status'=>'required|in:submitted,delivered,read,failed','provider_message_id'=>'nullable|string|max:255']);$current=DB::table('notifications')->where('id',$d['notification_id'])->value('status');if(!$current)return response()->json(['ok'=>false,'error'=>'Unknown notification'],404);
  if(($current==='read'&&$d['status']!=='read')||($current==='delivered'&&in_array($d['status'],['submitted','failed'],true)))return response()->json(['ok'=>true,'unchanged'=>true]);
  $update=['status'=>$d['status'],'updated_at'=>now()];if(!empty($d['provider_message_id']))$update['provider_message_id']=$d['provider_message_id'];if($d['status']==='delivered')$update['delivered_at']=now();DB::table('notifications')->where('id',$d['notification_id'])->update($update);return response()->json(['ok'=>true]);}
 public function report(Request $r){$q=DB::table('invoices as i')->join('billing_runs as b','b.id','=','i.billing_run_id')->join('departments as d','d.id','=','i.department_id')->select('d.name as department','i.currency','b.period_start')->selectRaw('SUM(i.total_minor) AS billed,SUM(i.paid_minor) AS received,SUM(i.total_minor-i.paid_minor) AS outstanding');if($dep=$r->query('department_id'))$q->where('i.department_id',$dep);if($month=$r->query('month'))$q->where('b.period_start','like',$month.'%');if(!in_array($r->user()->role,['super_admin','finance_admin'],true)){$q->where('i.campus_id',$r->user()->campus_id)->when($r->user()->department_id,fn($q)=>$q->where('i.department_id',$r->user()->department_id));}$rows=$q->groupBy('d.name','i.currency','b.period_start')->orderByDesc('b.period_start')->get();$classQuery=DB::table('invoice_items as item')->join('invoices as i','i.id','=','item.invoice_id')->join('enrollments as e','e.id','=','item.enrollment_id')->join('departments as d','d.id','=','i.department_id')->select('d.name as department','e.grade_label','i.currency')->selectRaw('SUM(item.net_minor) AS billed,SUM(item.paid_minor) AS received')->groupBy('d.name','e.grade_label','i.currency');
  if($dep=$r->query('department_id'))$classQuery->where('i.department_id',$dep);
  if($month=$r->query('month'))$classQuery->where('item.period_start','like',$month.'%');
  if(!in_array($r->user()->role,['super_admin','finance_admin'],true)){$classQuery->where('i.campus_id',$r->user()->campus_id)->when($r->user()->department_id,fn($v)=>$v->where('i.department_id',$r->user()->department_id));}
  $dateFormatSql = DB::connection()->getDriverName() === 'sqlite' ? "strftime('%Y-%m', p.received_on)" : "DATE_FORMAT(p.received_on, '%Y-%m')";
  $payments=DB::table('payments as p')->join('departments as d','d.id','=','p.department_id')->select('d.name as department','p.currency')->selectRaw("{$dateFormatSql} AS received_month, SUM(p.amount_minor) AS collected")->groupBy('d.name','p.currency',DB::raw($dateFormatSql));
  if($dep=$r->query('department_id'))$payments->where('p.department_id',$dep);if($month=$r->query('month'))$payments->where('p.received_on','like',$month.'%');if(!in_array($r->user()->role,['super_admin','finance_admin'],true)){$payments->where('p.campus_id',$r->user()->campus_id)->when($r->user()->department_id,fn($v)=>$v->where('p.department_id',$r->user()->department_id));}
  return view('reports',['rows'=>$rows,'classes'=>$classQuery->orderBy('d.name')->get(),'collections'=>$payments->orderByDesc('received_month')->get(),'departments'=>DB::table('departments')->get()]);}
 public function exportReport(Request $r){$q=DB::table('invoices as i')->join('families as f','f.id','=','i.family_id')->join('departments as d','d.id','=','i.department_id')->join('billing_runs as b','b.id','=','i.billing_run_id')->select('i.invoice_number','f.family_code','d.name as department','b.period_start','i.currency','i.total_minor','i.paid_minor');if($dept=$r->query('department_id'))$q->where('i.department_id',$dept);if($month=$r->query('month'))$q->where('b.period_start','like',$month.'%');if(!in_array($r->user()->role,['super_admin','finance_admin'],true)){$q->where('i.campus_id',$r->user()->campus_id)->when($r->user()->department_id,fn($v)=>$v->where('i.department_id',$r->user()->department_id));}
  return response()->streamDownload(function()use($q){$f=fopen('php://output','w');fputcsv($f,['Invoice','Family','Department','Period','Currency','Billed','Received','Outstanding']);foreach($q->cursor() as $row){fputcsv($f,[$row->invoice_number,$row->family_code,$row->department,$row->period_start,$row->currency,Money::format($row->total_minor),Money::format($row->paid_minor),Money::format($row->total_minor-$row->paid_minor)]);}fclose($f);},'iqra-report.csv',['Content-Type'=>'text/csv']);}


 public function exportXlsx(Request $r){
  $department=$r->query('department_id');$month=$r->query('month');
  $q=DB::table('invoice_items as x')->join('invoices as i','i.id','=','x.invoice_id')->join('families as f','f.id','=','i.family_id')->join('departments as d','d.id','=','i.department_id')->join('students as s','s.id','=','x.student_id')->leftJoin('enrollments as e','e.id','=','x.enrollment_id')->select('i.invoice_number','f.family_code','d.name as department','s.gr_number','s.name as student','e.grade_label','x.period_start','i.currency','x.description','x.net_minor','x.paid_minor');
  if($department)$q->where('i.department_id',$department);if($month)$q->where('x.period_start','like',$month.'%');
  if(!in_array($r->user()->role,['super_admin','finance_admin'],true)){$q->where('i.campus_id',$r->user()->campus_id)->when($r->user()->department_id,fn($v)=>$v->where('i.department_id',$r->user()->department_id));}
  $sheet=(new \PhpOffice\PhpSpreadsheet\Spreadsheet());$ws=$sheet->getActiveSheet();$ws->setTitle('Fee Report');
  $headers=['Invoice','Family','Department','GR','Student','Class','Period','Currency','Fee Type','Billed','Received','Outstanding'];$ws->fromArray($headers,null,'A1');$rnum=2;
  foreach($q->orderBy('i.invoice_number')->cursor() as $item){$ws->fromArray([$item->invoice_number,$item->family_code,$item->department,$item->gr_number,$item->student,$item->grade_label,$item->period_start,$item->currency,$item->description,($item->net_minor/100),($item->paid_minor/100),(($item->net_minor-$item->paid_minor)/100)],null,'A'.$rnum++);}
  $ws->getStyle('A1:L1')->getFont()->setBold(true);$ws->getStyle('J2:L'.max(2,$rnum))->getNumberFormat()->setFormatCode('#,##0.00');$ws->freezePane('A2');foreach(range('A','L') as $col)$ws->getColumnDimension($col)->setWidth(in_array($col,['E','I'],true)?28:16);
  $tmp=tempnam(sys_get_temp_dir(),'iqra-xlsx-');try{(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sheet))->save($tmp);return response()->download($tmp,'iqra-fee-report.xlsx')->deleteFileAfterSend(true);}catch(\Throwable $e){@unlink($tmp);throw $e;}
 }
 public function reportPdf(Request $r){$q=DB::table('invoices as i')->join('departments as d','d.id','=','i.department_id')->join('billing_runs as b','b.id','=','i.billing_run_id')->select('d.name as department','i.currency','b.period_start')->selectRaw('SUM(i.total_minor) AS billed,SUM(i.paid_minor) AS received,SUM(i.total_minor-i.paid_minor) AS outstanding')->groupBy('d.name','i.currency','b.period_start');if($dept=$r->query('department_id'))$q->where('i.department_id',$dept);if($month=$r->query('month'))$q->where('b.period_start','like',$month.'%');if(!in_array($r->user()->role,['super_admin','finance_admin'],true)){$q->where('i.campus_id',$r->user()->campus_id)->when($r->user()->department_id,fn($v)=>$v->where('i.department_id',$r->user()->department_id));}$rows=$q->orderByDesc('b.period_start')->get();return \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.report',compact('rows'))->stream('iqra-report.pdf');
 }

 private function bannerDataUri(?object $branding):string {
  $path=$branding?->logo_path;
  if($path && \Illuminate\Support\Facades\Storage::disk('local')->exists($path)){
   $blob=\Illuminate\Support\Facades\Storage::disk('local')->get($path);$mime=str_ends_with(strtolower($path),'.png')?'image/png':'image/jpeg';return 'data:'.$mime.';base64,'.base64_encode($blob);
  }
  return 'data:image/png;base64,'.base64_encode(file_get_contents(public_path('assets/ivs-banner.png')));
 }
 public function branding(Request $r){abort_unless(in_array($r->user()->role,['super_admin','finance_admin'],true),403);return view('branding',['departments'=>DB::table('departments')->get(),'branding'=>DB::table('department_branding')->get()->keyBy('department_id')]);}
 public function saveBranding(Request $r){abort_unless(in_array($r->user()->role,['super_admin','finance_admin'],true),403);$d=$r->validate(['department_id'=>'required|exists:departments,id','display_name'=>'required|string|max:200','payment_instructions'=>'nullable|string|max:7000','footer_email'=>'nullable|email|max:200','footer_phone'=>'nullable|string|max:60','logo'=>'nullable|image|mimes:png,jpg,jpeg|max:2048']);$fields=['display_name'=>$d['display_name'],'payment_instructions'=>$d['payment_instructions']??null,'footer_email'=>$d['footer_email']??null,'footer_phone'=>$d['footer_phone']??null,'updated_at'=>now()];if($r->hasFile('logo'))$fields['logo_path']=$r->file('logo')->store('branding','local');DB::table('department_branding')->updateOrInsert(['department_id'=>$d['department_id']],$fields);return back()->with('notice','Invoice branding updated.');}
 public function updateFamilyContact(Request $r,int $family){
  abort_unless(in_array($r->user()->role,['super_admin','finance_admin'],true),403);
  $d=$r->validate(['guardian_name'=>'nullable|string|max:200','email'=>'nullable|email|max:254','whatsapp'=>['nullable','regex:/^\+[1-9][0-9]{6,14}$/'],'contact_verified'=>'nullable|boolean','messaging_opt_in'=>'nullable|boolean']);
  DB::table('families')->where('id',$family)->update(['guardian_name'=>$d['guardian_name']??null,'email'=>$d['email']??null,'whatsapp'=>$d['whatsapp']??null,'contact_verified'=>!empty($d['contact_verified']),'messaging_opt_in'=>!empty($d['messaging_opt_in']),'updated_at'=>now()]);
  DB::table('audit_logs')->insert(['actor_id'=>$r->user()->id,'action'=>'family_contact_updated','subject_type'=>'family','subject_id'=>$family,'metadata'=>json_encode(['contact_verified'=>!empty($d['contact_verified']),'messaging_opt_in'=>!empty($d['messaging_opt_in'])]),'created_at'=>now(),'updated_at'=>now()]);
  return back()->with('notice','Family contact updated.');
 }
 public function addEnrollment(Request $r,int $student){
  $d=$r->validate(['campus_id'=>'required|exists:campuses,id','department_id'=>'required|exists:departments,id','grade_label'=>'required|string|max:200','subject_name'=>'nullable|string|max:150','currency'=>'required|alpha|size:3','class_days_per_week'=>'nullable|integer|min:1|max:7','monthly_fee'=>['nullable','regex:/^\d{1,13}(?:\.\d{1,2})?$/']]);abort_if($r->user()->role==='auditor',403);$this->assertAccess($r,$d['department_id'],$d['campus_id']);abort_unless(DB::table('students')->where('id',$student)->exists(),404);
  // Department staff may update only students already visible within their own scope.
  // A global administrator can create the first enrollment for any student.
  if(!in_array($r->user()->role,['super_admin','finance_admin'],true)){
   abort_unless(DB::table('enrollments')->where('student_id',$student)->where('campus_id',$r->user()->campus_id)
    ->when($r->user()->department_id,fn($q)=>$q->where('department_id',$r->user()->department_id))->exists(),403);
  }
  $program=DB::table('programs')->where(['department_id'=>$d['department_id'],'name'=>$d['grade_label']])->first();$now=now();
  $programId=$program?->id??DB::table('programs')->insertGetId(['department_id'=>$d['department_id'],'name'=>$d['grade_label'],'created_at'=>$now,'updated_at'=>$now]);
  $amount=Money::minor((string)($d['monthly_fee']??0));
  DB::transaction(function()use($d,$student,$programId,$amount,$now){
   $id=DB::table('enrollments')->insertGetId(['student_id'=>$student,'campus_id'=>$d['campus_id'],'department_id'=>$d['department_id'],'program_id'=>$programId,'grade_label'=>$d['grade_label'],'subject_name'=>$d['subject_name']??null,'class_days_per_week'=>$d['class_days_per_week']??null,'currency'=>strtoupper($d['currency']),'monthly_fee_minor'=>$amount,'status'=>'active','created_at'=>$now,'updated_at'=>$now]);
   if($amount>0){$feeType=DB::table('fee_types')->whereNull('department_id')->where('code','monthly')->first();DB::table('fee_plan_items')->insert(['enrollment_id'=>$id,'fee_type_id'=>$feeType->id,'description'=>($d['subject_name']??'Monthly Fee'),'frequency'=>'monthly','subject_name'=>$d['subject_name']??null,'class_days_per_week'=>$d['class_days_per_week']??null,'currency'=>strtoupper($d['currency']),'amount_minor'=>$amount,'created_at'=>$now,'updated_at'=>$now]);}
  });return back()->with('notice','Department enrollment added.');
 }
  public function importOutstanding(Request $r){
   abort_unless(in_array($r->user()->role,['super_admin','finance_admin','department_manager'],true),403);
   return redirect('/challan')->withErrors(['error'=>'Direct-to-ledger outstanding fee imports are disabled during Phase 1 Recovery Protection until the staging and reconciliation system is implemented in Phase 2.']);
  }
  public function importOutstandingPreloaded(Request $r){
   abort_unless(in_array($r->user()->role,['super_admin','finance_admin','department_manager'],true),403);
   return redirect('/challan')->withErrors(['error'=>'Direct-to-ledger outstanding fee imports are disabled during Phase 1 Recovery Protection until the staging and reconciliation system is implemented in Phase 2.']);
  }
  public function importCollection(Request $r){
   abort_unless(in_array($r->user()->role,['super_admin','finance_admin','department_manager'],true),403);
   return redirect('/challan')->withErrors(['error'=>'Direct-to-ledger historical collection imports are disabled during Phase 1 Recovery Protection until the staging and reconciliation system is implemented in Phase 2.']);
  }
  public function importCollectionPreloaded(Request $r){
   abort_unless(in_array($r->user()->role,['super_admin','finance_admin','department_manager'],true),403);
   return redirect('/challan')->withErrors(['error'=>'Direct-to-ledger historical collection imports are disabled during Phase 1 Recovery Protection until the staging and reconciliation system is implemented in Phase 2.']);
  }
  public function clearFeeData(Request $r){
   abort(403, 'Dangerous ledger deletion endpoint has been disabled for Phase 1 Recovery Protection.');
  }
}
