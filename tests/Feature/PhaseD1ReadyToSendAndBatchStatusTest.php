<?php

namespace Tests\Feature;

use App\Services\ConsolidatedInvoiceBuilder;
use App\Services\InvoiceDeliveryQueueService;
use App\Services\SafeSchReplacementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhaseD1ReadyToSendAndBatchStatusTest extends TestCase
{
    use RefreshDatabase;

    private object $admin;
    private int $campusId;
    private int $deptId;
    private InvoiceDeliveryQueueService $queueService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deptId = DB::table('departments')->insertGetId([
            'name' => 'Department D1',
            'code' => 'DEP-D1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->campusId = DB::table('campuses')->insertGetId([
            'name' => 'Campus D1',
            'code' => 'C-D1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $adminId = DB::table('users')->insertGetId([
            'name' => 'Super Admin D1',
            'email' => 'admin_d1@iqra.test',
            'password' => bcrypt('secret123'),
            'role' => 'super_admin',
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->admin = \App\Models\User::find($adminId);

        $this->queueService = app(InvoiceDeliveryQueueService::class);

        DB::table('webhook_settings')->updateOrInsert(['webhook_type' => 'monthly'], [
            'url' => 'https://webhook.test/monthly',
            'is_enabled' => 1,
            'secret' => 'test-secret',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createFamilyWithInvoice(array $attrs = []): array
    {
        $code = 'FAM-D1-' . uniqid();
        $familyId = DB::table('families')->insertGetId([
            'family_code' => $code,
            'guardian_name' => $attrs['guardian_name'] ?? ('Guardian ' . $code),
            'email' => $code . '@test.com',
            'whatsapp' => '+966500000001',
            'contact_verified' => 1,
            'messaging_opt_in' => 1,
            'whatsapp_verified' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentId = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'name' => 'Student ' . $code,
            'gr_number' => 'GR-' . rand(10000, 99999),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrollmentId = DB::table('enrollments')->insertGetId([
            'student_id' => $studentId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'grade_label' => 'Grade 1',
            'currency' => $attrs['currency'] ?? 'SAR',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $feeTypeId = DB::table('fee_types')->insertGetId([
            'name' => 'Tuition D1 ' . uniqid(),
            'code' => 'TUI-D1-' . uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $billingRunId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'frequency' => 'monthly',
            'period_key' => $attrs['period'] ?? '2026-10',
            'period_start' => ($attrs['period'] ?? '2026-10') . '-01',
            'period_end' => ($attrs['period'] ?? '2026-10') . '-31',
            'due_date' => ($attrs['period'] ?? '2026-10') . '-10',
            'created_by' => $this->admin->id,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $createdAt = $attrs['created_at'] ?? '2026-10-01 10:00:00';
        $invAttrs = [
            'invoice_number' => $attrs['invoice_number'] ?? ('SCH-D1-' . rand(10000, 99999)),
            'family_id' => $familyId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'billing_run_id' => $billingRunId,
            'currency' => $attrs['currency'] ?? 'SAR',
            'total_minor' => $attrs['total_minor'] ?? 50000,
            'paid_minor' => $attrs['paid_minor'] ?? 0,
            'status' => $attrs['status'] ?? 'issued',
            'issued_on' => ($attrs['period'] ?? '2026-10') . '-01',
            'due_date' => ($attrs['period'] ?? '2026-10') . '-10',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
        if (isset($attrs['id'])) {
            $invAttrs['id'] = $attrs['id'];
        }

        $invId = DB::table('invoices')->insertGetId($invAttrs);

        DB::table('invoice_items')->insert([
            'invoice_id' => $invId,
            'student_id' => $studentId,
            'enrollment_id' => $enrollmentId,
            'fee_type_id' => $feeTypeId,
            'description' => 'Tuition ' . ($attrs['period'] ?? '2026-10'),
            'period_key' => $attrs['period'] ?? '2026-10',
            'period_start' => ($attrs['period'] ?? '2026-10') . '-01',
            'period_end' => ($attrs['period'] ?? '2026-10') . '-31',
            'amount_minor' => $attrs['total_minor'] ?? 50000,
            'net_minor' => $attrs['total_minor'] ?? 50000,
            'paid_minor' => $attrs['paid_minor'] ?? 0,
            'currency' => $attrs['currency'] ?? 'SAR',
            'status' => 'active',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return [
            'family_id' => $familyId,
            'family_code' => $code,
            'student_id' => $studentId,
            'enrollment_id' => $enrollmentId,
            'fee_type_id' => $feeTypeId,
            'billing_run_id' => $billingRunId,
            'invoice_id' => $invId,
            'invoice_number' => $invAttrs['invoice_number'],
            'created_at' => $createdAt,
        ];
    }

    /** 1. Keep exactly one consolidated Ready to Send row per eligible family */
    public function test_1_one_ready_to_send_row_per_family(): void
    {
        $data = $this->createFamilyWithInvoice();

        // Add a second invoice for the same family in the same period (e.g. second sibling)
        $billingRun2 = DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'frequency' => 'monthly',
            'period_key' => '2026-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-10',
            'created_by' => $this->admin->id,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $secondInvId = DB::table('invoices')->insertGetId([
            'invoice_number' => 'SCH-D1-SIB-' . rand(1000, 9999),
            'family_id' => $data['family_id'],
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'billing_run_id' => $billingRun2,
            'currency' => 'SAR',
            'total_minor' => 30000,
            'paid_minor' => 0,
            'status' => 'issued',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-10',
            'created_at' => '2026-10-02 10:00:00',
            'updated_at' => '2026-10-02 10:00:00',
        ]);

        DB::table('invoice_items')->insert([
            'invoice_id' => $secondInvId,
            'student_id' => $data['student_id'],
            'enrollment_id' => $data['enrollment_id'],
            'fee_type_id' => $data['fee_type_id'],
            'description' => 'Tuition Oct Sib',
            'period_key' => '2026-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'amount_minor' => 30000,
            'net_minor' => 30000,
            'paid_minor' => 0,
            'currency' => 'SAR',
            'status' => 'active',
            'created_at' => '2026-10-02 10:00:00',
            'updated_at' => '2026-10-02 10:00:00',
        ]);

        $res = $this->queueService->getReadyToSendChallans(['period' => '2026-10'], $this->admin);

        $matching = collect($res->items())->filter(fn($r) => (int)$r->family_id === (int)$data['family_id']);
        $this->assertCount(1, $matching, 'Should return exactly one consolidated row per family.');
    }

    /** 2. Select actual newest authoritative SCH using invoices.created_at DESC, invoices.id DESC */
    public function test_2_newest_valid_sch_correctly_selected(): void
    {
        $data = $this->createFamilyWithInvoice([
            'invoice_number' => 'SCH-D1-OLD',
            'created_at' => '2026-10-05 09:00:00',
        ]);

        // Newer invoice created on 2026-10-15
        $newerInvId = DB::table('invoices')->insertGetId([
            'invoice_number' => 'SCH-D1-NEWER',
            'family_id' => $data['family_id'],
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'billing_run_id' => $data['billing_run_id'],
            'currency' => 'SAR',
            'total_minor' => 50000,
            'paid_minor' => 0,
            'status' => 'issued',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-10',
            'created_at' => '2026-10-15 14:00:00',
            'updated_at' => '2026-10-15 14:00:00',
        ]);

        DB::table('invoice_items')->insert([
            'invoice_id' => $newerInvId,
            'student_id' => $data['student_id'],
            'enrollment_id' => $data['enrollment_id'],
            'fee_type_id' => $data['fee_type_id'],
            'description' => 'Tuition Oct Newer',
            'period_key' => '2026-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'amount_minor' => 50000,
            'net_minor' => 50000,
            'paid_minor' => 0,
            'currency' => 'SAR',
            'status' => 'active',
            'created_at' => '2026-10-15 14:00:00',
            'updated_at' => '2026-10-15 14:00:00',
        ]);

        $res = $this->queueService->getReadyToSendChallans(['period' => '2026-10'], $this->admin);

        $matching = collect($res->items())->first(fn($r) => (int)$r->family_id === (int)$data['family_id']);
        $this->assertNotNull($matching);
        $this->assertEquals($newerInvId, $matching->invoice_id);
        $this->assertEquals('SCH-D1-NEWER', $matching->invoice_number);
    }

    /** 3. Identical creation timestamps handled by invoice ID, and no mixing of MAX(created_at) and MAX(id) */
    public function test_3_identical_creation_timestamps_handled_by_invoice_id(): void
    {
        // Family A has:
        // - Older invoice created on 2026-10-10 with a very high ID (e.g. 99999)
        // - Newer invoice created on 2026-10-15 12:00:00 with lower ID (e.g. 500)
        // Under the old bug: MAX(created_at) = 2026-10-15 and MAX(id) = 99999, so Family A was ranked as (2026-10-15, 99999)
        // Family B has:
        // - Authoritative invoice created on 2026-10-15 12:00:00 with ID (e.g. 600)
        // Correct behavior: Both created at 12:00:00, but Family B's genuine authoritative invoice (ID 600) is > Family A's (ID 500).
        // Therefore Family B MUST come before Family A!
        $famA = $this->createFamilyWithInvoice([
            'id' => 500,
            'invoice_number' => 'SCH-D1-FA-NEW',
            'created_at' => '2026-10-15 12:00:00',
        ]);
        $famB = $this->createFamilyWithInvoice([
            'id' => 600,
            'invoice_number' => 'SCH-D1-FB-NEW',
            'created_at' => '2026-10-15 12:00:00',
        ]);

        // Add older invoice to Family A with HUGE ID (99999) but older timestamp (2026-10-10)
        DB::table('invoices')->insert([
            'id' => 99999,
            'invoice_number' => 'SCH-D1-FA-OLD-HIGHID',
            'family_id' => $famA['family_id'],
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'billing_run_id' => $famA['billing_run_id'],
            'currency' => 'SAR',
            'total_minor' => 10000,
            'paid_minor' => 0,
            'status' => 'issued',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-10',
            'created_at' => '2026-10-10 10:00:00',
            'updated_at' => '2026-10-10 10:00:00',
        ]);
        DB::table('invoice_items')->insert([
            'invoice_id' => 99999,
            'student_id' => $famA['student_id'],
            'enrollment_id' => $famA['enrollment_id'],
            'fee_type_id' => $famA['fee_type_id'],
            'description' => 'Tuition Oct Old High ID',
            'period_key' => '2026-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'amount_minor' => 10000,
            'net_minor' => 10000,
            'paid_minor' => 0,
            'currency' => 'SAR',
            'status' => 'active',
            'created_at' => '2026-10-10 10:00:00',
            'updated_at' => '2026-10-10 10:00:00',
        ]);

        $res = $this->queueService->getReadyToSendChallans(['period' => '2026-10'], $this->admin, 25);
        $items = collect($res->items());

        $indexA = $items->search(fn($r) => (int)$r->family_id === (int)$famA['family_id']);
        $indexB = $items->search(fn($r) => (int)$r->family_id === (int)$famB['family_id']);

        $this->assertNotFalse($indexA);
        $this->assertNotFalse($indexB);

        // Family B (genuine SCH ID 600 at 12:00) MUST come before Family A (genuine SCH ID 500 at 12:00)
        // Despite Family A having older invoice with ID 99999!
        $this->assertLessThan($indexA, $indexB, 'Family B (ID 600) must precede Family A (ID 500) when timestamps match.');

        // Family A's authoritative selected invoice must be ID 500, NOT 99999
        $rowA = $items[$indexA];
        $this->assertEquals(500, $rowA->invoice_id);
    }

    /** 4. Correct pagination across historical invoices */
    public function test_4_correct_pagination_across_historical_invoices(): void
    {
        $fam1 = $this->createFamilyWithInvoice(['created_at' => '2026-10-15 10:00:00']);
        $fam2 = $this->createFamilyWithInvoice(['created_at' => '2026-10-15 12:00:00']);
        $fam3 = $this->createFamilyWithInvoice(['created_at' => '2026-10-15 14:00:00']);

        // Expected order: fam3 (14:00), fam2 (12:00), fam1 (10:00)
        \Illuminate\Pagination\Paginator::currentPageResolver(fn() => 1);
        $p1 = $this->queueService->getReadyToSendChallans(['period' => '2026-10'], $this->admin, 1);
        $this->assertCount(1, $p1->items());
        $this->assertEquals($fam3['family_id'], $p1->items()[0]->family_id);

        \Illuminate\Pagination\Paginator::currentPageResolver(fn() => 2);
        $p2 = $this->queueService->getReadyToSendChallans(['period' => '2026-10'], $this->admin, 1);
        $this->assertCount(1, $p2->items());
        $this->assertEquals($fam2['family_id'], $p2->items()[0]->family_id);

        \Illuminate\Pagination\Paginator::currentPageResolver(fn() => 3);
        $p3 = $this->queueService->getReadyToSendChallans(['period' => '2026-10'], $this->admin, 1);
        $this->assertCount(1, $p3->items());
        $this->assertEquals($fam1['family_id'], $p3->items()[0]->family_id);

        // Reset resolver
        \Illuminate\Pagination\Paginator::currentPageResolver(fn() => 1);
    }

    /** 5. Consolidated balances unchanged across multiple obligations */
    public function test_5_consolidated_balances_unchanged(): void
    {
        $fam = $this->createFamilyWithInvoice([
            'total_minor' => 40000,
            'paid_minor' => 10000, // 30,000 remaining
        ]);

        // Add a prior month unpaid obligation (September 2026: 25,000)
        $priorRun = DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'frequency' => 'monthly',
            'period_key' => '2026-09',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'due_date' => '2026-09-10',
            'created_by' => $this->admin->id,
            'status' => 'completed',
            'created_at' => '2026-09-01 10:00:00',
            'updated_at' => '2026-09-01 10:00:00',
        ]);
        $priorInvId = DB::table('invoices')->insertGetId([
            'invoice_number' => 'SCH-D1-PRIOR-' . rand(1000, 9999),
            'family_id' => $fam['family_id'],
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'billing_run_id' => $priorRun,
            'currency' => 'SAR',
            'total_minor' => 25000,
            'paid_minor' => 0,
            'status' => 'issued',
            'issued_on' => '2026-09-01',
            'due_date' => '2026-09-10',
            'created_at' => '2026-09-01 10:00:00',
            'updated_at' => '2026-09-01 10:00:00',
        ]);
        DB::table('invoice_items')->insert([
            'invoice_id' => $priorInvId,
            'student_id' => $fam['student_id'],
            'enrollment_id' => $fam['enrollment_id'],
            'fee_type_id' => $fam['fee_type_id'],
            'description' => 'Tuition September 2026',
            'period_key' => '2026-09',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'amount_minor' => 25000,
            'net_minor' => 25000,
            'paid_minor' => 0,
            'currency' => 'SAR',
            'status' => 'active',
            'created_at' => '2026-09-01 10:00:00',
            'updated_at' => '2026-09-01 10:00:00',
        ]);

        $res = $this->queueService->getReadyToSendChallans(['period' => '2026-10'], $this->admin);
        $row = collect($res->items())->first(fn($r) => (int)$r->family_id === (int)$fam['family_id']);

        $this->assertNotNull($row);
        $this->assertEquals(25000, $row->previous_outstanding_minor);
        $this->assertEquals(30000, $row->remaining_balance_minor);
        $this->assertEquals(55000, $row->total_payable_minor);
    }

    /** 6. Superseded batches excluded from successful delivery counts and active pending display */
    public function test_6_superseded_batches_excluded_from_successful_delivery_counts(): void
    {
        $data = $this->createFamilyWithInvoice();

        $batchId = 'BATCH-D1-ALLSUP-' . uniqid();
        DB::table('notification_batches')->insert([
            'batch_id' => $batchId,
            'billing_period' => '2026-10',
            'webhook_type' => 'monthly',
            'status' => 'queued',
            'chunks_total' => 1,
            'chunks_sent' => 0,
            'total_recipients' => 1,
            'total_invoices' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $notifId = DB::table('notifications')->insertGetId([
            'invoice_id' => $data['invoice_id'],
            'batch_id' => $batchId,
            'channel' => 'whatsapp',
            'kind' => 'monthly',
            'status' => 'superseded',
            'is_superseded' => true,
            'superseded_by_invoice_id' => $data['invoice_id'],
            'attempts' => 0,
            'chunk_index' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 1. getBatchDetails reporting
        $batchDetails = $this->queueService->getBatchDetails($batchId, $this->admin);
        $this->assertNotNull($batchDetails);
        $this->assertTrue($batchDetails['batch']->is_all_superseded);
        $this->assertEquals('superseded', $batchDetails['batch']->display_status);
        $this->assertEquals(0, $batchDetails['stats']['submitted']);
        $this->assertEquals(0, $batchDetails['stats']['delivered']);
        $this->assertEquals(1, $batchDetails['stats']['superseded']);

        // 2. getEnrichedBatches pipeline reporting
        $enriched = $this->queueService->getEnrichedBatches('2026-10', 10);
        $batchRow = $enriched->firstWhere('batch_id', $batchId);
        $this->assertNotNull($batchRow);
        $this->assertTrue($batchRow->is_all_superseded);
        $this->assertEquals('superseded', $batchRow->display_status);
        $this->assertEquals(0, $batchRow->submitted_recipients);
        $this->assertEquals(0, $batchRow->chunks_pending); // NOT active pending!

        // 3. UI rendering: Approved Batch Pipeline shows SUPERSEDED badge, not SUBMITTED or QUEUED
        $uiResponse = $this->actingAs($this->admin)->get('/invoices?tab=processing&period=2026-10');
        $uiResponse->assertStatus(200);
        $uiResponse->assertSee('SUPERSEDED');
        $uiResponse->assertSee('All 1 superseded');
    }

    /** 7. Mixed batches retain correct statuses and active pending counts */
    public function test_7_mixed_batches_retain_correct_statuses(): void
    {
        $fam1 = $this->createFamilyWithInvoice();
        $fam2 = $this->createFamilyWithInvoice();

        $batchId = 'BATCH-D1-MIXED-' . uniqid();
        DB::table('notification_batches')->insert([
            'batch_id' => $batchId,
            'billing_period' => '2026-10',
            'webhook_type' => 'monthly',
            'status' => 'submitted',
            'chunks_total' => 2,
            'chunks_sent' => 1,
            'total_recipients' => 2,
            'total_invoices' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Notification 1: Superseded
        DB::table('notifications')->insert([
            'invoice_id' => $fam1['invoice_id'],
            'batch_id' => $batchId,
            'channel' => 'whatsapp',
            'kind' => 'monthly',
            'status' => 'superseded',
            'is_superseded' => true,
            'attempts' => 0,
            'chunk_index' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Notification 2: Submitted / Delivered
        DB::table('notifications')->insert([
            'invoice_id' => $fam2['invoice_id'],
            'batch_id' => $batchId,
            'channel' => 'whatsapp',
            'kind' => 'monthly',
            'status' => 'delivered',
            'is_superseded' => false,
            'attempts' => 1,
            'chunk_index' => 2,
            'submitted_at' => now(),
            'delivered_at' => now(),
            'provider_message_id' => 'MSG-12345',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 1. getBatchDetails reporting
        $batchDetails = $this->queueService->getBatchDetails($batchId, $this->admin);
        $this->assertNotNull($batchDetails);
        $this->assertFalse($batchDetails['batch']->is_all_superseded);
        $this->assertEquals('submitted', $batchDetails['batch']->display_status);
        $this->assertEquals(1, $batchDetails['stats']['submitted']);
        $this->assertEquals(1, $batchDetails['stats']['delivered']);
        $this->assertEquals(1, $batchDetails['stats']['superseded']);

        // 2. getEnrichedBatches reporting
        $enriched = $this->queueService->getEnrichedBatches('2026-10', 10);
        $batchRow = $enriched->firstWhere('batch_id', $batchId);
        $this->assertNotNull($batchRow);
        $this->assertFalse($batchRow->is_all_superseded);
        $this->assertEquals('submitted', $batchRow->display_status);
        $this->assertEquals(1, $batchRow->submitted_recipients);
    }

    /** 8. Manual resends and retries remain intact; superseded cannot be retried */
    public function test_8_manual_resends_and_retries_remain_intact(): void
    {
        $fam = $this->createFamilyWithInvoice();

        $batchId = 'BATCH-D1-RETRY-' . uniqid();
        DB::table('notification_batches')->insert([
            'batch_id' => $batchId,
            'billing_period' => '2026-10',
            'webhook_type' => 'monthly',
            'status' => 'failed',
            'chunks_total' => 1,
            'chunks_sent' => 0,
            'total_recipients' => 1,
            'total_invoices' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $notifId = DB::table('notifications')->insertGetId([
            'invoice_id' => $fam['invoice_id'],
            'batch_id' => $batchId,
            'channel' => 'whatsapp',
            'kind' => 'monthly',
            'status' => 'failed',
            'is_superseded' => false,
            'attempts' => 1,
            'error' => 'Simulated delivery failure',
            'chunk_index' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake();

        // Retry failed notifications via BatchDeliveryService
        $batchService = app(\App\Services\BatchDeliveryService::class);
        $result = $batchService->retryFailedBatchRecipients($batchId, $this->admin);

        $this->assertEquals(1, $result['retried_count']);
        // Because QUEUE_CONNECTION=sync, the worker job runs immediately with Http::fake() and succeeds
        $this->assertEquals('submitted', DB::table('notifications')->where('id', $notifId)->value('status'));

        // Now add a notification for fam2 that was superseded: retry should ignore it
        $fam2 = $this->createFamilyWithInvoice();
        $supNotifId = DB::table('notifications')->insertGetId([
            'invoice_id' => $fam2['invoice_id'],
            'batch_id' => $batchId,
            'channel' => 'whatsapp',
            'kind' => 'monthly',
            'status' => 'superseded',
            'is_superseded' => true,
            'attempts' => 1,
            'error' => 'Superseded',
            'chunk_index' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result2 = $batchService->retryFailedBatchRecipients($batchId, $this->admin);
        $this->assertEquals(0, $result2['retried_count']);
        $this->assertEquals('superseded', DB::table('notifications')->where('id', $supNotifId)->value('status'));
    }

    /** 9. Zero changes to financial data when querying Ready to Send and batches */
    public function test_9_no_changes_to_financial_data(): void
    {
        $fam = $this->createFamilyWithInvoice();

        $preInvoices = DB::table('invoices')->get()->toArray();
        $preItems = DB::table('invoice_items')->get()->toArray();
        $prePayments = DB::table('payments')->get()->toArray();
        $preAllocations = DB::table('payment_allocations')->get()->toArray();

        // Perform read queries
        $this->queueService->getReadyToSendChallans(['period' => '2026-10'], $this->admin, 25);
        $this->queueService->getEnrichedBatches('2026-10', 10);
        $this->actingAs($this->admin)->get('/invoices?period=2026-10');
        $this->actingAs($this->admin)->get('/invoices/queue');

        $postInvoices = DB::table('invoices')->get()->toArray();
        $postItems = DB::table('invoice_items')->get()->toArray();
        $postPayments = DB::table('payments')->get()->toArray();
        $postAllocations = DB::table('payment_allocations')->get()->toArray();

        $this->assertEquals($preInvoices, $postInvoices);
        $this->assertEquals($preItems, $postItems);
        $this->assertEquals($prePayments, $postPayments);
        $this->assertEquals($preAllocations, $postAllocations);
    }
}
