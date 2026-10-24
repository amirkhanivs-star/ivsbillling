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

class InvoiceDeliveryQueueTest extends TestCase
{
    use RefreshDatabase;

    private function setupTestData(): array
    {
        $this->seed();

        $campus = DB::table('campuses')->value('id');
        $dept = DB::table('departments')->value('id');
        $program = DB::table('programs')->value('id');

        $superAdmin = User::create([
            'name' => 'Super Admin Queue',
            'email' => 'admin_queue_' . uniqid() . '@example.test',
            'password' => bcrypt('password123'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $monthlyUrl = 'https://n8n.example.com/webhook/monthly-invoices';
        $paidUrl = 'https://n8n.example.com/webhook/paid-invoices';
        $secret = 'test-queue-secret-key-123';

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

    private function createFamilyWithInvoice(
        array $data,
        int $index,
        string $grade = 'Grade 1',
        bool $verified = true,
        bool $consented = true,
        int $amountMinor = 10000,
        int $paidMinor = 0,
        string $currency = 'SAR',
        ?int $campusId = null,
        ?int $deptId = null,
        string $period = '2026-10'
    ): array {
        $cId = $campusId ?: $data['campus'];
        $dId = $deptId ?: $data['dept'];

        $famId = DB::table('families')->insertGetId([
            'family_code' => "FAM-Q-{$index}",
            'guardian_name' => "Guardian {$index}",
            'whatsapp' => "+96650" . str_pad((string)$index, 7, '0', STR_PAD_LEFT),
            'contact_verified' => $verified,
            'whatsapp_verified' => $verified,
            'email_verified' => $verified,
            'messaging_opt_in' => $consented,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stuId = DB::table('students')->insertGetId([
            'family_id' => $famId,
            'name' => "Student {$index}",
            'gr_number' => "GR-Q-{$index}",
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrId = DB::table('enrollments')->insertGetId([
            'student_id' => $stuId,
            'campus_id' => $cId,
            'department_id' => $dId,
            'program_id' => $data['program'],
            'grade_label' => $grade,
            'currency' => $currency,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $runId = $data['run'];
        if ($period !== '2026-10') {
            $runId = DB::table('billing_runs')->insertGetId([
                'campus_id' => $cId,
                'department_id' => $dId,
                'frequency' => 'monthly',
                'period_key' => "monthly:{$period}-01..{$period}-30:test{$index}",
                'period_start' => "{$period}-01",
                'period_end' => "{$period}-30",
                'due_date' => "{$period}-25",
                'created_by' => $data['superAdmin']->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $invId = DB::table('invoices')->insertGetId([
            'billing_run_id' => $runId,
            'family_id' => $famId,
            'campus_id' => $cId,
            'department_id' => $dId,
            'currency' => $currency,
            'invoice_number' => "INV-Q-{$index}",
            'issued_on' => "{$period}-01",
            'due_date' => "{$period}-25",
            'total_minor' => $amountMinor,
            'paid_minor' => $paidMinor,
            'status' => ($paidMinor >= $amountMinor) ? 'paid' : ($paidMinor > 0 ? 'partially_paid' : 'unpaid'),
            'approved_to_send' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invId,
            'student_id' => $stuId,
            'enrollment_id' => $enrId,
            'period_key' => "monthly:{$period}-01..{$period}-30:q{$index}",
            'currency' => $currency,
            'period_start' => "{$period}-01",
            'period_end' => "{$period}-30",
            'description' => "Tuition Fee {$period}",
            'amount_minor' => $amountMinor,
            'discount_minor' => 0,
            'net_minor' => $amountMinor,
            'paid_minor' => $paidMinor,
            'status' => ($paidMinor >= $amountMinor) ? 'paid' : ($paidMinor > 0 ? 'partially_paid' : 'unpaid'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('famId', 'stuId', 'enrId', 'invId', 'itemId');
    }

    /**
     * 1. Test 260 recipients across six chunks (chunk_size=50) with accurate chunk numbering and pagination.
     */
    public function test_260_recipients_across_six_chunks_chunking_and_pagination(): void
    {
        $data = $this->setupTestData();
        Queue::fake([DispatchBatchToN8n::class]);

        config(['services.n8n.batch_chunk_size' => 50]);

        $createdInvoices = [];
        for ($i = 1; $i <= 260; $i++) {
            $res = $this->createFamilyWithInvoice($data, 1000 + $i, 'Grade 1');
            $createdInvoices[] = $res['invId'];
        }

        $service = app(BatchDeliveryService::class);
        $batchRes = $service->createAndDispatchBatch(
            ['webhook_type' => 'monthly', 'grade' => 'Grade 1'],
            ['Grade 1'],
            ['whatsapp'],
            $data['superAdmin'],
            '2026-10'
        );

        $this->assertSame(260, $batchRes['eligible_count']);
        $this->assertSame(6, $batchRes['chunks_total']);

        // Verify chunk_index distribution in database (50 per chunk for 1-5, 10 for chunk 6)
        $chunkCounts = DB::table('notifications')
            ->where('batch_id', $batchRes['batch_id'])
            ->select('chunk_index', DB::raw('count(*) as count'))
            ->groupBy('chunk_index')
            ->orderBy('chunk_index')
            ->pluck('count', 'chunk_index')
            ->all();

        $this->assertCount(6, $chunkCounts);
        $this->assertSame(50, (int)$chunkCounts[1]);
        $this->assertSame(50, (int)$chunkCounts[2]);
        $this->assertSame(50, (int)$chunkCounts[3]);
        $this->assertSame(50, (int)$chunkCounts[4]);
        $this->assertSame(50, (int)$chunkCounts[5]);
        $this->assertSame(10, (int)$chunkCounts[6]);

        // Access delivery queue view with batch_id and per_page=50
        $response = $this->actingAs($data['superAdmin'])->get("/invoices/queue?batch_id={$batchRes['batch_id']}&per_page=50");
        $response->assertStatus(200);
        $response->assertViewHas('notifications');
        $notifications = $response->viewData('notifications');
        $this->assertSame(260, $notifications->total());
        $this->assertSame(50, $notifications->count());
        $this->assertSame(6, $notifications->lastPage());
    }

    /**
     * 2. Test server-side pagination with 25, 50, 100 rows per page, accurate totals and stable sorting.
     */
    public function test_server_side_pagination_supports_25_50_100_rows_per_page(): void
    {
        $data = $this->setupTestData();
        Queue::fake([DispatchBatchToN8n::class]);
        config(['services.n8n.batch_chunk_size' => 50]);

        for ($i = 1; $i <= 60; $i++) {
            $this->createFamilyWithInvoice($data, 2000 + $i, 'Grade 2');
        }

        $service = app(BatchDeliveryService::class);
        $batchRes = $service->createAndDispatchBatch(
            ['webhook_type' => 'monthly', 'grade' => 'Grade 2'],
            ['Grade 2'],
            ['whatsapp'],
            $data['superAdmin'],
            '2026-10'
        );

        // 25 per page
        $res25 = $this->actingAs($data['superAdmin'])->get("/invoices/queue?batch_id={$batchRes['batch_id']}&per_page=25");
        $res25->assertStatus(200);
        $notifs25 = $res25->viewData('notifications');
        $this->assertSame(25, $notifs25->count());
        $this->assertSame(60, $notifs25->total());
        $this->assertSame(3, $notifs25->lastPage());

        // 50 per page
        $res50 = $this->actingAs($data['superAdmin'])->get("/invoices/queue?batch_id={$batchRes['batch_id']}&per_page=50");
        $res50->assertStatus(200);
        $notifs50 = $res50->viewData('notifications');
        $this->assertSame(50, $notifs50->count());
        $this->assertSame(60, $notifs50->total());
        $this->assertSame(2, $notifs50->lastPage());

        // 100 per page
        $res100 = $this->actingAs($data['superAdmin'])->get("/invoices/queue?batch_id={$batchRes['batch_id']}&per_page=100");
        $res100->assertStatus(200);
        $notifs100 = $res100->viewData('notifications');
        $this->assertSame(60, $notifs100->count());
        $this->assertSame(60, $notifs100->total());
        $this->assertSame(1, $notifs100->lastPage());

        // Invalid per_page defaults to 25
        $resDef = $this->actingAs($data['superAdmin'])->get("/invoices/queue?batch_id={$batchRes['batch_id']}&per_page=999");
        $resDef->assertStatus(200);
        $notifsDef = $resDef->viewData('notifications');
        $this->assertSame(25, $notifsDef->count());
    }

    /**
     * 3. Test search by student name, GR number, family code, WhatsApp number, invoice number, batch ID, notification ID.
     */
    public function test_universal_search_matches_all_target_identifiers(): void
    {
        $data = $this->setupTestData();
        Queue::fake([DispatchBatchToN8n::class]);

        $f = $this->createFamilyWithInvoice($data, 3001, 'Grade 3');
        DB::table('students')->where('id', $f['stuId'])->update([
            'name' => 'Tariq Unique Student',
            'gr_number' => 'GR-SPECIAL-77',
        ]);
        DB::table('families')->where('id', $f['famId'])->update([
            'family_code' => 'FAM-CODE-999',
            'whatsapp' => '+923001122334',
        ]);
        DB::table('invoices')->where('id', $f['invId'])->update([
            'invoice_number' => 'INV-SPECIAL-001',
        ]);

        $service = app(BatchDeliveryService::class);
        $batchRes = $service->createAndDispatchBatch(
            ['webhook_type' => 'monthly', 'grade' => 'Grade 3'],
            ['Grade 3'],
            ['whatsapp'],
            $data['superAdmin'],
            '2026-10'
        );

        $notif = DB::table('notifications')->where('invoice_id', $f['invId'])->first();

        // 1. Search by Student Name
        $res = $this->actingAs($data['superAdmin'])->get('/invoices/queue?search=Tariq+Unique');
        $res->assertStatus(200);
        $this->assertSame(1, $res->viewData('notifications')->total());

        // 2. Search by GR Number
        $res = $this->actingAs($data['superAdmin'])->get('/invoices/queue?search=GR-SPECIAL-77');
        $this->assertSame(1, $res->viewData('notifications')->total());

        // 3. Search by Family Code
        $res = $this->actingAs($data['superAdmin'])->get('/invoices/queue?search=FAM-CODE-999');
        $this->assertSame(1, $res->viewData('notifications')->total());

        // 4. Search by WhatsApp Number
        $res = $this->actingAs($data['superAdmin'])->get('/invoices/queue?search=03001122334');
        $this->assertSame(1, $res->viewData('notifications')->total());

        // 5. Search by Invoice Number
        $res = $this->actingAs($data['superAdmin'])->get('/invoices/queue?search=INV-SPECIAL-001');
        $this->assertSame(1, $res->viewData('notifications')->total());

        // 6. Search by Batch ID
        $res = $this->actingAs($data['superAdmin'])->get("/invoices/queue?search={$batchRes['batch_id']}");
        $this->assertSame(1, $res->viewData('notifications')->total());

        // 7. Search by Notification ID
        $res = $this->actingAs($data['superAdmin'])->get("/invoices/queue?search={$notif->id}");
        $this->assertSame(1, $res->viewData('notifications')->total());

        // 8. Search non-existent
        $res = $this->actingAs($data['superAdmin'])->get('/invoices/queue?search=NONEXISTENT_KEYWORD_XYZ');
        $this->assertSame(0, $res->viewData('notifications')->total());
    }

    /**
     * 4. Test filters for batch, billing period, department, grade, currency, webhook submission status, and actual delivery status.
     */
    public function test_filters_by_batch_period_department_grade_currency_and_status(): void
    {
        $data = $this->setupTestData();
        Queue::fake([DispatchBatchToN8n::class]);

        // Create Grade 1 vs Grade 2
        $f1 = $this->createFamilyWithInvoice($data, 4001, 'Grade 1', true, true, 10000, 0, 'SAR', null, null, '2026-10');
        $f2 = $this->createFamilyWithInvoice($data, 4002, 'Grade 2', true, true, 10000, 0, 'PKR', null, null, '2026-11');

        $service = app(BatchDeliveryService::class);
        $b1 = $service->createAndDispatchBatch(['webhook_type' => 'monthly', 'grade' => 'Grade 1'], ['Grade 1'], ['whatsapp'], $data['superAdmin'], '2026-10');
        $b2 = $service->createAndDispatchBatch(['webhook_type' => 'monthly', 'grade' => 'Grade 2'], ['Grade 2'], ['whatsapp'], $data['superAdmin'], '2026-11');

        // Set different statuses
        DB::table('notifications')->where('invoice_id', $f1['invId'])->update([
            'status' => 'delivered',
            'submitted_at' => now(),
            'delivered_at' => now(),
        ]);
        DB::table('notifications')->where('invoice_id', $f2['invId'])->update([
            'status' => 'failed',
            'error' => 'Webhook HTTP 500',
        ]);

        // Filter by batch_id
        $res = $this->actingAs($data['superAdmin'])->get("/invoices/queue?batch_id={$b1['batch_id']}");
        $this->assertSame(1, $res->viewData('notifications')->total());

        // Filter by period
        $res = $this->actingAs($data['superAdmin'])->get('/invoices/queue?period=2026-11');
        $this->assertSame(1, $res->viewData('notifications')->total());

        // Filter by grade
        $res = $this->actingAs($data['superAdmin'])->get('/invoices/queue?grade=Grade+1');
        $this->assertSame(1, $res->viewData('notifications')->total());

        // Filter by currency
        $res = $this->actingAs($data['superAdmin'])->get('/invoices/queue?currency=PKR');
        $this->assertSame(1, $res->viewData('notifications')->total());

        // Filter by webhook submission status (submitted vs failed)
        $resSub = $this->actingAs($data['superAdmin'])->get('/invoices/queue?submission_status=submitted');
        $this->assertSame(1, $resSub->viewData('notifications')->total());

        $resFail = $this->actingAs($data['superAdmin'])->get('/invoices/queue?submission_status=failed');
        $this->assertSame(1, $resFail->viewData('notifications')->total());

        // Filter by actual delivery status (delivered vs failed)
        $resDel = $this->actingAs($data['superAdmin'])->get('/invoices/queue?delivery_status=delivered');
        $this->assertSame(1, $resDel->viewData('notifications')->total());
    }

    /**
     * 5. Test role and campus authorization restrictions.
     */
    public function test_role_and_campus_authorization_restricts_campus_staff(): void
    {
        $data = $this->setupTestData();
        Queue::fake([DispatchBatchToN8n::class]);

        $campus2 = DB::table('campuses')->insertGetId(['name' => 'Campus Two', 'code' => 'C2']);

        $f1 = $this->createFamilyWithInvoice($data, 5001, 'Grade 1', true, true, 10000, 0, 'SAR', $data['campus']);
        $f2 = $this->createFamilyWithInvoice($data, 5002, 'Grade 1', true, true, 10000, 0, 'SAR', $campus2);

        $service = app(BatchDeliveryService::class);
        $b1 = $service->createAndDispatchBatch(['webhook_type' => 'monthly', 'grade' => 'Grade 1', 'campus_id' => $data['campus']], ['Grade 1'], ['whatsapp'], $data['superAdmin'], '2026-10');
        $b2 = $service->createAndDispatchBatch(['webhook_type' => 'monthly', 'grade' => 'Grade 1', 'campus_id' => $campus2], ['Grade 1'], ['whatsapp'], $data['superAdmin'], '2026-10');

        $staffCampus1 = User::create([
            'name' => 'Staff Campus 1',
            'email' => 'staff_c1_' . uniqid() . '@example.test',
            'password' => bcrypt('password123'),
            'role' => 'cashier',
            'campus_id' => $data['campus'],
            'is_active' => true,
        ]);

        // Staff Campus 1 only sees notifications from Campus 1
        $res = $this->actingAs($staffCampus1)->get('/invoices/queue');
        $res->assertStatus(200);
        $items = $res->viewData('notifications');
        foreach ($items as $item) {
            $this->assertSame($data['campus'], (int)$item->campus_id);
        }

        // Staff Campus 1 attempting to access batch belonging to Campus 2 gets 404
        $resDenied = $this->actingAs($staffCampus1)->get("/invoices/batches/{$b2['batch_id']}");
        $resDenied->assertStatus(404);

        // Super Admin sees everything
        $resAdmin = $this->actingAs($data['superAdmin'])->get("/invoices/batches/{$b2['batch_id']}");
        $resAdmin->assertStatus(200);
    }

    /**
     * 6. Test deduplication of recipients across pagination and chunks.
     */
    public function test_deduplication_of_recipients_across_pagination_and_chunks(): void
    {
        $data = $this->setupTestData();
        Queue::fake([DispatchBatchToN8n::class]);
        config(['services.n8n.batch_chunk_size' => 20]);

        for ($i = 1; $i <= 45; $i++) {
            $this->createFamilyWithInvoice($data, 6000 + $i, 'Grade 4');
        }

        $service = app(BatchDeliveryService::class);
        $batchRes = $service->createAndDispatchBatch(
            ['webhook_type' => 'monthly', 'grade' => 'Grade 4'],
            ['Grade 4'],
            ['whatsapp'],
            $data['superAdmin'],
            '2026-10'
        );

        // Paginate page 1 and page 2 at 25 per page
        $page1 = $this->actingAs($data['superAdmin'])->get("/invoices/queue?batch_id={$batchRes['batch_id']}&per_page=25&page=1");
        $page2 = $this->actingAs($data['superAdmin'])->get("/invoices/queue?batch_id={$batchRes['batch_id']}&per_page=25&page=2");

        $idsPage1 = $page1->viewData('notifications')->pluck('notification_id')->all();
        $idsPage2 = $page2->viewData('notifications')->pluck('notification_id')->all();

        $this->assertCount(25, $idsPage1);
        $this->assertCount(20, $idsPage2);

        // Ensure zero overlap between page 1 and page 2
        $intersection = array_intersect($idsPage1, $idsPage2);
        $this->assertEmpty($intersection, 'Pagination emitted duplicate notification IDs across pages!');
    }

    /**
     * 7. Test summary metrics accuracy (total, queued, submitted, delivered, read, failed, superseded).
     */
    public function test_queue_summary_metrics_accuracy(): void
    {
        $data = $this->setupTestData();
        Queue::fake([DispatchBatchToN8n::class]);

        for ($i = 1; $i <= 6; $i++) {
            $this->createFamilyWithInvoice($data, 7000 + $i, 'Grade 5');
        }

        $service = app(BatchDeliveryService::class);
        $batchRes = $service->createAndDispatchBatch(
            ['webhook_type' => 'monthly', 'grade' => 'Grade 5'],
            ['Grade 5'],
            ['whatsapp'],
            $data['superAdmin'],
            '2026-10'
        );

        $notifs = DB::table('notifications')->where('batch_id', $batchRes['batch_id'])->orderBy('id')->get();

        // 1: submitted, 2: delivered, 3: read, 4: failed, 5: queued, 6: superseded
        DB::table('notifications')->where('id', $notifs[0]->id)->update(['status' => 'submitted', 'submitted_at' => now()]);
        DB::table('notifications')->where('id', $notifs[1]->id)->update(['status' => 'delivered', 'submitted_at' => now(), 'delivered_at' => now()]);
        DB::table('notifications')->where('id', $notifs[2]->id)->update(['status' => 'read', 'submitted_at' => now(), 'delivered_at' => now()]);
        DB::table('notifications')->where('id', $notifs[3]->id)->update(['status' => 'failed', 'error' => 'HTTP 429']);
        DB::table('notifications')->where('id', $notifs[4]->id)->update(['status' => 'queued']);
        DB::table('notifications')->where('id', $notifs[5]->id)->update(['is_superseded' => true]);

        $queueService = app(InvoiceDeliveryQueueService::class);
        $stats = $queueService->getQueueStats(['batch_id' => $batchRes['batch_id']], $data['superAdmin']);

        $this->assertSame(6, $stats['total']);
        $this->assertSame(3, $stats['submitted']); // submitted + delivered + read
        $this->assertSame(1, $stats['delivered']);
        $this->assertSame(1, $stats['read']);
        $this->assertSame(1, $stats['failed']);
        $this->assertSame(2, $stats['queued']); // notifs[4] + notifs[5] (if not updated)
        $this->assertSame(1, $stats['superseded']);
    }

    /**
     * 8. Test distinct Webhook Submission vs Actual WhatsApp Delivery status handling.
     */
    public function test_webhook_submission_distinct_from_whatsapp_delivery_status(): void
    {
        $data = $this->setupTestData();
        Queue::fake([DispatchBatchToN8n::class]);

        $f = $this->createFamilyWithInvoice($data, 8001, 'Grade 6');
        $service = app(BatchDeliveryService::class);
        $b = $service->createAndDispatchBatch(['webhook_type' => 'monthly', 'grade' => 'Grade 6'], ['Grade 6'], ['whatsapp'], $data['superAdmin'], '2026-10');

        $notif = DB::table('notifications')->where('invoice_id', $f['invId'])->first();

        // Initially queued
        $queueService = app(InvoiceDeliveryQueueService::class);
        $page = $queueService->getPaginatedQueue(['batch_id' => $b['batch_id']], $data['superAdmin']);
        $row = $page->first();
        $this->assertSame('queued', $row->webhook_submission_status);
        $this->assertSame('pending', $row->whatsapp_delivery_status);

        // Dispatched to n8n (HTTP 200/202 accepted)
        DB::table('notifications')->where('id', $notif->id)->update([
            'status' => 'submitted',
            'submitted_at' => now(),
            'provider_message_id' => 'wamid.TEST_123',
        ]);
        $page = $queueService->getPaginatedQueue(['batch_id' => $b['batch_id']], $data['superAdmin']);
        $row = $page->first();
        $this->assertSame('submitted', $row->webhook_submission_status);
        $this->assertSame('pending', $row->whatsapp_delivery_status);

        // Callback delivered
        DB::table('notifications')->where('id', $notif->id)->update([
            'status' => 'delivered',
            'delivered_at' => now(),
        ]);
        $page = $queueService->getPaginatedQueue(['batch_id' => $b['batch_id']], $data['superAdmin']);
        $row = $page->first();
        $this->assertSame('submitted', $row->webhook_submission_status);
        $this->assertSame('delivered', $row->whatsapp_delivery_status);

        // Callback read
        DB::table('notifications')->where('id', $notif->id)->update(['status' => 'read']);
        $page = $queueService->getPaginatedQueue(['batch_id' => $b['batch_id']], $data['superAdmin']);
        $row = $page->first();
        $this->assertSame('submitted', $row->webhook_submission_status);
        $this->assertSame('read', $row->whatsapp_delivery_status);
    }

    /**
     * 9. Test safe Retry Failed Only without resending successful recipients.
     */
    public function test_safe_retry_failed_only_requeues_only_failed_recipients(): void
    {
        $data = $this->setupTestData();
        Queue::fake([DispatchBatchToN8n::class]);

        $f1 = $this->createFamilyWithInvoice($data, 9001, 'Grade 7');
        $f2 = $this->createFamilyWithInvoice($data, 9002, 'Grade 7');
        $f3 = $this->createFamilyWithInvoice($data, 9003, 'Grade 7');

        $service = app(BatchDeliveryService::class);
        $b = $service->createAndDispatchBatch(['webhook_type' => 'monthly', 'grade' => 'Grade 7'], ['Grade 7'], ['whatsapp'], $data['superAdmin'], '2026-10');

        // Mark 1 as submitted, 1 as delivered, 1 as failed
        DB::table('notifications')->where('invoice_id', $f1['invId'])->update(['status' => 'submitted', 'submitted_at' => now()]);
        DB::table('notifications')->where('invoice_id', $f2['invId'])->update(['status' => 'delivered', 'submitted_at' => now(), 'delivered_at' => now()]);
        DB::table('notifications')->where('invoice_id', $f3['invId'])->update(['status' => 'failed', 'error' => 'Network timeout']);

        // Execute retry failed only
        $res = $this->actingAs($data['superAdmin'])->post("/invoices/batches/{$b['batch_id']}/retry-failed");
        $res->assertSessionHas('notice');

        // Verify ONLY f3 is requeued; f1 and f2 are untouched!
        $n1 = DB::table('notifications')->where('invoice_id', $f1['invId'])->first();
        $n2 = DB::table('notifications')->where('invoice_id', $f2['invId'])->first();
        $n3 = DB::table('notifications')->where('invoice_id', $f3['invId'])->first();

        $this->assertSame('submitted', $n1->status);
        $this->assertSame('delivered', $n2->status);
        $this->assertSame('queued', $n3->status);
    }

    /**
     * 10. Test preserves sibling consolidation and previous dues without ledger mutations.
     */
    public function test_preserves_sibling_consolidation_and_previous_dues_without_ledger_mutation(): void
    {
        $data = $this->setupTestData();
        Queue::fake([DispatchBatchToN8n::class]);

        // Family with 2 students
        $f = $this->createFamilyWithInvoice($data, 9101, 'Grade 8', true, true, 8000, 0, 'SAR', null, null, '2026-10');

        // Add second sibling
        $sibId = DB::table('students')->insertGetId([
            'family_id' => $f['famId'],
            'name' => 'Sibling Student',
            'gr_number' => 'GR-SIB-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('enrollments')->insert([
            'student_id' => $sibId,
            'campus_id' => $data['campus'],
            'department_id' => $data['dept'],
            'program_id' => $data['program'],
            'grade_label' => 'Grade 6',
            'currency' => 'SAR',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Add older unpaid invoice for September (previous dues)
        $sepRun = DB::table('billing_runs')->insertGetId([
            'campus_id' => $data['campus'],
            'department_id' => $data['dept'],
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-09-01..2026-09-30:old',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'due_date' => '2026-09-25',
            'created_by' => $data['superAdmin']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $oldInvId = DB::table('invoices')->insertGetId([
            'billing_run_id' => $sepRun,
            'family_id' => $f['famId'],
            'campus_id' => $data['campus'],
            'department_id' => $data['dept'],
            'currency' => 'SAR',
            'invoice_number' => 'INV-OLD-9101',
            'issued_on' => '2026-09-01',
            'due_date' => '2026-09-25',
            'total_minor' => 5000,
            'paid_minor' => 0,
            'status' => 'unpaid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('invoice_items')->insert([
            'invoice_id' => $oldInvId,
            'student_id' => $f['stuId'],
            'period_key' => 'monthly:2026-09-01..2026-09-30:old9101',
            'currency' => 'SAR',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'description' => 'Tuition Fee 2026-09',
            'amount_minor' => 5000,
            'net_minor' => 5000,
            'paid_minor' => 0,
            'status' => 'unpaid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(BatchDeliveryService::class);
        $b = $service->createAndDispatchBatch(['webhook_type' => 'monthly', 'grade' => 'Grade 8'], ['Grade 8'], ['whatsapp'], $data['superAdmin'], '2026-10');

        $queueService = app(InvoiceDeliveryQueueService::class);
        $page = $queueService->getPaginatedQueue(['batch_id' => $b['batch_id']], $data['superAdmin']);
        $row = $page->first();

        // Both siblings present
        $this->assertCount(2, $row->siblings_list);

        // Previous dues accurately calculated (5000 minor)
        $this->assertSame(5000, $row->previous_dues_minor);
        $this->assertSame(8000, $row->remaining_balance_minor);
        $this->assertSame(13000, $row->total_payable_minor);

        // Ledger status of the old invoice remains untouched!
        $oldInv = DB::table('invoices')->where('id', $oldInvId)->first();
        $this->assertSame('unpaid', $oldInv->status);
        $this->assertSame(5000, (int)$oldInv->total_minor);
    }

    /**
     * 11. Test CSV export streams accurate filtered batch and queue records.
     */
    public function test_csv_export_streams_accurate_filtered_queue_records(): void
    {
        $data = $this->setupTestData();
        Queue::fake([DispatchBatchToN8n::class]);

        $f = $this->createFamilyWithInvoice($data, 9201, 'Grade 1');
        $service = app(BatchDeliveryService::class);
        $b = $service->createAndDispatchBatch(['webhook_type' => 'monthly', 'grade' => 'Grade 1'], ['Grade 1'], ['whatsapp'], $data['superAdmin'], '2026-10');

        $res = $this->actingAs($data['superAdmin'])->get("/invoices/queue/export?batch_id={$b['batch_id']}");
        $res->assertStatus(200);
        $res->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $content = $res->streamedContent();
        $this->assertStringContainsString('Notification ID', $content);
        $this->assertStringContainsString('Batch ID', $content);
        $this->assertStringContainsString('Chunk Index', $content);
        $this->assertStringContainsString($b['batch_id'], $content);
        $this->assertStringContainsString('INV-Q-9201', $content);
    }

    /**
     * 12. Test zero unintended financial ledger mutations.
     */
    public function test_zero_unintended_financial_ledger_mutations(): void
    {
        $data = $this->setupTestData();
        Queue::fake([DispatchBatchToN8n::class]);

        $this->createFamilyWithInvoice($data, 9301, 'Grade 1');

        $studentsBefore = DB::table('students')->count();
        $invoicesBefore = DB::table('invoices')->count();
        $itemsBefore = DB::table('invoice_items')->count();
        $paymentsBefore = DB::table('payments')->count();

        $service = app(BatchDeliveryService::class);
        $b = $service->createAndDispatchBatch(['webhook_type' => 'monthly', 'grade' => 'Grade 1'], ['Grade 1'], ['whatsapp'], $data['superAdmin'], '2026-10');

        // View queue, search, filter, export
        $this->actingAs($data['superAdmin'])->get("/invoices/queue?batch_id={$b['batch_id']}");
        $this->actingAs($data['superAdmin'])->get("/invoices/batches/{$b['batch_id']}");
        $this->actingAs($data['superAdmin'])->get("/invoices/queue/export?batch_id={$b['batch_id']}");

        $studentsAfter = DB::table('students')->count();
        $invoicesAfter = DB::table('invoices')->count();
        $itemsAfter = DB::table('invoice_items')->count();
        $paymentsAfter = DB::table('payments')->count();

        $this->assertSame($studentsBefore, $studentsAfter);
        $this->assertSame($invoicesBefore, $invoicesAfter);
        $this->assertSame($itemsBefore, $itemsAfter);
        $this->assertSame($paymentsBefore, $paymentsAfter);
    }
}
