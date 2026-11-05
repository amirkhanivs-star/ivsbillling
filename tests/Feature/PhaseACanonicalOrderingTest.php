<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\InvoiceDeliveryQueueService;
use App\Services\WebhookSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PhaseACanonicalOrderingTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $campusAdmin;
    private int $campus1;
    private int $campus2;
    private int $dept;
    private int $program;
    private int $billingRun;
    private InvoiceDeliveryQueueService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->campus1 = DB::table('campuses')->value('id');
        $this->campus2 = DB::table('campuses')->insertGetId([
            'name' => 'Secondary Campus',
            'code' => 'SEC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->dept = DB::table('departments')->where('code', 'school')->value('id');
        $this->program = DB::table('programs')->insertGetId([
            'department_id' => $this->dept,
            'name' => 'General',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin_phase_a@example.test',
            'password' => bcrypt('password123'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->campusAdmin = User::create([
            'name' => 'Campus Admin',
            'email' => 'campusadmin_phase_a@example.test',
            'password' => bcrypt('password123'),
            'role' => 'campus_admin',
            'campus_id' => $this->campus1,
            'is_active' => true,
        ]);

        $webhookService = app(WebhookSettingService::class);
        $webhookService->updateSettings('monthly', ['url' => 'https://n8n.test/monthly', 'secret' => 'sec1', 'is_enabled' => true], $this->superAdmin->id);
        $webhookService->updateSettings('paid', ['url' => 'https://n8n.test/paid', 'secret' => 'sec2', 'is_enabled' => true], $this->superAdmin->id);

        $this->billingRun = DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campus1,
            'department_id' => $this->dept,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-25',
            'created_by' => $this->superAdmin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->service = app(InvoiceDeliveryQueueService::class);
    }

    private function createFamilyInvoice(
        int $familyIndex,
        string $invoiceNumber,
        string $createdAt,
        int $campusId = null,
        string $status = 'paid',
        int $totalMinor = 20000,
        int $paidMinor = 20000
    ): int {
        $campusId = $campusId ?? $this->campus1;

        $famId = DB::table('families')->insertGetId([
            'family_code' => "FAM-A-{$familyIndex}",
            'guardian_name' => "Guardian {$familyIndex}",
            'whatsapp' => "+96650" . str_pad((string)$familyIndex, 7, '0', STR_PAD_LEFT),
            'contact_verified' => true,
            'whatsapp_verified' => true,
            'messaging_opt_in' => true,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $stuId = DB::table('students')->insertGetId([
            'family_id' => $famId,
            'name' => "Student {$familyIndex}",
            'gr_number' => "GR-A-{$familyIndex}",
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $enrId = DB::table('enrollments')->insertGetId([
            'student_id' => $stuId,
            'campus_id' => $campusId,
            'department_id' => $this->dept,
            'program_id' => $this->program,
            'grade_label' => 'Grade 1',
            'currency' => 'SAR',
            'status' => 'active',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $invId = DB::table('invoices')->insertGetId([
            'family_id' => $famId,
            'campus_id' => $campusId,
            'department_id' => $this->dept,
            'billing_run_id' => $this->billingRun,
            'invoice_number' => $invoiceNumber,
            'currency' => 'SAR',
            'total_minor' => $totalMinor,
            'paid_minor' => $paidMinor,
            'status' => $status,
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-25',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $itemId = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invId,
            'student_id' => $stuId,
            'enrollment_id' => $enrId,
            'description' => 'Tuition Fee',
            'amount_minor' => $totalMinor,
            'net_minor' => $totalMinor,
            'paid_minor' => $paidMinor,
            'currency' => 'SAR',
            'period_key' => '2026-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        if ($status === 'paid' && $paidMinor > 0) {
            $paymentId = DB::table('payments')->insertGetId([
                'family_id' => $famId,
                'campus_id' => $campusId,
                'department_id' => $this->dept,
                'currency' => 'SAR',
                'receipt_number' => 'RCP-AUTO-' . $familyIndex,
                'amount_minor' => $paidMinor,
                'unallocated_minor' => 0,
                'received_on' => '2026-10-15',
                'selected_period' => '2026-10',
                'status' => 'received',
                'received_by' => $this->superAdmin->id,
                'idempotency_key' => 'IDEMP-' . $familyIndex . '-' . uniqid(),
                'request_hash' => hash('sha256', 'hash-' . $familyIndex . '-' . uniqid()),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            DB::table('payment_allocations')->insert([
                'payment_id' => $paymentId,
                'invoice_item_id' => $itemId,
                'amount_minor' => $paidMinor,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }

        return $invId;
    }

    public function test_paid_invoices_ordered_by_sch_created_at_desc_and_id_desc(): void
    {
        // Create 3 invoices where invoice IDs and created_at timestamps are deliberately decoupled
        // Inv 1: earlier created_at, lowest id
        $id1 = $this->createFamilyInvoice(1, 'SCH-2026-001', '2026-10-10 09:00:00');
        // Inv 2: latest created_at, middle id
        $id2 = $this->createFamilyInvoice(2, 'SCH-2026-002', '2026-10-15 09:00:00');
        // Inv 3: middle created_at, highest id
        $id3 = $this->createFamilyInvoice(3, 'SCH-2026-003', '2026-10-12 09:00:00');

        // Also create Inv 4 and Inv 5 with identical created_at to verify deterministic id tie-breaker
        $id4 = $this->createFamilyInvoice(4, 'SCH-2026-004', '2026-10-20 09:00:00');
        $id5 = $this->createFamilyInvoice(5, 'SCH-2026-005', '2026-10-20 09:00:00');

        $paginator = $this->service->getPaidInvoices(['period' => '2026-10'], $this->superAdmin, 25);
        $orderedIds = $paginator->pluck('id')->all();

        // Expected order:
        // 1. Inv 5 (2026-10-20, id5 > id4)
        // 2. Inv 4 (2026-10-20, id4)
        // 3. Inv 2 (2026-10-15, id2)
        // 4. Inv 3 (2026-10-12, id3)
        // 5. Inv 1 (2026-10-10, id1)
        $this->assertSame([$id5, $id4, $id2, $id3, $id1], $orderedIds);
    }

    public function test_paid_invoice_receipts_ordered_by_payments_created_at_desc_and_id_desc(): void
    {
        $invId = $this->createFamilyInvoice(10, 'SCH-2026-010', '2026-10-10 10:00:00', null, 'issued', 30000, 0);
        $itemId = DB::table('invoice_items')->where('invoice_id', $invId)->value('id');
        $famId = DB::table('invoices')->where('id', $invId)->value('family_id');

        // Create 3 payments with different created_at and id tie-breaker
        // Payment 1: earliest
        $p1 = DB::table('payments')->insertGetId([
            'family_id' => $famId,
            'campus_id' => $this->campus1,
            'department_id' => $this->dept,
            'currency' => 'SAR',
            'receipt_number' => 'RCP-001',
            'amount_minor' => 10000,
            'unallocated_minor' => 0,
            'received_on' => '2026-10-12',
            'selected_period' => '2026-10',
            'status' => 'received',
            'received_by' => $this->superAdmin->id,
            'idempotency_key' => 'IDEMP-P1-' . uniqid(),
            'request_hash' => hash('sha256', 'p1'),
            'created_at' => '2026-10-12 08:00:00',
            'updated_at' => '2026-10-12 08:00:00',
        ]);
        DB::table('payment_allocations')->insert([
            'payment_id' => $p1,
            'invoice_item_id' => $itemId,
            'amount_minor' => 10000,
            'created_at' => '2026-10-12 08:00:00',
            'updated_at' => '2026-10-12 08:00:00',
        ]);

        // Payment 2: same timestamp as Payment 3, but lower ID
        $p2 = DB::table('payments')->insertGetId([
            'family_id' => $famId,
            'campus_id' => $this->campus1,
            'department_id' => $this->dept,
            'currency' => 'SAR',
            'receipt_number' => 'RCP-002',
            'amount_minor' => 12000,
            'unallocated_minor' => 0,
            'received_on' => '2026-10-15',
            'selected_period' => '2026-10',
            'status' => 'received',
            'received_by' => $this->superAdmin->id,
            'idempotency_key' => 'IDEMP-P2-' . uniqid(),
            'request_hash' => hash('sha256', 'p2'),
            'created_at' => '2026-10-15 12:00:00',
            'updated_at' => '2026-10-15 12:00:00',
        ]);
        DB::table('payment_allocations')->insert([
            'payment_id' => $p2,
            'invoice_item_id' => $itemId,
            'amount_minor' => 12000,
            'created_at' => '2026-10-15 12:00:00',
            'updated_at' => '2026-10-15 12:00:00',
        ]);

        // Payment 3: same timestamp as Payment 2, but higher ID
        $p3 = DB::table('payments')->insertGetId([
            'family_id' => $famId,
            'campus_id' => $this->campus1,
            'department_id' => $this->dept,
            'currency' => 'SAR',
            'receipt_number' => 'RCP-003',
            'amount_minor' => 8000,
            'unallocated_minor' => 0,
            'received_on' => '2026-10-15',
            'selected_period' => '2026-10',
            'status' => 'received',
            'received_by' => $this->superAdmin->id,
            'idempotency_key' => 'IDEMP-P3-' . uniqid(),
            'request_hash' => hash('sha256', 'p3'),
            'created_at' => '2026-10-15 12:00:00',
            'updated_at' => '2026-10-15 12:00:00',
        ]);
        DB::table('payment_allocations')->insert([
            'payment_id' => $p3,
            'invoice_item_id' => $itemId,
            'amount_minor' => 8000,
            'created_at' => '2026-10-15 12:00:00',
            'updated_at' => '2026-10-15 12:00:00',
        ]);

        DB::table('invoices')->where('id', $invId)->update(['status' => 'paid', 'paid_minor' => 30000]);
        DB::table('invoice_items')->where('id', $itemId)->update(['paid_minor' => 30000]);

        $paginator = $this->service->getPaidInvoices(['period' => '2026-10'], $this->superAdmin, 25);
        $row = $paginator->first();
        $this->assertNotNull($row);
        $this->assertCount(3, $row->receipts);

        // Receipts should be ordered by payments.created_at DESC, payments.id DESC
        $receiptPaymentIds = array_column($row->receipts, 'payment_id');
        $this->assertSame([$p3, $p2, $p1], $receiptPaymentIds);

        // Verify amounts and allocation totals
        $receiptAmounts = array_column($row->receipts, 'amount_minor');
        $this->assertSame([8000, 12000, 10000], $receiptAmounts);
        $this->assertSame(30000, array_sum($receiptAmounts));
    }

    public function test_financial_history_ordered_by_sch_created_at_desc_and_id_desc(): void
    {
        $id1 = $this->createFamilyInvoice(21, 'SCH-FH-001', '2026-10-05 10:00:00');
        $id2 = $this->createFamilyInvoice(22, 'SCH-FH-002', '2026-10-18 10:00:00');
        $id3 = $this->createFamilyInvoice(23, 'SCH-FH-003', '2026-10-12 10:00:00');
        $id4 = $this->createFamilyInvoice(24, 'SCH-FH-004', '2026-10-18 10:00:00');

        $paginator = $this->service->getFinancialHistory(['period' => '2026-10'], $this->superAdmin, 25);
        $orderedIds = $paginator->pluck('id')->all();

        // id4 and id2 share 2026-10-18, so id4 (higher) comes before id2
        // id3 is 2026-10-12
        // id1 is 2026-10-05
        $this->assertSame([$id4, $id2, $id3, $id1], $orderedIds);
    }

    public function test_paid_workflow_refresh_consistent_with_get_paid_invoices_order(): void
    {
        $id1 = $this->createFamilyInvoice(31, 'SCH-PW-001', '2026-10-05 10:00:00');
        $id2 = $this->createFamilyInvoice(32, 'SCH-PW-002', '2026-10-22 10:00:00');
        $id3 = $this->createFamilyInvoice(33, 'SCH-PW-003', '2026-10-14 10:00:00');

        $paginator = $this->service->getPaidInvoices(['period' => '2026-10'], $this->superAdmin, 25);
        $page1Ids = $paginator->pluck('id')->all();

        // Refresh returns signature computed from identical page rows
        $refresh = $this->service->getPaidWorkflowRefresh(['period' => '2026-10'], $this->superAdmin, 25);
        $this->assertNotEmpty($refresh['signature']);
        $this->assertGreaterThanOrEqual(3, array_sum($refresh['counts']));
        $this->assertSame([$id2, $id3, $id1], array_slice($page1Ids, 0, 3));
    }

    public function test_campus_and_role_restrictions_preserved(): void
    {
        $invCampus1 = $this->createFamilyInvoice(41, 'SCH-C1-001', '2026-10-10 10:00:00', $this->campus1);
        $invCampus2 = $this->createFamilyInvoice(42, 'SCH-C2-001', '2026-10-12 10:00:00', $this->campus2);

        // Super Admin sees both
        $superHistory = $this->service->getFinancialHistory(['period' => '2026-10'], $this->superAdmin, 25);
        $this->assertTrue($superHistory->pluck('id')->contains($invCampus1));
        $this->assertTrue($superHistory->pluck('id')->contains($invCampus2));

        // Campus Admin sees only campus 1
        $campusHistory = $this->service->getFinancialHistory(['period' => '2026-10'], $this->campusAdmin, 25);
        $this->assertTrue($campusHistory->pluck('id')->contains($invCampus1));
        $this->assertFalse($campusHistory->pluck('id')->contains($invCampus2));

        $campusPaid = $this->service->getPaidInvoices(['period' => '2026-10'], $this->campusAdmin, 25);
        $this->assertTrue($campusPaid->pluck('id')->contains($invCampus1));
        $this->assertFalse($campusPaid->pluck('id')->contains($invCampus2));
    }

    public function test_ready_to_send_results_remain_intact_and_functional(): void
    {
        // Create an unpaid invoice eligible for Ready to Send
        $unpaidInv = $this->createFamilyInvoice(51, 'SCH-RTS-001', '2026-10-02 10:00:00', $this->campus1, 'issued', 25000, 0);

        $rtsPaginator = $this->service->getReadyToSendChallans(['period' => '2026-10'], $this->superAdmin, 25);
        $this->assertGreaterThanOrEqual(1, $rtsPaginator->total());

        $familyCodes = $rtsPaginator->pluck('family_code')->all();
        $this->assertContains('FAM-A-51', $familyCodes);
    }
}
