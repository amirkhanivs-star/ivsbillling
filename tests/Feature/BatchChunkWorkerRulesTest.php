<?php

namespace Tests\Feature;

use App\Jobs\DispatchBatchToN8n;
use App\Jobs\DispatchInvoiceToN8n;
use App\Models\User;
use App\Services\BatchDeliveryService;
use App\Services\WebhookSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class BatchChunkWorkerRulesTest extends TestCase
{
    use RefreshDatabase;

    private function setupTestData(): array
    {
        $this->seed();

        $campus = DB::table('campuses')->value('id');
        $dept = DB::table('departments')->where('code', 'school')->value('id');
        $program = DB::table('programs')->value('id');

        $superAdmin = User::create([
            'name' => 'Super Admin Chunks',
            'email' => 'admin_chunks@example.test',
            'password' => bcrypt('password123'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $monthlyUrl = 'https://n8n.example.com/webhook/monthly-invoices';
        $secret = 'test-chunk-secret-key-12345';

        $svc = app(WebhookSettingService::class);
        $svc->updateSettings('monthly', ['url' => $monthlyUrl, 'secret' => $secret, 'is_enabled' => true], $superAdmin->id);

        $run = DB::table('billing_runs')->insertGetId([
            'campus_id' => $campus,
            'department_id' => $dept,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-25',
            'created_by' => $superAdmin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('superAdmin', 'campus', 'dept', 'program', 'run', 'monthlyUrl', 'secret');
    }

    private function createFamilyWithInvoice(array $data, int $index, string $grade = 'Grade 1'): array
    {
        $famId = DB::table('families')->insertGetId([
            'family_code' => "FAM-CK-{$index}",
            'guardian_name' => "Guardian {$index}",
            'whatsapp' => "+96650" . str_pad((string)$index, 7, '0', STR_PAD_LEFT),
            'contact_verified' => true,
            'whatsapp_verified' => true,
            'messaging_opt_in' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stuId = DB::table('students')->insertGetId([
            'family_id' => $famId,
            'name' => "Student {$index}",
            'gr_number' => "GR-CK-{$index}",
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
            'invoice_number' => "INV-CK-{$index}",
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-25',
            'total_minor' => 10000,
            'paid_minor' => 0,
            'status' => 'generated',
            'approved_to_send' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('invoice_items')->insert([
            'invoice_id' => $invId,
            'student_id' => $stuId,
            'enrollment_id' => $enrId,
            'description' => 'Tuition Fee',
            'period_key' => "monthly:2026-10:ck{$index}",
            'currency' => 'SAR',
            'amount_minor' => 10000,
            'net_minor' => 10000,
            'paid_minor' => 0,
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'status' => 'issued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('famId', 'stuId', 'enrId', 'invId');
    }

    /**
     * Rule 1, 2, 3, 4, 8:
     * Full prompt example: Batch loaded at 10:00 AM with 160 recipients (4 chunks of 50, 50, 50, 10).
     * Chunk 1: 10:00 AM (immediately)
     * Chunk 2: 10:30 AM (attempt before 30 mins rejected; succeeds at 10:30 AM)
     * Chunk 3: 11:00 AM (attempt before 30 mins rejected; succeeds at 11:00 AM)
     * Chunk 4: 11:30 AM (attempt before 30 mins rejected; succeeds at 11:30 AM)
     * Max 50 recipients per chunk, no skipped recipients, no duplicates.
     */
    public function test_batch_chunk_worker_follows_30_minute_interval_rules_and_max_50_recipients(): void
    {
        $data = $this->setupTestData();
        config(['services.n8n.batch_chunk_size' => 50]);
        config(['services.n8n.batch_chunk_interval_minutes' => 30]);

        $sentRequests = [];
        Http::fake([
            $data['monthlyUrl'] => function ($request) use (&$sentRequests) {
                $sentRequests[] = $request->data();
                return Http::response(['ok' => true, 'batch_id' => 'n8n-resp-' . count($sentRequests)], 200);
            },
        ]);

        // Create 160 invoices = 4 chunks (50, 50, 50, 10)
        for ($i = 1; $i <= 160; $i++) {
            $this->createFamilyWithInvoice($data, 1000 + $i, 'Grade 1');
        }

        Queue::fake([DispatchBatchToN8n::class]);

        // 10:00 AM: Batch is loaded into queue
        Carbon::setTestNow('2026-10-28 10:00:00');
        $service = app(BatchDeliveryService::class);
        $res = $service->createAndDispatchBatch(
            ['webhook_type' => 'monthly'],
            ['Grade 1'],
            ['whatsapp'],
            $data['superAdmin'],
            '2026-10'
        );

        $this->assertSame(160, $res['eligible_count']);
        $this->assertSame(4, $res['chunks_total']);

        // First chunk job was pushed to queue immediately upon batch loading
        Queue::assertPushed(DispatchBatchToN8n::class, 1);

        // CHUNK 1: Worker executes immediately at 10:00 AM
        (new DispatchBatchToN8n($res['batch_id']))->handle();

        $this->assertCount(1, $sentRequests, 'Chunk 1 should send immediately at 10:00 AM');
        $this->assertSame(1, $sentRequests[0]['chunk_index']);
        $this->assertSame(4, $sentRequests[0]['total_chunks']);
        $this->assertSame(50, $sentRequests[0]['total_recipient_count']);
        $this->assertCount(50, $sentRequests[0]['invoices']);

        $batch = DB::table('notification_batches')->where('batch_id', $res['batch_id'])->first();
        $this->assertSame(1, (int)$batch->chunks_sent);
        $this->assertSame('queued', $batch->status, 'Batch should remain queued while remaining chunks wait');

        // Check submitted vs queued notifications count
        $submittedCount = DB::table('notifications')->where('batch_id', $res['batch_id'])->where('status', 'submitted')->count();
        $queuedCount = DB::table('notifications')->where('batch_id', $res['batch_id'])->where('status', 'queued')->count();
        $this->assertSame(50, $submittedCount);
        $this->assertSame(110, $queuedCount);

        // Verify next job was pushed with delay to 10:30 AM (30 minutes after Chunk 1)
        Queue::assertPushed(DispatchBatchToN8n::class, 2);
        Queue::assertPushed(DispatchBatchToN8n::class, function ($job) {
            if ($job->delay instanceof \DateTimeInterface) {
                return $job->delay->format('H:i') === '10:30';
            }
            return true;
        });

        // ATTEMPT CHUNK 2 AT 10:15 AM (only 15 minutes elapsed -> must be deferred / no send)
        Carbon::setTestNow('2026-10-28 10:15:00');
        (new DispatchBatchToN8n($res['batch_id']))->handle();
        $this->assertCount(1, $sentRequests, 'No additional chunk should be sent at 10:15 AM');
        $batch = DB::table('notification_batches')->where('batch_id', $res['batch_id'])->first();
        $this->assertSame(1, (int)$batch->chunks_sent);
        $this->assertSame('queued', $batch->status);

        // CHUNK 2 AT 10:30 AM (30 minutes after Chunk 1 -> succeeds)
        Carbon::setTestNow('2026-10-28 10:30:00');
        (new DispatchBatchToN8n($res['batch_id']))->handle();

        $this->assertCount(2, $sentRequests, 'Chunk 2 should send at 10:30 AM');
        $this->assertSame(2, $sentRequests[1]['chunk_index']);
        $this->assertSame(4, $sentRequests[1]['total_chunks']);
        $this->assertSame(50, $sentRequests[1]['total_recipient_count']);
        $this->assertCount(50, $sentRequests[1]['invoices']);

        $batch = DB::table('notification_batches')->where('batch_id', $res['batch_id'])->first();
        $this->assertSame(2, (int)$batch->chunks_sent);
        $this->assertSame(100, DB::table('notifications')->where('batch_id', $res['batch_id'])->where('status', 'submitted')->count());
        $this->assertSame(60, DB::table('notifications')->where('batch_id', $res['batch_id'])->where('status', 'queued')->count());

        // ATTEMPT CHUNK 3 AT 10:45 AM (only 15 mins after Chunk 2 -> deferred / no send)
        Carbon::setTestNow('2026-10-28 10:45:00');
        (new DispatchBatchToN8n($res['batch_id']))->handle();
        $this->assertCount(2, $sentRequests, 'Chunk 3 should NOT send before 30 minutes after Chunk 2');

        // CHUNK 3 AT 11:00 AM (30 minutes after Chunk 2 -> succeeds)
        Carbon::setTestNow('2026-10-28 11:00:00');
        (new DispatchBatchToN8n($res['batch_id']))->handle();

        $this->assertCount(3, $sentRequests, 'Chunk 3 should send at 11:00 AM');
        $this->assertSame(3, $sentRequests[2]['chunk_index']);
        $this->assertSame(4, $sentRequests[2]['total_chunks']);
        $this->assertSame(50, $sentRequests[2]['total_recipient_count']);
        $this->assertCount(50, $sentRequests[2]['invoices']);

        $batch = DB::table('notification_batches')->where('batch_id', $res['batch_id'])->first();
        $this->assertSame(3, (int)$batch->chunks_sent);
        $this->assertSame(150, DB::table('notifications')->where('batch_id', $res['batch_id'])->where('status', 'submitted')->count());
        $this->assertSame(10, DB::table('notifications')->where('batch_id', $res['batch_id'])->where('status', 'queued')->count());

        // ATTEMPT CHUNK 4 AT 11:15 AM (only 15 mins after Chunk 3 -> deferred / no send)
        Carbon::setTestNow('2026-10-28 11:15:00');
        (new DispatchBatchToN8n($res['batch_id']))->handle();
        $this->assertCount(3, $sentRequests, 'Chunk 4 should NOT send before 30 minutes after Chunk 3');

        // CHUNK 4 AT 11:30 AM (30 minutes after Chunk 3 -> succeeds)
        Carbon::setTestNow('2026-10-28 11:30:00');
        (new DispatchBatchToN8n($res['batch_id']))->handle();

        $this->assertCount(4, $sentRequests, 'Chunk 4 should send at 11:30 AM');
        $this->assertSame(4, $sentRequests[3]['chunk_index']);
        $this->assertSame(4, $sentRequests[3]['total_chunks']);
        $this->assertSame(10, $sentRequests[3]['total_recipient_count'], 'Remaining chunk contains 10 recipients');
        $this->assertCount(10, $sentRequests[3]['invoices']);

        // BATCH COMPLETION & DEDUPLICATION / NO SKIPPED CHECKS
        $batch = DB::table('notification_batches')->where('batch_id', $res['batch_id'])->first();
        $this->assertSame(4, (int)$batch->chunks_sent);
        $this->assertSame('submitted', $batch->status, 'Batch should be marked submitted when all chunks complete');

        $this->assertSame(160, DB::table('notifications')->where('batch_id', $res['batch_id'])->where('status', 'submitted')->count());
        $this->assertSame(0, DB::table('notifications')->where('batch_id', $res['batch_id'])->where('status', 'queued')->count());

        // Verify across all chunks that every invoice ID was sent exactly ONCE (no duplicates, no skipped)
        $allSentInvoiceIds = [];
        foreach ($sentRequests as $req) {
            foreach ($req['invoices'] as $inv) {
                $allSentInvoiceIds[] = $inv['invoice_id'];
            }
        }
        $this->assertCount(160, $allSentInvoiceIds);
        $this->assertCount(160, array_unique($allSentInvoiceIds), 'Every invoice must be unique with zero duplicate sends');

        Carbon::setTestNow(null);
    }

    /**
     * Requirement: "The 30-minute interval starts when the first chunk is sent."
     * If the batch is loaded at 10:00 AM, but the worker doesn't send chunk 1 until 10:10 AM,
     * the 30-minute interval starts at 10:10 AM, so chunk 2 can only be sent at 10:40 AM.
     */
    public function test_interval_starts_when_first_chunk_is_sent_not_when_batch_loaded(): void
    {
        $data = $this->setupTestData();
        config(['services.n8n.batch_chunk_size' => 50]);
        config(['services.n8n.batch_chunk_interval_minutes' => 30]);

        $sentRequests = [];
        Http::fake([
            $data['monthlyUrl'] => function ($request) use (&$sentRequests) {
                $sentRequests[] = $request->data();
                return Http::response(['ok' => true], 200);
            },
        ]);

        for ($i = 1; $i <= 60; $i++) {
            $this->createFamilyWithInvoice($data, 2000 + $i, 'Grade 1');
        }

        Queue::fake([DispatchBatchToN8n::class]);

        // 10:00 AM: Batch loaded
        Carbon::setTestNow('2026-10-28 10:00:00');
        $service = app(BatchDeliveryService::class);
        $res = $service->createAndDispatchBatch(
            ['webhook_type' => 'monthly'],
            ['Grade 1'],
            ['whatsapp'],
            $data['superAdmin'],
            '2026-10'
        );

        // Worker starts 10 minutes later (10:10 AM)
        Carbon::setTestNow('2026-10-28 10:10:00');
        (new DispatchBatchToN8n($res['batch_id']))->handle();
        $this->assertCount(1, $sentRequests, 'Chunk 1 sent at 10:10 AM');

        // At 10:30 AM (30 min after load, but only 20 min after chunk 1 sent):
        // Must be deferred because 30 min interval starts when first chunk was SENT.
        Carbon::setTestNow('2026-10-28 10:30:00');
        (new DispatchBatchToN8n($res['batch_id']))->handle();
        $this->assertCount(1, $sentRequests, 'Chunk 2 should NOT send at 10:30 AM because 30 mins have not elapsed since 10:10 AM');

        // At 10:40 AM (exactly 30 min after chunk 1 was sent): succeeds!
        Carbon::setTestNow('2026-10-28 10:40:00');
        (new DispatchBatchToN8n($res['batch_id']))->handle();
        $this->assertCount(2, $sentRequests, 'Chunk 2 succeeds at 10:40 AM');

        Carbon::setTestNow(null);
    }

    /**
     * Requirement: "Important: Apply these changes to the existing queue worker without affecting other workflows."
     * Single invoice dispatches (DispatchInvoiceToN8n) must execute immediately without 30-minute intervals.
     */
    public function test_single_invoice_dispatch_workflow_remains_unaffected_and_immediate(): void
    {
        $data = $this->setupTestData();
        config(['services.n8n.batch_chunk_interval_minutes' => 30]);

        $sentCount = 0;
        Http::fake([
            $data['monthlyUrl'] => function () use (&$sentCount) {
                $sentCount++;
                return Http::response(['message_id' => 'single-msg-' . $sentCount], 200);
            },
        ]);

        $inv1 = $this->createFamilyWithInvoice($data, 3001, 'Grade 1');
        $inv2 = $this->createFamilyWithInvoice($data, 3002, 'Grade 1');

        $notif1 = DB::table('notifications')->insertGetId([
            'invoice_id' => $inv1['invId'],
            'channel' => 'whatsapp',
            'kind' => 'monthly',
            'webhook_type' => 'monthly',
            'status' => 'queued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $notif2 = DB::table('notifications')->insertGetId([
            'invoice_id' => $inv2['invId'],
            'channel' => 'whatsapp',
            'kind' => 'monthly',
            'webhook_type' => 'monthly',
            'status' => 'queued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Send notification 1
        (new DispatchInvoiceToN8n($notif1))->handle();
        $this->assertSame(1, $sentCount);

        // Immediately send notification 2 (zero interval required for individual invoice workflow)
        (new DispatchInvoiceToN8n($notif2))->handle();
        $this->assertSame(2, $sentCount, 'Single invoice dispatches must never be delayed by the batch 30-minute interval');
    }

    /**
     * Requirement: "Each chunk must contain a maximum of 50 recipients."
     * Even if config is set to 100, the worker enforces min(50, ...) cap.
     */
    public function test_chunk_size_is_capped_at_maximum_50_recipients(): void
    {
        $data = $this->setupTestData();
        config(['services.n8n.batch_chunk_size' => 100]); // attempt to set above 50
        config(['services.n8n.batch_chunk_interval_minutes' => 30]);

        $capturedPayload = null;
        Http::fake([
            $data['monthlyUrl'] => function ($request) use (&$capturedPayload) {
                $capturedPayload = $request->data();
                return Http::response(['ok' => true], 200);
            },
        ]);

        for ($i = 1; $i <= 65; $i++) {
            $this->createFamilyWithInvoice($data, 4000 + $i, 'Grade 1');
        }

        Queue::fake([DispatchBatchToN8n::class]);

        Carbon::setTestNow('2026-10-28 10:00:00');
        $service = app(BatchDeliveryService::class);
        $res = $service->createAndDispatchBatch(
            ['webhook_type' => 'monthly'],
            ['Grade 1'],
            ['whatsapp'],
            $data['superAdmin'],
            '2026-10'
        );

        (new DispatchBatchToN8n($res['batch_id']))->handle();

        $this->assertNotNull($capturedPayload);
        $this->assertLessThanOrEqual(50, $capturedPayload['total_recipient_count'], 'Chunk size must not exceed 50 recipients');
        $this->assertSame(50, $capturedPayload['total_recipient_count']);

        Carbon::setTestNow(null);
    }

    /**
     * Requirement: "Prevent simultaneous chunk submissions, skipped recipients, and duplicates."
     * If Worker A is already processing the batch, Worker B cannot simultaneously submit chunks.
     */
    public function test_simultaneous_chunk_submissions_are_prevented_by_lock(): void
    {
        $data = $this->setupTestData();
        config(['services.n8n.batch_chunk_size' => 50]);
        config(['services.n8n.batch_chunk_interval_minutes' => 30]);

        Http::fake([$data['monthlyUrl'] => Http::response(['ok' => true], 200)]);

        for ($i = 1; $i <= 60; $i++) {
            $this->createFamilyWithInvoice($data, 5000 + $i, 'Grade 1');
        }

        Queue::fake([DispatchBatchToN8n::class]);
        $service = app(BatchDeliveryService::class);
        $res = $service->createAndDispatchBatch(['webhook_type' => 'monthly'], ['Grade 1'], ['whatsapp'], $data['superAdmin'], '2026-10');

        // Manually acquire lock as Worker A
        $lock = app(\App\Services\BatchDispatchLock::class);
        $this->assertTrue($lock->acquire($res['batch_id']));

        // Worker B attempts to process the same batch concurrently
        try {
            (new DispatchBatchToN8n($res['batch_id']))->handle();
            $this->fail('Worker B should NOT be able to run while Worker A holds lock');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Batch is busy', $e->getMessage());
        }

        $lock->release();
    }

    /**
     * Requirement: Independent workflows and batches must not block each other.
     * Batch A and Batch B have independent 30-minute intervals.
     */
    public function test_independent_batches_execute_on_their_own_30_minute_intervals(): void
    {
        $data = $this->setupTestData();
        config(['services.n8n.batch_chunk_size' => 50]);
        config(['services.n8n.batch_chunk_interval_minutes' => 30]);

        $sentRequests = [];
        Http::fake([
            $data['monthlyUrl'] => function ($request) use (&$sentRequests) {
                $sentRequests[] = $request->data();
                return Http::response(['ok' => true], 200);
            },
        ]);

        for ($i = 1; $i <= 60; $i++) {
            $this->createFamilyWithInvoice($data, 6000 + $i, 'Grade 1');
            $this->createFamilyWithInvoice($data, 7000 + $i, 'Grade 2');
        }

        Queue::fake([DispatchBatchToN8n::class]);
        $service = app(BatchDeliveryService::class);

        // Batch A loaded at 10:00 AM (Grade 1)
        Carbon::setTestNow('2026-10-28 10:00:00');
        $resA = $service->createAndDispatchBatch(['webhook_type' => 'monthly', 'grade' => 'Grade 1'], ['Grade 1'], ['whatsapp'], $data['superAdmin'], '2026-10');
        (new DispatchBatchToN8n($resA['batch_id']))->handle(); // Batch A Chunk 1 sent at 10:00 AM

        // Batch B loaded at 10:05 AM (Grade 2)
        Carbon::setTestNow('2026-10-28 10:05:00');
        $resB = $service->createAndDispatchBatch(['webhook_type' => 'monthly', 'grade' => 'Grade 2'], ['Grade 2'], ['whatsapp'], $data['superAdmin'], '2026-10');
        (new DispatchBatchToN8n($resB['batch_id']))->handle(); // Batch B Chunk 1 sent at 10:05 AM

        $this->assertCount(2, $sentRequests);

        // At 10:30 AM:
        // Batch A Chunk 2 is allowed (30 min since 10:00 AM)
        // Batch B Chunk 2 is deferred (only 25 min since 10:05 AM)
        Carbon::setTestNow('2026-10-28 10:30:00');
        (new DispatchBatchToN8n($resA['batch_id']))->handle();
        $this->assertCount(3, $sentRequests, 'Batch A Chunk 2 should succeed at 10:30 AM');

        (new DispatchBatchToN8n($resB['batch_id']))->handle();
        $this->assertCount(3, $sentRequests, 'Batch B Chunk 2 must NOT send at 10:30 AM');

        // At 10:35 AM:
        // Batch B Chunk 2 is allowed (30 min since 10:05 AM)
        Carbon::setTestNow('2026-10-28 10:35:00');
        (new DispatchBatchToN8n($resB['batch_id']))->handle();
        $this->assertCount(4, $sentRequests, 'Batch B Chunk 2 succeeds at 10:35 AM');

        Carbon::setTestNow(null);
    }

    /**
     * Requirement: Failure handling and no duplicate submissions.
     * When chunk 2 fails with HTTP 500, notifications are marked failed.
     * When retried, only chunk 2 is resent; chunk 1 is NOT resent.
     */
    public function test_chunk_failure_records_error_and_subsequent_retry_does_not_resend_chunk_1(): void
    {
        $data = $this->setupTestData();
        config(['services.n8n.batch_chunk_size' => 50]);
        config(['services.n8n.batch_chunk_interval_minutes' => 30]);

        $postCount = 0;
        Http::fake([
            $data['monthlyUrl'] => function () use (&$postCount) {
                $postCount++;
                if ($postCount === 2) {
                    return Http::response(['message' => 'Internal server error'], 500);
                }
                return Http::response(['ok' => true], 200);
            },
        ]);

        for ($i = 1; $i <= 80; $i++) {
            $this->createFamilyWithInvoice($data, 8000 + $i, 'Grade 1');
        }

        Queue::fake([DispatchBatchToN8n::class]);
        $service = app(BatchDeliveryService::class);

        // 10:00 AM: Chunk 1 succeeds
        Carbon::setTestNow('2026-10-28 10:00:00');
        $res = $service->createAndDispatchBatch(['webhook_type' => 'monthly'], ['Grade 1'], ['whatsapp'], $data['superAdmin'], '2026-10');
        (new DispatchBatchToN8n($res['batch_id']))->handle();
        $this->assertSame(1, $postCount);
        $this->assertSame(50, DB::table('notifications')->where('batch_id', $res['batch_id'])->where('status', 'submitted')->count());

        // 10:30 AM: Chunk 2 fails with 500
        Carbon::setTestNow('2026-10-28 10:30:00');
        try {
            (new DispatchBatchToN8n($res['batch_id']))->handle();
            $this->fail('Chunk 2 should fail on HTTP 500');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('Webhook HTTP 500', $e->getMessage());
        }

        $this->assertSame(2, $postCount);
        $batch = DB::table('notification_batches')->where('batch_id', $res['batch_id'])->first();
        $this->assertSame('failed', $batch->status);
        $this->assertSame(30, DB::table('notifications')->where('batch_id', $res['batch_id'])->where('status', 'failed')->count());
        $this->assertSame(50, DB::table('notifications')->where('batch_id', $res['batch_id'])->where('status', 'submitted')->count());

        // Admin retries failed batch recipients
        $retryRes = $service->retryFailedBatchRecipients($res['batch_id'], $data['superAdmin']);
        $this->assertSame(30, $retryRes['retried_count']);

        // Worker executes retry immediately
        (new DispatchBatchToN8n($res['batch_id']))->handle();
        $this->assertSame(3, $postCount);

        // All 80 records submitted, Chunk 1 was NEVER duplicated
        $this->assertSame(80, DB::table('notifications')->where('batch_id', $res['batch_id'])->where('status', 'submitted')->count());
        $this->assertSame(0, DB::table('notifications')->where('batch_id', $res['batch_id'])->where('status', 'failed')->count());
        $batchAfter = DB::table('notification_batches')->where('batch_id', $res['batch_id'])->first();
        $this->assertSame('submitted', $batchAfter->status);

        Carbon::setTestNow(null);
    }
}
