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

class BatchDeliveryTest extends TestCase
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
            'email' => 'admin_batch@example.test',
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

    /**
     * Helper to create a complete family with student, enrollment, invoice, and invoice item.
     */
    private function createFamilyWithInvoice(
        array $data,
        int $index,
        string $grade = 'Grade 1',
        bool $verified = true,
        bool $consented = true,
        int $amountMinor = 10000,
        int $paidMinor = 0,
        string $status = 'issued',
        ?int $billingRunId = null
    ): array {
        $famId = DB::table('families')->insertGetId([
            'family_code' => "FAM-BATCH-{$index}",
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
            'gr_number' => "GR-B-{$index}",
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
            'billing_run_id' => $billingRunId ?: $data['run'],
            'family_id' => $famId,
            'campus_id' => $data['campus'],
            'department_id' => $data['dept'],
            'currency' => 'SAR',
            'invoice_number' => "INV-BATCH-{$index}",
            'issued_on' => now()->toDateString(),
            'due_date' => now()->addDays(15)->toDateString(),
            'total_minor' => $amountMinor,
            'paid_minor' => $paidMinor,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('invoice_items')->insert([
            'invoice_id' => $invId,
            'student_id' => $stuId,
            'enrollment_id' => $enrId,
            'description' => "Tuition {$grade}",
            'period_key' => "monthly:2026-10-01..2026-10-31:b{$index}",
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'currency' => 'SAR',
            'amount_minor' => $amountMinor,
            'discount_minor' => 0,
            'net_minor' => $amountMinor,
            'paid_minor' => $paidMinor,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('famId', 'stuId', 'enrId', 'invId');
    }

    /**
     * 1. A filter matching 250 invoices across 9 pages resolves all eligible records server-side, not only 30.
     */
    public function test_filter_matching_250_invoices_across_pages_resolves_all_eligible_records_server_side(): void
    {
        $data = $this->setupTestData();
        Queue::fake([DispatchBatchToN8n::class]);

        // Create 250 invoices (30 per page = ~9 pages)
        for ($i = 1; $i <= 250; $i++) {
            $this->createFamilyWithInvoice($data, $i, 'Grade 1');
        }

        // Hit selection snapshot endpoint
        $response = $this->actingAs($data['superAdmin'])
            ->getJson('/invoices/selection-snapshot?grade=Grade 1&period=2026-10');

        $response->assertStatus(200);
        $snapshot = $response->json();

        // Must resolve all 250 matching records across all 9 pages, not just the first 30!
        $this->assertSame(250, $snapshot['total_matched_invoices']);
        $this->assertSame(250, $snapshot['total_eligible_recipients']);
        $this->assertSame(250, $snapshot['distinct_family_count']);
        $this->assertCount(250, $snapshot['eligible_invoice_ids']);

        // Submit bulk batch with selection_mode = 'all_matching'
        $postResponse = $this->actingAs($data['superAdmin'])
            ->post('/invoices/send-batch', [
                'selection_mode' => 'all_matching',
                'channels' => ['whatsapp'],
                'confirmed_grades' => ['Grade 1'],
                'review_confirmed' => '1',
                'webhook_type' => 'monthly',
                'grade' => 'Grade 1',
                'period' => '2026-10',
            ]);

        $postResponse->assertRedirect();
        $postResponse->assertSessionHas('notice');

        // Check that a single batch was created with 250 recipients
        $batch = DB::table('notification_batches')->latest('id')->first();
        $this->assertNotNull($batch);
        $this->assertSame(250, (int)$batch->total_recipients);
        $this->assertSame('queued', $batch->status);

        // Check that all 250 notifications were queued with the batch ID
        $queuedNotifs = DB::table('notifications')->where('batch_id', $batch->batch_id)->count();
        $this->assertSame(250, $queuedNotifs);

        Queue::assertPushed(DispatchBatchToN8n::class, 1);
    }

    /**
     * 2. A grade filter excludes other unrelated families.
     */
    public function test_grade_filter_excludes_other_unrelated_families(): void
    {
        $data = $this->setupTestData();

        // 3 Grade 1 invoices, 2 Grade 2 invoices
        for ($i = 1; $i <= 3; $i++) {
            $this->createFamilyWithInvoice($data, $i, 'Grade 1');
        }
        for ($i = 4; $i <= 5; $i++) {
            $this->createFamilyWithInvoice($data, $i, 'Grade 2');
        }

        $service = app(BatchDeliveryService::class);
        $snapshot = $service->resolveFilteredSelection(['grade' => 'Grade 1'], $data['superAdmin']);

        $this->assertSame(3, $snapshot['total_matched_invoices']);
        $this->assertSame(3, $snapshot['total_eligible_recipients']);
        $this->assertSame(['Grade 1'], $snapshot['all_grades']);
    }

    /**
     * 3. Multi-student family challans include all appropriate siblings.
     */
    public function test_multi_student_family_challans_include_all_appropriate_siblings(): void
    {
        $data = $this->setupTestData();

        // Family with 2 siblings under same invoice
        $famId = DB::table('families')->insertGetId([
            'family_code' => 'FAM-SIBLINGS',
            'guardian_name' => 'Father of Two',
            'whatsapp' => '+966500000099',
            'contact_verified' => true,
            'whatsapp_verified' => true,
            'messaging_opt_in' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $s1 = DB::table('students')->insertGetId(['family_id' => $famId, 'name' => 'Sibling A', 'gr_number' => 'GR-A', 'created_at' => now(), 'updated_at' => now()]);
        $s2 = DB::table('students')->insertGetId(['family_id' => $famId, 'name' => 'Sibling B', 'gr_number' => 'GR-B', 'created_at' => now(), 'updated_at' => now()]);

        $e1 = DB::table('enrollments')->insertGetId(['student_id' => $s1, 'campus_id' => $data['campus'], 'department_id' => $data['dept'], 'program_id' => $data['program'], 'grade_label' => 'Grade 1', 'currency' => 'SAR', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $e2 = DB::table('enrollments')->insertGetId(['student_id' => $s2, 'campus_id' => $data['campus'], 'department_id' => $data['dept'], 'program_id' => $data['program'], 'grade_label' => 'Grade 3', 'currency' => 'SAR', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $invId = DB::table('invoices')->insertGetId([
            'billing_run_id' => $data['run'],
            'family_id' => $famId,
            'campus_id' => $data['campus'],
            'department_id' => $data['dept'],
            'currency' => 'SAR',
            'invoice_number' => 'INV-SIBLINGS-01',
            'issued_on' => now()->toDateString(),
            'due_date' => now()->addDays(15)->toDateString(),
            'total_minor' => 20000,
            'paid_minor' => 0,
            'status' => 'issued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('invoice_items')->insert([
            ['invoice_id' => $invId, 'student_id' => $s1, 'enrollment_id' => $e1, 'description' => 'Tuition A', 'period_key' => 'p1', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'currency' => 'SAR', 'amount_minor' => 10000, 'discount_minor' => 0, 'net_minor' => 10000, 'paid_minor' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['invoice_id' => $invId, 'student_id' => $s2, 'enrollment_id' => $e2, 'description' => 'Tuition B', 'period_key' => 'p2', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'currency' => 'SAR', 'amount_minor' => 10000, 'discount_minor' => 0, 'net_minor' => 10000, 'paid_minor' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $notifId = DB::table('notifications')->insertGetId([
            'invoice_id' => $invId,
            'channel' => 'whatsapp',
            'kind' => 'monthly',
            'status' => 'queued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $builder = app(\App\Services\InvoicePayloadBuilder::class);
        $payload = $builder->build(DB::table('notifications')->where('id', $notifId)->first(), DB::table('invoices')->where('id', $invId)->first());

        $this->assertCount(2, $payload['students']);
        $studentNames = array_column($payload['students'], 'name');
        $this->assertContains('Sibling A', $studentNames);
        $this->assertContains('Sibling B', $studentNames);
        $this->assertNotEmpty($payload['pdf_attachment']['consolidated_challan_url']);
    }

    /**
     * 4. Older unpaid months are consolidated without duplicating charges and marked as superseded for delivery.
     */
    public function test_older_unpaid_months_are_consolidated_without_duplicating_charges(): void
    {
        $data = $this->setupTestData();

        // Create older billing run (September 2026)
        $oldRun = DB::table('billing_runs')->insertGetId([
            'campus_id' => $data['campus'],
            'department_id' => $data['dept'],
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-09-01..2026-09-30',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'due_date' => '2026-09-25',
            'created_by' => $data['superAdmin']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $fam = $this->createFamilyWithInvoice($data, 101, 'Grade 1', true, true, 10000, 0, 'issued', $oldRun);
        // Current month (October 2026) for same family
        $currInvId = DB::table('invoices')->insertGetId([
            'billing_run_id' => $data['run'],
            'family_id' => $fam['famId'],
            'campus_id' => $data['campus'],
            'department_id' => $data['dept'],
            'currency' => 'SAR',
            'invoice_number' => 'INV-CURR-OCT',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-25',
            'total_minor' => 12000,
            'paid_minor' => 0,
            'status' => 'issued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('invoice_items')->insert([
            'invoice_id' => $currInvId,
            'student_id' => $fam['stuId'],
            'enrollment_id' => $fam['enrId'],
            'description' => 'Tuition Oct',
            'period_key' => 'monthly:2026-10-01..2026-10-31:b101',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'currency' => 'SAR',
            'amount_minor' => 12000,
            'discount_minor' => 0,
            'net_minor' => 12000,
            'paid_minor' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(BatchDeliveryService::class);
        $snapshot = $service->resolveFilteredSelection(['period' => '2026-10'], $data['superAdmin']);

        // Only 1 eligible recipient (the latest October challan)
        $this->assertSame(1, $snapshot['total_eligible_recipients']);
        $this->assertSame($currInvId, $snapshot['eligible_recipients'][0]['invoice_id']);

        // Older invoice was marked as superseded for delivery
        $this->assertContains($fam['invId'], $snapshot['superseded_invoice_ids']);
        $this->assertSame(1, $snapshot['excluded_breakdown']['superseded']);

        // Verify older invoice financial obligation was NOT voided or modified
        $oldInv = DB::table('invoices')->where('id', $fam['invId'])->first();
        $this->assertSame(10000, (int)$oldInv->total_minor);
        $this->assertSame(0, (int)$oldInv->paid_minor);
        $this->assertSame('issued', $oldInv->status);
    }

    /**
     * 5. One recipient/month is not notified multiple times.
     */
    public function test_one_recipient_month_is_not_notified_multiple_times(): void
    {
        $data = $this->setupTestData();

        $fam = $this->createFamilyWithInvoice($data, 201, 'Grade 1');

        // Mark as already submitted
        DB::table('notifications')->insert([
            'invoice_id' => $fam['invId'],
            'channel' => 'whatsapp',
            'kind' => 'monthly',
            'status' => 'submitted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(BatchDeliveryService::class);
        $snapshot = $service->resolveFilteredSelection([], $data['superAdmin']);

        // Recipient is excluded from delivery
        $this->assertSame(0, $snapshot['total_eligible_recipients']);
        $this->assertSame(1, $snapshot['excluded_breakdown']['already_submitted']);
    }

    /**
     * 6. Monthly and Paid events route independently.
     */
    public function test_monthly_and_paid_events_route_independently(): void
    {
        $data = $this->setupTestData();

        Http::fake([
            $data['monthlyUrl'] => Http::response(['ok' => true], 200),
            $data['paidUrl'] => Http::response(['ok' => true], 200),
        ]);

        $famUnpaid = $this->createFamilyWithInvoice($data, 301, 'Grade 1', true, true, 10000, 0, 'issued');
        $famPaid = $this->createFamilyWithInvoice($data, 302, 'Grade 1', true, true, 10000, 10000, 'paid');

        $itemId = DB::table('invoice_items')->where('invoice_id', $famPaid['invId'])->value('id');
        $paymentId = DB::table('payments')->insertGetId([
            'family_id' => $famPaid['famId'], 'campus_id' => $data['campus'], 'department_id' => $data['dept'],
            'currency' => 'SAR', 'receipt_number' => 'FICTIONAL-PAID-302', 'amount_minor' => 10000,
            'received_on' => '2026-10-09', 'received_by' => $data['superAdmin']->id,
            'idempotency_key' => 'fictional-paid-302', 'request_hash' => hash('sha256', 'fictional-paid-302'),
            'status' => 'received', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('payment_allocations')->insert([
            'payment_id' => $paymentId, 'invoice_item_id' => $itemId, 'amount_minor' => 10000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $service = app(BatchDeliveryService::class);

        // 1. Monthly batch
        $resM = $service->createAndDispatchBatch(['webhook_type' => 'monthly'], ['Grade 1'], ['whatsapp'], $data['superAdmin'], '2026-10');
        (new DispatchBatchToN8n($resM['batch_id']))->handle();

        Http::assertSent(function ($request) use ($data) {
            return $request->url() === $data['monthlyUrl'] && $request['event_type'] === 'invoice.monthly.batch';
        });

        // 2. Paid batch
        $resP = $service->createAndDispatchBatch(['webhook_type' => 'paid'], ['Grade 1'], ['whatsapp'], $data['superAdmin'], '2026-10');
        (new DispatchBatchToN8n($resP['batch_id'], DB::table('notifications')->where('batch_id', $resP['batch_id'])->pluck('id')->all()))->handle();

        Http::assertSent(function ($request) use ($data) {
            return $request->url() === $data['paidUrl'] && $request['event_type'] === 'invoice.paid.batch';
        });
    }

    /**
     * 7. One moderate-size selection produces one correct batch HTTP payload.
     */
    public function test_one_moderate_size_selection_produces_one_correct_batch_http_payload(): void
    {
        $data = $this->setupTestData();

        $capturedPayload = null;
        $capturedHeaders = [];
        Http::fake([
            $data['monthlyUrl'] => function ($request) use (&$capturedPayload, &$capturedHeaders) {
                $capturedPayload = $request->data();
                $capturedHeaders = $request->headers();
                return Http::response(['ok' => true, 'batch_id' => 'n8n-accepted-123'], 200);
            },
        ]);

        for ($i = 1; $i <= 10; $i++) {
            $this->createFamilyWithInvoice($data, 400 + $i, 'Grade 1');
        }

        $service = app(BatchDeliveryService::class);
        $res = $service->createAndDispatchBatch(['webhook_type' => 'monthly'], ['Grade 1'], ['whatsapp'], $data['superAdmin'], '2026-10');

        (new DispatchBatchToN8n($res['batch_id']))->handle();

        $this->assertNotNull($capturedPayload);
        $this->assertSame('invoice.monthly.batch', $capturedPayload['event_type']);
        $this->assertSame($res['batch_id'], $capturedPayload['batch_id']);
        $this->assertSame(10, $capturedPayload['total_recipient_count']);
        $this->assertCount(10, $capturedPayload['invoices']);

        // HMAC signature present
        $this->assertNotEmpty($capturedHeaders['X-IQRA-Signature'][0] ?? null);

        // Check batch updated to submitted
        $batch = DB::table('notification_batches')->where('batch_id', $res['batch_id'])->first();
        $this->assertSame('submitted', $batch->status);
    }

    /**
     * 8. Oversized selections safely partition under one batch ID.
     */
    public function test_oversized_selections_safely_partition_under_one_batch_id(): void
    {
        $data = $this->setupTestData();

        config(['services.n8n.batch_chunk_size' => 5]);

        $sentRequests = [];
        Http::fake([
            $data['monthlyUrl'] => function ($request) use (&$sentRequests) {
                $sentRequests[] = $request->data();
                return Http::response(['ok' => true], 200);
            },
        ]);

        for ($i = 1; $i <= 12; $i++) {
            $this->createFamilyWithInvoice($data, 500 + $i, 'Grade 1');
        }

        $service = app(BatchDeliveryService::class);
        $res = $service->createAndDispatchBatch(['webhook_type' => 'monthly'], ['Grade 1'], ['whatsapp'], $data['superAdmin'], '2026-10');

        (new DispatchBatchToN8n($res['batch_id']))->handle();

        // 12 records with chunk size 5 = 3 chunks (5, 5, 2)
        $this->assertCount(3, $sentRequests);

        // All chunks share the SAME batch ID
        foreach ($sentRequests as $chunk) {
            $this->assertSame($res['batch_id'], $chunk['batch_id']);
            $this->assertSame(3, $chunk['total_chunks']);
        }

        $this->assertSame(1, $sentRequests[0]['chunk_index']);
        $this->assertSame(5, $sentRequests[0]['total_recipient_count']);

        $this->assertSame(2, $sentRequests[1]['chunk_index']);
        $this->assertSame(5, $sentRequests[1]['total_recipient_count']);

        $this->assertSame(3, $sentRequests[2]['chunk_index']);
        $this->assertSame(2, $sentRequests[2]['total_recipient_count']);

        $batch = DB::table('notification_batches')->where('batch_id', $res['batch_id'])->first();
        $this->assertSame(3, (int)$batch->chunks_sent);
        $this->assertSame('submitted', $batch->status);
    }

    /**
     * 9. n8n 404/429/500 responses are handled correctly without corrupting batch state.
     */
    public function test_n8n_http_errors_handled_correctly_without_corrupting_batch_state(): void
    {
        $data = $this->setupTestData();

        Http::fake([
            $data['monthlyUrl'] => Http::sequence()
                ->push(['code' => 404, 'message' => 'Workflow not active'], 404)
                ->push(['ok' => true], 200),
        ]);

        $this->createFamilyWithInvoice($data, 601, 'Grade 1');

        Queue::fake([DispatchBatchToN8n::class]);
        $service = app(BatchDeliveryService::class);
        $res = $service->createAndDispatchBatch(['webhook_type' => 'monthly'], ['Grade 1'], ['whatsapp'], $data['superAdmin'], '2026-10');

        try {
            (new DispatchBatchToN8n($res['batch_id']))->handle();
            $this->fail('Should throw RuntimeException on HTTP 404');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('Webhook HTTP 404', $e->getMessage());
        }

        $batch = DB::table('notification_batches')->where('batch_id', $res['batch_id'])->first();
        $this->assertSame('failed', $batch->status);
        $this->assertSame(404, (int)$batch->response_code);
        $this->assertNotNull($batch->error);

        // Individual recipient also marked failed, ready for controlled retry
        $notif = DB::table('notifications')->where('batch_id', $res['batch_id'])->first();
        $this->assertSame('failed', $notif->status);

        // Now test retry failed recipients only
        $retryRes = $service->retryFailedBatchRecipients($res['batch_id'], $data['superAdmin']);
        $this->assertSame(1, $retryRes['retried_count']);

        (new DispatchBatchToN8n($res['batch_id']))->handle();

        $batchAfter = DB::table('notification_batches')->where('batch_id', $res['batch_id'])->first();
        $this->assertSame('submitted', $batchAfter->status);
    }

    /**
     * 10. Double submissions and worker retries are idempotent.
     */
    public function test_double_submissions_and_worker_retries_are_idempotent(): void
    {
        $data = $this->setupTestData();

        $callCount = 0;
        Http::fake([
            $data['monthlyUrl'] => function () use (&$callCount) {
                $callCount++;
                return Http::response(['ok' => true], 200);
            },
        ]);

        $this->createFamilyWithInvoice($data, 701, 'Grade 1');

        $service = app(BatchDeliveryService::class);
        $res = $service->createAndDispatchBatch(['webhook_type' => 'monthly'], ['Grade 1'], ['whatsapp'], $data['superAdmin'], '2026-10');

        (new DispatchBatchToN8n($res['batch_id']))->handle();
        $this->assertSame(1, $callCount);

        // Second call on already submitted batch does NOT make an extra HTTP request
        (new DispatchBatchToN8n($res['batch_id']))->handle();
        $this->assertSame(1, $callCount);
    }

    /**
     * 11. Previously submitted notifications are not silently resent.
     */
    public function test_previously_submitted_notifications_are_not_silently_resent(): void
    {
        $data = $this->setupTestData();
        Queue::fake([DispatchBatchToN8n::class]);

        $f1 = $this->createFamilyWithInvoice($data, 801, 'Grade 1');
        $f2 = $this->createFamilyWithInvoice($data, 802, 'Grade 1');

        // Mark f1 as already submitted
        DB::table('notifications')->insert([
            'invoice_id' => $f1['invId'],
            'channel' => 'whatsapp',
            'kind' => 'monthly',
            'status' => 'submitted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(BatchDeliveryService::class);
        $res = $service->createAndDispatchBatch(['webhook_type' => 'monthly'], ['Grade 1'], ['whatsapp'], $data['superAdmin'], '2026-10');

        // Only f2 is in the batch
        $this->assertSame(1, $res['eligible_count']);

        $notifsInBatch = DB::table('notifications')->where('batch_id', $res['batch_id'])->pluck('invoice_id')->all();
        $this->assertNotContains($f1['invId'], $notifsInBatch);
        $this->assertContains($f2['invId'], $notifsInBatch);
    }

    /**
     * 12. Zero unintended financial ledger mutations.
     */
    public function test_zero_unintended_financial_ledger_mutations(): void
    {
        $data = $this->setupTestData();

        Http::fake([
            $data['monthlyUrl'] => Http::response(['ok' => true], 200),
        ]);

        for ($i = 1; $i <= 5; $i++) {
            $this->createFamilyWithInvoice($data, 900 + $i, 'Grade 1');
        }

        $invCountBefore = DB::table('invoices')->count();
        $itemCountBefore = DB::table('invoice_items')->count();
        $sumTotalBefore = DB::table('invoices')->sum('total_minor');
        $sumPaidBefore = DB::table('invoices')->sum('paid_minor');
        $paymentsCountBefore = DB::table('payments')->count();

        $service = app(BatchDeliveryService::class);
        $res = $service->createAndDispatchBatch(['webhook_type' => 'monthly'], ['Grade 1'], ['whatsapp'], $data['superAdmin'], '2026-10');
        (new DispatchBatchToN8n($res['batch_id']))->handle();

        $this->assertSame($invCountBefore, DB::table('invoices')->count());
        $this->assertSame($itemCountBefore, DB::table('invoice_items')->count());
        $this->assertSame($sumTotalBefore, DB::table('invoices')->sum('total_minor'));
        $this->assertSame($sumPaidBefore, DB::table('invoices')->sum('paid_minor'));
        $this->assertSame($paymentsCountBefore, DB::table('payments')->count());
    }
}
