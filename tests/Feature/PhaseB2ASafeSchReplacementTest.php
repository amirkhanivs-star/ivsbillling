<?php

namespace Tests\Feature;

use App\Jobs\DispatchBatchToN8n;
use App\Services\BatchDispatchLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\SafeSchReplacementDecisionHelper;
use Tests\TestCase;

class PhaseB2ASafeSchReplacementTest extends TestCase
{
    use RefreshDatabase;

    private SafeSchReplacementDecisionHelper $helper;
    private object $admin;
    private object $deptManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->helper = new SafeSchReplacementDecisionHelper();

        // Create authorized admin user
        $adminId = DB::table('users')->insertGetId([
            'name' => 'Finance Admin User',
            'email' => 'admin_test_b2a@iqra.test',
            'password' => bcrypt('secret123'),
            'role' => 'finance_admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->admin = DB::table('users')->where('id', $adminId)->first();

        // Create department first for foreign key constraint
        $deptId = DB::table('departments')->insertGetId([
            'name' => 'Department B2A',
            'code' => 'DEP-B2A',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create restricted department manager
        $deptManagerId = DB::table('users')->insertGetId([
            'name' => 'Dept Manager User',
            'email' => 'dept_manager_test_b2a@iqra.test',
            'password' => bcrypt('secret123'),
            'role' => 'department_manager',
            'department_id' => $deptId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->deptManager = DB::table('users')->where('id', $deptManagerId)->first();
    }

    private function createFamilyWithInvoices(array $options = []): array
    {
        $currency = $options['currency'] ?? 'SAR';
        $familyCode = $options['family_code'] ?? 'FAM-B2A-' . uniqid();
        $familyId = DB::table('families')->insertGetId([
            'family_code' => $familyCode,
            'guardian_name' => 'Guardian ' . $familyCode,
            'email' => $familyCode . '@test.com',
            'whatsapp' => '+966500000001',
            'contact_verified' => 1,
            'messaging_opt_in' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $campusId = DB::table('campuses')->insertGetId([
            'name' => 'Campus ' . uniqid(),
            'code' => 'C-' . uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentId = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'name' => 'Student B2A',
            'gr_number' => 'GR-' . uniqid(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrollmentId = DB::table('enrollments')->insertGetId([
            'student_id' => $studentId,
            'campus_id' => $campusId,
            'department_id' => $this->deptManager->department_id,
            'status' => 'enrolled',
            'currency' => $currency,
            'monthly_fee_minor' => 50000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $feeTypeId = DB::table('fee_types')->insertGetId([
            'name' => 'Tuition Fee',
            'code' => 'TUI-' . uniqid(),
            'department_id' => $this->deptManager->department_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $billingRunId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $campusId,
            'department_id' => $this->deptManager->department_id,
            'frequency' => 'monthly',
            'period_key' => '2026-09',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'due_date' => '2026-09-10',
            'status' => 'completed',
            'created_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Older Invoice (e.g. Month 1)
        $olderAmount = $options['older_amount'] ?? 50000;
        $olderPaid = $options['older_paid'] ?? 0;
        $olderStatus = $options['older_status'] ?? 'unpaid';
        $olderDate = $options['older_date'] ?? '2026-09-01 10:00:00';

        $olderInvId = DB::table('invoices')->insertGetId([
            'billing_run_id' => $billingRunId,
            'family_id' => $familyId,
            'campus_id' => $campusId,
            'department_id' => $this->deptManager->department_id,
            'invoice_number' => 'SCH-B2A-OLD-' . uniqid(),
            'currency' => $currency,
            'total_minor' => $olderAmount,
            'paid_minor' => $olderPaid,
            'status' => $olderStatus,
            'issued_on' => '2026-09-01',
            'due_date' => '2026-09-10',
            'created_at' => $olderDate,
            'updated_at' => $olderDate,
        ]);

        DB::table('invoice_items')->insert([
            'invoice_id' => $olderInvId,
            'student_id' => $studentId,
            'enrollment_id' => $enrollmentId,
            'fee_type_id' => $feeTypeId,
            'description' => 'Tuition Fee 2026-09',
            'period_key' => '2026-09',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'amount_minor' => $olderAmount,
            'net_minor' => $olderAmount,
            'paid_minor' => $olderPaid,
            'currency' => $currency,
            'status' => 'active',
            'created_at' => $olderDate,
            'updated_at' => $olderDate,
        ]);

        // Newer Consolidated Invoice (e.g. Month 2)
        $newerAmount = $options['newer_amount'] ?? 100000;
        $newerPaid = $options['newer_paid'] ?? 0;
        $newerStatus = $options['newer_status'] ?? 'unpaid';
        $newerCurrency = $options['newer_currency'] ?? $currency;
        $newerDate = $options['newer_date'] ?? '2026-10-01 10:00:00';

        $newerInvId = DB::table('invoices')->insertGetId([
            'billing_run_id' => $billingRunId,
            'family_id' => $options['newer_family_id'] ?? $familyId,
            'campus_id' => $campusId,
            'department_id' => $this->deptManager->department_id,
            'invoice_number' => 'SCH-B2A-NEW-' . uniqid(),
            'currency' => $newerCurrency,
            'total_minor' => $newerAmount,
            'paid_minor' => $newerPaid,
            'status' => $newerStatus,
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-10',
            'created_at' => $newerDate,
            'updated_at' => $newerDate,
        ]);

        DB::table('invoice_items')->insert([
            'invoice_id' => $newerInvId,
            'student_id' => $studentId,
            'enrollment_id' => $enrollmentId,
            'fee_type_id' => $feeTypeId,
            'description' => 'Tuition Fee 2026-10',
            'period_key' => '2026-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'amount_minor' => $newerAmount,
            'net_minor' => $newerAmount,
            'paid_minor' => $newerPaid,
            'currency' => $newerCurrency,
            'status' => 'active',
            'created_at' => $newerDate,
            'updated_at' => $newerDate,
        ]);

        // Older Notification
        $batchId = $options['batch_id'] ?? 'BATCH-B2A-' . uniqid();
        $notifStatus = $options['notif_status'] ?? 'queued';
        $notifAttempts = $options['notif_attempts'] ?? 0;
        $notifLastAttempt = $options['notif_last_attempt'] ?? null;
        $notifSubmitted = $options['notif_submitted'] ?? null;
        $isSuperseded = $options['is_superseded'] ?? false;

        $olderNotifId = DB::table('notifications')->insertGetId([
            'invoice_id' => $olderInvId,
            'channel' => 'whatsapp',
            'kind' => 'monthly',
            'webhook_type' => 'monthly',
            'event_type' => 'invoice.monthly',
            'status' => $notifStatus,
            'batch_id' => $batchId,
            'chunk_index' => 1,
            'attempts' => $notifAttempts,
            'last_attempt_at' => $notifLastAttempt,
            'submitted_at' => $notifSubmitted,
            'is_superseded' => $isSuperseded,
            'created_at' => $olderDate,
            'updated_at' => $olderDate,
        ]);

        // Create or update notification batch
        DB::table('notification_batches')->updateOrInsert(
            ['batch_id' => $batchId],
            [
                'webhook_type' => 'monthly',
                'status' => $options['batch_status'] ?? 'queued',
                'total_recipients' => 1,
                'total_invoices' => 1,
                'approved_by' => $this->admin->id,
                'created_at' => $olderDate,
                'updated_at' => $olderDate,
            ]
        );

        return [
            'family_id' => $familyId,
            'older_invoice_id' => $olderInvId,
            'newer_invoice_id' => $newerInvId,
            'older_notif_id' => $olderNotifId,
            'batch_id' => $batchId,
            'student_id' => $studentId,
        ];
    }

    /** Test 1: Older queued SCH with zero attempts and no active worker -> REPLACED */
    public function test_01_older_queued_sch_zero_attempts_no_active_worker_is_replaced(): void
    {
        $data = $this->createFamilyWithInvoices();

        $result = $this->helper->evaluateAndSupersede(
            $data['older_notif_id'],
            $data['newer_invoice_id'],
            $this->admin
        );

        $this->assertSame('REPLACE', $result['decision']);
        $this->assertTrue($result['replaced']);
        $this->assertTrue($result['lock_acquired']);

        $notif = DB::table('notifications')->where('id', $data['older_notif_id'])->first();
        $this->assertSame('superseded', $notif->status);
        $this->assertTrue((bool) $notif->is_superseded);
        $this->assertSame($data['newer_invoice_id'], (int) $notif->superseded_by_invoice_id);

        // Financial invoice must remain unchanged
        $inv = DB::table('invoices')->where('id', $data['older_invoice_id'])->first();
        $this->assertSame('unpaid', $inv->status);
        $this->assertSame(50000, (int) $inv->total_minor);
    }

    /** Test 2: Worker already holding the batch lock -> NO_REPLACE */
    public function test_02_worker_already_holding_batch_lock_prevents_replacement(): void
    {
        $data = $this->createFamilyWithInvoices();

        // Worker acquires the lock on the batch
        $workerLock = app(BatchDispatchLock::class);
        $this->assertTrue($workerLock->acquire($data['batch_id']));

        // Attempt replacement while lock is held
        $result = $this->helper->evaluateAndSupersede(
            $data['older_notif_id'],
            $data['newer_invoice_id'],
            $this->admin
        );

        $this->assertSame('NO_REPLACE', $result['decision']);
        $this->assertFalse($result['replaced']);
        $this->assertFalse($result['lock_acquired']);
        $this->assertStringContainsString('BatchDispatchLock', $result['reason']);

        // Notification must remain queued
        $notif = DB::table('notifications')->where('id', $data['older_notif_id'])->first();
        $this->assertSame('queued', $notif->status);
        $this->assertFalse((bool) $notif->is_superseded);

        $workerLock->release();
    }

    /** Test 3: Worker claiming the notification concurrently (state changes before lock) -> NO_REPLACE */
    public function test_03_worker_claiming_notification_concurrently_prevents_replacement(): void
    {
        $data = $this->createFamilyWithInvoices([
            'notif_status' => 'processing',
            'notif_attempts' => 1,
            'notif_last_attempt' => now(),
        ]);

        $result = $this->helper->evaluateAndSupersede(
            $data['older_notif_id'],
            $data['newer_invoice_id'],
            $this->admin
        );

        $this->assertSame('NO_REPLACE', $result['decision']);
        $this->assertFalse($result['replaced']);
        $this->assertStringContainsString('expected exactly \'queued\'', $result['reason']);
    }

    /** Test 4: Notification already processing -> NO_REPLACE */
    public function test_04_notification_already_processing_blocks_replacement(): void
    {
        $data = $this->createFamilyWithInvoices(['notif_status' => 'processing']);

        $result = $this->helper->evaluateAndSupersede(
            $data['older_notif_id'],
            $data['newer_invoice_id'],
            $this->admin
        );

        $this->assertSame('NO_REPLACE', $result['decision']);
        $this->assertFalse($result['replaced']);
    }

    /** Test 5: Notification submitted, delivered, or read -> NO_REPLACE */
    public function test_05_notification_submitted_delivered_read_blocks_replacement(): void
    {
        foreach (['submitted', 'delivered', 'read'] as $terminalStatus) {
            $data = $this->createFamilyWithInvoices([
                'notif_status' => $terminalStatus,
                'notif_submitted' => now(),
                'notif_attempts' => 1,
            ]);

            $result = $this->helper->evaluateAndSupersede(
                $data['older_notif_id'],
                $data['newer_invoice_id'],
                $this->admin
            );

            $this->assertSame('NO_REPLACE', $result['decision']);
            $this->assertFalse($result['replaced']);

            // Ensure notification status remained unaltered
            $notif = DB::table('notifications')->where('id', $data['older_notif_id'])->first();
            $this->assertSame($terminalStatus, $notif->status);
        }
    }

    /** Test 6: Notification failed or blocked -> NO_REPLACE */
    public function test_06_notification_failed_or_blocked_blocks_replacement(): void
    {
        foreach (['failed', 'blocked'] as $nonQueuedStatus) {
            $data = $this->createFamilyWithInvoices(['notif_status' => $nonQueuedStatus]);

            $result = $this->helper->evaluateAndSupersede(
                $data['older_notif_id'],
                $data['newer_invoice_id'],
                $this->admin
            );

            $this->assertSame('NO_REPLACE', $result['decision']);
            $this->assertFalse($result['replaced']);
        }
    }

    /** Test 7: Older queued notification with previous attempts -> NO_REPLACE */
    public function test_07_older_queued_notification_with_previous_attempts_blocks_replacement(): void
    {
        $data = $this->createFamilyWithInvoices([
            'notif_status' => 'queued',
            'notif_attempts' => 1,
            'notif_last_attempt' => now()->subMinutes(10),
        ]);

        $result = $this->helper->evaluateAndSupersede(
            $data['older_notif_id'],
            $data['newer_invoice_id'],
            $this->admin
        );

        $this->assertSame('NO_REPLACE', $result['decision']);
        $this->assertFalse($result['replaced']);
        $this->assertStringContainsString('attempts count is 1', $result['reason']);
    }

    /** Test 8: Different currencies -> NO_REPLACE */
    public function test_08_different_currencies_blocks_replacement(): void
    {
        $data = $this->createFamilyWithInvoices([
            'currency' => 'SAR',
            'newer_currency' => 'AED',
        ]);

        $result = $this->helper->evaluateAndSupersede(
            $data['older_notif_id'],
            $data['newer_invoice_id'],
            $this->admin
        );

        $this->assertSame('NO_REPLACE', $result['decision']);
        $this->assertFalse($result['replaced']);
        $this->assertStringContainsString('currency mismatch', $result['reason']);
    }

    /** Test 9: Different or unauthorized campus/department scope -> NO_REPLACE */
    public function test_09_unauthorized_campus_department_scope_blocks_replacement(): void
    {
        $data = $this->createFamilyWithInvoices();

        // Department manager attempting to supersede full family statement
        $result = $this->helper->evaluateAndSupersede(
            $data['older_notif_id'],
            $data['newer_invoice_id'],
            $this->deptManager
        );

        $this->assertSame('NO_REPLACE', $result['decision']);
        $this->assertFalse($result['replaced']);
        $this->assertStringContainsString('Unauthorized campus/department scope', $result['reason']);
    }

    /** Test 10: Invalid consolidated financial totals (zero total or fully paid) -> NO_REPLACE */
    public function test_10_invalid_consolidated_financial_totals_blocks_replacement(): void
    {
        $data = $this->createFamilyWithInvoices([
            'newer_amount' => 50000,
            'newer_paid' => 50000,
            'newer_status' => 'paid',
        ]);

        $result = $this->helper->evaluateAndSupersede(
            $data['older_notif_id'],
            $data['newer_invoice_id'],
            $this->admin
        );

        $this->assertSame('NO_REPLACE', $result['decision']);
        $this->assertFalse($result['replaced']);
        $this->assertStringContainsString('Invalid consolidated financial totals', $result['reason']);
    }

    /** Test 11: New SCH that does not include older outstanding obligations -> NO_REPLACE */
    public function test_11_new_sch_not_including_older_outstanding_obligations_blocks_replacement(): void
    {
        // Older invoice has 100,000 minor outstanding; candidate invoice only has 40,000 minor
        $data = $this->createFamilyWithInvoices([
            'older_amount' => 100000,
            'newer_amount' => 40000,
        ]);

        $result = $this->helper->evaluateAndSupersede(
            $data['older_notif_id'],
            $data['newer_invoice_id'],
            $this->admin
        );

        $this->assertSame('NO_REPLACE', $result['decision']);
        $this->assertFalse($result['replaced']);
        $this->assertStringContainsString('does not encompass older outstanding obligations', $result['reason']);
    }

    /** Test 12: Two replacement requests occurring simultaneously -> Lock serializes or rejects */
    public function test_12_simultaneous_replacement_requests_are_serialized_by_lock(): void
    {
        $data = $this->createFamilyWithInvoices();

        // Simulate request 1 acquiring lock
        $lock1 = app(BatchDispatchLock::class);
        $this->assertTrue($lock1->acquire($data['batch_id']));

        // Request 2 attempts evaluateAndSupersede
        $result2 = $this->helper->evaluateAndSupersede(
            $data['older_notif_id'],
            $data['newer_invoice_id'],
            $this->admin
        );
        $this->assertSame('NO_REPLACE', $result2['decision']);
        $this->assertFalse($result2['replaced']);

        // Lock 1 releases and completes replacement
        $lock1->release();

        $result1 = $this->helper->evaluateAndSupersede(
            $data['older_notif_id'],
            $data['newer_invoice_id'],
            $this->admin
        );
        $this->assertSame('REPLACE', $result1['decision']);
        $this->assertTrue($result1['replaced']);

        // Subsequent call on already superseded notification -> NO_REPLACE
        $result3 = $this->helper->evaluateAndSupersede(
            $data['older_notif_id'],
            $data['newer_invoice_id'],
            $this->admin
        );
        $this->assertSame('NO_REPLACE', $result3['decision']);
        $this->assertFalse($result3['replaced']);
    }

    /** Test 13: Two newer SCH records with identical creation timestamps -> Disambiguated by id DESC */
    public function test_13_identical_creation_timestamps_disambiguated_by_id_desc(): void
    {
        $data = $this->createFamilyWithInvoices(['newer_date' => '2026-10-01 10:00:00']);

        $olderInv = DB::table('invoices')->where('id', $data['older_invoice_id'])->first();

        // Create second newer invoice with exact same created_at but higher ID
        $newer2InvId = DB::table('invoices')->insertGetId([
            'billing_run_id' => $olderInv->billing_run_id,
            'family_id' => $data['family_id'],
            'campus_id' => $olderInv->campus_id,
            'department_id' => $olderInv->department_id,
            'invoice_number' => 'SCH-B2A-NEW-2',
            'currency' => 'SAR',
            'total_minor' => 120000,
            'paid_minor' => 0,
            'status' => 'unpaid',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-10',
            'created_at' => '2026-10-01 10:00:00',
            'updated_at' => '2026-10-01 10:00:00',
        ]);

        $firstItem = DB::table('invoice_items')->where('invoice_id', $data['older_invoice_id'])->first();

        DB::table('invoice_items')->insert([
            'invoice_id' => $newer2InvId,
            'student_id' => $data['student_id'],
            'enrollment_id' => $firstItem->enrollment_id,
            'fee_type_id' => $firstItem->fee_type_id,
            'description' => 'Tuition Fee 2026-10-2',
            'period_key' => '2026-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'amount_minor' => 120000,
            'net_minor' => 120000,
            'paid_minor' => 0,
            'currency' => 'SAR',
            'status' => 'active',
            'created_at' => '2026-10-01 10:00:00',
            'updated_at' => '2026-10-01 10:00:00',
        ]);

        // Trying to use lower ID ($newer_invoice_id) should fail because it's not the authoritative newest (newer2 has higher ID)
        $resultLower = $this->helper->evaluateAndSupersede(
            $data['older_notif_id'],
            $data['newer_invoice_id'],
            $this->admin
        );
        $this->assertSame('NO_REPLACE', $resultLower['decision']);
        $this->assertFalse($resultLower['replaced']);
        $this->assertSame($newer2InvId, $resultLower['authoritative_invoice_id']);

        // Using higher ID ($newer2InvId) succeeds
        $resultHigher = $this->helper->evaluateAndSupersede(
            $data['older_notif_id'],
            $newer2InvId,
            $this->admin
        );
        $this->assertSame('REPLACE', $resultHigher['decision']);
        $this->assertTrue($resultHigher['replaced']);
    }

    /** Test 14: Older notification belonging to a batch containing other families -> Other families preserved */
    public function test_14_superseding_one_notification_in_multi_family_batch_preserves_others(): void
    {
        $data1 = $this->createFamilyWithInvoices();
        $batchId = $data1['batch_id'];

        // Add Family 2 to the same batch
        $data2 = $this->createFamilyWithInvoices(['batch_id' => $batchId]);

        // Supersede notification 1
        $result = $this->helper->evaluateAndSupersede(
            $data1['older_notif_id'],
            $data1['newer_invoice_id'],
            $this->admin
        );
        $this->assertSame('REPLACE', $result['decision']);

        // Verify notification 1 is superseded
        $notif1 = DB::table('notifications')->where('id', $data1['older_notif_id'])->first();
        $this->assertSame('superseded', $notif1->status);
        $this->assertTrue((bool) $notif1->is_superseded);

        // Verify notification 2 in same batch remains queued and intact
        $notif2 = DB::table('notifications')->where('id', $data2['older_notif_id'])->first();
        $this->assertSame('queued', $notif2->status);
        $this->assertFalse((bool) $notif2->is_superseded);
        $this->assertSame($batchId, $notif2->batch_id);
    }

    /** Test 15: All notifications in a batch becoming superseded -> Batch reconciliation */
    public function test_15_all_notifications_in_batch_becoming_superseded(): void
    {
        // Setup webhook settings so batch is not blocked due to disabled webhooks
        DB::table('webhook_settings')->updateOrInsert(['webhook_type' => 'monthly'], [
            'url' => 'https://webhook.test/monthly',
            'is_enabled' => 1,
            'secret' => 'test-secret',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $data = $this->createFamilyWithInvoices();

        $result = $this->helper->evaluateAndSupersede(
            $data['older_notif_id'],
            $data['newer_invoice_id'],
            $this->admin
        );
        $this->assertSame('REPLACE', $result['decision']);

        // When batch worker runs on a batch where all notifications are superseded,
        // DispatchBatchToN8n pendingNotifications() returns empty, and reconcileBatch() executes.
        Http::fake();
        $job = new DispatchBatchToN8n($data['batch_id']);
        $job->handle();

        Http::assertNothingSent();

        // Batch status becomes 'submitted' under existing match expression (since none processing/queued/failed/blocked)
        $batch = DB::table('notification_batches')->where('batch_id', $data['batch_id'])->first();
        $this->assertSame('submitted', $batch->status);
    }

    /** Test 16: Previously superseded notification appearing in a worker retry -> Safely skipped */
    public function test_16_previously_superseded_notification_in_worker_retry_is_safely_skipped(): void
    {
        $data = $this->createFamilyWithInvoices();

        // Supersede notification
        $this->helper->evaluateAndSupersede(
            $data['older_notif_id'],
            $data['newer_invoice_id'],
            $this->admin
        );

        // Worker attempts to process
        Http::fake();
        $job = new DispatchBatchToN8n($data['batch_id']);
        $job->handle();

        // Zero webhook calls made
        Http::assertNothingSent();

        $notif = DB::table('notifications')->where('id', $data['older_notif_id'])->first();
        $this->assertSame('superseded', $notif->status);
    }

    /** Test 17: Existing authorized manual resend -> Not superseded if attempts > 0 */
    public function test_17_authorized_manual_resend_with_attempts_is_not_superseded(): void
    {
        $data = $this->createFamilyWithInvoices([
            'notif_status' => 'queued',
            'notif_attempts' => 1,
            'notif_last_attempt' => now()->subHour(),
        ]);

        $result = $this->helper->evaluateAndSupersede(
            $data['older_notif_id'],
            $data['newer_invoice_id'],
            $this->admin
        );

        $this->assertSame('NO_REPLACE', $result['decision']);
        $this->assertFalse($result['replaced']);
    }

    /** Test 18: Existing failed-delivery retry -> Cannot be silently superseded */
    public function test_18_existing_failed_delivery_retry_cannot_be_silently_superseded(): void
    {
        $data = $this->createFamilyWithInvoices([
            'notif_status' => 'failed',
            'notif_attempts' => 2,
            'notif_last_attempt' => now()->subMinutes(30),
        ]);

        $result = $this->helper->evaluateAndSupersede(
            $data['older_notif_id'],
            $data['newer_invoice_id'],
            $this->admin
        );

        $this->assertSame('NO_REPLACE', $result['decision']);
        $this->assertFalse($result['replaced']);
    }

    /** Test 19: No duplicate outbound webhook request */
    public function test_19_no_duplicate_outbound_webhook_request(): void
    {
        Http::fake(['*' => Http::response(['message_id' => 'MSG-123'], 200)]);

        // Setup webhook settings
        DB::table('webhook_settings')->updateOrInsert(['webhook_type' => 'monthly'], [
            'url' => 'https://webhook.test/monthly',
            'is_enabled' => 1,
            'secret' => 'test-secret',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $data1 = $this->createFamilyWithInvoices();
        $batchId = $data1['batch_id'];

        // Add Family 2 in same batch
        $data2 = $this->createFamilyWithInvoices(['batch_id' => $batchId]);

        // Supersede Family 1
        $this->helper->evaluateAndSupersede(
            $data1['older_notif_id'],
            $data1['newer_invoice_id'],
            $this->admin
        );

        // Run batch dispatch job
        $job = new DispatchBatchToN8n($batchId);
        $job->handle();

        // Exactly 1 webhook request should have been dispatched (for Family 2 only)
        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($data1, $data2) {
            $payload = json_decode($request->body(), true);
            $invoices = $payload['invoices'] ?? [];
            $invIds = array_column($invoices, 'invoice_id');

            // Must contain Family 2's invoice and MUST NOT contain Family 1's superseded invoice
            return in_array($data2['older_invoice_id'], $invIds, true)
                && !in_array($data1['older_invoice_id'], $invIds, true);
        });
    }

    /** Test 20: No modification to financial invoices, receipts, or payment allocations */
    public function test_20_no_modification_to_financial_invoices_receipts_or_allocations(): void
    {
        $data = $this->createFamilyWithInvoices();

        $olderItem = DB::table('invoice_items')->where('invoice_id', $data['older_invoice_id'])->first();

        // Add a receipt and payment allocation for older invoice
        $paymentId = DB::table('payments')->insertGetId([
            'family_id' => $data['family_id'],
            'campus_id' => 1,
            'department_id' => $this->deptManager->department_id,
            'receipt_number' => 'RCP-B2A-001',
            'amount_minor' => 10000,
            'currency' => 'SAR',
            'received_on' => '2026-09-05',
            'idempotency_key' => 'idemp-rcp-001',
            'request_hash' => hash('sha256', 'rcp-001'),
            'status' => 'received',
            'received_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $allocId = DB::table('payment_allocations')->insertGetId([
            'payment_id' => $paymentId,
            'invoice_item_id' => $olderItem->id,
            'amount_minor' => 10000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Capture snapshot before replacement
        $olderInvBefore = (array) DB::table('invoices')->where('id', $data['older_invoice_id'])->first();
        $newerInvBefore = (array) DB::table('invoices')->where('id', $data['newer_invoice_id'])->first();
        $paymentBefore = (array) DB::table('payments')->where('id', $paymentId)->first();
        $allocBefore = (array) DB::table('payment_allocations')->where('id', $allocId)->first();

        // Perform replacement
        $result = $this->helper->evaluateAndSupersede(
            $data['older_notif_id'],
            $data['newer_invoice_id'],
            $this->admin
        );
        $this->assertSame('REPLACE', $result['decision']);

        // Compare snapshot after replacement
        $olderInvAfter = (array) DB::table('invoices')->where('id', $data['older_invoice_id'])->first();
        $newerInvAfter = (array) DB::table('invoices')->where('id', $data['newer_invoice_id'])->first();
        $paymentAfter = (array) DB::table('payments')->where('id', $paymentId)->first();
        $allocAfter = (array) DB::table('payment_allocations')->where('id', $allocId)->first();

        $this->assertEquals($olderInvBefore, $olderInvAfter);
        $this->assertEquals($newerInvBefore, $newerInvAfter);
        $this->assertEquals($paymentBefore, $paymentAfter);
        $this->assertEquals($allocBefore, $allocAfter);
    }
}
