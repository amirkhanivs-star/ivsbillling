<?php

namespace Tests\Feature;

use App\Jobs\DispatchBatchToN8n;
use App\Models\User;
use App\Services\BatchDeliveryService;
use App\Services\WebhookSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PaidInvoicesTabTest extends TestCase
{
    use RefreshDatabase;

    private function setupTestData(): array
    {
        $this->seed();

        $campus = DB::table('campuses')->value('id');
        $dept = DB::table('departments')->where('code', 'school')->value('id');
        $program = DB::table('programs')->value('id');

        $superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin_paid@example.test',
            'password' => bcrypt('password123'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $monthlyUrl = 'https://n8n.example.com/webhook/monthly-invoices';
        $paidUrl = 'https://n8n.example.com/webhook/paid-invoices';
        $secret = 'test-batch-secret-key-123';

        $svc = app(WebhookSettingService::class);
        $svc->updateSettings('monthly', ['url' => $monthlyUrl, 'secret' => $secret, 'is_enabled' => true], $superAdmin->id);
        $svc->updateSettings('paid', ['url' => $paidUrl, 'secret' => $secret, 'is_enabled' => true], $superAdmin->id);

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

        return compact('superAdmin', 'campus', 'dept', 'program', 'run', 'monthlyUrl', 'paidUrl', 'secret');
    }

    private function createPaidFamilyWithReceipt(
        array $data,
        int $index,
        string $grade = 'Grade 1',
        int $amountMinor = 25000,
        string $receiptNumber = 'RCP-TEST-001',
        string $receivedOn = '2026-10-24'
    ): array {
        $famId = DB::table('families')->insertGetId([
            'family_code' => "FAM-PAID-{$index}",
            'guardian_name' => "Guardian Paid {$index}",
            'whatsapp' => "+96650" . str_pad((string)$index, 7, '0', STR_PAD_LEFT),
            'contact_verified' => true,
            'whatsapp_verified' => true,
            'messaging_opt_in' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stuId = DB::table('students')->insertGetId([
            'family_id' => $famId,
            'name' => "Student Paid {$index}",
            'gr_number' => "GR-P-{$index}",
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
            'family_id' => $famId,
            'campus_id' => $data['campus'],
            'department_id' => $data['dept'],
            'billing_run_id' => $data['run'],
            'invoice_number' => "SCH-2026-PAID-{$index}",
            'currency' => 'SAR',
            'total_minor' => $amountMinor,
            'paid_minor' => $amountMinor,
            'status' => 'paid',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-25',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invId,
            'student_id' => $stuId,
            'enrollment_id' => $enrId,
            'description' => 'Tuition Fee',
            'amount_minor' => $amountMinor,
            'net_minor' => $amountMinor,
            'paid_minor' => $amountMinor,
            'currency' => 'SAR',
            'period_key' => '2026-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $paymentId = DB::table('payments')->insertGetId([
            'family_id' => $famId,
            'campus_id' => $data['campus'],
            'department_id' => $data['dept'],
            'receipt_number' => $receiptNumber,
            'received_on' => $receivedOn,
            'amount_minor' => $amountMinor,
            'unallocated_minor' => 0,
            'currency' => 'SAR',
            'note' => 'Full payment received',
            'idempotency_key' => 'IDEMP-P-' . $index . '-' . uniqid(),
            'request_hash' => hash('sha256', 'P-' . $index),
            'received_by' => $data['superAdmin']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payment_allocations')->insert([
            'payment_id' => $paymentId,
            'invoice_item_id' => $itemId,
            'amount_minor' => $amountMinor,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('famId', 'stuId', 'enrId', 'invId', 'itemId', 'paymentId', 'receiptNumber');
    }

    /**
     * Test 1: Paid Invoices tab opens and displays correct records with receipts and PDFs.
     */
    public function test_paid_invoices_tab_opens_and_displays_correct_records(): void
    {
        $data = $this->setupTestData();
        $paid = $this->createPaidFamilyWithReceipt($data, 101, 'Grade 1', 25000, 'RCP-2026-999');

        $response = $this->actingAs($data['superAdmin'])
            ->get('/invoices?tab=paid&period=2026-10');

        $response->assertStatus(200);
        $response->assertViewHas('paidInvoicesPage');
        $response->assertSee('Paid Invoices · Review &amp; Outbound Delivery', false);
        $response->assertSee('SCH-2026-PAID-101');
        $response->assertSee('RCP-2026-999');
        $response->assertSee('Student Paid 101');
        $response->assertSee('SAR 250.00');
        $response->assertSee("/receipts/{$paid['paymentId']}/pdf");
        $response->assertSee("/invoices/{$paid['invId']}/pdf");
        $response->assertSee('Queue Approved Paid Batch to Webhook');
    }

    /**
     * Test 2: Mocked paid requests go to the Paid Webhook only.
     */
    public function test_mocked_paid_requests_go_to_paid_webhook_only(): void
    {
        $data = $this->setupTestData();
        $paid = $this->createPaidFamilyWithReceipt($data, 102, 'Grade 1', 35000, 'RCP-2026-102');

        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            $data['monthlyUrl'] => Http::response(['ok' => true], 200),
            $data['paidUrl'] => Http::response(['status' => 'success', 'batch_id' => 'mocked'], 200),
        ]);

        Queue::fake([DispatchBatchToN8n::class]);

        $postData = [
            'selection_mode' => 'page',
            'invoice_ids' => [$paid['invId']],
            'channels' => ['whatsapp'],
            'review_confirmed' => 1,
            'webhook_type' => 'paid',
            'paid_only' => 1,
            'period' => '2026-10',
        ];

        $response = $this->actingAs($data['superAdmin'])
            ->from('/invoices?tab=paid&period=2026-10')
            ->post('/invoices/send-batch', $postData);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect('/invoices?tab=paid&period=2026-10');

        Queue::assertPushed(DispatchBatchToN8n::class, function ($job) use ($data) {
            $batch = DB::table('notification_batches')->where('batch_id', $job->batchId)->first();
            return $batch && $batch->webhook_type === 'paid' && $batch->event_type === 'invoice.paid.batch';
        });

        // Now run the job handle to verify HTTP dispatch
        $batch = DB::table('notification_batches')->where('webhook_type', 'paid')->latest()->first();
        $this->assertNotNull($batch);

        (new DispatchBatchToN8n($batch->batch_id, DB::table('notifications')->where('batch_id', $batch->batch_id)->pluck('id')->all()))->handle();

        // Check that requests went ONLY to the Paid Webhook URL
        Http::assertSent(function ($request) use ($data) {
            return $request->url() === $data['paidUrl']
                && $request['event'] === 'invoice.paid.batch'
                && count($request['invoices']) === 1
                && isset($request['invoices'][0]['payment_details'])
                && $request['invoices'][0]['payment_details']['verified_receipt_numbers'] === ['RCP-2026-102'];
        });

        // Ensure monthly webhook was NEVER called
        Http::assertNotSent(function ($request) use ($data) {
            return $request->url() === $data['monthlyUrl'];
        });

        // Assert notification moved to 'submitted' state (which shows in Sent History)
        $notif = DB::table('notifications')->where('batch_id', $batch->batch_id)->first();
        $this->assertEquals('submitted', $notif->status);
    }

    /**
     * Test 3: Monthly sending remains unaffected and routes to monthly webhook only.
     */
    public function test_monthly_sending_remains_unaffected_and_routes_to_monthly_webhook_only(): void
    {
        $data = $this->setupTestData();

        // Create an unpaid monthly invoice
        $famId = DB::table('families')->insertGetId([
            'family_code' => "FAM-MONTHLY-201",
            'guardian_name' => "Guardian Monthly 201",
            'whatsapp' => "+966507777777",
            'contact_verified' => true,
            'whatsapp_verified' => true,
            'messaging_opt_in' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stuId = DB::table('students')->insertGetId([
            'family_id' => $famId,
            'name' => "Student Monthly 201",
            'gr_number' => "GR-M-201",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrId = DB::table('enrollments')->insertGetId([
            'student_id' => $stuId,
            'campus_id' => $data['campus'],
            'department_id' => $data['dept'],
            'program_id' => $data['program'],
            'grade_label' => 'Grade 1',
            'currency' => 'SAR',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invId = DB::table('invoices')->insertGetId([
            'family_id' => $famId,
            'campus_id' => $data['campus'],
            'department_id' => $data['dept'],
            'billing_run_id' => $data['run'],
            'invoice_number' => "SCH-2026-MONTHLY-201",
            'currency' => 'SAR',
            'total_minor' => 15000,
            'paid_minor' => 0,
            'status' => 'issued',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-25',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invId,
            'student_id' => $stuId,
            'enrollment_id' => $enrId,
            'description' => 'Tuition Fee',
            'amount_minor' => 15000,
            'net_minor' => 15000,
            'paid_minor' => 0,
            'currency' => 'SAR',
            'period_key' => '2026-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            $data['monthlyUrl'] => Http::response(['ok' => true], 200),
            $data['paidUrl'] => Http::response(['ok' => true], 200),
        ]);

        Queue::fake([DispatchBatchToN8n::class]);

        $postData = [
            'selection_mode' => 'page',
            'invoice_ids' => [$invId],
            'channels' => ['whatsapp'],
            'review_confirmed' => 1,
            'webhook_type' => 'monthly',
            'period' => '2026-10',
        ];

        $response = $this->actingAs($data['superAdmin'])
            ->from('/invoices?tab=ready&period=2026-10')
            ->post('/invoices/send-batch', $postData);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect('/invoices?tab=ready&period=2026-10');

        $batch = DB::table('notification_batches')->where('webhook_type', 'monthly')->latest()->first();
        $this->assertNotNull($batch);
        $this->assertEquals('invoice.monthly.batch', $batch->event_type);

        (new DispatchBatchToN8n($batch->batch_id))->handle();

        // Check that requests went ONLY to the Monthly Webhook URL
        Http::assertSent(function ($request) use ($data) {
            return $request->url() === $data['monthlyUrl'] && $request['event'] === 'invoice.monthly.batch';
        });

        // Ensure paid webhook was NEVER called
        Http::assertNotSent(function ($request) use ($data) {
            return $request->url() === $data['paidUrl'];
        });
    }

    /**
     * Test 4: Prevent duplicate sending for paid invoices.
     */
    public function test_duplicate_paid_sending_is_prevented(): void
    {
        $data = $this->setupTestData();
        $paid = $this->createPaidFamilyWithReceipt($data, 103, 'Grade 1', 12000, 'RCP-2026-103');

        // Record an existing submitted paid notification
        DB::table('notifications')->insert([
            'invoice_id' => $paid['invId'],
            'channel' => 'whatsapp',
            'kind' => 'paid',
            'webhook_type' => 'paid',
            'status' => 'submitted',
            'attempts' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $batchService = app(BatchDeliveryService::class);
        $snapshot = $batchService->resolveFilteredSelection([
            'period' => '2026-10',
            'webhook_type' => 'paid',
            'paid_only' => true,
        ], $data['superAdmin'], ['whatsapp']);

        $this->assertEquals(1, $snapshot['total_matched_invoices']);
        $this->assertEquals(0, $snapshot['total_eligible_recipients']);
        $this->assertEquals(1, $snapshot['already_submitted_recipients_count']);
    }

    /**
     * Ready-to-send paid invoices must have an actual payment receipt after October 8, 2026.
     * Historical receipts still exist in the ledger, but are not eligible for this delivery flow.
     */
    public function test_paid_ready_to_send_uses_actual_receipt_cutoff_for_list_counts_and_batch_selection(): void
    {
        $data = $this->setupTestData();
        $old = $this->createPaidFamilyWithReceipt($data, 201, 'Grade 1', 25000, 'RCP-CUTOFF-OLD', '2026-10-08');
        $new = $this->createPaidFamilyWithReceipt($data, 202, 'Grade 1', 25000, 'RCP-CUTOFF-NEW', '2026-10-09');

        $filters = [
            'period' => '2026-10',
            'paid_only' => true,
            'webhook_type' => 'paid',
            'paid_status' => 'unqueued',
        ];

        $queueService = app(\App\Services\InvoiceDeliveryQueueService::class);
        $paidPage = $queueService->getPaidInvoices($filters, $data['superAdmin']);
        $this->assertSame([$new['invId']], $paidPage->pluck('id')->all());

        $stats = $queueService->getPaidWorkflowStats('2026-10', $data['superAdmin']);
        $this->assertSame(1, $stats['unqueued']);
        $this->assertSame(1, $stats['total']);

        $batchService = app(BatchDeliveryService::class);
        $batchInvoiceIds = $batchService->buildFilterQuery($filters, $data['superAdmin'])->pluck('i.id')->all();
        $this->assertSame([$new['invId']], $batchInvoiceIds);
        $this->assertDatabaseHas('payments', ['id' => $old['paymentId'], 'received_on' => '2026-10-08']);
        $this->assertDatabaseHas('payments', ['id' => $new['paymentId'], 'received_on' => '2026-10-09']);
    }


    public function test_queued_paid_invoice_checkbox_sends_only_selected_invoice_from_shared_batch(): void
    {
        $data = $this->setupTestData();
        $first = $this->createPaidFamilyWithReceipt($data, 301, 'Grade 1', 25000, 'RCP-Q-301');
        $second = $this->createPaidFamilyWithReceipt($data, 302, 'Grade 1', 25000, 'RCP-Q-302');
        $batchId = 'BATCH-TEST-PAID-QUEUED';
        DB::table('notification_batches')->insert([
            'batch_id' => $batchId,
            'webhook_type' => 'paid',
            'event_type' => 'invoice.paid.batch',
            'billing_period' => '2026-10',
            'channels' => json_encode(['whatsapp']),
            'status' => 'queued',
            'total_recipients' => 2,
            'total_invoices' => 2,
            'approved_by' => $data['superAdmin']->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([$first['invId'], $second['invId']] as $invoiceId) {
            DB::table('notifications')->insert([
                'invoice_id' => $invoiceId,
                'batch_id' => $batchId,
                'kind' => 'paid',
                'webhook_type' => 'paid',
                'event_type' => 'invoice.paid',
                'channel' => 'whatsapp',
                'status' => 'queued',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $response = $this->actingAs($data['superAdmin'])
            ->get('/invoices?tab=paid&period=2026-10&paid_status=queued');
        $response->assertOk()->assertSee('form="paid-queued-send-form"', false)
            ->assertSee('Send Selected Queued Invoices');

        Queue::fake([DispatchBatchToN8n::class]);
        $this->actingAs($data['superAdmin'])->post('/invoices/paid/send-queue', [
            'period' => '2026-10', 'invoice_ids' => [$first['invId']],
        ])->assertSessionHasNoErrors();
        Queue::assertPushed(DispatchBatchToN8n::class, 1);
        $notificationId = DB::table('notifications')->where('invoice_id', $first['invId'])->value('id');
        Queue::assertPushed(DispatchBatchToN8n::class, fn ($job) =>
            $job->batchId === $batchId && $job->selectedNotificationIds === [$notificationId]
        );

        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([$data['paidUrl'] => Http::response(['ok' => true], 200)]);
        (new DispatchBatchToN8n($batchId, [$notificationId]))->handle();
        $this->assertDatabaseHas('notifications', ['invoice_id' => $first['invId'], 'status' => 'submitted']);
        $this->assertDatabaseHas('notifications', ['invoice_id' => $second['invId'], 'status' => 'queued']);
        $this->assertDatabaseHas('notification_batches', ['batch_id' => $batchId, 'status' => 'queued']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === $data['paidUrl'] && count($request['invoices']) === 1);
    }


    private function stagedPair(): array
    {
        $data = $this->setupTestData();
        $first = $this->createPaidFamilyWithReceipt($data, 801, receiptNumber: 'RCP-LIFE-801');
        $second = $this->createPaidFamilyWithReceipt($data, 802, receiptNumber: 'RCP-LIFE-802');
        $batch = 'BATCH-LIFECYCLE';
        DB::table('notification_batches')->insert([
            'batch_id' => $batch, 'webhook_type' => 'paid', 'event_type' => 'invoice.paid.batch',
            'billing_period' => '2026-10', 'channels' => '["whatsapp"]', 'status' => 'queued',
            'total_recipients' => 2, 'total_invoices' => 2, 'approved_by' => $data['superAdmin']->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $ids = [];
        foreach ([$first, $second] as $item) {
            $ids[] = DB::table('notifications')->insertGetId([
                'invoice_id' => $item['invId'], 'batch_id' => $batch, 'kind' => 'paid', 'webhook_type' => 'paid',
                'event_type' => 'invoice.paid', 'channel' => 'whatsapp', 'channels' => '["whatsapp"]',
                'status' => 'queued', 'event_id' => (string) \Illuminate\Support\Str::uuid(),
                'idempotency_key' => 'fictional-paid-'.$item['invId'], 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        Http::preventStrayRequests();
        return compact('data', 'first', 'second', 'batch', 'ids');
    }

    public function test_legacy_serialization_is_compatible_and_never_implies_paid_selection(): void
    {
        $f = $this->stagedPair();
        $serialized = 'O:27:"App\\Jobs\\DispatchBatchToN8n":1:{s:7:"batchId";s:15:"BATCH-LIFECYCLE";}';
        $job = unserialize($serialized);
        $this->assertNull($job->selectedNotificationIds);
        $job->handle();
        Http::assertNothingSent();
        $this->assertDatabaseHas('notification_batches', ['batch_id' => $f['batch'], 'status' => 'blocked']);
        $this->assertSame(2, DB::table('notifications')->whereIn('id', $f['ids'])->where('status', 'queued')->count());
        $selected = unserialize(serialize(new DispatchBatchToN8n($f['batch'], [$f['ids'][0]])));
        $this->assertSame([$f['ids'][0]], $selected->selectedNotificationIds);
    }

    public function test_competing_selection_is_released_then_only_its_own_invoice_is_sent(): void
    {
        $f = $this->stagedPair();
        $competitor = new DispatchBatchToN8n($f['batch'], [$f['ids'][1]]);
        $queueJob = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $queueJob->shouldReceive('release')->once()->with(30);
        $competitor->setJob($queueJob);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(function ($request) use ($f, $competitor) {
            $this->assertDatabaseHas('notifications', ['id' => $f['ids'][0], 'status' => 'processing']);
            $this->assertDatabaseHas('notifications', ['id' => $f['ids'][1], 'status' => 'queued']);
            $competitor->handle();
            return Http::response(['message_id' => 'fictional-acceptance'], 202);
        });
        (new DispatchBatchToN8n($f['batch'], [$f['ids'][0]]))->handle();
        $this->assertDatabaseHas('notification_batches', ['batch_id' => $f['batch'], 'status' => 'queued']);
        Http::assertSentCount(1);
        $this->assertDatabaseHas('notification_batches', ['batch_id' => $f['batch'], 'chunks_sent' => 1, 'chunks_total' => 2]);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([$f['data']['paidUrl'] => Http::response(['ok' => true], 200)]);
        (new DispatchBatchToN8n($f['batch'], [$f['ids'][1]]))->handle();
        $this->assertDatabaseHas('notification_batches', ['batch_id' => $f['batch'], 'status' => 'submitted']);
        Http::assertSent(fn ($r) => $r['invoices'][0]['notification_id'] === $f['ids'][1]);
    }

    public function test_pre_http_exception_is_recoverable_and_does_not_touch_unselected_invoice(): void
    {
        $f = $this->stagedPair();
        $service = \Mockery::mock(WebhookSettingService::class);
        $service->shouldReceive('getSettings')->once()->andThrow(new \RuntimeException('fictional lookup failure'));
        $this->app->instance(WebhookSettingService::class, $service);
        $job = new DispatchBatchToN8n($f['batch'], [$f['ids'][0]]);
        try { $job->handle(); $this->fail('Expected lookup exception.'); } catch (\RuntimeException $e) {}
        $this->assertDatabaseHas('notifications', ['id' => $f['ids'][0], 'status' => 'failed', 'attempts' => 0]);
        $this->assertDatabaseHas('notifications', ['id' => $f['ids'][1], 'status' => 'queued']);
        $this->assertNotSame('processing', DB::table('notification_batches')->where('batch_id', $f['batch'])->value('status'));
        Http::assertNothingSent();
        $this->app->forgetInstance(WebhookSettingService::class);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([$f['data']['paidUrl'] => Http::response(['ok' => true], 200)]);
        $job->handle();
        $this->assertDatabaseHas('notifications', ['id' => $f['ids'][0], 'status' => 'submitted', 'attempts' => 1]);
    }

    public function test_json_encoding_failure_has_no_http_attempt_and_releases_batch_lock(): void
    {
        $f = $this->stagedPair();
        $builder = \Mockery::mock(\App\Services\InvoicePayloadBuilder::class);
        $builder->shouldReceive('build')->once()->andReturn(['invalid' => "\xB1\x31"]);
        $this->app->instance(\App\Services\InvoicePayloadBuilder::class, $builder);
        try { (new DispatchBatchToN8n($f['batch'], [$f['ids'][0]]))->handle(); $this->fail('Expected JSON failure.'); }
        catch (\JsonException $e) {}
        $this->assertDatabaseHas('notifications', ['id' => $f['ids'][0], 'status' => 'failed', 'attempts' => 0]);
        $lock = new \App\Services\BatchDispatchLock();
        $this->assertTrue($lock->acquire($f['batch']));
        $lock->release();
        Http::assertNothingSent();
    }

    public function test_timeout_and_non_success_responses_require_reconciliation_before_replay(): void
    {
        $f = $this->stagedPair();
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('fictional timeout'));
        $job = new DispatchBatchToN8n($f['batch'], [$f['ids'][0]]);
        try { $job->handle(); $this->fail('Expected timeout.'); } catch (\Illuminate\Http\Client\ConnectionException $e) {}
        $this->assertDatabaseHas('notifications', ['id' => $f['ids'][0], 'status' => 'blocked', 'attempts' => 1]);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([$f['data']['paidUrl'] => Http::response(['error' => 'fictional failure'], 503)]);
        $job->handle();
        Http::assertNothingSent();
        try { (new DispatchBatchToN8n($f['batch'], [$f['ids'][1]]))->handle(); $this->fail('Expected HTTP failure.'); }
        catch (\RuntimeException $e) {}
        $this->assertDatabaseHas('notifications', ['id' => $f['ids'][1], 'status' => 'blocked', 'response_code' => 503, 'attempts' => 1]);
        $this->assertDatabaseHas('notification_batches', ['batch_id' => $f['batch'], 'status' => 'blocked']);
    }

    public function test_dead_worker_intent_and_terminal_failure_never_replay_uncertain_http(): void
    {
        $f = $this->stagedPair();
        DB::table('notification_batches')->where('batch_id', $f['batch'])->update(['status' => 'processing']);
        DB::table('notifications')->where('id', $f['ids'][0])->update(['status' => 'processing', 'attempts' => 1]);
        $job = new DispatchBatchToN8n($f['batch'], [$f['ids'][0]]);
        $job->failed(new \RuntimeException('fictional killed worker'));
        $this->assertDatabaseHas('notifications', ['id' => $f['ids'][0], 'status' => 'blocked']);
        $this->assertDatabaseHas('notifications', ['id' => $f['ids'][1], 'status' => 'queued']);
        $job->handle();
        Http::assertNothingSent();
        $this->assertNotSame('processing', DB::table('notification_batches')->where('batch_id', $f['batch'])->value('status'));
    }

    public function test_success_is_idempotent_preserves_hmac_contract_and_financial_records(): void
    {
        $f = $this->stagedPair();
        $before = [];
        foreach (['payments', 'payment_allocations', 'invoices', 'invoice_items'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([$f['data']['paidUrl'] => Http::response(['message_id' => 'fictional-id'], 202)]);
        $job = new DispatchBatchToN8n($f['batch'], [$f['ids'][0]]);
        $job->handle(); $job->handle(); $job->failed(new \RuntimeException('late terminal hook'));
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['event'] === 'invoice.paid.batch'
            && count($r['invoices']) === 1 && $r['invoices'][0]['idempotency_key'] === 'fictional-paid-'.$f['first']['invId']
            && $r->header('X-IQRA-Signature')[0] === hash_hmac('sha256', $r->body(), $f['data']['secret']));
        $this->assertDatabaseHas('notifications', ['id' => $f['ids'][0], 'status' => 'submitted', 'attempts' => 1]);
        foreach ($before as $table => $value) $this->assertSame($value, DB::table($table)->orderBy('id')->get()->toJson());
    }

    public function test_worker_rechecks_october_cutoff_reversal_and_positive_allocations(): void
    {
        $f = $this->stagedPair();
        DB::table('payments')->where('id', $f['first']['paymentId'])->update(['received_on' => '2026-10-08']);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([$f['data']['paidUrl'] => Http::response(['ok' => true], 200)]);
        (new DispatchBatchToN8n($f['batch'], [$f['ids'][0]]))->handle();
        $this->assertDatabaseHas('notifications', ['id' => $f['ids'][0], 'status' => 'blocked', 'attempts' => 0]);
        DB::table('payments')->where('id', $f['second']['paymentId'])->update(['received_on' => '2026-10-09']);
        (new DispatchBatchToN8n($f['batch'], [$f['ids'][1]]))->handle();
        $this->assertDatabaseHas('notifications', ['id' => $f['ids'][1], 'status' => 'submitted']);
        DB::table('payments')->where('id', $f['second']['paymentId'])->update(['status' => 'reversed', 'reversed_at' => now()]);
        $this->assertFalse(\App\Services\PaidInvoiceEligibility::eligible($f['second']['invId']));
        DB::table('payments')->where('id', $f['second']['paymentId'])->update(['status' => 'received', 'reversed_at' => null]);
        DB::table('payment_allocations')->where('payment_id', $f['second']['paymentId'])->update(['amount_minor' => 0]);
        $this->assertFalse(\App\Services\PaidInvoiceEligibility::eligible($f['second']['invId']));
        DB::table('payment_allocations')->where('payment_id', $f['second']['paymentId'])->update(['amount_minor' => 25000]);
        DB::table('invoices')->where('id', $f['second']['invId'])->update(['paid_minor' => 1]);
        $this->assertFalse(\App\Services\PaidInvoiceEligibility::eligible($f['second']['invId']));
        Http::assertSentCount(1);
    }

    public function test_dispatch_all_is_rejected_and_retry_revalidates_eligibility(): void
    {
        $f = $this->stagedPair(); Queue::fake([DispatchBatchToN8n::class]);
        $service = app(BatchDeliveryService::class);
        try { $service->sendQueuedPaidInvoices($f['data']['superAdmin'], '2026-10'); $this->fail('Explicit selection required.'); }
        catch (\RuntimeException $e) {}
        DB::table('notifications')->whereIn('id', $f['ids'])->update(['status' => 'failed']);
        DB::table('payments')->where('id', $f['first']['paymentId'])->update(['received_on' => '2026-10-08']);
        $service->retryFailedBatchRecipients($f['batch'], $f['data']['superAdmin']);
        Queue::assertPushed(DispatchBatchToN8n::class, fn ($j) => $j->selectedNotificationIds === [$f['ids'][1]]);
        $this->assertDatabaseHas('notifications', ['id' => $f['ids'][0], 'status' => 'blocked']);
    }

    public function test_paid_queue_does_not_inherit_another_members_processing_or_failure(): void
    {
        $f = $this->stagedPair(); Queue::fake([DispatchBatchToN8n::class]);
        $response = $this->actingAs($f['data']['superAdmin'])->post('/invoices/paid/send-queue', [
            'period' => '2026-10', 'invoice_ids' => [$f['first']['invId']],
        ]);
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('queued', $query['paid_status']);
        $this->assertSame((string)$f['first']['invId'], $query['paid_tracking']);
        DB::table('notification_batches')->where('batch_id', $f['batch'])->update(['status' => 'processing', 'updated_at' => now()]);
        $response = $this->get('/invoices?tab=paid&period=2026-10&paid_status=queued')->assertOk();
        $this->assertSame(2, $response->viewData('paidInvoicesPage')->total());
        $this->assertSame('queued', $response->viewData('paidInvoicesPage')->first()->delivery_status);
        DB::table('notifications')->where('id', $f['ids'][0])->update(['status' => 'blocked', 'error' => 'fictional reconciliation']);
        $response = $this->get('/invoices?tab=paid&period=2026-10&paid_status=attention')->assertOk()->assertSee('Blocked');
        $this->assertSame(1, $response->viewData('paidInvoicesPage')->total());
        $this->assertSame(1, $response->viewData('paidWorkflowStats')['queued']);
        $this->assertSame(1, $response->viewData('paidWorkflowStats')['attention']);
    }

    public function test_terminal_failure_before_http_and_contention_during_failure_are_safe(): void
    {
        $f = $this->stagedPair();
        DB::table('notification_batches')->where('batch_id', $f['batch'])->update(['status' => 'processing']);
        $job = new DispatchBatchToN8n($f['batch'], [$f['ids'][0]]);
        $lock = new \App\Services\BatchDispatchLock();
        $this->assertTrue($lock->acquire($f['batch']));
        $job->failed(new \RuntimeException('fictional exhausted worker'));
        $this->assertDatabaseHas('notifications', ['id' => $f['ids'][0], 'status' => 'queued']);
        $lock->release();
        $job->failed(new \RuntimeException('fictional exhausted worker'));
        $this->assertDatabaseHas('notifications', ['id' => $f['ids'][0], 'status' => 'failed', 'attempts' => 0]);
        $this->assertDatabaseHas('notifications', ['id' => $f['ids'][1], 'status' => 'queued']);
        $this->assertNotSame('processing', DB::table('notification_batches')->where('batch_id', $f['batch'])->value('status'));
        Http::assertNothingSent();
    }

    public function test_retry_after_worker_crash_blocks_old_intent_but_sends_only_fresh_selection(): void
    {
        $f = $this->stagedPair();
        DB::table('notification_batches')->where('batch_id', $f['batch'])->update(['status' => 'processing']);
        DB::table('notifications')->where('id', $f['ids'][0])->update(['status' => 'processing', 'attempts' => 1]);
        Http::fake([$f['data']['paidUrl'] => Http::response(['ok' => true], 200)]);
        (new DispatchBatchToN8n($f['batch'], [$f['ids'][1]]))->handle();
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['invoices'][0]['notification_id'] === $f['ids'][1]);
        $this->assertDatabaseHas('notifications', ['id' => $f['ids'][0], 'status' => 'blocked', 'attempts' => 1]);
        $this->assertDatabaseHas('notifications', ['id' => $f['ids'][1], 'status' => 'submitted']);
        $this->assertDatabaseHas('notification_batches', ['batch_id' => $f['batch'], 'status' => 'blocked']);
    }

    public function test_queue_reservation_and_exception_budget_cover_contention_and_timeout(): void
    {
        $job = new DispatchBatchToN8n('fictional', [1]);
        $this->assertGreaterThan($job->timeout, config('queue.connections.database.retry_after'));
        $this->assertTrue($job->failOnTimeout);
        $this->assertSame(3, $job->maxExceptions);
        $this->assertGreaterThan(now()->addMinutes(119)->timestamp, $job->retryUntil()->getTimestamp());
    }

    public function test_staging_dispatches_nothing_and_only_explicit_selected_job_is_enqueued(): void
    {
        $data = $this->setupTestData();
        $first = $this->createPaidFamilyWithReceipt($data, 901, receiptNumber: 'RCP-STAGE-901');
        $second = $this->createPaidFamilyWithReceipt($data, 902, receiptNumber: 'RCP-STAGE-902');
        Queue::fake([DispatchBatchToN8n::class]);
        $this->actingAs($data['superAdmin'])->get('/invoices?tab=paid&period=2026-10&paid_status=unqueued')
            ->assertOk()->assertViewHas('paidWorkflowStats', fn ($stats) => $stats['unqueued'] === 2 && $stats['queued'] === 0)
            ->assertSee('data-paid-tab="processing"', false)->assertSee('data-paid-tab="attention"', false);
        $this->post('/invoices/send-batch', [
            'period' => '2026-10', 'webhook_type' => 'paid', 'paid_only' => 1,
            'action_type' => 'add_to_queue', 'selection_mode' => 'page',
            'invoice_ids' => [$first['invId'], $second['invId']], 'channels' => ['whatsapp'], 'review_confirmed' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect('/invoices?tab=paid&period=2026-10&paid_status=queued');
        Queue::assertNothingPushed();
        $this->get('/invoices?tab=paid&period=2026-10&paid_status=queued')->assertOk()
            ->assertViewHas('paidWorkflowStats', fn ($stats) => $stats['unqueued'] === 0 && $stats['queued'] === 2)
            ->assertViewHas('paidInvoicesPage', fn ($page) => $page->total() === 2);
        $notificationId = DB::table('notifications')->where('invoice_id', $first['invId'])->value('id');
        $this->post('/invoices/paid/send-queue', ['period' => '2026-10', 'invoice_ids' => [$first['invId']]])->assertSessionHasNoErrors();
        Queue::assertPushed(DispatchBatchToN8n::class, 1);
        Queue::assertPushed(DispatchBatchToN8n::class, fn ($job) => $job->selectedNotificationIds === [$notificationId]);
    }

    public function test_explicit_reselection_can_recover_a_zero_attempt_block_without_resetting_other_invoices(): void
    {
        $f = $this->stagedPair(); Queue::fake([DispatchBatchToN8n::class]);
        DB::table('notification_batches')->where('batch_id', $f['batch'])->update(['status' => 'blocked']);
        DB::table('notifications')->where('id', $f['ids'][0])->update(['status' => 'blocked']);
        $this->actingAs($f['data']['superAdmin'])->post('/invoices/paid/send-queue', [
            'period' => '2026-10', 'invoice_ids' => [$f['first']['invId']],
        ])->assertSessionHasNoErrors();
        Http::fake([$f['data']['paidUrl'] => Http::response(['ok' => true], 200)]);
        (new DispatchBatchToN8n($f['batch'], [$f['ids'][0]]))->handle();
        Http::assertSentCount(1);
        $this->assertDatabaseHas('notifications', ['id' => $f['ids'][0], 'status' => 'submitted']);
        $this->assertDatabaseHas('notifications', ['id' => $f['ids'][1], 'status' => 'queued', 'attempts' => 0]);
    }

    private function markPortalProvenance(array $data, array $invoice, bool $portalPayment = true): void
    {
        $feeType = DB::table('fee_types')->where('code', 'monthly')->value('id');
        $plan = DB::table('fee_plan_items')->insertGetId([
            'enrollment_id' => $invoice['enrId'], 'fee_type_id' => $feeType,
            'description' => 'Fictional portal plan', 'frequency' => 'monthly', 'currency' => 'SAR', 'amount_minor' => 25000,
        ]);
        DB::table('invoice_items')->where('id', $invoice['itemId'])->update(['fee_plan_item_id' => $plan, 'fee_type_id' => $feeType]);
        if ($portalPayment) {
            DB::table('audit_logs')->insert([
                'actor_id' => $data['superAdmin']->id, 'action' => 'payment_received', 'subject_type' => 'payment',
                'subject_id' => $invoice['paymentId'], 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_portal_provenance_exempts_old_receipt_without_exempting_imported_invoices(): void
    {
        $data = $this->setupTestData();
        $portal = $this->createPaidFamilyWithReceipt($data, 1001, receiptNumber: 'FICTIONAL-PORTAL', receivedOn: '2026-10-08');
        $import = $this->createPaidFamilyWithReceipt($data, 1002, receiptNumber: 'FICTIONAL-IMPORT', receivedOn: '2026-10-08');
        $this->markPortalProvenance($data, $portal);
        $filters = ['period' => '2026-10', 'paid_only' => true, 'webhook_type' => 'paid', 'paid_status' => 'unqueued'];
        $service = app(\App\Services\InvoiceDeliveryQueueService::class);
        $this->assertSame([$portal['invId']], $service->getPaidInvoices($filters, $data['superAdmin'])->pluck('id')->all());
        $this->assertSame(1, $service->getPaidWorkflowStats('2026-10', $data['superAdmin'])['total']);
        $this->assertSame([$portal['invId']], app(BatchDeliveryService::class)->buildFilterQuery($filters, $data['superAdmin'])->pluck('i.id')->all());
        $this->assertTrue(\App\Services\PaidInvoiceEligibility::portalPaid($portal['invId']));
        $this->assertFalse(\App\Services\PaidInvoiceEligibility::eligible($import['invId']));
        DB::table('payments')->where('id', $import['paymentId'])->update(['received_on' => '2026-10-09']);
        $this->assertTrue(\App\Services\PaidInvoiceEligibility::eligible($import['invId']));
        $this->assertFalse(\App\Services\PaidInvoiceEligibility::portalPaid($import['invId']));
        DB::table('payments')->where('id', $portal['paymentId'])->update(['status' => 'reversed', 'reversed_at' => now()]);
        $this->assertFalse(\App\Services\PaidInvoiceEligibility::eligible($portal['invId']));
    }

    public function test_fee_plan_link_without_audited_portal_payment_does_not_remove_cutoff(): void
    {
        $data = $this->setupTestData();
        $invoice = $this->createPaidFamilyWithReceipt($data, 1003, receiptNumber: 'FICTIONAL-NO-AUDIT', receivedOn: '2026-10-08');
        $this->markPortalProvenance($data, $invoice, false);
        $this->assertFalse(\App\Services\PaidInvoiceEligibility::eligible($invoice['invId']));
        DB::table('payments')->where('id', $invoice['paymentId'])->update(['received_on' => '2026-10-09']);
        $this->assertTrue(\App\Services\PaidInvoiceEligibility::eligible($invoice['invId']));
        $this->assertFalse(\App\Services\PaidInvoiceEligibility::portalPaid($invoice['invId']));
    }

    public function test_mixed_invoice_provenance_does_not_bypass_historical_cutoff(): void
    {
        $data = $this->setupTestData();
        $invoice = $this->createPaidFamilyWithReceipt($data, 1004, receiptNumber: 'FICTIONAL-MIXED', receivedOn: '2026-10-08');
        $this->markPortalProvenance($data, $invoice);
        DB::table('invoice_items')->insert([
            'invoice_id' => $invoice['invId'], 'student_id' => $invoice['stuId'], 'description' => 'Unproven imported charge',
            'period_key' => 'fictional-import', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'currency' => 'SAR', 'amount_minor' => 1, 'net_minor' => 1, 'paid_minor' => 1,
        ]);
        $this->assertFalse(\App\Services\PaidInvoiceEligibility::portalPaid($invoice['invId']));
        $this->assertFalse(\App\Services\PaidInvoiceEligibility::eligible($invoice['invId']));
    }

    public function test_selected_portal_invoice_uses_paid_future_and_submits_old_receipt_once(): void
    {
        $f = $this->stagedPair();
        $this->markPortalProvenance($f['data'], $f['first']);
        DB::table('payments')->where('id', $f['first']['paymentId'])->update(['received_on' => '2026-10-01']);
        Queue::fake([DispatchBatchToN8n::class]);
        $this->actingAs($f['data']['superAdmin'])->post('/invoices/paid/send-queue', [
            'period' => '2026-10', 'invoice_ids' => [$f['first']['invId']],
        ])->assertSessionHasNoErrors();
        Queue::assertPushed(DispatchBatchToN8n::class, 1);
        Queue::assertPushed(DispatchBatchToN8n::class, fn ($j) => $j->queue === 'paid-future' && $j->selectedNotificationIds === [$f['ids'][0]]);
        Http::fake([$f['data']['paidUrl'] => Http::response(['message' => 'Workflow was started'], 200)]);
        $job = (new DispatchBatchToN8n($f['batch'], [$f['ids'][0]]))->onQueue('paid-future');
        $job->handle(); $job->handle();
        Http::assertSentCount(1);
        $this->assertDatabaseHas('notifications', ['id' => $f['ids'][0], 'status' => 'submitted', 'attempts' => 1, 'response_code' => 200]);
        $this->assertDatabaseHas('notifications', ['id' => $f['ids'][1], 'status' => 'queued', 'attempts' => 0]);
    }

    public function test_mixed_paid_selection_uses_one_automatic_job_without_early_submission(): void
    {
        $f = $this->stagedPair(); $this->markPortalProvenance($f['data'], $f['first']);
        Queue::fake([DispatchBatchToN8n::class]);
        app(BatchDeliveryService::class)->sendQueuedPaidInvoices($f['data']['superAdmin'], '2026-10', [$f['first']['invId'], $f['second']['invId']]);
        Queue::assertPushed(DispatchBatchToN8n::class, 1);
        Queue::assertPushed(DispatchBatchToN8n::class, fn ($j) => $j->queue === 'paid-future' && $j->selectedNotificationIds === $f['ids']);
        $this->assertSame(2, DB::table('notifications')->whereIn('id', $f['ids'])->where('status', 'queued')->count());
        $this->assertSame(0, DB::table('notifications')->whereIn('id', $f['ids'])->where('status', 'submitted')->count());
        Http::assertNothingSent();
    }

    public function test_paid_future_dispatches_cutoff_eligible_imported_invoice_and_respects_disable_switch(): void
    {
        $f = $this->stagedPair();
        $this->assertFalse(\App\Services\PaidInvoiceEligibility::portalPaid($f['second']['invId']));
        Http::fake([$f['data']['paidUrl'] => Http::response(['ok' => true], 200)]);
        (new DispatchBatchToN8n($f['batch'], [$f['ids'][1]]))->onQueue('paid-future')->handle();
        Http::assertSentCount(1);
        $this->assertDatabaseHas('notifications', ['id' => $f['ids'][1], 'status' => 'submitted', 'attempts' => 1, 'response_code' => 200]);
        $this->markPortalProvenance($f['data'], $f['first']);
        config(['services.n8n.paid_automatic_dispatch' => false]);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([$f['data']['paidUrl'] => Http::response(['ok' => true], 200)]);
        $queueJob = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $queueJob->shouldReceive('release')->once()->with(60);
        $job = (new DispatchBatchToN8n($f['batch'], [$f['ids'][0]]))->onQueue('paid-future');
        $job->setJob($queueJob); $job->handle();
        $this->assertDatabaseHas('notifications', ['id' => $f['ids'][0], 'status' => 'queued', 'attempts' => 0]);
        Http::assertNothingSent();
    }

    public function test_paid_future_still_rejects_historical_receipt_before_cutoff(): void
    {
        $f = $this->stagedPair();
        DB::table('payments')->where('id', $f['second']['paymentId'])->update(['received_on' => '2026-10-08']);
        $this->assertFalse(\App\Services\PaidInvoiceEligibility::eligible($f['second']['invId']));
        Http::fake([$f['data']['paidUrl'] => Http::response(['ok' => true], 200)]);
        (new DispatchBatchToN8n($f['batch'], [$f['ids'][1]]))->onQueue('paid-future')->handle();
        Http::assertNothingSent();
        $this->assertDatabaseHas('notifications', ['id' => $f['ids'][1], 'status' => 'blocked', 'attempts' => 0]);
    }

    public function test_opening_balance_run_never_gets_portal_cutoff_exemption(): void
    {
        $data = $this->setupTestData();
        $invoice = $this->createPaidFamilyWithReceipt($data, 1005, receiptNumber: 'FICTIONAL-OPENING', receivedOn: '2026-10-08');
        $this->markPortalProvenance($data, $invoice);
        DB::table('billing_runs')->where('id', $data['run'])->update(['frequency' => 'opening_balance']);
        $this->assertFalse(\App\Services\PaidInvoiceEligibility::portalPaid($invoice['invId']));
        $this->assertFalse(\App\Services\PaidInvoiceEligibility::eligible($invoice['invId']));
    }

    public function test_import_lineage_overrides_fee_plan_and_payment_audit_provenance(): void
    {
        $data = $this->setupTestData();
        $invoice = $this->createPaidFamilyWithReceipt($data, 1006, receiptNumber: 'FICTIONAL-LINEAGE', receivedOn: '2026-10-08');
        $this->markPortalProvenance($data, $invoice);
        DB::table('qa_opening_balance_lineage')->insert([
            'batch_id' => 'FICTIONAL-IMPORT', 'qa_staged_due_id' => 999001, 'source_row' => 1, 'gr_number' => 'GR-P-1006',
            'invoice_id' => $invoice['invId'], 'invoice_item_id' => $invoice['itemId'], 'family_id' => $invoice['famId'],
            'student_id' => $invoice['stuId'], 'enrollment_id' => $invoice['enrId'], 'currency' => 'SAR',
            'amount_minor' => 25000, 'period_key' => '2026-10',
        ]);
        $this->assertFalse(\App\Services\PaidInvoiceEligibility::portalPaid($invoice['invId']));
        $this->assertFalse(\App\Services\PaidInvoiceEligibility::eligible($invoice['invId']));
        DB::table('payments')->where('id', $invoice['paymentId'])->update(['received_on' => '2026-10-09']);
        $this->assertTrue(\App\Services\PaidInvoiceEligibility::eligible($invoice['invId']));
        $this->assertFalse(\App\Services\PaidInvoiceEligibility::portalPaid($invoice['invId']));
    }

    private function reservePaidSelection(string $batch, array $ids): int
    {
        $job = (new DispatchBatchToN8n($batch, $ids))->onQueue('paid-future');
        return DB::table('jobs')->insertGetId([
            'queue' => 'paid-future', 'attempts' => 1, 'reserved_at' => now()->timestamp,
            'available_at' => now()->timestamp, 'created_at' => now()->timestamp,
            'payload' => json_encode(['displayName' => DispatchBatchToN8n::class, 'data' => ['command' => serialize($job)]]),
        ]);
    }

    public function test_paid_dashboard_follows_only_the_selected_invoice_through_acceptance(): void
    {
        $f = $this->stagedPair(); $this->markPortalProvenance($f['data'], $f['first']);
        Queue::fake([DispatchBatchToN8n::class]);
        $this->reservePaidSelection($f['batch'], [$f['ids'][0]]);
        DB::table('notification_batches')->where('batch_id', $f['batch'])->update(['status' => 'processing', 'updated_at' => now()]);
        $response = $this->actingAs($f['data']['superAdmin'])->post('/invoices/paid/send-queue', [
            'period' => '2026-10', 'invoice_ids' => [$f['first']['invId']], 'search' => 'FAM-PAID-801', 'per_page' => 50,
        ])->assertSessionHasNoErrors();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('processing', $query['paid_status']);
        $this->assertSame('FAM-PAID-801', $query['search']);
        $this->assertSame('50', $query['per_page']);
        $this->assertSame((string)$f['first']['invId'], $query['paid_tracking']);
        Queue::assertPushed(DispatchBatchToN8n::class, fn ($j) => $j->queue === 'paid-future' && $j->selectedNotificationIds === [$f['ids'][0]]);
        $service = app(\App\Services\InvoiceDeliveryQueueService::class);
        $filters = ['period' => '2026-10'];
        $this->assertSame(['unqueued' => 0, 'queued' => 1, 'processing' => 1, 'submitted' => 0, 'attention' => 0, 'total' => 2],
            $service->getPaidWorkflowStats('2026-10', $f['data']['superAdmin']));
        $this->get('/invoices?tab=paid&period=2026-10&paid_status=processing')->assertOk()->assertSee('data-paid-state="processing"', false);
        Http::fake(function () use ($service, $f) {
            $stats = $service->getPaidWorkflowStats('2026-10', $f['data']['superAdmin']);
            $this->assertSame(1, $stats['processing']);
            $this->assertSame(0, $stats['submitted']);
            $this->assertSame(1, $stats['queued']);
            return Http::response(['message' => 'Workflow was started'], 200);
        });
        (new DispatchBatchToN8n($f['batch'], [$f['ids'][0]]))->onQueue('paid-future')->handle();
        $this->get('/invoices?tab=paid&period=2026-10&paid_status=queued&paid_refresh=1&paid_tracking='.$f['first']['invId'])
            ->assertOk()->assertJsonPath('counts.submitted', 1)->assertJsonPath('counts.queued', 1)->assertJsonPath('tracking_stage', 'submitted');
        $response = $this->get('/invoices?tab=paid&period=2026-10&paid_status=submitted')->assertOk()->assertSee('data-paid-state="submitted"', false);
        $this->assertSame([$f['first']['invId']], $response->viewData('paidInvoicesPage')->pluck('id')->all());
        $this->assertDatabaseHas('notifications', ['id' => $f['ids'][1], 'status' => 'queued', 'attempts' => 0]);
        Http::assertSentCount(1);
    }

    public function test_failed_paid_request_appears_in_attention_without_hiding_unselected_queue(): void
    {
        $f = $this->stagedPair(); $this->actingAs($f['data']['superAdmin']);
        Http::fake([$f['data']['paidUrl'] => Http::response(['error' => 'fictional failure'], 503)]);
        try { (new DispatchBatchToN8n($f['batch'], [$f['ids'][0]]))->handle(); $this->fail('Expected failed request.'); }
        catch (\RuntimeException $e) {}
        $response = $this->get('/invoices?tab=paid&period=2026-10&paid_status=attention')->assertOk()
            ->assertSee('data-paid-state="attention"', false)->assertSee('Blocked');
        $this->assertSame([$f['first']['invId']], $response->viewData('paidInvoicesPage')->pluck('id')->all());
        $this->assertSame(1, $response->viewData('paidWorkflowStats')['attention']);
        $this->assertSame(1, $response->viewData('paidWorkflowStats')['queued']);
        $this->assertSame(0, $response->viewData('paidWorkflowStats')['submitted']);
        $queued = $this->get('/invoices?tab=paid&period=2026-10&paid_status=queued')->assertOk();
        $this->assertSame([$f['second']['invId']], $queued->viewData('paidInvoicesPage')->pluck('id')->all());
    }

    public function test_historical_stalled_batches_are_attention_without_modifying_records(): void
    {
        $f = $this->stagedPair(); $this->actingAs($f['data']['superAdmin']);
        DB::table('notification_batches')->where('batch_id', $f['batch'])->update(['status' => 'processing', 'updated_at' => now()->subMinutes(20)]);
        $before = DB::table('notifications')->orderBy('id')->get()->toJson();
        $batchBefore = DB::table('notification_batches')->where('batch_id', $f['batch'])->first();
        $response = $this->get('/invoices?tab=paid&period=2026-10&paid_status=attention')->assertOk()->assertSee('Stalled');
        $this->assertSame(2, $response->viewData('paidInvoicesPage')->total());
        $this->assertSame(2, $response->viewData('paidWorkflowStats')['attention']);
        $this->assertSame(0, $response->viewData('paidWorkflowStats')['processing']);
        $this->assertSame(0, $response->viewData('paidWorkflowStats')['queued']);
        $this->get('/invoices?tab=paid&period=2026-10&paid_status=processing')->assertViewHas('paidInvoicesPage', fn ($page) => $page->total() === 0);
        $this->assertSame($before, DB::table('notifications')->orderBy('id')->get()->toJson());
        $this->assertEquals($batchBefore, DB::table('notification_batches')->where('batch_id', $f['batch'])->first());
    }

    public function test_old_inflight_notification_and_terminal_batch_do_not_imply_submission(): void
    {
        $f = $this->stagedPair(); $this->actingAs($f['data']['superAdmin']);
        DB::table('notifications')->where('id', $f['ids'][0])->update(['status' => 'processing', 'last_attempt_at' => now()->subMinutes(8)]);
        DB::table('notification_batches')->where('batch_id', $f['batch'])->update(['status' => 'submitted', 'submitted_at' => now()]);
        $response = $this->get('/invoices?tab=paid&period=2026-10&paid_status=attention')->assertOk();
        $this->assertSame(2, $response->viewData('paidInvoicesPage')->total());
        $this->assertSame(0, $response->viewData('paidWorkflowStats')['submitted']);
        $this->assertSame(2, $response->viewData('paidWorkflowStats')['attention']);
    }

    public function test_authoritative_paid_record_keeps_an_accepted_invoice_out_of_new_queue(): void
    {
        $f = $this->stagedPair(); $this->actingAs($f['data']['superAdmin']);
        DB::table('notifications')->where('id', $f['ids'][0])->update(['status' => 'delivered', 'is_superseded' => true, 'response_code' => 200, 'submitted_at' => now()]);
        DB::table('notifications')->insert([
            'invoice_id' => $f['first']['invId'], 'batch_id' => $f['batch'], 'kind' => 'paid', 'channel' => 'email',
            'status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $response = $this->get('/invoices?tab=paid&period=2026-10&paid_status=all')->assertOk()->assertSee('Submitted (Delivered)');
        $stats = $response->viewData('paidWorkflowStats');
        $this->assertSame(2, $stats['total']); $this->assertSame(1, $stats['submitted']); $this->assertSame(1, $stats['queued']);
        $row = $response->viewData('paidInvoicesPage')->firstWhere('id', $f['first']['invId']);
        $this->assertSame($f['ids'][0], $row->notification->id);
        $this->assertSame('submitted', $row->paid_workflow_stage);
        $this->get('/invoices?tab=paid&period=2026-10&paid_status=queued')
            ->assertViewHas('paidInvoicesPage', fn ($page) => $page->pluck('id')->all() === [$f['second']['invId']]);
    }

    public function test_paid_counts_share_search_grade_contact_and_period_filters_with_table(): void
    {
        $f = $this->stagedPair(); $this->actingAs($f['data']['superAdmin']);
        $response = $this->get('/invoices?tab=paid&period=2026-10&paid_status=queued&search=FAM-PAID-801&grade=Grade%201&contact_status=eligible_only&per_page=50')
            ->assertOk();
        $this->assertSame(1, $response->viewData('paidWorkflowStats')['total']);
        $this->assertSame(1, $response->viewData('paidWorkflowStats')['queued']);
        $this->assertSame(1, $response->viewData('paidInvoicesPage')->total());
        $this->assertSame(50, $response->viewData('paidInvoicesPage')->perPage());
        $response->assertSee('search=FAM-PAID-801')->assertSee('per_page=50');
        $refresh = $this->get('/invoices?tab=paid&period=2026-10&paid_status=queued&search=FAM-PAID-801&grade=Grade%201&contact_status=eligible_only&per_page=50&paid_refresh=1')
            ->assertOk()->assertJsonPath('counts.total', 1);
        $this->assertSame($response->viewData('paidRefreshSignature'), $refresh->json('signature'));
        $this->get('/invoices?tab=paid&period=2026-11&paid_status=all')->assertViewHas('paidWorkflowStats', fn ($s) => $s['total'] === 0);
    }

    public function test_older_invoice_staged_in_october_remains_visible_after_acceptance(): void
    {
        $f = $this->stagedPair(); $this->actingAs($f['data']['superAdmin']);
        $run = DB::table('billing_runs')->insertGetId([
            'campus_id' => $f['data']['campus'], 'department_id' => $f['data']['dept'], 'frequency' => 'monthly',
            'period_key' => 'monthly:2026-09-01..2026-09-30', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
            'due_date' => '2026-09-25', 'created_by' => $f['data']['superAdmin']->id,
        ]);
        DB::table('invoices')->where('id', $f['first']['invId'])->update(['billing_run_id' => $run]);
        $service = app(\App\Services\InvoiceDeliveryQueueService::class);
        $this->assertSame(2, $service->getPaidWorkflowStats('2026-10', $f['data']['superAdmin'])['total']);
        DB::table('notifications')->where('id', $f['ids'][0])->update(['status' => 'submitted', 'response_code' => 200, 'submitted_at' => now()]);
        $this->assertSame(2, $service->getPaidWorkflowStats('2026-10', $f['data']['superAdmin'])['total']);
        $this->get('/invoices?tab=paid&period=2026-10&paid_status=submitted')
            ->assertViewHas('paidInvoicesPage', fn ($p) => $p->pluck('id')->all() === [$f['first']['invId']]);
        DB::table('notification_batches')->where('batch_id', $f['batch'])->update(['billing_period' => '2026-09']);
        $this->assertSame(1, $service->getPaidWorkflowStats('2026-10', $f['data']['superAdmin'])['total']);
        $this->assertSame(1, $service->getPaidWorkflowStats('2026-09', $f['data']['superAdmin'])['total']);
    }

    public function test_send_redirect_preserves_context_and_follows_actual_execution_only(): void
    {
        $f = $this->stagedPair(); Queue::fake([DispatchBatchToN8n::class]); $this->actingAs($f['data']['superAdmin']);
        $form = ['period' => '2026-10', 'invoice_ids' => [$f['first']['invId']], 'search' => 'FAM-PAID-801', 'per_page' => 50, 'page' => 2, 'grade' => 'Grade 1'];
        $response = $this->post('/invoices/paid/send-queue', $form)->assertSessionHasNoErrors();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('queued', $query['paid_status']); $this->assertSame('2', $query['page']);
        $this->assertSame('50', $query['per_page']); $this->assertSame('FAM-PAID-801', $query['search']);
        $this->reservePaidSelection($f['batch'], [$f['ids'][0]]);
        $response = $this->post('/invoices/paid/send-queue', $form)->assertSessionHasNoErrors();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('processing', $query['paid_status']);
    }

    public function test_paid_refresh_is_read_only_scoped_and_upper_shared_workflow_remains(): void
    {
        $f = $this->stagedPair(); $this->actingAs($f['data']['superAdmin']);
        $before = DB::table('notifications')->orderBy('id')->get()->toJson();
        $this->get('/invoices?tab=paid&period=2026-10&paid_status=queued&paid_refresh=1&paid_tracking='.$f['first']['invId'])
            ->assertOk()->assertJsonPath('tracking_stage', 'queued')->assertJsonPath('counts.queued', 2);
        $this->assertSame($before, DB::table('notifications')->orderBy('id')->get()->toJson());
        $this->get('/invoices?tab=processing&period=2026-10')->assertOk()->assertSee('Itemized Notification Queue');
        $this->get('/invoices?tab=sent&period=2026-10')->assertOk()->assertDontSee('id="paid-invoice-workflow"', false);
        $scopedUser = User::create(['name' => 'Fictional unscoped cashier', 'email' => 'unscoped-dashboard@example.test', 'password' => 'fictional', 'role' => 'cashier', 'is_active' => true]);
        $this->actingAs($scopedUser)->get('/invoices?tab=paid&period=2026-10&paid_status=all&paid_refresh=1&paid_tracking='.$f['first']['invId'])
            ->assertOk()->assertJsonPath('counts.total', 0)->assertJsonPath('tracking_stage', null);
        Http::assertNothingSent();
    }

    public function test_paid_refresh_javascript_pauses_for_selection_and_preserves_navigation_context(): void
    {
        $f = $this->stagedPair(); $this->actingAs($f['data']['superAdmin']);
        $response = $this->get('/invoices?tab=paid&period=2026-10&paid_status=queued&search=FAM-PAID-801&per_page=50&page=2');
        $response->assertOk();
        preg_match_all('/<script>(.*?)<\/script>/s', $response->getContent(), $scripts);
        $polling = collect($scripts[1])->first(fn ($script) => str_contains($script, 'const initialSignature'));
        $this->assertNotNull($polling);
        $harness = <<<'JS'
const fs = require('fs'), vm = require('vm'), assert = require('assert');
const code = JSON.parse(fs.readFileSync(0, 'utf8')).code;
async function scenario(options = {}) {
    const state = {selected: false, callbacks: [], handlers: {}, fetched: 0, reloaded: 0, replaced: null};
    const root = {
        dataset: {paidStage: 'queued'},
        addEventListener: (name, fn) => state.handlers[name] = fn,
        querySelector: selector => selector === '#selection-mode' ? (options.allMatching ? {value: 'all_matching'} : null) : (state.selected ? {} : null),
        contains: () => false
    };
    const location = {href: 'http://example.test/invoices?tab=paid&period=2026-10&paid_status=queued&search=FAM-PAID-801&per_page=50&page=2',
        reload: () => state.reloaded++, replace: url => state.replaced = String(url)};
    const document = {hidden: false, activeElement: {closest: () => null}, getElementById: id => id === 'paid-invoice-workflow' ? root : {textContent: ''}};
    const context = {URL, Date, document, window: {location, setTimeout: fn => state.callbacks.push(fn)},
        fetch: async () => {state.fetched++; if (options.race) state.selected = true;
            return {ok: true, redirected: false, json: async () => ({signature: 'changed', tracking_stage: options.tracking || null})};}};
    vm.runInNewContext(code, context);
    if (options.selected) state.selected = true;
    if (options.editing) state.handlers.input({target: {matches: () => false}});
    await state.callbacks.shift()();
    return {state, location};
}
(async () => {
    let x = await scenario({selected: true}); assert.equal(x.state.fetched, 0); assert.equal(x.state.reloaded, 0);
    x = await scenario({editing: true}); assert.equal(x.state.fetched, 0);
    x = await scenario({allMatching: true}); assert.equal(x.state.fetched, 0); assert.equal(x.state.reloaded, 0);
    x = await scenario({race: true}); assert.equal(x.state.fetched, 1); assert.equal(x.state.reloaded, 0); assert.equal(x.state.replaced, null);
    x = await scenario(); assert.equal(x.state.reloaded, 1); assert.ok(x.location.href.includes('page=2')); assert.ok(x.location.href.includes('per_page=50'));
    x = await scenario({tracking: 'processing'}); const next = new URL(x.state.replaced);
    assert.equal(next.searchParams.get('paid_status'), 'processing'); assert.equal(next.searchParams.get('search'), 'FAM-PAID-801');
    assert.equal(next.searchParams.get('per_page'), '50'); assert.equal(next.searchParams.get('period'), '2026-10');
    console.log('Paid refresh behavior verified');
})().catch(error => {console.error(error); process.exitCode = 1;});
JS;
        $process = new \Symfony\Component\Process\Process(['node', '-e', $harness]);
        $process->setInput(json_encode(['code' => $polling]));
        $process->mustRun();
        $this->assertStringContainsString('Paid refresh behavior verified', $process->getOutput());
    }

}
