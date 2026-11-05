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

class PhaseD4HistoricalPaidInvoicesTest extends TestCase
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
        $deptSchool = DB::table('departments')->where('code', 'school')->value('id');
        $program = DB::table('programs')->value('id');

        $superAdmin = User::create([
            'name' => 'Super Admin Phase D4',
            'email' => 'admin_d4@example.test',
            'password' => bcrypt('password123'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $monthlyUrl = 'https://n8n.example.test/webhook/monthly-invoices';
        $paidUrl = 'https://n8n.example.test/webhook/paid-invoices';
        $secret = 'test-phase-d4-secret';

        $svc = app(WebhookSettingService::class);
        $svc->updateSettings('monthly', ['url' => $monthlyUrl, 'secret' => $secret, 'is_enabled' => true], $superAdmin->id);
        $svc->updateSettings('paid', ['url' => $paidUrl, 'secret' => $secret, 'is_enabled' => true], $superAdmin->id);

        $runSep = DB::table('billing_runs')->insertGetId([
            'campus_id' => $campus1,
            'department_id' => $deptSchool,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-09-01..2026-09-30',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'due_date' => '2026-09-25',
            'created_by' => $superAdmin->id,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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

        return compact('superAdmin', 'campus1', 'deptSchool', 'program', 'runSep', 'runOct', 'runNov', 'monthlyUrl', 'paidUrl', 'secret');
    }

    private function createPaidInvoiceWithReceipt(
        int $billingRunId,
        string $familyCode,
        string $invoiceNumber,
        string $receiptNumber,
        int $amountMinor = 30000,
        string $periodMonth = '2026-10',
        string $receivedOn = '2026-10-15',
        ?string $createdAt = null,
        ?int $familyId = null,
        ?int $studentId = null,
        bool $contactVerified = true
    ): array {
        $created = $createdAt ?? "{$periodMonth}-05 10:00:00";

        if ($familyId) {
            $famId = $familyId;
        } else {
            $famId = DB::table('families')->insertGetId([
                'family_code' => $familyCode,
                'guardian_name' => "Guardian {$familyCode}",
                'whatsapp' => '+96650' . str_pad((string) crc32($familyCode . $invoiceNumber), 7, '0', STR_PAD_LEFT),
                'contact_verified' => $contactVerified,
                'whatsapp_verified' => $contactVerified,
                'messaging_opt_in' => $contactVerified,
                'created_at' => $created,
                'updated_at' => $created,
            ]);
        }

        if ($studentId) {
            $stuId = $studentId;
            $enrId = DB::table('enrollments')->where('student_id', $stuId)->value('id');
        } else {
            $stuId = DB::table('students')->insertGetId([
                'family_id' => $famId,
                'name' => "Student {$familyCode}",
                'gr_number' => 'GR-' . substr(md5($familyCode . $invoiceNumber), 0, 6),
                'status' => 'active',
                'created_at' => $created,
                'updated_at' => $created,
            ]);
            $enrId = DB::table('enrollments')->insertGetId([
                'student_id' => $stuId,
                'campus_id' => $this->env['campus1'],
                'department_id' => $this->env['deptSchool'],
                'program_id' => $this->env['program'],
                'grade_label' => 'Grade 1',
                'currency' => 'SAR',
                'status' => 'active',
                'created_at' => $created,
                'updated_at' => $created,
            ]);
        }

        $feeTypeId = DB::table('fee_types')->where('code', 'monthly')->value('id')
            ?? DB::table('fee_types')->value('id');

        $invId = DB::table('invoices')->insertGetId([
            'family_id' => $famId,
            'campus_id' => $this->env['campus1'],
            'department_id' => $this->env['deptSchool'],
            'billing_run_id' => $billingRunId,
            'invoice_number' => $invoiceNumber,
            'currency' => 'SAR',
            'total_minor' => $amountMinor,
            'paid_minor' => $amountMinor,
            'status' => 'paid',
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
            'paid_minor' => $amountMinor,
            'currency' => 'SAR',
            'period_key' => $periodMonth,
            'period_start' => "{$periodMonth}-01",
            'period_end' => "{$periodMonth}-28",
            'status' => 'paid',
            'created_at' => $created,
            'updated_at' => $created,
        ]);

        $paymentId = DB::table('payments')->insertGetId([
            'family_id' => $famId,
            'campus_id' => $this->env['campus1'],
            'department_id' => $this->env['deptSchool'],
            'receipt_number' => $receiptNumber,
            'received_on' => $receivedOn,
            'amount_minor' => $amountMinor,
            'unallocated_minor' => 0,
            'currency' => 'SAR',
            'status' => 'received',
            'note' => 'Full payment received',
            'idempotency_key' => 'IDEMP-D4-' . $invoiceNumber . '-' . uniqid(),
            'request_hash' => hash('sha256', 'D4-' . $invoiceNumber),
            'received_by' => $this->env['superAdmin']->id,
            'created_at' => $created,
            'updated_at' => $created,
        ]);

        DB::table('payment_allocations')->insert([
            'payment_id' => $paymentId,
            'invoice_item_id' => $itemId,
            'amount_minor' => $amountMinor,
            'created_at' => $created,
            'updated_at' => $created,
        ]);

        return compact('famId', 'stuId', 'enrId', 'invId', 'itemId', 'paymentId', 'receiptNumber', 'invoiceNumber');
    }

    /**
     * Test 1 & 2: October, November, and older historical paid SCH invoices remain visible
     * in all-period mode (/invoices?tab=paid&period=all), ordered by created_at DESC, id DESC.
     */
    public function test_october_november_and_older_historical_paid_invoices_visible_in_all_period_mode(): void
    {
        $sep = $this->createPaidInvoiceWithReceipt(
            $this->env['runSep'],
            'FAM-D4-SEP',
            'SCH-2026-SEP-001',
            'RCP-2026-SEP-001',
            20000,
            '2026-09',
            '2026-10-12',
            '2026-09-10 08:00:00'
        );

        $octSubmitted = $this->createPaidInvoiceWithReceipt(
            $this->env['runOct'],
            'FAM-D4-OCT-SUB',
            'SCH-2026-OCT-001',
            'RCP-2026-OCT-001',
            25000,
            '2026-10',
            '2026-10-18',
            '2026-10-10 09:00:00'
        );
        DB::table('notifications')->insert([
            'invoice_id' => $octSubmitted['invId'],
            'channel' => 'whatsapp',
            'kind' => 'paid',
            'webhook_type' => 'paid',
            'status' => 'submitted',
            'attempts' => 1,
            'submitted_at' => '2026-10-19 10:00:00',
            'created_at' => '2026-10-19 10:00:00',
            'updated_at' => '2026-10-19 10:00:00',
        ]);

        $octQueued = $this->createPaidInvoiceWithReceipt(
            $this->env['runOct'],
            'FAM-D4-OCT-Q',
            'SCH-2026-OCT-002',
            'RCP-2026-OCT-002',
            30000,
            '2026-10',
            '2026-10-20',
            '2026-10-12 09:00:00'
        );
        DB::table('notifications')->insert([
            'invoice_id' => $octQueued['invId'],
            'batch_id' => 'BATCH-OCT-Q',
            'channel' => 'whatsapp',
            'kind' => 'paid',
            'webhook_type' => 'paid',
            'status' => 'queued',
            'attempts' => 0,
            'created_at' => '2026-10-21 10:00:00',
            'updated_at' => '2026-10-21 10:00:00',
        ]);

        $novUnqueued = $this->createPaidInvoiceWithReceipt(
            $this->env['runNov'],
            'FAM-D4-NOV',
            'SCH-2026-NOV-001',
            'RCP-2026-NOV-001',
            35000,
            '2026-11',
            '2026-11-02',
            '2026-11-01 09:00:00'
        );

        // Default Paid tab in all-period mode (no paid_status specified -> defaults to 'all')
        $res = $this->actingAs($this->env['superAdmin'])->get('/invoices?tab=paid&period=all');
        $res->assertOk();

        $this->assertSame('paid', $res->viewData('currentTab'));
        $this->assertSame('all', $res->viewData('currentPaidStatus'));
        $this->assertTrue($res->viewData('isAllPeriod'));

        // All 4 paid SCH invoices across Sep, Oct, Nov must be visible
        $page = $res->viewData('paidInvoicesPage');
        $this->assertSame(4, $page->total());
        $this->assertSame(
            [$novUnqueued['invId'], $octQueued['invId'], $octSubmitted['invId'], $sep['invId']],
            $page->pluck('id')->all()
        );

        // Top KPI and workflow stats must match all-period totals, not just November (1)
        $dashboardStats = $res->viewData('dashboardStats');
        $paidStats = $res->viewData('paidWorkflowStats');
        $this->assertSame(4, $dashboardStats['paid_invoices']);
        $this->assertSame(4, $paidStats['total']);
        $this->assertSame(2, $paidStats['unqueued']); // Nov + Sep
        $this->assertSame(1, $paidStats['queued']);   // Oct queued
        $this->assertSame(1, $paidStats['submitted']); // Oct submitted

        $res->assertSee('SCH-2026-NOV-001');
        $res->assertSee('SCH-2026-OCT-002');
        $res->assertSee('SCH-2026-OCT-001');
        $res->assertSee('SCH-2026-SEP-001');
    }

    /**
     * Test 3 & 7: Each genuine paid SCH appears once with accurate linked RCP receipt allocations
     * and without duplicating payment amounts when multiple items or multiple receipts exist.
     */
    public function test_each_genuine_paid_sch_appears_once_with_accurate_receipt_allocations(): void
    {
        $inv = $this->createPaidInvoiceWithReceipt(
            $this->env['runOct'],
            'FAM-D4-MULTI',
            'SCH-2026-MULTI-001',
            'RCP-2026-PART-A',
            20000,
            '2026-10',
            '2026-10-14',
            '2026-10-05 10:00:00'
        );

        // Add second item and second receipt to the same SCH invoice
        $feeTypeId = DB::table('fee_types')->value('id');
        $item2Id = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $inv['invId'],
            'student_id' => $inv['stuId'],
            'enrollment_id' => $inv['enrId'],
            'fee_type_id' => $feeTypeId,
            'description' => 'Transport Fee 2026-10',
            'amount_minor' => 15000,
            'net_minor' => 15000,
            'paid_minor' => 15000,
            'currency' => 'SAR',
            'period_key' => '2026-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'status' => 'paid',
            'created_at' => '2026-10-05 10:00:00',
            'updated_at' => '2026-10-05 10:00:00',
        ]);

        DB::table('invoices')->where('id', $inv['invId'])->update([
            'total_minor' => 35000,
            'paid_minor' => 35000,
        ]);

        $payment2Id = DB::table('payments')->insertGetId([
            'family_id' => $inv['famId'],
            'campus_id' => $this->env['campus1'],
            'department_id' => $this->env['deptSchool'],
            'receipt_number' => 'RCP-2026-PART-B',
            'received_on' => '2026-10-16',
            'amount_minor' => 15000,
            'unallocated_minor' => 0,
            'currency' => 'SAR',
            'status' => 'received',
            'note' => 'Second installment',
            'idempotency_key' => 'IDEMP-D4-PART-B-' . uniqid(),
            'request_hash' => hash('sha256', 'D4-PART-B'),
            'received_by' => $this->env['superAdmin']->id,
            'created_at' => '2026-10-16 10:00:00',
            'updated_at' => '2026-10-16 10:00:00',
        ]);

        DB::table('payment_allocations')->insert([
            'payment_id' => $payment2Id,
            'invoice_item_id' => $item2Id,
            'amount_minor' => 15000,
            'created_at' => '2026-10-16 10:00:00',
            'updated_at' => '2026-10-16 10:00:00',
        ]);

        // Also add multiple notification records across channels for the same invoice to ensure no row duplication
        DB::table('notifications')->insert([
            [
                'invoice_id' => $inv['invId'],
                'channel' => 'email',
                'kind' => 'paid',
                'webhook_type' => 'paid',
                'status' => 'queued',
                'is_superseded' => true,
                'created_at' => '2026-10-16 11:00:00',
                'updated_at' => '2026-10-16 11:00:00',
            ],
            [
                'invoice_id' => $inv['invId'],
                'channel' => 'whatsapp',
                'kind' => 'paid',
                'webhook_type' => 'paid',
                'status' => 'submitted',
                'is_superseded' => false,
                'created_at' => '2026-10-16 12:00:00',
                'updated_at' => '2026-10-16 12:00:00',
            ],
        ]);

        $res = $this->actingAs($this->env['superAdmin'])->get('/invoices?tab=paid&period=all');
        $res->assertOk();

        $page = $res->viewData('paidInvoicesPage');
        $this->assertSame(1, $page->total());

        $row = $page->first();
        $this->assertSame($inv['invId'], $row->id);
        $this->assertSame(35000, (int) $row->paid_minor);
        $this->assertCount(2, $row->receipts);

        $receiptMap = collect($row->receipts)->keyBy('receipt_number');
        $this->assertSame(20000, $receiptMap['RCP-2026-PART-A']['amount_minor']);
        $this->assertSame('200.00', $receiptMap['RCP-2026-PART-A']['formatted_amount']);
        $this->assertSame(15000, $receiptMap['RCP-2026-PART-B']['amount_minor']);
        $this->assertSame('150.00', $receiptMap['RCP-2026-PART-B']['formatted_amount']);
    }

    /**
     * Test 4, 5 & 6: All Paid Invoices vs Unqueued vs In Queue vs Submitted distinction,
     * accurate counters and pagination, and existing queued/submitted invoices are not resent automatically.
     */
    public function test_all_paid_vs_unqueued_distinction_status_counters_pagination_and_no_automatic_resend(): void
    {
        Queue::fake([DispatchBatchToN8n::class]);
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);

        $octSubmitted = $this->createPaidInvoiceWithReceipt(
            $this->env['runOct'],
            'FAM-D4-S1',
            'SCH-2026-S1',
            'RCP-2026-S1',
            25000,
            '2026-10',
            '2026-10-15',
            '2026-10-05 10:00:00'
        );
        DB::table('notifications')->insert([
            'invoice_id' => $octSubmitted['invId'],
            'batch_id' => 'BATCH-HIST-SUB',
            'channel' => 'whatsapp',
            'kind' => 'paid',
            'webhook_type' => 'paid',
            'status' => 'submitted',
            'attempts' => 1,
            'submitted_at' => '2026-10-16 10:00:00',
            'created_at' => '2026-10-16 10:00:00',
            'updated_at' => '2026-10-16 10:00:00',
        ]);

        $octQueued = $this->createPaidInvoiceWithReceipt(
            $this->env['runOct'],
            'FAM-D4-Q1',
            'SCH-2026-Q1',
            'RCP-2026-Q1',
            25000,
            '2026-10',
            '2026-10-16',
            '2026-10-06 10:00:00'
        );
        DB::table('notification_batches')->insert([
            'batch_id' => 'BATCH-HIST-Q',
            'webhook_type' => 'paid',
            'event_type' => 'invoice.paid.batch',
            'billing_period' => '2026-10',
            'channels' => json_encode(['whatsapp']),
            'status' => 'queued',
            'total_recipients' => 1,
            'total_invoices' => 1,
            'approved_by' => $this->env['superAdmin']->id,
            'created_at' => '2026-10-17 10:00:00',
            'updated_at' => '2026-10-17 10:00:00',
        ]);
        DB::table('notifications')->insert([
            'invoice_id' => $octQueued['invId'],
            'batch_id' => 'BATCH-HIST-Q',
            'channel' => 'whatsapp',
            'kind' => 'paid',
            'webhook_type' => 'paid',
            'status' => 'queued',
            'attempts' => 0,
            'created_at' => '2026-10-17 10:00:00',
            'updated_at' => '2026-10-17 10:00:00',
        ]);

        $octUnqueued = $this->createPaidInvoiceWithReceipt(
            $this->env['runOct'],
            'FAM-D4-U1',
            'SCH-2026-U1',
            'RCP-2026-U1',
            25000,
            '2026-10',
            '2026-10-18',
            '2026-10-08 10:00:00'
        );

        $novUnqueued = $this->createPaidInvoiceWithReceipt(
            $this->env['runNov'],
            'FAM-D4-U2',
            'SCH-2026-U2',
            'RCP-2026-U2',
            30000,
            '2026-11',
            '2026-11-02',
            '2026-11-02 10:00:00'
        );

        // 1. All Paid Invoices subtab shows all 4, but snapshot only targets the 2 unqueued eligible invoices
        $resAll = $this->actingAs($this->env['superAdmin'])->get('/invoices?tab=paid&period=all&paid_status=all');
        $resAll->assertOk();
        $this->assertSame(4, $resAll->viewData('paidInvoicesPage')->total());
        $snapshotAll = $resAll->viewData('selectionSnapshot');
        $this->assertSame(4, $snapshotAll['total_matched_invoices']);
        $this->assertSame(2, $snapshotAll['total_eligible_recipients']);
        $this->assertSame(1, $snapshotAll['already_submitted_recipients_count']);
        $this->assertSame(1, $snapshotAll['excluded_breakdown']['already_queued']);

        // 2. Unqueued subtab shows only the 2 unqueued invoices and produces the exact same snapshot ID
        $resUnqueued = $this->actingAs($this->env['superAdmin'])->get('/invoices?tab=paid&period=all&paid_status=unqueued');
        $resUnqueued->assertOk();
        $this->assertSame(2, $resUnqueued->viewData('paidInvoicesPage')->total());
        $this->assertSame([$novUnqueued['invId'], $octUnqueued['invId']], $resUnqueued->viewData('paidInvoicesPage')->pluck('id')->all());
        $snapshotUnqueued = $resUnqueued->viewData('selectionSnapshot');
        $this->assertSame(2, $snapshotUnqueued['total_eligible_recipients']);
        $this->assertSame($snapshotAll['snapshot_id'], $snapshotUnqueued['snapshot_id']);

        // 3. In Queue subtab shows only the 1 queued October invoice
        $resQueued = $this->actingAs($this->env['superAdmin'])->get('/invoices?tab=paid&period=all&paid_status=queued');
        $resQueued->assertOk();
        $this->assertSame(1, $resQueued->viewData('paidInvoicesPage')->total());
        $this->assertSame([$octQueued['invId']], $resQueued->viewData('paidInvoicesPage')->pluck('id')->all());

        // 4. Submitted subtab shows only the 1 submitted October invoice
        $resSubmitted = $this->actingAs($this->env['superAdmin'])->get('/invoices?tab=paid&period=all&paid_status=submitted');
        $resSubmitted->assertOk();
        $this->assertSame(1, $resSubmitted->viewData('paidInvoicesPage')->total());
        $this->assertSame([$octSubmitted['invId']], $resSubmitted->viewData('paidInvoicesPage')->pluck('id')->all());

        // 5. Staging all matching from all-period mode stages ONLY the 2 unqueued invoices (Oct + Nov)
        // and does NOT touch or resend the already-queued or already-submitted invoices
        $beforeSubNotif = DB::table('notifications')->where('invoice_id', $octSubmitted['invId'])->first();
        $beforeQueuedNotif = DB::table('notifications')->where('invoice_id', $octQueued['invId'])->first();

        $postStage = $this->actingAs($this->env['superAdmin'])->post('/invoices/send-batch', [
            'selection_mode' => 'all_matching',
            'channels' => ['whatsapp'],
            'confirmed_grades' => ['Grade 1'],
            'snapshot_id' => $snapshotAll['snapshot_id'],
            'review_confirmed' => '1',
            'webhook_type' => 'paid',
            'paid_only' => '1',
            'action_type' => 'add_to_queue',
            'period' => '2026-11',
            'all_period' => '1',
        ]);
        $postStage->assertSessionHasNoErrors();
        $postStage->assertRedirect('/invoices?tab=paid&period=all&paid_status=queued');
        Queue::assertNothingPushed();

        // Both Oct unqueued and Nov unqueued are now queued; existing submitted and queued records are untouched
        $this->assertEquals($beforeSubNotif, DB::table('notifications')->where('invoice_id', $octSubmitted['invId'])->first());
        $this->assertEquals($beforeQueuedNotif, DB::table('notifications')->where('invoice_id', $octQueued['invId'])->first());
        $this->assertDatabaseHas('notifications', ['invoice_id' => $octUnqueued['invId'], 'status' => 'queued', 'attempts' => 0]);
        $this->assertDatabaseHas('notifications', ['invoice_id' => $novUnqueued['invId'], 'status' => 'queued', 'attempts' => 0]);

        // Refresh JSON endpoint in all-period mode reflects updated counts (3 queued, 1 submitted, 0 unqueued, 4 total)
        $refresh = $this->actingAs($this->env['superAdmin'])
            ->get('/invoices?tab=paid&period=all&paid_status=queued&paid_refresh=1')
            ->assertOk();
        $refresh->assertJsonPath('counts.total', 4);
        $refresh->assertJsonPath('counts.queued', 3);
        $refresh->assertJsonPath('counts.submitted', 1);
        $refresh->assertJsonPath('counts.unqueued', 0);

        // 6. Dispatching a selected October queued invoice from period=all works and preserves all ledger/allocation rows
        $beforeInvoices = DB::table('invoices')->orderBy('id')->get()->toJson();
        $beforeItems = DB::table('invoice_items')->orderBy('id')->get()->toJson();
        $beforePayments = DB::table('payments')->orderBy('id')->get()->toJson();
        $beforeAllocations = DB::table('payment_allocations')->orderBy('id')->get()->toJson();

        $sendRes = $this->actingAs($this->env['superAdmin'])->post('/invoices/paid/send-queue', [
            'period' => '2026-11',
            'all_period' => '1',
            'invoice_ids' => [$octQueued['invId']],
        ]);
        $sendRes->assertSessionHasNoErrors();
        parse_str(parse_url($sendRes->headers->get('Location'), PHP_URL_QUERY), $redirectQuery);
        $this->assertSame('all', $redirectQuery['period'] ?? null);
        $this->assertSame((string) $octQueued['invId'], $redirectQuery['paid_tracking'] ?? null);

        Queue::assertPushed(DispatchBatchToN8n::class, 1);

        $this->assertSame($beforeInvoices, DB::table('invoices')->orderBy('id')->get()->toJson());
        $this->assertSame($beforeItems, DB::table('invoice_items')->orderBy('id')->get()->toJson());
        $this->assertSame($beforePayments, DB::table('payments')->orderBy('id')->get()->toJson());
        $this->assertSame($beforeAllocations, DB::table('payment_allocations')->orderBy('id')->get()->toJson());
    }

    /**
     * Test 5 & 8: Pagination across multiple pages in all-period mode maintains canonical ordering
     * (invoices.created_at DESC, invoices.id DESC) and accurate totals.
     */
    public function test_pagination_in_all_period_mode_maintains_canonical_ordering_and_accurate_totals(): void
    {
        $createdIds = [];
        for ($i = 1; $i <= 30; $i++) {
            $isOct = $i <= 15;
            $runId = $isOct ? $this->env['runOct'] : $this->env['runNov'];
            $period = $isOct ? '2026-10' : '2026-11';
            $day = str_pad((string) ((($i - 1) % 15) + 1), 2, '0', STR_PAD_LEFT);
            $inv = $this->createPaidInvoiceWithReceipt(
                $runId,
                "FAM-D4-PG-{$i}",
                "SCH-2026-PG-{$i}",
                "RCP-2026-PG-{$i}",
                20000,
                $period,
                "2026-10-20",
                "{$period}-{$day} 10:00:00"
            );
            $createdIds[] = $inv['invId'];
        }

        $page1 = $this->actingAs($this->env['superAdmin'])
            ->get('/invoices?tab=paid&period=all&per_page=25&page=1')
            ->assertOk()
            ->viewData('paidInvoicesPage');

        $page2 = $this->actingAs($this->env['superAdmin'])
            ->get('/invoices?tab=paid&period=all&per_page=25&page=2')
            ->assertOk()
            ->viewData('paidInvoicesPage');

        $this->assertSame(30, $page1->total());
        $this->assertCount(25, $page1->items());
        $this->assertCount(5, $page2->items());

        $combinedIds = array_merge(
            collect($page1->items())->pluck('id')->all(),
            collect($page2->items())->pluck('id')->all()
        );
        $this->assertCount(30, array_unique($combinedIds));
        $this->assertSame(array_reverse($createdIds), $combinedIds);
    }
}
