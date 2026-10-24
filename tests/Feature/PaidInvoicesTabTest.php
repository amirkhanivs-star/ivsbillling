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
        string $receiptNumber = 'RCP-TEST-001'
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
            'received_on' => '2026-10-24',
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

        (new DispatchBatchToN8n($batch->batch_id))->handle();

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
}
