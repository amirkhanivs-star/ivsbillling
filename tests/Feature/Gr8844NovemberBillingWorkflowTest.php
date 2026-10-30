<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FeeEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Gr8844NovemberBillingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private string $batchId;
    private int $campusId;
    private int $deptId;
    private int $monthlyFeeTypeId;
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        Config::set('reconciliation.allow_apply', true);
        $this->batchId = (string) config('imports.active_batch_id', 'BATCH-20261025-FINAL-MYSKOOL');

        $this->campusId = (int) DB::table('campuses')->value('id');
        $this->deptId = (int) DB::table('departments')->where('code', 'school')->value('id');
        $this->monthlyFeeTypeId = (int) DB::table('fee_types')->where('code', 'monthly')->value('id');

        $this->adminUser = User::firstOrFail();
        DB::table('users')->where('id', $this->adminUser->id)->update([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $this->adminUser->refresh();
        $this->actingAs($this->adminUser);
    }

    public function test_gr8844_complete_preview_creation_and_fee_collection_workflow(): void
    {
        // 1. Setup Family & Student 8844 with Approved Monthly Fee = SAR 244.00
        $familyId = (int) DB::table('families')->insertGetId([
            'family_code' => '8844',
            'guardian_name' => 'Khuram Shahzad',
            'is_provisional' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentId = (int) DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => '8844',
            'name' => 'Muhammad Hasan Khurram',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrollmentId = (int) DB::table('enrollments')->insertGetId([
            'student_id' => $studentId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'grade_label' => 'FS-2 - A',
            'currency' => 'SAR',
            'monthly_fee_minor' => 24400,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Student fee plan with approved fee SAR 244
        $planItemId = (int) DB::table('fee_plan_items')->insertGetId([
            'enrollment_id' => $enrollmentId,
            'fee_type_id' => $this->monthlyFeeTypeId,
            'description' => 'Monthly Fee',
            'frequency' => 'monthly',
            'currency' => 'SAR',
            'amount_minor' => 24400,
            'discount_kind' => 'none',
            'discount_value' => 0,
            'is_active' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Setup Historical MySkool Payment: SAR 121.00 on Slip 27224 for Nov-2026
        $colId = (int) DB::table('qa_staged_collections')->insertGetId([
            'batch_id' => $this->batchId,
            'source_row' => 34441,
            'slip_number' => '27224',
            'gr_number' => '8844',
            'fee_particular' => 'Monthly Fee - Nov-2026',
            'currency' => 'SAR',
            'amount_minor' => 12100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Step 1 Investigation: Verify NO invoice exists for Nov 2026 prior to creation
        $priorInvoices = DB::table('invoice_items')
            ->where('student_id', $studentId)
            ->where('period_start', '2026-11-01')
            ->count();
        $this->assertSame(0, $priorInvoices, 'Prior to generation, no November invoice item should exist.');

        // Step 2 Investigation: Verify Create Fee Preview recognizes SAR 121 as partial historical advance
        $previewResp = $this->postJson('/challan/preview-fee', [
            'family_id' => $familyId,
            'student_ids' => [$studentId],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [11],
            'year' => 2026,
        ]);

        $previewResp->assertOk();
        $previewData = $previewResp->json();
        $this->assertTrue($previewData['success']);
        $this->assertSame(1, $previewData['summary']['total_eligible']);
        $item = $previewData['items'][0];
        $this->assertTrue($item['is_eligible']);
        $this->assertSame('Eligible for creation', $item['status']);
        $this->assertSame(12100, $item['historical_paid_minor']);
        $this->assertSame(12300, $item['expected_remaining_minor']);
        $this->assertTrue($item['is_partial_historical']);

        // Step 3 Investigation: Verify POST Create Fee creates SAR 244 obligation with SAR 121 credited
        $createResp = $this->post('/challan/create-fee', [
            'family_id' => $familyId,
            'student_ids' => [$studentId],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [11],
            'year' => 2026,
        ]);

        $createResp->assertRedirect();

        // Step 4 Investigation: Verify invoice_items, qa_historical_settlements, and Fee Collection summaries
        $invoice = DB::table('invoices')->where('family_id', $familyId)->where('currency', 'SAR')->first();
        $this->assertNotNull($invoice);
        $this->assertSame(24400, (int) $invoice->total_minor);
        $this->assertSame(12100, (int) $invoice->paid_minor);
        $this->assertSame('partial', $invoice->status);

        $invItem = DB::table('invoice_items')->where('student_id', $studentId)->first();
        $this->assertNotNull($invItem);
        $this->assertSame(24400, (int) $invItem->amount_minor);
        $this->assertSame(24400, (int) $invItem->net_minor);
        $this->assertSame(12100, (int) $invItem->paid_minor);
        $this->assertSame('partial', $invItem->status);
        $this->assertSame(12300, (int) $invItem->net_minor - (int) $invItem->paid_minor);

        $settlement = DB::table('qa_historical_settlements')->where('invoice_item_id', $invItem->id)->first();
        $this->assertNotNull($settlement);
        $this->assertSame(24400, (int) $settlement->original_obligation_minor);
        $this->assertSame(12100, (int) $settlement->settled_amount_minor);
        $this->assertSame(12300, (int) $settlement->remaining_obligation_minor);
        $this->assertSame('applied', $settlement->status);

        // Step 5 Investigation: Verify Fee Collection view shows SAR 123 outstanding
        $collectionResp = $this->get('/challan?family=8844');
        $collectionResp->assertOk();
        $collectionResp->assertSee('SAR 123');
        $collectionResp->assertSee('Paid: SAR 121 / 244');
        $collectionResp->assertSee('value="123"', false);

        // Test with GR search as well
        $collectionGrResp = $this->get('/challan?gr=8844');
        $collectionGrResp->assertOk();
        $collectionGrResp->assertSee('SAR 123');
        $collectionGrResp->assertSee('Paid: SAR 121 / 244');
        $collectionGrResp->assertSee('value="123"', false);
    }

    public function test_gr9596_workflow_sar_220_charge_with_143_paid_leaves_77_outstanding(): void
    {
        $familyId = (int) DB::table('families')->insertGetId([
            'family_code' => '9595',
            'guardian_name' => 'Muhammad Anas Shaikh',
            'is_provisional' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentId = (int) DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => '9596',
            'name' => 'Muhammad Usman Shaikh',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrollmentId = (int) DB::table('enrollments')->insertGetId([
            'student_id' => $studentId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'grade_label' => '1 - A',
            'currency' => 'SAR',
            'monthly_fee_minor' => 22000,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('fee_plan_items')->insert([
            'enrollment_id' => $enrollmentId,
            'fee_type_id' => $this->monthlyFeeTypeId,
            'description' => 'Monthly Fee',
            'frequency' => 'monthly',
            'currency' => 'SAR',
            'amount_minor' => 22000,
            'discount_kind' => 'none',
            'discount_value' => 0,
            'is_active' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('qa_staged_collections')->insert([
            'batch_id' => $this->batchId,
            'source_row' => 34439,
            'slip_number' => '27185',
            'gr_number' => '9596',
            'fee_particular' => 'Monthly Fee - Nov-2026',
            'currency' => 'SAR',
            'amount_minor' => 14300,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Preview
        $previewResp = $this->postJson('/challan/preview-fee', [
            'family_id' => $familyId,
            'student_ids' => [$studentId],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [11],
            'year' => 2026,
        ]);
        $previewResp->assertOk();
        $preview = $previewResp->json();
        $this->assertSame(14300, $preview['items'][0]['historical_paid_minor']);
        $this->assertSame(7700, $preview['items'][0]['expected_remaining_minor']);

        // Create
        $this->post('/challan/create-fee', [
            'family_id' => $familyId,
            'student_ids' => [$studentId],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [11],
            'year' => 2026,
        ])->assertRedirect();

        // Fee Collection page shows SAR 77.00
        $colResp = $this->get('/challan?gr=9596');
        $colResp->assertOk();
        $colResp->assertSee('SAR 77');
        $colResp->assertSee('Paid: SAR 143 / 220');
    }

    public function test_gr9840_workflow_aed_185_charge_with_112_paid_leaves_73_outstanding(): void
    {
        $familyId = (int) DB::table('families')->insertGetId([
            'family_code' => '5300',
            'guardian_name' => 'Tahsin Guardian',
            'is_provisional' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentId = (int) DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => '9840',
            'name' => 'Malikah Tahsin',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrollmentId = (int) DB::table('enrollments')->insertGetId([
            'student_id' => $studentId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'grade_label' => 'FS-2 - A',
            'currency' => 'AED',
            'monthly_fee_minor' => 18500,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('fee_plan_items')->insert([
            'enrollment_id' => $enrollmentId,
            'fee_type_id' => $this->monthlyFeeTypeId,
            'description' => 'Monthly Fee',
            'frequency' => 'monthly',
            'currency' => 'AED',
            'amount_minor' => 18500,
            'discount_kind' => 'none',
            'discount_value' => 0,
            'is_active' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('qa_staged_collections')->insert([
            'batch_id' => $this->batchId,
            'source_row' => 34442,
            'slip_number' => '27238',
            'gr_number' => '9840',
            'fee_particular' => 'Monthly Fee - Nov-2026',
            'currency' => 'AED',
            'amount_minor' => 11200,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Preview
        $previewResp = $this->postJson('/challan/preview-fee', [
            'family_id' => $familyId,
            'student_ids' => [$studentId],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [11],
            'year' => 2026,
        ]);
        $previewResp->assertOk();
        $preview = $previewResp->json();
        $this->assertSame(11200, $preview['items'][0]['historical_paid_minor']);
        $this->assertSame(7300, $preview['items'][0]['expected_remaining_minor']);

        // Create
        $this->post('/challan/create-fee', [
            'family_id' => $familyId,
            'student_ids' => [$studentId],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [11],
            'year' => 2026,
        ])->assertRedirect();

        // Fee Collection page shows AED 73.00
        $colResp = $this->get('/challan?gr=9840');
        $colResp->assertOk();
        $colResp->assertSee('AED 73');
        $colResp->assertSee('Paid: AED 112 / 185');
    }

    public function test_gr9896_workflow_sar_230_charge_with_100_paid_leaves_130_outstanding(): void
    {
        $familyId = (int) DB::table('families')->insertGetId([
            'family_code' => '5336',
            'guardian_name' => 'Jawad Guardian',
            'is_provisional' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentId = (int) DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => '9896',
            'name' => 'Aaira jawad',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrollmentId = (int) DB::table('enrollments')->insertGetId([
            'student_id' => $studentId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'grade_label' => '2 - A',
            'currency' => 'SAR',
            'monthly_fee_minor' => 23000,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('fee_plan_items')->insert([
            'enrollment_id' => $enrollmentId,
            'fee_type_id' => $this->monthlyFeeTypeId,
            'description' => 'Monthly Fee',
            'frequency' => 'monthly',
            'currency' => 'SAR',
            'amount_minor' => 23000,
            'discount_kind' => 'none',
            'discount_value' => 0,
            'is_active' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('qa_staged_collections')->insert([
            'batch_id' => $this->batchId,
            'source_row' => 34443,
            'slip_number' => '27302',
            'gr_number' => '9896',
            'fee_particular' => 'Monthly Fee - Nov-2026',
            'currency' => 'SAR',
            'amount_minor' => 10000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Preview
        $previewResp = $this->postJson('/challan/preview-fee', [
            'family_id' => $familyId,
            'student_ids' => [$studentId],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [11],
            'year' => 2026,
        ]);
        $previewResp->assertOk();
        $preview = $previewResp->json();
        $this->assertSame(10000, $preview['items'][0]['historical_paid_minor']);
        $this->assertSame(13000, $preview['items'][0]['expected_remaining_minor']);

        // Create
        $this->post('/challan/create-fee', [
            'family_id' => $familyId,
            'student_ids' => [$studentId],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [11],
            'year' => 2026,
        ])->assertRedirect();

        // Fee Collection page shows SAR 130.00
        $colResp = $this->get('/challan?gr=9896');
        $colResp->assertOk();
        $colResp->assertSee('SAR 130');
        $colResp->assertSee('Paid: SAR 100 / 230');
    }
}
