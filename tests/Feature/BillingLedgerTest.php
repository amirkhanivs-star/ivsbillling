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
  $this->seed();$campus=DB::table('campuses')->value('id');$dept=DB::table('departments')->where('code','school')->value('id');$family=DB::table('families')->insertGetId(['family_code'=>'FAM-100','created_at'=>now(),'updated_at'=>now()]);$feeType=DB::table('fee_types')->where('code','monthly')->value('id');$user=DB::table('users')->insertGetId(['name'=>'Accounts Test','email'=>'accounts@example.test','password'=>bcrypt('unpublished-secret'),'role'=>'super_admin','created_at'=>now(),'updated_at'=>now()]);
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

}
