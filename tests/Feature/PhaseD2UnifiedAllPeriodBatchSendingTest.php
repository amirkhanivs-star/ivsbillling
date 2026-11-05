<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BatchDeliveryService;
use App\Services\InvoiceDeliveryQueueService;
use App\Services\WebhookSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PhaseD2UnifiedAllPeriodBatchSendingTest extends TestCase
{
    use RefreshDatabase;

    private array $env;

    protected function setUp(): void
    {
        parent::setUp();
        $this->env = $this->setupEnvironment();
    }

    private function setupEnvironment(): array
    {
        $this->seed();

        $campus1 = DB::table('campuses')->orderBy('id')->first()->id;
        $campus2 = DB::table('campuses')->where('id', '!=', $campus1)->value('id');
        if (!$campus2) {
            $campus2 = DB::table('campuses')->insertGetId([
                'name' => 'Campus Two D2',
                'code' => 'campus_two_d2',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $deptSchool = DB::table('departments')->where('code', 'school')->value('id');
        $program = DB::table('programs')->value('id');

        $superAdmin = User::create([
            'name' => 'Super Admin Phase D2',
            'email' => 'admin_d2@example.test',
            'password' => bcrypt('password123'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $cashierCampus1 = User::create([
            'name' => 'Cashier Campus 1 D2',
            'email' => 'cashier_c1_d2@example.test',
            'password' => bcrypt('password123'),
            'role' => 'cashier',
            'campus_id' => $campus1,
            'department_id' => $deptSchool,
            'is_active' => true,
        ]);

        $monthlyUrl = 'https://n8n.example.test/webhook/monthly-invoices';
        $paidUrl = 'https://n8n.example.test/webhook/paid-invoices';
        $secret = 'test-phase-d2-secret';

        $svc = app(WebhookSettingService::class);
        $svc->updateSettings('monthly', ['url' => $monthlyUrl, 'secret' => $secret, 'is_enabled' => true], $superAdmin->id);
        $svc->updateSettings('paid', ['url' => $paidUrl, 'secret' => $secret, 'is_enabled' => true], $superAdmin->id);

        $runOct = DB::table('billing_runs')->insertGetId([
            'campus_id' => $campus1,
            'department_id' => $deptSchool,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-25',
            'created_by' => $superAdmin->id,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $runNov = DB::table('billing_runs')->insertGetId([
            'campus_id' => $campus1,
            'department_id' => $deptSchool,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-11-01..2026-11-30',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'due_date' => '2026-11-25',
            'created_by' => $superAdmin->id,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $runDec = DB::table('billing_runs')->insertGetId([
            'campus_id' => $campus1,
            'department_id' => $deptSchool,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-12-01..2026-12-31',
            'period_start' => '2026-12-01',
            'period_end' => '2026-12-31',
            'due_date' => '2026-12-25',
            'created_by' => $superAdmin->id,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('superAdmin', 'cashierCampus1', 'campus1', 'campus2', 'deptSchool', 'program', 'runOct', 'runNov', 'runDec', 'monthlyUrl', 'paidUrl');
    }

    private function createFamilyWithInvoice(
        int $billingRunId,
        string $familyCode,
        string $invoiceNumber,
        int $amountMinor = 50000,
        int $paidMinor = 0,
        string $currency = 'SAR',
        ?int $campusId = null,
        ?int $familyId = null,
        ?int $studentId = null,
        string $periodMonth = '2026-10',
        ?string $createdAt = null
    ): array {
        $campus = $campusId ?? $this->env['campus1'];
        $created = $createdAt ?? "{$periodMonth}-01 10:00:00";

        if ($familyId) {
            $famId = $familyId;
        } else {
            $fam = DB::table('families')->where('family_code', $familyCode)->first();
            $famId = $fam ? $fam->id : DB::table('families')->insertGetId([
                'family_code' => $familyCode,
                'guardian_name' => "Guardian {$familyCode}",
                'whatsapp' => '+96650' . rand(1000000, 9999999),
                'contact_verified' => true,
                'whatsapp_verified' => true,
                'messaging_opt_in' => true,
                'created_at' => $created,
                'updated_at' => $created,
            ]);
        }

        if ($studentId) {
            $stuId = $studentId;
            $enrId = DB::table('enrollments')->where('student_id', $stuId)->value('id');
        } else {
            $stu = DB::table('students')->where('family_id', $famId)->first();
            if ($stu) {
                $stuId = $stu->id;
                $enrId = DB::table('enrollments')->where('student_id', $stuId)->value('id');
            } else {
                $stuId = DB::table('students')->insertGetId([
                    'family_id' => $famId,
                    'name' => "Student {$familyCode}",
                    'gr_number' => 'GR-' . rand(1000, 9999),
                    'status' => 'active',
                    'created_at' => $created,
                    'updated_at' => $created,
                ]);
                $enrId = DB::table('enrollments')->insertGetId([
                    'student_id' => $stuId,
                    'campus_id' => $campus,
                    'department_id' => $this->env['deptSchool'],
                    'program_id' => $this->env['program'],
                    'grade_label' => 'Grade 1',
                    'currency' => $currency,
                    'status' => 'active',
                    'created_at' => $created,
                    'updated_at' => $created,
                ]);
            }
        }

        $feeTypeId = DB::table('fee_types')->value('id') ?? DB::table('fee_types')->insertGetId([
            'name' => 'Tuition D2',
            'code' => 'tuition_d2',
            'category' => 'tuition',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $status = ($paidMinor >= $amountMinor) ? 'paid' : (($paidMinor > 0) ? 'partial' : 'issued');

        $invId = DB::table('invoices')->insertGetId([
            'family_id' => $famId,
            'campus_id' => $campus,
            'department_id' => $this->env['deptSchool'],
            'billing_run_id' => $billingRunId,
            'invoice_number' => $invoiceNumber,
            'currency' => $currency,
            'total_minor' => $amountMinor,
            'paid_minor' => $paidMinor,
            'status' => $status,
            'issued_on' => "{$periodMonth}-01",
            'due_date' => "{$periodMonth}-25",
            'created_at' => $created,
            'updated_at' => $created,
        ]);

        $itemId = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invId,
            'student_id' => $stuId,
            'enrollment_id' => $enrId,
            'fee_type_id' => $feeTypeId,
            'description' => "Tuition Fee {$periodMonth}",
            'amount_minor' => $amountMinor,
            'net_minor' => $amountMinor,
            'paid_minor' => $paidMinor,
            'currency' => $currency,
            'period_key' => $periodMonth,
            'period_start' => "{$periodMonth}-01",
            'period_end' => "{$periodMonth}-28",
            'status' => $paidMinor >= $amountMinor ? 'paid' : ($paidMinor > 0 ? 'partial' : 'unpaid'),
            'created_at' => $created,
            'updated_at' => $created,
        ]);

        return compact('famId', 'stuId', 'enrId', 'invId', 'itemId');
    }

    /**
     * Requirement 1 & 3: Exactly one eligible consolidated row per family,
     * referencing the newest authoritative SCH (created_at DESC, id DESC).
     */
    public function test_1_one_ready_to_send_row_per_family_using_newest_authoritative_sch(): void
    {
        // Family with 3 monthly invoices: Oct, Nov, Dec
        $oct = $this->createFamilyWithInvoice($this->env['runOct'], 'FAM-D2-UNIQ', 'SCH-OCT-01', 50000, 0, 'SAR', null, null, null, '2026-10', '2026-10-01 09:00:00');
        $nov = $this->createFamilyWithInvoice($this->env['runNov'], 'FAM-D2-UNIQ', 'SCH-NOV-01', 50000, 0, 'SAR', null, $oct['famId'], $oct['stuId'], '2026-11', '2026-11-01 09:00:00');
        $dec = $this->createFamilyWithInvoice($this->env['runDec'], 'FAM-D2-UNIQ', 'SCH-DEC-01', 50000, 0, 'SAR', null, $oct['famId'], $oct['stuId'], '2026-12', '2026-12-01 09:00:00');

        $res = $this->actingAs($this->env['superAdmin'])->get('/invoices?tab=ready&period=all');
        $res->assertOk();

        $challans = $res->viewData('readyChallans');
        $this->assertSame(1, $challans->total(), 'Must show exactly one row for the family.');

        $row = $challans->items()[0];
        $this->assertEquals($oct['famId'], $row->family_id);
        // Primary invoice must be the newest authoritative SCH (December)
        $this->assertEquals($dec['invId'], $row->invoice_id);
        $this->assertEquals('SCH-DEC-01', $row->invoice_number);

        // All 3 invoice IDs and numbers are preserved
        $this->assertCount(3, $row->invoice_ids);
        $this->assertContains($oct['invId'], $row->invoice_ids);
        $this->assertContains($nov['invId'], $row->invoice_ids);
        $this->assertContains($dec['invId'], $row->invoice_ids);
    }

    /**
     * Requirement 2 & 4: Multiple monthly obligations are consolidated correctly.
     */
    public function test_2_multiple_monthly_obligations_consolidated_correctly(): void
    {
        // 500 + 500 + 400 = SAR 1,400 (140,000 minor)
        $oct = $this->createFamilyWithInvoice($this->env['runOct'], 'FAM-D2-CONS', 'SCH-OCT-CONS', 50000, 0, 'SAR', null, null, null, '2026-10', '2026-10-01 09:00:00');
        $nov = $this->createFamilyWithInvoice($this->env['runNov'], 'FAM-D2-CONS', 'SCH-NOV-CONS', 50000, 0, 'SAR', null, $oct['famId'], $oct['stuId'], '2026-11', '2026-11-01 09:00:00');
        $dec = $this->createFamilyWithInvoice($this->env['runDec'], 'FAM-D2-CONS', 'SCH-DEC-CONS', 40000, 0, 'SAR', null, $oct['famId'], $oct['stuId'], '2026-12', '2026-12-01 09:00:00');

        $res = $this->actingAs($this->env['superAdmin'])->get('/invoices?tab=ready&period=all');
        $res->assertOk();

        $row = $res->viewData('readyChallans')->items()[0];
        $this->assertSame(140000, $row->total_payable_minor);
        $this->assertSame('SAR 1,400.00', $row->formatted_total_payable);

        // Snapshot is resolved and passed to the view
        $snapshot = $res->viewData('selectionSnapshot');
        $this->assertNotNull($snapshot);
        $this->assertSame(1, $snapshot['total_eligible_recipients']);
        $this->assertSame(140000, $snapshot['currencies_summary']['SAR']['outstanding_minor']);
    }

    /**
     * Requirement 6 & 7: Staff can select families and send batches from period=all.
     */
    public function test_3_authorized_all_period_batch_send_successfully_dispatched(): void
    {
        Http::fake([
            'https://n8n.example.test/*' => Http::response(['status' => 'success', 'batch_id' => 'OK'], 200),
        ]);

        $f1 = $this->createFamilyWithInvoice($this->env['runOct'], 'FAM-D2-SEND-1', 'SCH-OCT-S1', 50000, 0, 'SAR', null, null, null, '2026-10', '2026-10-01 09:00:00');
        $f1Nov = $this->createFamilyWithInvoice($this->env['runNov'], 'FAM-D2-SEND-1', 'SCH-NOV-S1', 50000, 0, 'SAR', null, $f1['famId'], $f1['stuId'], '2026-11', '2026-11-01 09:00:00');

        $f2 = $this->createFamilyWithInvoice($this->env['runNov'], 'FAM-D2-SEND-2', 'SCH-NOV-S2', 60000, 0, 'SAR', null, null, null, '2026-11', '2026-11-01 09:00:00');

        // Resolve snapshot through the authorized workflow
        $batchService = app(BatchDeliveryService::class);
        $snapshot = $batchService->resolveFilteredSelection(['period' => 'all'], $this->env['superAdmin'], ['whatsapp']);
        $this->assertSame(2, $snapshot['total_eligible_recipients']);

        // Dispatch page selection with period=all and valid snapshot_id
        $res = $this->actingAs($this->env['superAdmin'])->post('/invoices/send-batch', [
            'selection_mode' => 'page',
            'invoice_ids' => [$f1Nov['invId'], $f2['invId']],
            'channels' => ['whatsapp'],
            'confirmed_grades' => ['Grade 1'],
            'review_confirmed' => '1',
            'webhook_type' => 'monthly',
            'period' => 'all',
            'snapshot_id' => $snapshot['snapshot_id'],
        ]);

        $res->assertSessionHasNoErrors();
        $res->assertRedirect();

        // Verify batch was created with billing_period = 'all'
        $batch = DB::table('notification_batches')->where('billing_period', 'all')->first();
        $this->assertNotNull($batch);
        $this->assertSame(2, (int)$batch->total_recipients);
        $this->assertContains($batch->status, ['queued', 'submitted']);

        // Verify exactly one notification per family, referencing the newest authoritative SCH
        $notifs = DB::table('notifications')->where('batch_id', $batch->batch_id)->get();
        $this->assertCount(2, $notifs);
        $this->assertTrue($notifs->contains('invoice_id', $f1Nov['invId']));
        $this->assertTrue($notifs->contains('invoice_id', $f2['invId']));

        // Verify older October invoice for Family 1 was marked superseded
        $olderNotif = DB::table('notifications')->where('invoice_id', $f1['invId'])->first();
        if ($olderNotif) {
            $this->assertTrue((bool)$olderNotif->is_superseded);
        }
    }

    /**
     * Requirement 8 & Server-Side Protection: Arbitrary POST with period=all without snapshot is rejected.
     */
    public function test_4_arbitrary_post_with_period_all_without_snapshot_is_safely_rejected(): void
    {
        $f = $this->createFamilyWithInvoice($this->env['runOct'], 'FAM-D2-REJ', 'SCH-OCT-REJ');

        $initialBatches = DB::table('notification_batches')->count();

        // 1. POST /invoices/send-batch with period=all without snapshot_id
        $resNoSnapshot = $this->actingAs($this->env['superAdmin'])->post('/invoices/send-batch', [
            'selection_mode' => 'all_matching',
            'channels' => ['whatsapp'],
            'confirmed_grades' => ['Grade 1'],
            'review_confirmed' => '1',
            'webhook_type' => 'monthly',
            'period' => 'all',
            // snapshot_id omitted
        ]);
        $resNoSnapshot->assertSessionHasErrors(['period']);

        // 2. POST /invoices/send with period=all (legacy page-send route)
        $resLegacySend = $this->actingAs($this->env['superAdmin'])->post('/invoices/send', [
            'invoice_ids' => [$f['invId']],
            'channels' => ['whatsapp'],
            'confirmed_grades' => ['Grade 1'],
            'review_confirmed' => '1',
            'webhook_type' => 'monthly',
            'period' => 'all',
        ]);
        $resLegacySend->assertSessionHasErrors(['period']);

        // 3. POST /invoices/paid/send-queue with period=all
        $resPaidQueue = $this->actingAs($this->env['superAdmin'])->post('/invoices/paid/send-queue', [
            'period' => 'all',
            'invoice_ids' => [$f['invId']],
        ]);
        $resPaidQueue->assertSessionHasErrors(['period']);

        // Zero batches were created
        $this->assertSame($initialBatches, DB::table('notification_batches')->count());
    }

    /**
     * Requirement 8 & 9: Already queued or submitted families cannot be duplicated.
     */
    public function test_5_already_queued_or_submitted_families_cannot_be_duplicated(): void
    {
        $f = $this->createFamilyWithInvoice($this->env['runOct'], 'FAM-D2-DUP', 'SCH-OCT-DUP');

        $batchService = app(BatchDeliveryService::class);
        $snapshot = $batchService->resolveFilteredSelection(['period' => 'all'], $this->env['superAdmin'], ['whatsapp']);

        // Stage first batch
        $result = $batchService->createAndDispatchBatch(
            ['period' => 'all'],
            ['Grade 1'],
            ['whatsapp'],
            $this->env['superAdmin'],
            'all',
            [$f['invId']],
            $snapshot['snapshot_id'],
            true,
            false // staged but not dispatched yet
        );
        $this->assertSame('queued', $result['status']);

        // Second attempt to resolve snapshot for period=all must exclude this family (already queued)
        $snapshot2 = $batchService->resolveFilteredSelection(['period' => 'all'], $this->env['superAdmin'], ['whatsapp']);
        $this->assertSame(0, $snapshot2['total_eligible_recipients']);
        $this->assertArrayNotHasKey($f['invId'], array_flip($snapshot2['eligible_invoice_ids']));

        // Direct staging attempt must return empty / reject
        $result2 = $batchService->createAndDispatchBatch(
            ['period' => 'all'],
            ['Grade 1'],
            ['whatsapp'],
            $this->env['superAdmin'],
            'all',
            [$f['invId']],
            null,
            true,
            false
        );
        $this->assertSame('empty', $result2['status']);
    }

    /**
     * Worker and batch locks prevent overlapping dispatch.
     */
    public function test_6_worker_and_batch_locks_prevent_overlapping_dispatch(): void
    {
        $f = $this->createFamilyWithInvoice($this->env['runOct'], 'FAM-D2-LOCK', 'SCH-OCT-LOCK');

        $batchService = app(BatchDeliveryService::class);
        $snapshot = $batchService->resolveFilteredSelection(['period' => 'all'], $this->env['superAdmin'], ['whatsapp']);

        // Simulate concurrent attempt with stale snapshot after first batch is staged
        $result1 = $batchService->createAndDispatchBatch(
            ['period' => 'all'],
            ['Grade 1'],
            ['whatsapp'],
            $this->env['superAdmin'],
            'all',
            [$f['invId']],
            $snapshot['snapshot_id'],
            true,
            false
        );
        $this->assertSame('queued', $result1['status']);

        // Attempting to stage with the now-stale snapshot ID throws RuntimeException
        $this->expectException(\RuntimeException::class);
        $batchService->createAndDispatchBatch(
            ['period' => 'all'],
            ['Grade 1'],
            ['whatsapp'],
            $this->env['superAdmin'],
            'all',
            [$f['invId']],
            $snapshot['snapshot_id'], // stale!
            true,
            false
        );
    }

    /**
     * Requirement 10: Manual resends and retries remain intact.
     */
    public function test_7_manual_resend_and_failed_retry_remain_intact(): void
    {
        Http::fake([
            'https://n8n.example.test/*' => Http::response(['status' => 'success'], 200),
        ]);

        $f = $this->createFamilyWithInvoice($this->env['runOct'], 'FAM-D2-RETRY', 'SCH-OCT-RETRY');

        $batchId = 'BATCH-RETRY-D2';
        DB::table('notification_batches')->insert([
            'batch_id' => $batchId,
            'webhook_type' => 'monthly',
            'billing_period' => 'all',
            'status' => 'failed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $notifId = DB::table('notifications')->insertGetId([
            'batch_id' => $batchId,
            'invoice_id' => $f['invId'],
            'channel' => 'whatsapp',
            'status' => 'failed',
            'attempts' => 1,
            'webhook_type' => 'monthly',
            'kind' => 'monthly',
            'is_superseded' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Retry notification via existing authorized route
        $res = $this->actingAs($this->env['superAdmin'])->post("/invoices/notifications/{$notifId}/retry");
        $res->assertSessionHasNoErrors();
        $res->assertRedirect();

        $notif = DB::table('notifications')->where('id', $notifId)->first();
        $this->assertContains($notif->status, ['queued', 'submitted']);
    }

    /**
     * Safety Requirement: Campus, department, and currency permissions remain strictly correct.
     */
    public function test_8_campus_department_and_currency_permissions_strictly_enforced(): void
    {
        // Family with invoice in Campus 1 and Campus 2
        $fC1 = $this->createFamilyWithInvoice($this->env['runOct'], 'FAM-D2-PERM', 'SCH-C1-01', 50000, 0, 'SAR', $this->env['campus1'], null, null, '2026-10');
        $fC2 = $this->createFamilyWithInvoice($this->env['runNov'], 'FAM-D2-PERM', 'SCH-C2-01', 60000, 0, 'SAR', $this->env['campus2'], $fC1['famId'], null, '2026-11');

        // Cashier on Campus 1 requests all-period Ready
        $res = $this->actingAs($this->env['cashierCampus1'])->get('/invoices?tab=ready&period=all');
        $res->assertOk();

        $items = $res->viewData('readyChallans')->items();
        $this->assertCount(1, $items);

        $row = $items[0];
        // Cashier on Campus 1 only sees Campus 1 dues: SAR 500.00
        $this->assertSame(50000, $row->total_payable_minor);
        $this->assertNotContains($fC2['invId'], $row->invoice_ids);

        // Cashier cannot dispatch full-family statements (403 Forbidden)
        $batchService = app(BatchDeliveryService::class);
        $snapshot = $batchService->resolveFilteredSelection(['period' => 'all'], $this->env['cashierCampus1'], ['whatsapp']);

        $resPost = $this->actingAs($this->env['cashierCampus1'])->post('/invoices/send-batch', [
            'selection_mode' => 'page',
            'invoice_ids' => [$fC1['invId']],
            'channels' => ['whatsapp'],
            'confirmed_grades' => ['Grade 1'],
            'review_confirmed' => '1',
            'webhook_type' => 'monthly',
            'period' => 'all',
            'snapshot_id' => $snapshot['snapshot_id'] ?? 'DUMMY',
        ]);
        $resPost->assertForbidden();
    }

    /**
     * Financial immutability: Existing SCH, RCP, and ledger records remain completely unchanged.
     */
    public function test_9_existing_sch_and_rcp_records_and_routes_remain_unchanged(): void
    {
        $f = $this->createFamilyWithInvoice($this->env['runOct'], 'FAM-D2-IMMUT', 'SCH-OCT-IMMUT', 50000, 0);

        $initialInvoices = DB::table('invoices')->get();
        $initialItems = DB::table('invoice_items')->get();
        $initialPayments = DB::table('payments')->get();

        // Access all-period Ready
        $this->actingAs($this->env['superAdmin'])->get('/invoices?tab=ready&period=all')->assertOk();

        // Financial records remain completely identical
        $this->assertEquals($initialInvoices, DB::table('invoices')->get());
        $this->assertEquals($initialItems, DB::table('invoice_items')->get());
        $this->assertEquals($initialPayments, DB::table('payments')->get());
    }

    /**
     * Webhook payload contract and structure: Ensure payload contract is strictly preserved.
     */
    public function test_10_webhook_payload_contract_and_structure_preserved(): void
    {
        $capturedPayload = null;
        Http::fake([
            'https://n8n.example.test/*' => function ($request) use (&$capturedPayload) {
                $capturedPayload = $request->data();
                return Http::response(['status' => 'success'], 200);
            },
        ]);

        $oct = $this->createFamilyWithInvoice($this->env['runOct'], 'FAM-PAYLOAD', 'SCH-OCT-P1', 50000, 0, 'SAR', null, null, null, '2026-10', '2026-10-01 09:00:00');
        $nov = $this->createFamilyWithInvoice($this->env['runNov'], 'FAM-PAYLOAD', 'SCH-NOV-P1', 30000, 0, 'SAR', null, $oct['famId'], $oct['stuId'], '2026-11', '2026-11-01 09:00:00');

        $batchService = app(BatchDeliveryService::class);
        $snapshot = $batchService->resolveFilteredSelection(['period' => 'all'], $this->env['superAdmin'], ['whatsapp']);

        $this->actingAs($this->env['superAdmin'])->post('/invoices/send-batch', [
            'selection_mode' => 'page',
            'invoice_ids' => [$nov['invId']],
            'channels' => ['whatsapp'],
            'confirmed_grades' => ['Grade 1'],
            'review_confirmed' => '1',
            'webhook_type' => 'monthly',
            'period' => 'all',
            'snapshot_id' => $snapshot['snapshot_id'],
        ])->assertRedirect();

        $this->assertNotNull($capturedPayload, 'Webhook should have received the batch payload.');
        $this->assertArrayHasKey('batch_id', $capturedPayload);
        $this->assertArrayHasKey('event_type', $capturedPayload);
        $this->assertSame('invoice.monthly.batch', $capturedPayload['event_type']);
        $this->assertArrayHasKey('invoices', $capturedPayload);
        $this->assertCount(1, $capturedPayload['invoices']);

        $invEntry = $capturedPayload['invoices'][0];
        $this->assertArrayHasKey('invoice', $invEntry);
        $invData = $invEntry['invoice'];
        $this->assertSame('SCH-NOV-P1', $invData['invoice_number']);
        $this->assertSame('FAM-PAYLOAD', $invEntry['family']['family_code']);
        // Total payable is consolidated 500 + 300 = 800 (80,000 minor)
        $this->assertSame(80000, $invData['total_payable_minor']);
        $this->assertSame('800.00', $invData['total_payable_amount']);
        $this->assertSame('SAR', $invData['currency']);
        $this->assertArrayHasKey('dues_by_month', $invData);
        $this->assertArrayHasKey('pdf_attachment', $invEntry);
        $this->assertArrayHasKey('consolidated_challan_url', $invEntry['pdf_attachment']);
    }
}
