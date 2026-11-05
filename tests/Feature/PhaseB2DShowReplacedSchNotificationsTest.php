<?php

namespace Tests\Feature;

use App\Services\InvoiceDeliveryQueueService;
use App\Services\PdfAccessService;
use App\Services\SafeSchReplacementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PhaseB2DShowReplacedSchNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private object $admin;
    private object $restrictedStaff;
    private int $campusId;
    private int $deptId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deptId = DB::table('departments')->insertGetId([
            'name' => 'Department B2D',
            'code' => 'DEP-B2D',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->campusId = DB::table('campuses')->insertGetId([
            'name' => 'Campus B2D',
            'code' => 'C-B2D',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $adminId = DB::table('users')->insertGetId([
            'name' => 'Finance Admin B2D',
            'email' => 'admin_b2d@iqra.test',
            'password' => bcrypt('secret123'),
            'role' => 'finance_admin',
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->admin = \App\Models\User::find($adminId);

        $staffId = DB::table('users')->insertGetId([
            'name' => 'Campus Staff B2D',
            'email' => 'staff_b2d@iqra.test',
            'password' => bcrypt('secret123'),
            'role' => 'cashier',
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->restrictedStaff = \App\Models\User::find($staffId);
    }

    private function createFamilyWithInvoices(array $overrides = []): array
    {
        $familyCode = 'FAM-B2D-' . uniqid();
        $familyId = DB::table('families')->insertGetId([
            'family_code' => $familyCode,
            'guardian_name' => 'Guardian ' . $familyCode,
            'email' => $familyCode . '@test.com',
            'whatsapp' => '+966500000001',
            'contact_verified' => 1,
            'messaging_opt_in' => 1,
            'whatsapp_verified' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentId = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'name' => 'Student ' . $familyCode,
            'gr_number' => 'GR-' . rand(1000, 9999),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrollmentId = DB::table('enrollments')->insertGetId([
            'student_id' => $studentId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'grade_label' => 'Grade 1',
            'currency' => 'SAR',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $feeTypeId = DB::table('fee_types')->insertGetId([
            'name' => 'Tuition B2D',
            'code' => 'TUI-B2D',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $billingRunId = DB::table('billing_runs')->insertGetId([
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

        $olderInvId = DB::table('invoices')->insertGetId([
            'invoice_number' => 'SCH-B2D-OLD-' . rand(1000, 9999),
            'family_id' => $familyId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'billing_run_id' => $billingRunId,
            'currency' => 'SAR',
            'total_minor' => 50000,
            'paid_minor' => 0,
            'status' => 'unpaid',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-10',
            'created_at' => '2026-10-01 08:00:00',
            'updated_at' => '2026-10-01 08:00:00',
        ]);

        DB::table('invoice_items')->insert([
            'invoice_id' => $olderInvId,
            'student_id' => $studentId,
            'enrollment_id' => $enrollmentId,
            'fee_type_id' => $feeTypeId,
            'description' => 'Tuition October 2026',
            'period_key' => '2026-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'amount_minor' => 50000,
            'net_minor' => 50000,
            'paid_minor' => 0,
            'currency' => 'SAR',
            'status' => 'active',
            'created_at' => '2026-10-01 08:00:00',
            'updated_at' => '2026-10-01 08:00:00',
        ]);

        $batchId = $overrides['batch_id'] ?? 'BATCH-B2D-' . uniqid();
        if (!DB::table('notification_batches')->where('batch_id', $batchId)->exists()) {
            DB::table('notification_batches')->insert([
                'batch_id' => $batchId,
                'billing_period' => '2026-10',
                'webhook_type' => 'monthly',
                'status' => 'queued',
                'total_recipients' => 1,
                'total_invoices' => 1,
                'chunks_total' => 1,
                'chunks_sent' => 0,
                'approved_by' => $this->admin->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $olderNotifId = DB::table('notifications')->insertGetId([
            'invoice_id' => $olderInvId,
            'batch_id' => $batchId,
            'channel' => 'whatsapp',
            'kind' => 'monthly',
            'webhook_type' => 'monthly',
            'status' => 'queued',
            'attempts' => 0,
            'is_superseded' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $newerInvId = DB::table('invoices')->insertGetId([
            'invoice_number' => 'SCH-B2D-NEW-' . rand(1000, 9999),
            'family_id' => $familyId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'billing_run_id' => $billingRunId,
            'currency' => 'SAR',
            'total_minor' => 100000,
            'paid_minor' => 0,
            'status' => 'unpaid',
            'issued_on' => '2026-10-02',
            'due_date' => '2026-10-10',
            'created_at' => '2026-10-02 09:00:00',
            'updated_at' => '2026-10-02 09:00:00',
        ]);

        DB::table('invoice_items')->insert([
            'invoice_id' => $newerInvId,
            'student_id' => $studentId,
            'enrollment_id' => $enrollmentId,
            'fee_type_id' => $feeTypeId,
            'description' => 'Tuition October 2026 Updated',
            'period_key' => '2026-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'amount_minor' => 100000,
            'net_minor' => 100000,
            'paid_minor' => 0,
            'currency' => 'SAR',
            'status' => 'active',
            'created_at' => '2026-10-02 09:00:00',
            'updated_at' => '2026-10-02 09:00:00',
        ]);

        return [
            'family_id' => $familyId,
            'batch_id' => $batchId,
            'older_invoice_id' => $olderInvId,
            'older_invoice_number' => DB::table('invoices')->where('id', $olderInvId)->value('invoice_number'),
            'older_notif_id' => $olderNotifId,
            'newer_invoice_id' => $newerInvId,
            'newer_invoice_number' => DB::table('invoices')->where('id', $newerInvId)->value('invoice_number'),
        ];
    }

    /** 1. Older SCH correctly displayed as superseded and shows original & replacing SCH numbers */
    public function test_older_sch_correctly_displayed_as_superseded_with_both_numbers(): void
    {
        $data = $this->createFamilyWithInvoices();

        // Perform safe replacement
        $replacer = app(SafeSchReplacementService::class);
        $res = $replacer->evaluateAndSupersede($data['older_notif_id'], $data['newer_invoice_id'], $this->admin);
        $this->assertSame('REPLACE', $res['decision']);

        // Check delivery queue view
        $response = $this->actingAs($this->admin)->get('/invoices/queue?batch_id=' . $data['batch_id']);
        $response->assertStatus(200);

        // Verify superseded status display
        $response->assertSee('Superseded');
        $response->assertSee('SUPERSEDED');

        // Verify original and newer SCH numbers are displayed
        $response->assertSee($data['older_invoice_number']);
        $response->assertSee($data['newer_invoice_number']);
        $response->assertSee('Replaced by:');
    }

    /** 2. New SCH is correctly linked using authorized invoice route */
    public function test_new_sch_is_correctly_linked_using_authorized_invoice_route(): void
    {
        $data = $this->createFamilyWithInvoices();

        $replacer = app(SafeSchReplacementService::class);
        $replacer->evaluateAndSupersede($data['older_notif_id'], $data['newer_invoice_id'], $this->admin);

        // Verify admin link
        $expectedAdminUrl = app(PdfAccessService::class)->getInvoicePdfUrl((int) $data['newer_invoice_id'], $this->admin);
        $responseAdmin = $this->actingAs($this->admin)->get('/invoices/queue?batch_id=' . $data['batch_id']);
        $responseAdmin->assertStatus(200);
        $responseAdmin->assertSee($expectedAdminUrl, false);

        // Verify restricted staff link
        $expectedStaffUrl = app(PdfAccessService::class)->getInvoicePdfUrl((int) $data['newer_invoice_id'], $this->restrictedStaff);
        $responseStaff = $this->actingAs($this->restrictedStaff)->get('/invoices/queue?batch_id=' . $data['batch_id']);
        $responseStaff->assertStatus(200);
        $responseStaff->assertSee($expectedStaffUrl, false);
    }

    /** 3. Superseded notification cannot accidentally be selected for retry */
    public function test_superseded_notification_cannot_be_retried(): void
    {
        $data = $this->createFamilyWithInvoices();

        $replacer = app(SafeSchReplacementService::class);
        $replacer->evaluateAndSupersede($data['older_notif_id'], $data['newer_invoice_id'], $this->admin);

        // In UI, retry button is not present
        $response = $this->actingAs($this->admin)->get('/invoices/queue?batch_id=' . $data['batch_id']);
        $response->assertStatus(200);
        $response->assertDontSee('/invoices/notifications/' . $data['older_notif_id'] . '/retry');

        // Direct POST to retry endpoint is blocked
        $retryResponse = $this->actingAs($this->admin)->post('/invoices/notifications/' . $data['older_notif_id'] . '/retry');
        $retryResponse->assertRedirect();
        $retryResponse->assertSessionHas('error', 'Superseded notifications cannot be retried.');
    }

    /** 4. Queue counters exclude superseded records from active counts */
    public function test_queue_counters_exclude_superseded_notifications(): void
    {
        // Batch with Family 1 (to be superseded) and Family 2 (active queued)
        $batchId = 'BATCH-STATS-' . uniqid();
        $data1 = $this->createFamilyWithInvoices(['batch_id' => $batchId]);
        $data2 = $this->createFamilyWithInvoices(['batch_id' => $batchId]);

        // Supersede Family 1
        $replacer = app(SafeSchReplacementService::class);
        $replacer->evaluateAndSupersede($data1['older_notif_id'], $data1['newer_invoice_id'], $this->admin);

        $queueService = app(InvoiceDeliveryQueueService::class);
        $stats = $queueService->getQueueStats(['batch_id' => $batchId], $this->admin);

        $this->assertEquals(2, $stats['total']);
        $this->assertEquals(1, $stats['queued']);
        $this->assertEquals(0, $stats['submitted']);
        $this->assertEquals(1, $stats['superseded']);
    }

    /** 5. Ready to Send shows one consolidated family entry with latest authoritative SCH */
    public function test_ready_to_send_shows_one_family_entry_with_latest_authoritative_sch(): void
    {
        $data = $this->createFamilyWithInvoices();

        // Perform safe replacement of older notification
        $replacer = app(SafeSchReplacementService::class);
        $replacer->evaluateAndSupersede($data['older_notif_id'], $data['newer_invoice_id'], $this->admin);

        $queueService = app(InvoiceDeliveryQueueService::class);
        $challans = $queueService->getReadyToSendChallans(['period' => '2026-10'], $this->admin);

        $matchingItems = collect($challans->items())->filter(fn($r) => (int)$r->family_id === (int)$data['family_id']);
        $this->assertCount(1, $matchingItems);

        $familyRow = $matchingItems->first();
        // Authoritative reference is the newest invoice (created later)
        $this->assertEquals($data['newer_invoice_id'], $familyRow->invoice_id);
        $this->assertEquals($data['newer_invoice_number'], $familyRow->invoice_number);
    }

    /** 6. Ready to Send families are ordered by authoritative SCH created_at DESC, id DESC */
    public function test_ready_to_send_families_ordered_by_authoritative_sch_created_at_desc_and_id_desc(): void
    {
        // Create 2 families
        $dataA = $this->createFamilyWithInvoices();
        $dataB = $this->createFamilyWithInvoices();

        // Perform safe replacement of older notifications so newer invoices are Ready to Send
        $replacer = app(SafeSchReplacementService::class);
        $replacer->evaluateAndSupersede($dataA['older_notif_id'], $dataA['newer_invoice_id'], $this->admin);
        $replacer->evaluateAndSupersede($dataB['older_notif_id'], $dataB['newer_invoice_id'], $this->admin);

        // Set explicit created_at timestamps on their authoritative invoices
        DB::table('invoices')->where('id', $dataA['newer_invoice_id'])->update([
            'created_at' => '2026-10-15 12:00:00',
        ]);
        DB::table('invoices')->where('id', $dataB['newer_invoice_id'])->update([
            'created_at' => '2026-10-15 14:00:00',
        ]);

        $queueService = app(InvoiceDeliveryQueueService::class);
        $challans = $queueService->getReadyToSendChallans(['period' => '2026-10'], $this->admin, 25);

        $items = collect($challans->items());
        $indexA = $items->search(fn($r) => (int)$r->family_id === (int)$dataA['family_id']);
        $indexB = $items->search(fn($r) => (int)$r->family_id === (int)$dataB['family_id']);

        $this->assertNotFalse($indexA);
        $this->assertNotFalse($indexB);
        // B (14:00) must appear before A (12:00)
        $this->assertLessThan($indexA, $indexB);
    }

    /** 7. Batch where every notification is superseded displays accurate Superseded status in UI */
    public function test_batch_where_every_notification_is_superseded_displays_superseded_in_ui(): void
    {
        $data = $this->createFamilyWithInvoices();

        $replacer = app(SafeSchReplacementService::class);
        $replacer->evaluateAndSupersede($data['older_notif_id'], $data['newer_invoice_id'], $this->admin);

        $queueService = app(InvoiceDeliveryQueueService::class);
        $batchDetails = $queueService->getBatchDetails($data['batch_id'], $this->admin);

        $this->assertNotNull($batchDetails);
        $this->assertTrue($batchDetails['batch']->is_all_superseded);
        $this->assertEquals('superseded', $batchDetails['batch']->display_status);

        // In batch details view
        $response = $this->actingAs($this->admin)->get('/invoices/batches/' . $data['batch_id']);
        $response->assertStatus(200);
        $response->assertSee('SUPERSEDED');
        $response->assertSee('All notifications in this batch were superseded');
    }

    /** 8. Zero changes to invoice and receipt financial amounts */
    public function test_zero_changes_to_invoice_or_receipt_financial_amounts(): void
    {
        $data = $this->createFamilyWithInvoices();

        $oldInvPre = DB::table('invoices')->where('id', $data['older_invoice_id'])->first();
        $newInvPre = DB::table('invoices')->where('id', $data['newer_invoice_id'])->first();

        // Perform view operations and replacement
        $replacer = app(SafeSchReplacementService::class);
        $replacer->evaluateAndSupersede($data['older_notif_id'], $data['newer_invoice_id'], $this->admin);

        $this->actingAs($this->admin)->get('/invoices/queue');
        $this->actingAs($this->admin)->get('/invoices/batches/' . $data['batch_id']);
        $this->actingAs($this->admin)->get('/invoices?tab=ready&period=2026-10');

        $oldInvPost = DB::table('invoices')->where('id', $data['older_invoice_id'])->first();
        $newInvPost = DB::table('invoices')->where('id', $data['newer_invoice_id'])->first();

        $this->assertEquals($oldInvPre->total_minor, $oldInvPost->total_minor);
        $this->assertEquals($oldInvPre->paid_minor, $oldInvPost->paid_minor);
        $this->assertEquals($newInvPre->total_minor, $newInvPost->total_minor);
        $this->assertEquals($newInvPre->paid_minor, $newInvPost->paid_minor);
    }
}
