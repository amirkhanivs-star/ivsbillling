<?php

namespace Tests\Feature;

use App\Jobs\DispatchBatchToN8n;
use App\Models\User;
use App\Services\BatchDeliveryService;
use App\Services\InvoiceDeliveryQueueService;
use App\Services\WebhookSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BatchQueueDispatcherTest extends TestCase
{
    use RefreshDatabase;

    private function setupTestData(): array
    {
        $this->seed();

        $campus = DB::table('campuses')->value('id');
        $dept = DB::table('departments')->where('code', 'school')->value('id');
        $program = DB::table('programs')->value('id');

        $superAdmin = User::create([
            'name' => 'Super Admin Queue',
            'email' => 'admin_queue@example.test',
            'password' => bcrypt('password123'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $monthlyUrl = 'https://n8n.example.com/webhook/monthly-invoices';
        $paidUrl = 'https://n8n.example.com/webhook/paid-invoices';
        $secret = 'test-secret-key-12345';

        $svc = app(WebhookSettingService::class);
        $svc->updateSettings('monthly', ['url' => $monthlyUrl, 'secret' => $secret, 'is_enabled' => true], $superAdmin->id);
        $svc->updateSettings('paid', ['url' => $paidUrl, 'secret' => $secret, 'is_enabled' => true], $superAdmin->id);

        $run = DB::table('billing_runs')->insertGetId([
            'campus_id' => $campus,
            'department_id' => $dept,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-09-01..2026-09-30',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'due_date' => '2026-09-25',
            'created_by' => $superAdmin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('superAdmin', 'campus', 'dept', 'program', 'run', 'monthlyUrl', 'paidUrl', 'secret');
    }

    private function createFamilyWithInvoice(
        array $data,
        int $index,
        string $grade = 'Grade 1',
        bool $verified = true,
        bool $consented = true,
        string $period = '2026-09'
    ): array {
        $famId = DB::table('families')->insertGetId([
            'family_code' => "FAM-DISP-{$index}",
            'guardian_name' => "Guardian {$index}",
            'whatsapp' => "+96650" . str_pad((string)$index, 7, '0', STR_PAD_LEFT),
            'contact_verified' => $verified,
            'whatsapp_verified' => $verified,
            'messaging_opt_in' => $consented,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stuId = DB::table('students')->insertGetId([
            'family_id' => $famId,
            'name' => "Student {$index}",
            'gr_number' => "GR-DISP-{$index}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrId = DB::table('enrollments')->insertGetId([
            'student_id' => $stuId,
            'campus_id' => $data['campus'],
            'department_id' => $data['dept'],
            'program_id' => $data['program'],
            'grade_label' => $grade,
            'currency' => 'SAR',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invId = DB::table('invoices')->insertGetId([
            'billing_run_id' => $data['run'],
            'family_id' => $famId,
            'campus_id' => $data['campus'],
            'department_id' => $data['dept'],
            'currency' => 'SAR',
            'invoice_number' => "INV-DISP-{$index}",
            'issued_on' => "{$period}-01",
            'due_date' => "{$period}-25",
            'total_minor' => 10000,
            'paid_minor' => 0,
            'status' => 'generated',
            'approved_to_send' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invId,
            'student_id' => $stuId,
            'enrollment_id' => $enrId,
            'description' => 'Monthly Fee',
            'period_key' => "monthly:{$period}:disp{$index}",
            'currency' => 'SAR',
            'amount_minor' => 10000,
            'net_minor' => 10000,
            'paid_minor' => 0,
            'period_start' => "{$period}-01",
            'period_end' => "{$period}-30",
            'status' => 'issued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('famId', 'stuId', 'enrId', 'invId');
    }

    /**
     * 1. Approval creates a dispatchable batch and queues DispatchBatchToN8n on 'notifications'.
     */
    public function test_approval_creates_a_dispatchable_batch(): void
    {
        $data = $this->setupTestData();
        Queue::fake([DispatchBatchToN8n::class]);

        $this->createFamilyWithInvoice($data, 101, 'Grade 1');
        $this->createFamilyWithInvoice($data, 102, 'Grade 1');

        $service = app(BatchDeliveryService::class);
        $res = $service->createAndDispatchBatch(['webhook_type' => 'monthly'], ['Grade 1'], ['whatsapp'], $data['superAdmin'], '2026-09');

        $this->assertNotEmpty($res['batch_id']);
        $this->assertSame(2, $res['eligible_count']);

        // Check batch created with status 'queued'
        $batch = DB::table('notification_batches')->where('batch_id', $res['batch_id'])->first();
        $this->assertNotNull($batch);
        $this->assertSame('queued', $batch->status);
        $this->assertSame(2, (int)$batch->total_recipients);

        // Job was queued specifically on 'notifications' queue
        Queue::assertPushedOn('notifications', DispatchBatchToN8n::class, function ($job) use ($res) {
            return $job->batchId === $res['batch_id'];
        });
    }

    /**
     * 2. Background worker processes batch with HTTP 200 moving records to Sent Invoices.
     */
    public function test_background_worker_processes_batch_to_sent_invoices_on_http_200(): void
    {
        $data = $this->setupTestData();

        Http::fake([
            $data['monthlyUrl'] => Http::response(['message_id' => 'n8n-msg-123', 'status' => 'accepted'], 200),
        ]);

        $this->createFamilyWithInvoice($data, 201, 'Grade 1');
        $this->createFamilyWithInvoice($data, 202, 'Grade 1');

        Queue::fake([DispatchBatchToN8n::class]);
        $service = app(BatchDeliveryService::class);
        $res = $service->createAndDispatchBatch(['webhook_type' => 'monthly'], ['Grade 1'], ['whatsapp'], $data['superAdmin'], '2026-09');

        // Execute the job
        (new DispatchBatchToN8n($res['batch_id']))->handle();

        // Batch should be marked 'submitted'
        $batch = DB::table('notification_batches')->where('batch_id', $res['batch_id'])->first();
        $this->assertSame('submitted', $batch->status);
        $this->assertSame(200, (int)$batch->response_code);
        $this->assertNotNull($batch->submitted_at);

        // All notifications in batch should be marked 'submitted'
        $notifs = DB::table('notifications')->where('batch_id', $res['batch_id'])->get();
        $this->assertCount(2, $notifs);
        foreach ($notifs as $n) {
            $this->assertSame('submitted', $n->status);
            $this->assertSame('n8n-msg-123', $n->provider_message_id);
            $this->assertNotNull($n->submitted_at);
        }
    }

    /**
     * 3. HTTP 404/500 produces a visible failure on the batch and notifications.
     */
    public function test_http_error_produces_visible_failure(): void
    {
        $data = $this->setupTestData();

        Http::fake([
            $data['monthlyUrl'] => Http::response(['error' => 'n8n workflow inactive'], 500),
        ]);

        $this->createFamilyWithInvoice($data, 301, 'Grade 1');

        Queue::fake([DispatchBatchToN8n::class]);
        $service = app(BatchDeliveryService::class);
        $res = $service->createAndDispatchBatch(['webhook_type' => 'monthly'], ['Grade 1'], ['whatsapp'], $data['superAdmin'], '2026-09');

        try {
            (new DispatchBatchToN8n($res['batch_id']))->handle();
            $this->fail('Expected exception on HTTP 500');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('Webhook HTTP 500', $e->getMessage());
        }

        $batch = DB::table('notification_batches')->where('batch_id', $res['batch_id'])->first();
        $this->assertSame('failed', $batch->status);
        $this->assertSame(500, (int)$batch->response_code);
        $this->assertStringContainsString('Webhook HTTP 500', $batch->error);

        $notif = DB::table('notifications')->where('batch_id', $res['batch_id'])->first();
        $this->assertSame('failed', $notif->status);
        $this->assertStringContainsString('Webhook HTTP 500', $notif->error);
    }

    /**
     * 4. Worker restart and retry does not duplicate submissions (idempotent).
     */
    public function test_worker_restart_does_not_duplicate_submissions(): void
    {
        $data = $this->setupTestData();

        $httpCalls = 0;
        Http::fake([
            $data['monthlyUrl'] => function () use (&$httpCalls) {
                $httpCalls++;
                return Http::response(['ok' => true], 200);
            },
        ]);

        $this->createFamilyWithInvoice($data, 401, 'Grade 1');

        Queue::fake([DispatchBatchToN8n::class]);
        $service = app(BatchDeliveryService::class);
        $res = $service->createAndDispatchBatch(['webhook_type' => 'monthly'], ['Grade 1'], ['whatsapp'], $data['superAdmin'], '2026-09');

        (new DispatchBatchToN8n($res['batch_id']))->handle();
        $this->assertSame(1, $httpCalls);

        // Re-run handle simulates worker restart / re-delivery of the same job
        (new DispatchBatchToN8n($res['batch_id']))->handle();
        $this->assertSame(1, $httpCalls); // No second HTTP call made!
    }

    /**
     * 5. Existing successful notifications are skipped upon retry.
     */
    public function test_existing_successful_notifications_are_skipped(): void
    {
        $data = $this->setupTestData();

        $sentItems = [];
        Http::fake([
            $data['monthlyUrl'] => function ($req) use (&$sentItems) {
                $sentItems[] = $req->data();
                return Http::response(['ok' => true], 200);
            },
        ]);

        $f1 = $this->createFamilyWithInvoice($data, 501, 'Grade 1');
        $f2 = $this->createFamilyWithInvoice($data, 502, 'Grade 1');

        Queue::fake([DispatchBatchToN8n::class]);
        $service = app(BatchDeliveryService::class);
        $res = $service->createAndDispatchBatch(['webhook_type' => 'monthly'], ['Grade 1'], ['whatsapp'], $data['superAdmin'], '2026-09');

        // Manually mark f1 as already submitted
        DB::table('notifications')
            ->where('batch_id', $res['batch_id'])
            ->where('invoice_id', $f1['invId'])
            ->update([
                'status' => 'submitted',
                'submitted_at' => now(),
            ]);

        // Dispatch
        (new DispatchBatchToN8n($res['batch_id']))->handle();

        // Only 1 HTTP call made containing f2
        $this->assertCount(1, $sentItems);
        $this->assertCount(1, $sentItems[0]['invoices']);
        $this->assertSame("INV-DISP-502", $sentItems[0]['invoices'][0]['invoice']['invoice_number']);
    }

    /**
     * 6. Stale superseded or void invoices are not dispatched.
     */
    public function test_stale_superseded_invoices_are_not_dispatched(): void
    {
        $data = $this->setupTestData();

        $sentPayloads = [];
        Http::fake([
            $data['monthlyUrl'] => function ($req) use (&$sentPayloads) {
                $sentPayloads[] = $req->data();
                return Http::response(['ok' => true], 200);
            },
        ]);

        $f = $this->createFamilyWithInvoice($data, 601, 'Grade 1');

        Queue::fake([DispatchBatchToN8n::class]);
        $service = app(BatchDeliveryService::class);
        $res = $service->createAndDispatchBatch(['webhook_type' => 'monthly'], ['Grade 1'], ['whatsapp'], $data['superAdmin'], '2026-09');

        // Mark invoice void before worker runs
        DB::table('invoices')->where('id', $f['invId'])->update(['status' => 'voided']);

        (new DispatchBatchToN8n($res['batch_id']))->handle();

        // Zero HTTP dispatches sent
        $this->assertCount(0, $sentPayloads);

        // Notification marked superseded
        $notif = DB::table('notifications')->where('batch_id', $res['batch_id'])->first();
        $this->assertSame('superseded', $notif->status);
        $this->assertTrue((bool)$notif->is_superseded);
    }

    /**
     * 7. Processing tab renders batches pipeline table, chunk metrics, and worker status banner.
     */
    public function test_processing_tab_renders_pipeline_and_worker_status_banner(): void
    {
        $data = $this->setupTestData();

        $this->createFamilyWithInvoice($data, 701, 'Grade 1');

        Queue::fake([DispatchBatchToN8n::class]);
        $service = app(BatchDeliveryService::class);
        $res = $service->createAndDispatchBatch(['webhook_type' => 'monthly'], ['Grade 1'], ['whatsapp'], $data['superAdmin'], '2026-09');

        // Insert a pending job in jobs table to simulate queued buffer with offline worker
        DB::table('jobs')->insert([
            'queue' => 'notifications',
            'payload' => json_encode(['displayName' => 'App\Jobs\DispatchBatchToN8n']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time(),
            'created_at' => time(),
        ]);

        // In this test environment, queue worker is offline, so offline warning should appear
        $response = $this->actingAs($data['superAdmin'])->get('/invoices?tab=processing&period=2026-09');

        $response->assertStatus(200);
        $response->assertSee('Approved Batch Delivery Pipeline');
        $response->assertSee($res['batch_id']);
        $response->assertSee('Process Pending Queue Now');
    }
}
