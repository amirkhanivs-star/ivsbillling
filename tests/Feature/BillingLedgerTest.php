<?php
namespace Tests\Feature;
use App\Services\InvoiceService;
use App\Services\BillingPeriod;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use App\Services\ReceivePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
class BillingLedgerTest extends TestCase {
 use RefreshDatabase;
 private function setupData():array {
  $this->seed();$campus=DB::table('campuses')->value('id');$dept=DB::table('departments')->where('code','school')->value('id');$family=DB::table('families')->insertGetId(['family_code'=>'FAM-100','created_at'=>now(),'updated_at'=>now()]);$feeType=DB::table('fee_types')->where('code','monthly')->value('id');$user=DB::table('users')->insertGetId(['name'=>'Accounts Test','email'=>'acivs2021@gmail.com','password'=>bcrypt('unpublished-secret'),'role'=>'super_admin','created_at'=>now(),'updated_at'=>now()]);
  foreach([['GR1',30000],['GR2',25000]] as [$gr,$fee]){$student=DB::table('students')->insertGetId(['gr_number'=>$gr,'family_id'=>$family,'name'=>$gr,'created_at'=>now(),'updated_at'=>now()]);$enroll=DB::table('enrollments')->insertGetId(['student_id'=>$student,'campus_id'=>$campus,'department_id'=>$dept,'currency'=>'SAR','monthly_fee_minor'=>$fee,'created_at'=>now(),'updated_at'=>now()]);DB::table('fee_plan_items')->insert(['enrollment_id'=>$enroll,'fee_type_id'=>$feeType,'description'=>'Monthly Fee','frequency'=>'monthly','currency'=>'SAR','amount_minor'=>$fee,'created_at'=>now(),'updated_at'=>now()]);}
  return compact('campus','dept','family','user');
 }
 private function makeRun(array $ids,string $period):int {return DB::table('billing_runs')->insertGetId(['campus_id'=>$ids['campus'],'department_id'=>$ids['dept'],'frequency'=>'monthly','period_key'=>'monthly:'.$period.'-01..'.date('Y-m-t',strtotime($period.'-01')),'period_start'=>$period.'-01','period_end'=>date('Y-m-t',strtotime($period.'-01')),'due_date'=>$period.'-25','created_by'=>$ids['user'],'created_at'=>now(),'updated_at'=>now()]);}
 public function test_family_grouping_and_no_duplicate_charges():void {
  $data=$this->setupData();$run=$this->makeRun($data,'2026-10');$result=app(InvoiceService::class)->generate($run);$this->assertSame(1,$result['created']);$this->assertSame(2,DB::table('invoice_items')->count());$this->assertSame(55000,(int)DB::table('invoices')->value('total_minor'));
  app(InvoiceService::class)->generate($this->makeRun($data,'2026-10'));
  $this->assertSame(2,DB::table('invoice_items')->count());
 }
 public function test_receive_partial_payment_once_and_keep_rest_as_due():void {
  $d=$this->setupData();app(InvoiceService::class)->generate($this->makeRun($d,'2026-10'));
  $input=['family_id'=>$d['family'],'campus_id'=>$d['campus'],'department_id'=>$d['dept'],'currency'=>'SAR','receiving_amount'=>'300.00','idempotency_key'=>(string)Str::uuid()];
  $service=app(ReceivePaymentService::class);$first=$service->receive($input,$d['user']);$second=$service->receive($input,$d['user']);$this->assertSame($first->id,$second->id);$this->assertSame(1,DB::table('payments')->count());$this->assertSame(30000,(int)DB::table('invoices')->value('paid_minor'));$this->assertSame(25000,(int)DB::table('invoices')->value('total_minor')-(int)DB::table('invoices')->value('paid_minor'));
 }
 public function test_advance_payment_applies_to_future_invoice():void {
  $d=$this->setupData();$input=['family_id'=>$d['family'],'campus_id'=>$d['campus'],'department_id'=>$d['dept'],'currency'=>'SAR','receiving_amount'=>'100.00','idempotency_key'=>(string)Str::uuid()];$pay=app(ReceivePaymentService::class)->receive($input,$d['user']);$this->assertSame(10000,(int)$pay->unallocated_minor);
  app(InvoiceService::class)->generate($this->makeRun($d,'2026-10'));$this->assertSame(0,(int)DB::table('payments')->value('unallocated_minor'));$this->assertSame(10000,(int)DB::table('invoices')->value('paid_minor'));
 }
 public function test_specific_month_advance_does_not_pay_a_different_month():void {
  $d=$this->setupData();
  $input=['family_id'=>$d['family'],'campus_id'=>$d['campus'],'department_id'=>$d['dept'],
   'currency'=>'SAR','receiving_amount'=>'100.00','selected_period'=>'2026-11','idempotency_key'=>(string)Str::uuid()];
  $payment=app(ReceivePaymentService::class)->receive($input,$d['user']);
  app(InvoiceService::class)->generate($this->makeRun($d,'2026-10'));
  $this->assertSame(10000,(int)DB::table('payments')->where('id',$payment->id)->value('unallocated_minor'));
  $this->assertSame(0,(int)DB::table('invoices')->where('due_date','2026-10-25')->value('paid_minor'));
  app(InvoiceService::class)->generate($this->makeRun($d,'2026-11'));
  $this->assertSame(0,(int)DB::table('payments')->where('id',$payment->id)->value('unallocated_minor'));
  $this->assertSame(10000,(int)DB::table('invoices')->where('due_date','2026-11-25')->value('paid_minor'));
 }
 public function test_rejects_unrelated_family_department_currency_payment():void {
  $d=$this->setupData();
  $otherDepartment=DB::table('departments')->where('code','quran')->value('id');
  $this->expectException(ValidationException::class);
  app(ReceivePaymentService::class)->receive([
   'family_id'=>$d['family'],'campus_id'=>$d['campus'],
   'department_id'=>$otherDepartment,'currency'=>'SAR',
   'receiving_amount'=>'25.00','idempotency_key'=>(string)Str::uuid(),
  ],$d['user']);
 }
 public function test_month_chip_post_creates_each_month_and_skips_duplicate_obligations():void {
  $d=$this->setupData();
  $payload=['campus_id'=>$d['campus'],'department_id'=>$d['dept'],'frequency'=>'monthly',
   'monthly_selection'=>'1','year'=>2026,'months'=>[10,11],'due_day'=>25];
  $this->actingAs(User::findOrFail($d['user']))->post('/fee-creator',$payload)->assertRedirect('/invoices');
  $this->assertSame(2,DB::table('invoices')->count());
  $this->assertSame(4,DB::table('invoice_items')->count());
  $this->post('/fee-creator',$payload)->assertRedirect('/invoices');
  $this->assertSame(2,DB::table('invoices')->count());
  $this->assertSame(4,DB::table('invoice_items')->count());
 }
 public function test_cashier_without_campus_scope_has_no_ledger_access():void {
  $cashier=new User(['role'=>'cashier','is_active'=>true,'department_id'=>null,'campus_id'=>null]);
  $this->assertFalse($cashier->canAccessDepartment(1,1));
  $cashier->campus_id=1;
  $this->assertTrue($cashier->canAccessDepartment(1,1));
  $this->assertFalse($cashier->canAccessDepartment(1,2));
 }

 public function test_new_fee_plan_in_same_month_reuses_unsent_family_invoice():void {
  $d=$this->setupData();
  app(InvoiceService::class)->generate($this->makeRun($d,'2026-10'));
  $original=DB::table('invoices')->value('id');
  $enrollment=DB::table('enrollments')->first();
  $type=DB::table('fee_types')->where('code','custom')->value('id');
  DB::table('fee_plan_items')->insert([
   'enrollment_id'=>$enrollment->id,'fee_type_id'=>$type,'description'=>'Extra monthly plan',
   'frequency'=>'monthly','currency'=>'SAR','amount_minor'=>2000,
   'created_at'=>now(),'updated_at'=>now(),
  ]);
  app(InvoiceService::class)->generate($this->makeRun($d,'2026-10'));
  $this->assertSame(1,DB::table('invoices')->count());
  $this->assertSame($original,(int)DB::table('invoices')->value('id'));
  $this->assertSame(57000,(int)DB::table('invoices')->value('total_minor'));
 }
 public function test_cannot_silently_change_invoice_after_send_approval():void {
  $d=$this->setupData();
  app(InvoiceService::class)->generate($this->makeRun($d,'2026-10'));
  DB::table('invoices')->update(['approved_to_send'=>true]);
  $enrollment=DB::table('enrollments')->first();
  $type=DB::table('fee_types')->where('code','custom')->value('id');
  DB::table('fee_plan_items')->insert([
   'enrollment_id'=>$enrollment->id,'fee_type_id'=>$type,'description'=>'Post approval fee',
   'frequency'=>'monthly','currency'=>'SAR','amount_minor'=>2000,
   'created_at'=>now(),'updated_at'=>now(),
  ]);
  $this->expectException(ValidationException::class);
  app(InvoiceService::class)->generate($this->makeRun($d,'2026-10'));
 }

 public function test_fee_creator_due_date_policy_and_unified_invoice_pdf_display():void {
  $d=$this->setupData();
  DB::table('families')->where('id',$d['family'])->update([
   'whatsapp'=>'+966500000001',
   'contact_verified'=>true,
   'messaging_opt_in'=>true,
  ]);
  $admin=User::findOrFail($d['user']);

  \Carbon\Carbon::setTestNow(\Carbon\Carbon::create(2026,10,28,14,0,0,'Asia/Karachi'));

  // 1. Verify /fee-creator UI fields & defaults:
  // - issue_date defaults to today (2026-10-28)
  // - due_day defaults to 5
  // - minimum_payment_days defaults to 10
  // - calculated final due date shown before clicking Create Fee
  $creatorHtml=$this->actingAs($admin)->get('/fee-creator')->assertOk()->getContent();
  $this->assertMatchesRegularExpression('/name="issue_date"[^>]*value="2026-10-28"/i',$creatorHtml);
  $this->assertMatchesRegularExpression('/<option\s+value="5"\s+selected/i',$creatorHtml);
  $this->assertMatchesRegularExpression('/name="minimum_payment_days"[^>]*value="10"/i',$creatorHtml);
  $this->assertStringContainsString('Calculated Final Due Date Preview',$creatorHtml);
  $this->assertStringContainsString('2026-11-07 (07-November-2026)',$creatorHtml);

  // Verify authoritative server preview for multiple selected months (Oct, Nov, Dec 2026)
  $previewJson=$this->actingAs($admin)->getJson('/fee-creator?preview_due_dates=1&year=2026&issue_date=2026-10-28&due_day=5&minimum_payment_days=10&months[]=10&months[]=11&months[]=12')
   ->assertOk()
   ->json();
  $this->assertCount(3,$previewJson['periods']);
  $this->assertSame('2026-11-07',$previewJson['periods'][0]['due']);
  $this->assertSame('2026-11-07',$previewJson['periods'][1]['due']);
  $this->assertSame('2026-12-05',$previewJson['periods'][2]['due']);

  // 2. Verify calculation and persistence for the 3 required scenarios:
  // Scenario 1: Issue 20 September, October fee -> due 5 October (2026-10-05)
  $this->actingAs($admin)->post('/fee-creator',[
   'campus_id'=>$d['campus'],'department_id'=>$d['dept'],'frequency'=>'monthly',
   'monthly_selection'=>'1','year'=>2026,'months'=>[10],
   'issue_date'=>'2026-09-20','due_day'=>5,'minimum_payment_days'=>10,
  ])->assertRedirect('/invoices');
  $octEarlyInv=DB::table('invoices')->orderByDesc('id')->first();
  $octEarlyRun=DB::table('billing_runs')->where('id',$octEarlyInv->billing_run_id)->first();
  $this->assertSame('2026-09-20',$octEarlyInv->issued_on);
  $this->assertSame('2026-10-05',$octEarlyInv->due_date);
  $this->assertSame('2026-10-05',$octEarlyRun->due_date);

  // Ensure existing invoice is NOT silently modified if a later run or new fee plan item is generated for Oct 2026 on 28 Oct
  $enrollment=DB::table('enrollments')->first();
  $customType=DB::table('fee_types')->where('code','custom')->value('id');
  DB::table('fee_plan_items')->insert([
   'enrollment_id'=>$enrollment->id,'fee_type_id'=>$customType,'description'=>'Lab Fee',
   'frequency'=>'monthly','currency'=>'SAR','amount_minor'=>1500,
   'created_at'=>now(),'updated_at'=>now(),
  ]);
  $this->actingAs($admin)->post('/fee-creator',[
   'campus_id'=>$d['campus'],'department_id'=>$d['dept'],'frequency'=>'monthly',
   'monthly_selection'=>'1','year'=>2026,'months'=>[10],
   'issue_date'=>'2026-10-28','due_day'=>5,'minimum_payment_days'=>10,
  ])->assertRedirect('/invoices');
  $octAfterReRun=DB::table('invoices')->where('id',$octEarlyInv->id)->first();
  $this->assertSame('2026-09-20',$octAfterReRun->issued_on,'Existing invoice issued_on must not be silently modified');
  $this->assertSame('2026-10-05',$octAfterReRun->due_date,'Existing invoice due_date must not be silently modified');

  // Scenario 2: Issue 28 October, October fee (for a fresh family) -> due 7 November (2026-11-07)
  $family2=DB::table('families')->insertGetId(['family_code'=>'FAM-101','whatsapp'=>'+966500000002','created_at'=>now(),'updated_at'=>now()]);
  $stu2=DB::table('students')->insertGetId(['gr_number'=>'GR-OCT28','family_id'=>$family2,'name'=>'Student Oct28','created_at'=>now(),'updated_at'=>now()]);
  $enr2=DB::table('enrollments')->insertGetId(['student_id'=>$stu2,'campus_id'=>$d['campus'],'department_id'=>$d['dept'],'currency'=>'SAR','monthly_fee_minor'=>20000,'created_at'=>now(),'updated_at'=>now()]);
  $monthlyType=DB::table('fee_types')->where('code','monthly')->value('id');
  DB::table('fee_plan_items')->insert(['enrollment_id'=>$enr2,'fee_type_id'=>$monthlyType,'description'=>'Monthly Fee','frequency'=>'monthly','currency'=>'SAR','amount_minor'=>20000,'created_at'=>now(),'updated_at'=>now()]);

  $this->actingAs($admin)->post('/fee-creator',[
   'campus_id'=>$d['campus'],'department_id'=>$d['dept'],'frequency'=>'monthly',
   'monthly_selection'=>'1','year'=>2026,'months'=>[10],
   'issue_date'=>'2026-10-28','due_day'=>5,'minimum_payment_days'=>10,
  ])->assertRedirect('/invoices');
  $octLateInv=DB::table('invoices')->where('family_id',$family2)->orderByDesc('id')->first();
  $octLateRun=DB::table('billing_runs')->where('id',$octLateInv->billing_run_id)->first();
  $this->assertSame('2026-10-28',$octLateInv->issued_on);
  $this->assertSame('2026-11-07',$octLateInv->due_date);
  $this->assertSame('2026-11-07',$octLateRun->due_date);

  // Scenario 3: Issue 28 October, November fee -> due 7 November (2026-11-07)
  $this->actingAs($admin)->post('/fee-creator',[
   'campus_id'=>$d['campus'],'department_id'=>$d['dept'],'frequency'=>'monthly',
   'monthly_selection'=>'1','year'=>2026,'months'=>[11],
   'issue_date'=>'2026-10-28','due_day'=>5,'minimum_payment_days'=>10,
  ])->assertRedirect('/invoices');
  $novInv=DB::table('invoices')->where('family_id',$d['family'])->orderByDesc('id')->first();
  $novRun=DB::table('billing_runs')->where('id',$novInv->billing_run_id)->first();
  $this->assertSame('2026-10-28',$novInv->issued_on);
  $this->assertSame('2026-11-07',$novInv->due_date);
  $this->assertSame('2026-11-07',$novRun->due_date);

  // 3. Also create a historical opening-balance invoice (May 2026, due 2026-05-10) for family 1 to test historical overdue distinction
  $obRunId=DB::table('billing_runs')->insertGetId([
   'campus_id'=>$d['campus'],'department_id'=>$d['dept'],'frequency'=>'opening_balance',
   'period_key'=>'opening_balance:2026-05-01..2026-05-31','period_start'=>'2026-05-01','period_end'=>'2026-05-31',
   'due_date'=>'2026-05-10','status'=>'generated','created_by'=>$d['user'],'created_at'=>now(),'updated_at'=>now(),
  ]);
  $obInvId=DB::table('invoices')->insertGetId([
   'billing_run_id'=>$obRunId,'family_id'=>$d['family'],'department_id'=>$d['dept'],'campus_id'=>$d['campus'],
   'currency'=>'SAR','invoice_number'=>'SCH-OB-2026-0000001','issued_on'=>'2026-05-01','due_date'=>'2026-05-10',
   'total_minor'=>10000,'paid_minor'=>0,'status'=>'generated','created_at'=>now(),'updated_at'=>now(),
  ]);
  $studentId=(int)DB::table('students')->where('family_id',$d['family'])->value('id');
  DB::table('invoice_items')->insert([
   'invoice_id'=>$obInvId,'student_id'=>$studentId,'enrollment_id'=>$enrollment->id,'fee_type_id'=>$monthlyType,
   'description'=>'Opening Balance - May 2026','period_key'=>'2026-05','period_start'=>'2026-05-01','period_end'=>'2026-05-31',
   'currency'=>'SAR','amount_minor'=>10000,'discount_minor'=>0,'net_minor'=>10000,'paid_minor'=>0,'status'=>'active',
   'created_at'=>now(),'updated_at'=>now(),
  ]);

  // 4. Verify all individual, student, family, and public invoice routes return 200 PDF
  $pdfService=app(\App\Services\PdfAccessService::class);
  $cleanInvoiceUrl=$pdfService->getInvoicePdfUrl((int)$novInv->id);
  $signedInvoiceUrl=\Illuminate\Support\Facades\URL::signedRoute('invoices.signed_pdf',[
   'invoice'=>(int)$novInv->id,
   'k'=>$pdfService->getAccessKey('invoice',(int)$novInv->id),
  ]);
  $signedInvoiceNamedUrl=\Illuminate\Support\Facades\URL::signedRoute('invoices.signed_pdf_named',[
   'invoice'=>(int)$novInv->id,
   'k'=>$pdfService->getAccessKey('invoice',(int)$novInv->id),
  ]);
  $cleanChallanUrl=$pdfService->getFamilyChallanPdfUrl((int)$d['family']);
  $signedChallanUrl=\Illuminate\Support\Facades\URL::signedRoute('families.signed_challan_pdf',[
   'family'=>(int)$d['family'],
   'k'=>$pdfService->getAccessKey('family',(int)$d['family']),
  ]);
  $signedChallanNamedUrl=\Illuminate\Support\Facades\URL::signedRoute('families.signed_challan_pdf_named',[
   'family'=>(int)$d['family'],
   'k'=>$pdfService->getAccessKey('family',(int)$d['family']),
  ]);

  $this->actingAs($admin)->get("/invoices/{$novInv->id}/pdf")->assertOk();
  $this->get($cleanInvoiceUrl)->assertOk();
  $this->get($signedInvoiceUrl)->assertOk();
  $this->get($signedInvoiceNamedUrl)->assertOk();
  $this->actingAs($admin)->get("/families/{$d['family']}/challan/pdf")->assertOk();
  $this->get($cleanChallanUrl)->assertOk();
  $this->get($signedChallanUrl)->assertOk();
  $this->get($signedChallanNamedUrl)->assertOk();
  $this->actingAs($admin)->get("/students/{$studentId}/challan/pdf")->assertOk();

  // 5. Verify consolidated challan preserves actual saved due dates (10-May-2026, 05-October-2026, 07-November-2026)
  // and distinguishes Historical Overdue, Prior Overdue, and Current Monthly Due
  $ctrl=app(\App\Http\Controllers\BillingController::class);
  $ref=new \ReflectionMethod($ctrl,'resolveAuthoritativeChallanDates');
  $ref->setAccessible(true);
  $unpaidItems=DB::table('invoice_items as ii')
   ->join('invoices as i','i.id','=','ii.invoice_id')
   ->join('billing_runs as b','b.id','=','i.billing_run_id')
   ->join('students as s','s.id','=','ii.student_id')
   ->where('i.family_id',$d['family'])
   ->select('ii.*','s.name as student_name','s.gr_number','i.id as invoice_id','i.invoice_number','i.issued_on','i.due_date','b.frequency as billing_frequency')
   ->orderBy('ii.period_start')
   ->get();
  $resolved=$ref->invoke($ctrl,$unpaidItems,null);
  $this->assertSame('2026-11-07',$resolved['due_date']);
  $this->assertSame('2026-10-28',$resolved['issued_on']);
  $this->assertCount(2,$resolved['historical_due_dates']);

  $challanHtml=view('pdf.consolidated-challan',[
   'mode'=>'student',
   'student'=>DB::table('students as s')->join('families as f','f.id','=','s.family_id')->where('s.id',$studentId)->select('s.*','f.family_code')->first(),
   'enrollment'=>$enrollment,
   'parentDisplay'=>['label'=>'Guardian','name'=>'Parent'],
   'currencyGroups'=>collect([
    'SAR'=>[
     'currency'=>'SAR',
     'items'=>$unpaidItems->where('student_id',$studentId)->values(),
     'due_date'=>$resolved['due_date'],
     'issued_on'=>$resolved['issued_on'],
     'current_invoice_id'=>$resolved['current_invoice_id'],
     'current_period_start'=>$resolved['current_period_start'],
     'historical_due_dates'=>$resolved['historical_due_dates'],
     'total_net_minor'=>$unpaidItems->where('student_id',$studentId)->sum('net_minor'),
     'total_paid_minor'=>0,
     'total_balance_minor'=>$unpaidItems->where('student_id',$studentId)->sum('net_minor'),
    ],
   ]),
   'totalItemsCount'=>$unpaidItems->where('student_id',$studentId)->count(),
   'dept'=>DB::table('departments')->where('id',$d['dept'])->first(),
   'branding'=>null,'banner'=>'','statementNumber'=>'CHL-STU-GR1-20261028',
   'generatedAt'=>now(),'issuedOn'=>$resolved['issued_on'],'dueDate'=>$resolved['due_date'],
  ])->render();

  $this->assertStringContainsString('10-May-2026',$challanHtml);
  $this->assertStringContainsString('Historical Overdue',$challanHtml);
  $this->assertStringContainsString('05-October-2026',$challanHtml);
  $this->assertStringContainsString('Prior Overdue',$challanHtml);
  $this->assertStringContainsString('07-November-2026',$challanHtml);
  $this->assertStringContainsString('Current Monthly Due',$challanHtml);

  \Carbon\Carbon::setTestNow();
 }

}
