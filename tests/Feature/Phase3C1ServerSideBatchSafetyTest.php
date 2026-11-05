<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\WebhookSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class Phase3C1ServerSideBatchSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function setupTestData(): array
    {
        $this->seed();

        $campus1 = DB::table('campuses')->orderBy('id')->first()->id;
        $campus2 = DB::table('campuses')->where('id', '!=', $campus1)->value('id');
        if (!$campus2) {
            $campus2 = DB::table('campuses')->insertGetId([
                'name' => 'Campus Two',
                'code' => 'campus_two',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $deptSchool = DB::table('departments')->where('code', 'school')->value('id');
        $program = DB::table('programs')->value('id');

        $superAdmin = User::create([
            'name' => 'Super Admin Phase3C1',
            'email' => 'superadmin_phase3c1@example.test',
            'password' => bcrypt('password123'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $cashierCampus1 = User::create([
            'name' => 'Cashier Campus 1',
            'email' => 'cashier_c1_phase3c1@example.test',
            'password' => bcrypt('password123'),
            'role' => 'cashier',
            'campus_id' => $campus1,
            'department_id' => $deptSchool,
            'is_active' => true,
        ]);

        $monthlyUrl = 'https://n8n.example.test/webhook/monthly-invoices';
        $paidUrl = 'https://n8n.example.test/webhook/paid-invoices';
        $secret = 'test-phase3c1-secret-key';

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
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('superAdmin', 'cashierCampus1', 'campus1', 'campus2', 'deptSchool', 'program', 'runOct', 'runNov', 'monthlyUrl', 'paidUrl');
    }

    private function createFamilyWithInvoice(
        array $env,
        int $billingRunId,
        string $familyCode,
        string $invoiceNumber,
        int $amountMinor = 50000,
        int $paidMinor = 0,
        string $status = 'issued',
        string $currency = 'SAR',
        string $periodStart = '2026-10-01'
    ): array {
        $fam = DB::table('families')->where('family_code', $familyCode)->first();
        $famId = $fam ? $fam->id : DB::table('families')->insertGetId([
            'family_code' => $familyCode,
            'guardian_name' => "Guardian {$familyCode}",
            'whatsapp' => '+96650' . rand(1000000, 9999999),
            'contact_verified' => true,
            'whatsapp_verified' => true,
            'messaging_opt_in' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stu = DB::table('students')->where('family_id', $famId)->first();
        $stuId = $stu ? $stu->id : DB::table('students')->insertGetId([
            'family_id' => $famId,
            'name' => "Student {$familyCode}",
            'gr_number' => 'GR-' . rand(1000, 9999),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enr = DB::table('enrollments')->where('student_id', $stuId)->first();
        $enrId = $enr ? $enr->id : DB::table('enrollments')->insertGetId([
            'student_id' => $stuId,
            'campus_id' => $env['campus1'],
            'department_id' => $env['deptSchool'],
            'program_id' => $env['program'],
            'grade_label' => 'Grade 1',
            'currency' => $currency,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invId = DB::table('invoices')->insertGetId([
            'family_id' => $famId,
            'billing_run_id' => $billingRunId,
            'department_id' => $env['deptSchool'],
            'campus_id' => $env['campus1'],
            'invoice_number' => $invoiceNumber,
            'currency' => $currency,
            'status' => $status,
            'issued_on' => $periodStart,
            'due_date' => now()->addDays(15)->toDateString(),
            'total_minor' => $amountMinor,
            'paid_minor' => $paidMinor,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $feeTypeId = DB::table('fee_types')->value('id') ?? DB::table('fee_types')->insertGetId([
            'name' => 'Tuition',
            'code' => 'tuition',
            'category' => 'tuition',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invId,
            'student_id' => $stuId,
            'enrollment_id' => $enrId,
            'fee_type_id' => $feeTypeId,
            'description' => "Tuition {$familyCode}",
            'currency' => $currency,
            'amount_minor' => $amountMinor,
            'net_minor' => $amountMinor,
            'paid_minor' => $paidMinor,
            'status' => $paidMinor >= $amountMinor ? 'paid' : ($paidMinor > 0 ? 'partial' : 'unpaid'),
            'period_key' => "monthly:{$periodStart}.." . date('Y-m-t', strtotime($periodStart)),
            'period_start' => $periodStart,
            'period_end' => date('Y-m-t', strtotime($periodStart)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('famId', 'stuId', 'enrId', 'invId', 'itemId');
    }

    /**
     * Requirement 1: period=all cannot initiate an unauthorized batch.
     */
    public function test_1_period_all_cannot_initiate_an_unauthorized_batch(): void
    {
        Http::preventStrayRequests();
        $env = $this->setupTestData();

        $f = $this->createFamilyWithInvoice($env, $env['runOct'], 'FAM-ALL-REJ', 'INV-OCT-REJ');

        $initialBatches = DB::table('notification_batches')->count();
        $initialNotifications = DB::table('notifications')->count();

        // 1A: POST /invoices/send-batch with period=all and selection_mode=all_matching
        $resAllMatching = $this->actingAs($env['superAdmin'])->post('/invoices/send-batch', [
            'selection_mode' => 'all_matching',
            'channels' => ['whatsapp'],
            'confirmed_grades' => ['Grade 1'],
            'review_confirmed' => '1',
            'webhook_type' => 'monthly',
            'period' => 'all',
        ]);
        $resAllMatching->assertSessionHasErrors(['period']);

        // 1B: POST /invoices/send-batch with period=all and selection_mode=page
        $resPage = $this->actingAs($env['superAdmin'])->post('/invoices/send-batch', [
            'selection_mode' => 'page',
            'invoice_ids' => [$f['invId']],
            'channels' => ['whatsapp'],
            'confirmed_grades' => ['Grade 1'],
            'review_confirmed' => '1',
            'webhook_type' => 'monthly',
            'period' => 'all',
        ]);
        $resPage->assertSessionHasErrors(['period']);

        // 1C: POST /invoices/send with period=all
        $resSend = $this->actingAs($env['superAdmin'])->post('/invoices/send', [
            'invoice_ids' => [$f['invId']],
            'channels' => ['whatsapp'],
            'confirmed_grades' => ['Grade 1'],
            'review_confirmed' => '1',
            'webhook_type' => 'monthly',
            'period' => 'all',
        ]);
        $resSend->assertSessionHasErrors(['period']);

        // 1D: POST /invoices/paid/send-queue with period=all
        $resPaidQueue = $this->actingAs($env['superAdmin'])->post('/invoices/paid/send-queue', [
            'period' => 'all',
            'invoice_ids' => [$f['invId']],
        ]);
        $resPaidQueue->assertSessionHasErrors(['period']);

        // Zero batches and zero notifications were created
        $this->assertSame($initialBatches, DB::table('notification_batches')->count());
        $this->assertSame($initialNotifications, DB::table('notifications')->count());
    }

    /**
     * Requirement 2: Omitted or invalid period values cannot unexpectedly expand recipient selection.
     */
    public function test_2_omitted_or_invalid_period_values_cannot_unexpectedly_expand_selection(): void
    {
        Http::preventStrayRequests();
        $env = $this->setupTestData();

        $fOct = $this->createFamilyWithInvoice($env, $env['runOct'], 'FAM-OCT-SAFE', 'INV-OCT-SAFE', 50000, 0, 'issued', 'SAR', '2026-10-01');
        $fNov = $this->createFamilyWithInvoice($env, $env['runNov'], 'FAM-NOV-SAFE', 'INV-NOV-SAFE', 50000, 0, 'issued', 'SAR', '2026-11-01');

        // 2A: Invalid format values rejected by validation
        foreach (['2026', '2026-13', '2026/10', 'invalid', 'all', 'ALL'] as $badPeriod) {
            $res = $this->actingAs($env['superAdmin'])->post('/invoices/send-batch', [
                'selection_mode' => 'all_matching',
                'channels' => ['whatsapp'],
                'confirmed_grades' => ['Grade 1'],
                'review_confirmed' => '1',
                'webhook_type' => 'monthly',
                'period' => $badPeriod,
            ]);
            $res->assertSessionHasErrors(['period']);
        }

        // 2B: Mismatched period in page selection mode with invoices from other periods is rejected
        $resMismatch = $this->actingAs($env['superAdmin'])->post('/invoices/send-batch', [
            'selection_mode' => 'page',
            'invoice_ids' => [$fNov['invId']], // Nov invoice, while targeted billing period is Oct
            'channels' => ['whatsapp'],
            'confirmed_grades' => ['Grade 1'],
            'review_confirmed' => '1',
            'webhook_type' => 'monthly',
            'period' => '2026-10',
        ]);
        $resMismatch->assertSessionHasErrors(['invoice_ids']);

        // 2C: POST /invoices/send with invoice from another period is rejected when period is provided
        $resSendMismatch = $this->actingAs($env['superAdmin'])->post('/invoices/send', [
            'invoice_ids' => [$fNov['invId']],
            'channels' => ['whatsapp'],
            'confirmed_grades' => ['Grade 1'],
            'review_confirmed' => '1',
            'webhook_type' => 'monthly',
            'period' => '2026-10',
        ]);
        $resSendMismatch->assertSessionHasErrors(['invoice_ids']);
    }

    /**
     * Requirement 3: Valid month-specific batch selection still works.
     */
    public function test_3_valid_month_specific_batch_selection_still_works(): void
    {
        Http::fake([
            'https://n8n.example.test/*' => Http::response(['status' => 'success', 'batch_id' => 'OK'], 200),
        ]);

        $env = $this->setupTestData();
        $f = $this->createFamilyWithInvoice($env, $env['runOct'], 'FAM-VALID-M', 'INV-VALID-M', 50000, 0, 'issued', 'SAR', '2026-10-01');

        $res = $this->actingAs($env['superAdmin'])->post('/invoices/send-batch', [
            'selection_mode' => 'page',
            'invoice_ids' => [$f['invId']],
            'channels' => ['whatsapp'],
            'confirmed_grades' => ['Grade 1'],
            'review_confirmed' => '1',
            'webhook_type' => 'monthly',
            'period' => '2026-10',
        ]);

        $res->assertSessionHasNoErrors();
        $res->assertRedirect();

        $batch = DB::table('notification_batches')->where('billing_period', '2026-10')->first();
        $this->assertNotNull($batch);
        $this->assertSame(1, (int)$batch->total_recipients);
    }

    /**
     * Requirement 4: Previously valid authorized retry and resend paths still work.
     */
    public function test_4_authorized_retry_and_resend_paths_still_work(): void
    {
        Http::fake([
            'https://n8n.example.test/*' => Http::response(['status' => 'success'], 200),
        ]);

        $env = $this->setupTestData();
        $f = $this->createFamilyWithInvoice($env, $env['runOct'], 'FAM-RETRY', 'INV-RETRY-01');

        $batchId = 'BATCH-RETRY-TEST';
        DB::table('notification_batches')->insert([
            'batch_id' => $batchId,
            'webhook_type' => 'monthly',
            'billing_period' => '2026-10',
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
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Retry via batch endpoint
        $resBatchRetry = $this->actingAs($env['superAdmin'])->post("/invoices/batches/{$batchId}/retry-failed");
        $resBatchRetry->assertSessionHasNoErrors();
        $resBatchRetry->assertRedirect();

        // Single notification retry
        $resSingleRetry = $this->actingAs($env['superAdmin'])->post("/invoices/notifications/{$notifId}/retry");
        $resSingleRetry->assertSessionHasNoErrors();
        $resSingleRetry->assertRedirect();
    }

    /**
     * Requirement 5: Paid receipt sending retains its existing behavior.
     */
    public function test_5_paid_receipt_sending_retains_existing_behavior(): void
    {
        Http::fake([
            'https://n8n.example.test/*' => Http::response(['status' => 'success'], 200),
        ]);

        $env = $this->setupTestData();
        $f = $this->createFamilyWithInvoice($env, $env['runOct'], 'FAM-PAID-01', 'INV-PAID-01', 50000, 50000, 'paid', 'SAR', '2026-10-01');

        // Record payment and allocation with receipt dated Oct 10, 2026
        $paymentId = DB::table('payments')->insertGetId([
            'family_id' => $f['famId'],
            'campus_id' => $env['campus1'],
            'department_id' => $env['deptSchool'],
            'amount_minor' => 50000,
            'currency' => 'SAR',
            'received_on' => '2026-10-10',
            'receipt_number' => 'RCT-P3C1-01',
            'idempotency_key' => 'IDEMP-P3C1-' . uniqid(),
            'request_hash' => hash('sha256', 'P3C1-HASH'),
            'received_by' => $env['superAdmin']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payment_allocations')->insert([
            'payment_id' => $paymentId,
            'invoice_item_id' => $f['itemId'],
            'amount_minor' => 50000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Stage paid invoice in queue
        $batchId = 'BATCH-PAID-TEST';
        DB::table('notification_batches')->insert([
            'batch_id' => $batchId,
            'webhook_type' => 'paid',
            'billing_period' => '2026-10',
            'status' => 'queued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('notifications')->insert([
            'batch_id' => $batchId,
            'invoice_id' => $f['invId'],
            'channel' => 'whatsapp',
            'kind' => 'paid',
            'status' => 'queued',
            'attempts' => 0,
            'webhook_type' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Send queued paid invoice
        $res = $this->actingAs($env['superAdmin'])->post('/invoices/paid/send-queue', [
            'period' => '2026-10',
            'invoice_ids' => [$f['invId']],
        ]);

        $res->assertSessionHasNoErrors();
        $res->assertRedirect();
    }

    /**
     * Requirement 6: Phase 3A cross-month duplicate protections remain intact.
     */
    public function test_6_phase_3a_cross_month_duplicate_protections_remain_intact(): void
    {
        Http::fake([
            'https://n8n.example.test/*' => Http::response(['status' => 'success'], 200),
        ]);

        $env = $this->setupTestData();
        $fOct = $this->createFamilyWithInvoice($env, $env['runOct'], 'FAM-3A-OCT', 'INV-3A-OCT-01', 50000, 0, 'issued', 'SAR', '2026-10-01');

        $batchService = app(\App\Services\BatchDeliveryService::class);

        // Stage initial delivery for October
        $decBatch = $batchService->createAndDispatchBatch(
            ['period' => '2026-10'],
            ['Grade 1'],
            ['whatsapp'],
            $env['superAdmin'],
            '2026-10',
            [$fOct['invId']],
            null,
            true,
            false // staged before worker completion
        );

        $this->assertSame('queued', $decBatch['status']);
        $this->assertNotNull($decBatch['batch_id']);

        // Create November invoice for the same family
        $fNov = $this->createFamilyWithInvoice($env, $env['runNov'], 'FAM-3A-OCT', 'INV-3A-NOV-01', 50000, 0, 'issued', 'SAR', '2026-11-01');

        // November selection preview excludes the family because October initial delivery is in flight
        $novSnapshot = $batchService->resolveFilteredSelection(['period' => '2026-11'], $env['superAdmin']);
        $this->assertSame(0, $novSnapshot['total_eligible_recipients']);
        $this->assertGreaterThan(0, $novSnapshot['excluded_breakdown']['already_queued']);

        // Attempting to stage November delivery returns empty status
        $novBatch = $batchService->createAndDispatchBatch(
            ['period' => '2026-11'],
            ['Grade 1'],
            ['whatsapp'],
            $env['superAdmin'],
            '2026-11',
            [$fNov['invId']],
            null,
            true,
            false
        );
        $this->assertSame('empty', $novBatch['status']);
        $this->assertNull($novBatch['batch_id']);
    }

    /**
     * Requirement 7: Phase 3B all-period listing remains read-only.
     */
    public function test_7_phase_3b_all_period_listing_remains_read_only(): void
    {
        $env = $this->setupTestData();
        $this->createFamilyWithInvoice($env, $env['runOct'], 'FAM-RO', 'INV-RO-01');

        $res = $this->actingAs($env['superAdmin'])->get('/invoices?tab=ready&period=all');
        $res->assertOk();

        // Selection snapshot is null
        $this->assertNull($res->viewData('selectionSnapshot'));

        // Shows read-only inspection banner
        $res->assertSee('Ready to Send — All Billing Periods (Read-Only Inspection Mode)');
        $res->assertSee('Read-Only Mode');

        // Form action for sending batch is NOT exposed
        $res->assertDontSee('action="/invoices/send-batch" id="iq-send-form"');
    }

    /**
     * Requirement 8: All invoice and challan routes remain unchanged.
     */
    public function test_8_all_invoice_and_challan_routes_remain_unchanged(): void
    {
        $expectedUris = [
            'challan',
            'invoices',
            'invoices/batches/{batch}',
            'invoices/batches/{batch}/retry-failed',
            'invoices/notifications/process-queue',
            'invoices/notifications/{notification}/retry',
            'invoices/paid/send-queue',
            'invoices/queue',
            'invoices/queue/export',
            'invoices/selection-snapshot',
            'invoices/send',
            'invoices/send-batch',
            'invoices/{invoice}/pdf',
            'public/challans/{family}/{token}/Monthly-Fee-Invoice.pdf',
            'public/families/{family}/challan/Monthly-Fee-Invoice.pdf',
            'public/invoices/{invoice}/Monthly-Fee-Invoice.pdf',
            'public/invoices/{invoice}/pdf',
            'public/invoices/{invoice}/{token}/Monthly-Fee-Invoice.pdf',
        ];

        $routeCollection = Route::getRoutes();
        $matchedUris = [];
        foreach ($routeCollection as $route) {
            $uri = $route->uri();
            if (str_contains($uri, 'invoice') || str_contains($uri, 'challan')) {
                $matchedUris[] = $uri;
            }
        }
        $matchedUris = array_values(array_unique($matchedUris));

        foreach ($expectedUris as $expected) {
            $this->assertContains($expected, $matchedUris, "Expected route URI [{$expected}] not found.");
        }
    }

    /**
     * Requirement 9: No financial records are modified by validation or read-only requests.
     */
    public function test_9_no_financial_records_modified_by_validation_or_readonly_requests(): void
    {
        Http::preventStrayRequests();
        $env = $this->setupTestData();

        $f = $this->createFamilyWithInvoice($env, $env['runOct'], 'FAM-FIN', 'INV-FIN-01', 50000, 0, 'issued', 'SAR', '2026-10-01');

        $fingerprint = fn() => [
            'invoices' => DB::table('invoices')->select('id', 'total_minor', 'paid_minor', 'status')->get()->toArray(),
            'items' => DB::table('invoice_items')->select('id', 'amount_minor', 'paid_minor', 'status')->get()->toArray(),
            'payments' => DB::table('payments')->get()->toArray(),
            'allocations' => DB::table('payment_allocations')->get()->toArray(),
            'runs' => DB::table('billing_runs')->get()->toArray(),
            'batches' => DB::table('notification_batches')->get()->toArray(),
            'notifications' => DB::table('notifications')->get()->toArray(),
        ];

        $before = $fingerprint();

        // 1. Read-only GET period=all
        $this->actingAs($env['superAdmin'])->get('/invoices?tab=ready&period=all');

        // 2. Rejected period=all batch POST
        $this->actingAs($env['superAdmin'])->post('/invoices/send-batch', [
            'selection_mode' => 'all_matching',
            'channels' => ['whatsapp'],
            'confirmed_grades' => ['Grade 1'],
            'review_confirmed' => '1',
            'webhook_type' => 'monthly',
            'period' => 'all',
        ]);

        // 3. Rejected period=all individual POST
        $this->actingAs($env['superAdmin'])->post('/invoices/send', [
            'invoice_ids' => [$f['invId']],
            'channels' => ['whatsapp'],
            'confirmed_grades' => ['Grade 1'],
            'review_confirmed' => '1',
            'webhook_type' => 'monthly',
            'period' => 'all',
        ]);

        $after = $fingerprint();

        $this->assertEquals($before, $after, 'Financial records or queues were mutated during validation or read-only requests.');
    }
}
