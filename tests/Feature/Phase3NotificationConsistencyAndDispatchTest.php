<?php

namespace Tests\Feature;

use App\Jobs\DispatchBatchToN8n;
use App\Jobs\DispatchInvoiceToN8n;
use App\Models\User;
use App\Services\BatchDeliveryService;
use App\Services\ConsolidatedInvoiceBuilder;
use App\Services\InvoiceDeliveryQueueService;
use App\Services\InvoicePayloadBuilder;
use App\Services\PdfAccessService;
use App\Services\WebhookSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class Phase3NotificationConsistencyAndDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $financeAdmin;
    protected User $schoolCashier;
    protected int $campusId;
    protected int $otherCampusId;
    protected int $schoolDeptId;
    protected int $hifzDeptId;
    protected int $feeTypeId;
    protected string $monthlyWebhookUrl = 'https://n8n.fictional-test.invalid/webhook/monthly-consolidated';
    protected string $paidWebhookUrl = 'https://n8n.fictional-test.invalid/webhook/paid-receipts';
    protected string $webhookSecret = 'phase3-fictional-test-secret-2026';

    protected function setUp(): void
    {
        parent::setUp();

        $this->campusId = DB::table('campuses')->insertGetId([
            'name' => 'Main Campus',
            'code' => 'MAIN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->otherCampusId = DB::table('campuses')->insertGetId([
            'name' => 'North Branch Campus',
            'code' => 'NORTH',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->schoolDeptId = DB::table('departments')->insertGetId([
            'name' => 'School Department',
            'code' => 'SCHOOL',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->hifzDeptId = DB::table('departments')->insertGetId([
            'name' => 'Hifz Department',
            'code' => 'HIFZ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('department_branding')->insert([
            'department_id' => $this->schoolDeptId,
            'display_name' => 'IQRA Virtual School',
            'payment_instructions' => 'IQRA Virtual School billing portal. Please deposit fees to authorized bank accounts.',
            'footer_email' => 'accounts@fictional-iqra.invalid',
            'footer_phone' => '+966500009999',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->feeTypeId = DB::table('fee_types')->insertGetId([
            'department_id' => null,
            'name' => 'Monthly Tuition Fee',
            'code' => 'monthly',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->superAdmin = User::create([
            'name' => 'Phase 3 Super Admin',
            'email' => 'superadmin.p3@fictional-iqra.invalid',
            'password' => bcrypt('StrongSecret2026!'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->financeAdmin = User::create([
            'name' => 'Phase 3 Finance Admin',
            'email' => 'financeadmin.p3@fictional-iqra.invalid',
            'password' => bcrypt('StrongSecret2026!'),
            'role' => 'finance_admin',
            'is_active' => true,
        ]);

        $this->schoolCashier = User::create([
            'name' => 'Phase 3 School Cashier',
            'email' => 'cashier.p3@fictional-iqra.invalid',
            'password' => bcrypt('StrongSecret2026!'),
            'role' => 'cashier',
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'is_active' => true,
        ]);

        $webhookSvc = app(WebhookSettingService::class);
        $webhookSvc->updateSettings('monthly', [
            'url' => $this->monthlyWebhookUrl,
            'secret' => $this->webhookSecret,
            'is_enabled' => true,
        ], $this->superAdmin->id);

        $webhookSvc->updateSettings('paid', [
            'url' => $this->paidWebhookUrl,
            'secret' => $this->webhookSecret,
            'is_enabled' => true,
        ], $this->superAdmin->id);
    }

    private function createBillingRun(
        string $periodStart,
        string $frequency = 'monthly',
        ?int $campusId = null,
        ?int $deptId = null
    ): int {
        $dt = Carbon::parse($periodStart);
        $campusId ??= $this->campusId;
        $deptId ??= $this->schoolDeptId;
        $key = "{$frequency}:{$dt->format('Y-m')}:c{$campusId}:d{$deptId}";

        $existing = DB::table('billing_runs')->where('period_key', $key)->value('id');
        if ($existing) {
            return (int) $existing;
        }

        return DB::table('billing_runs')->insertGetId([
            'department_id' => $deptId,
            'campus_id' => $campusId,
            'frequency' => $frequency,
            'period_key' => $key,
            'period_start' => $dt->startOfMonth()->toDateString(),
            'period_end' => $dt->endOfMonth()->toDateString(),
            'due_date' => $dt->copy()->addDays(9)->toDateString(),
            'created_by' => $this->superAdmin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createFictionalFamilyWithStudent(
        string $familyCode,
        string $grNumber,
        string $studentName,
        string $whatsapp = '+966500001111',
        bool $verified = true,
        bool $optIn = true,
        string $gradeLabel = 'Grade 3',
        string $currency = 'SAR',
        ?int $campusId = null,
        ?int $deptId = null
    ): array {
        $campusId ??= $this->campusId;
        $deptId ??= $this->schoolDeptId;

        $familyId = DB::table('families')->insertGetId([
            'family_code' => $familyCode,
            'guardian_name' => 'Guardian of ' . $studentName,
            'email' => strtolower($familyCode) . '@fictional-parent.invalid',
            'whatsapp' => $whatsapp,
            'contact_verified' => $verified,
            'whatsapp_verified' => $verified,
            'email_verified' => $verified,
            'messaging_opt_in' => $optIn,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentId = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => $grNumber,
            'name' => $studentName,
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrollmentId = DB::table('enrollments')->insertGetId([
            'student_id' => $studentId,
            'campus_id' => $campusId,
            'department_id' => $deptId,
            'grade_label' => $gradeLabel,
            'currency' => $currency,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$familyId, $studentId, $enrollmentId];
    }

    private function createMonthlyInvoiceWithItem(
        int $familyId,
        int $studentId,
        int $enrollmentId,
        string $invoiceNumber,
        string $periodStart,
        string $currency,
        int $netMinor,
        int $paidMinor = 0,
        ?string $dueDate = null,
        ?int $campusId = null,
        ?int $deptId = null,
        string $frequency = 'monthly'
    ): array {
        $campusId ??= $this->campusId;
        $deptId ??= $this->schoolDeptId;
        $runId = $this->createBillingRun($periodStart, $frequency, $campusId, $deptId);
        $dt = Carbon::parse($periodStart);
        $due = $dueDate ?? $dt->copy()->addDays(9)->toDateString();

        $invStatus = ($paidMinor >= $netMinor && $netMinor > 0)
            ? 'paid'
            : ($paidMinor > 0 ? 'partial' : 'issued');

        $invoiceId = DB::table('invoices')->insertGetId([
            'invoice_number' => $invoiceNumber,
            'family_id' => $familyId,
            'department_id' => $deptId,
            'campus_id' => $campusId,
            'billing_run_id' => $runId,
            'currency' => $currency,
            'issued_on' => $dt->toDateString(),
            'due_date' => $due,
            'total_minor' => $netMinor,
            'paid_minor' => $paidMinor,
            'status' => $invStatus,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invoiceId,
            'student_id' => $studentId,
            'enrollment_id' => $enrollmentId,
            'fee_type_id' => $this->feeTypeId,
            'currency' => $currency,
            'description' => 'Monthly Fee (' . $dt->format('M-Y') . ')',
            'period_key' => "monthly:{$dt->format('Y-m')}:i{$invoiceId}:s{$studentId}",
            'period_start' => $dt->startOfMonth()->toDateString(),
            'period_end' => $dt->endOfMonth()->toDateString(),
            'amount_minor' => $netMinor,
            'discount_minor' => 0,
            'net_minor' => $netMinor,
            'paid_minor' => $paidMinor,
            'status' => $invStatus,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$invoiceId, $itemId];
    }

    /**
     * Test A: Family consolidated notification
     * Previous SAR 500 (August SAR 300 + September SAR 200) plus October SAR 350.
     * Expected outgoing total: SAR 850.
     * PDF link opens the approved consolidated invoice.
     */
    public function test_a_family_consolidated_notification_uses_canonical_850_total_and_consolidated_pdf(): void
    {
        Http::fake([
            $this->monthlyWebhookUrl => Http::response(['ok' => true, 'message_id' => 'msg-p3-test-a'], 200),
        ]);
        Queue::fake([DispatchInvoiceToN8n::class, DispatchBatchToN8n::class]);

        [$familyId, $studentId, $enrollmentId] = $this->createFictionalFamilyWithStudent(
            'FAM-P3-A',
            'GR-P3-A',
            'Ahmad Al-Mansoor'
        );

        // Previous unpaid fees: Aug SAR 300 + Sep SAR 200 = SAR 500
        $this->createMonthlyInvoiceWithItem($familyId, $studentId, $enrollmentId, 'INV-P3-A-08', '2026-08-01', 'SAR', 30000, 0, '2026-08-10');
        $this->createMonthlyInvoiceWithItem($familyId, $studentId, $enrollmentId, 'INV-P3-A-09', '2026-09-01', 'SAR', 20000, 0, '2026-09-10');
        // Selected campaign month (October): SAR 350
        [$octInvoiceId] = $this->createMonthlyInvoiceWithItem($familyId, $studentId, $enrollmentId, 'INV-P3-A-10', '2026-10-01', 'SAR', 35000, 0, '2026-10-10');

        // Send individual WhatsApp notification for October campaign
        $this->actingAs($this->superAdmin)
            ->post('/invoices/send', [
                'invoice_ids' => [$octInvoiceId],
                'channels' => ['whatsapp'],
                'confirmed_grades' => ['Grade 3'],
                'review_confirmed' => '1',
                'webhook_type' => 'monthly',
            ])
            ->assertRedirect()
            ->assertSessionHas('notice');

        $notif = DB::table('notifications')->where('invoice_id', $octInvoiceId)->first();
        $this->assertNotNull($notif);
        $this->assertEquals('queued', $notif->status);

        // Execute the queued job against the faked HTTP endpoint
        $capturedPayload = null;
        Http::fake([
            $this->monthlyWebhookUrl => function ($request) use (&$capturedPayload) {
                $capturedPayload = $request->data();
                return Http::response(['ok' => true, 'message_id' => 'msg-p3-test-a'], 200);
            },
        ]);

        (new DispatchInvoiceToN8n($notif->id))->handle();

        $this->assertNotNull($capturedPayload);
        $this->assertEquals('invoice.monthly', $capturedPayload['event']);
        $this->assertEquals('FAM-P3-A', $capturedPayload['family']['family_code']);
        $this->assertEquals('Guardian of Ahmad Al-Mansoor', $capturedPayload['family']['guardian_name']);
        $this->assertEquals('2026-10', $capturedPayload['invoice']['billing_period']);

        // Outgoing total must be SAR 850.00 (500 previous + 350 October), NOT merely 350.00
        $this->assertEquals('SAR', $capturedPayload['invoice']['currency']);
        $this->assertEquals('850.00', $capturedPayload['invoice']['total_amount']);
        $this->assertEquals('850.00', $capturedPayload['invoice']['remaining_amount']);
        $this->assertEquals('850.00', $capturedPayload['invoice']['total_payable_amount']);
        $this->assertEquals(85000, $capturedPayload['invoice']['total_payable_minor']);
        $this->assertEquals('500.00', $capturedPayload['invoice']['previous_dues_amount']);
        $this->assertEquals('350.00', $capturedPayload['invoice']['selected_month_amount']);

        // Primary PDF link must be the family consolidated PDF URL, and must open the approved consolidated invoice
        $primaryPdfUrl = $capturedPayload['pdf_attachment']['pdf_url'];
        $this->assertStringContainsString("/public/challans/{$familyId}/", $primaryPdfUrl);
        $this->assertStringEndsWith('/Monthly-Fee-Invoice.pdf', $primaryPdfUrl);
        $this->assertEquals($primaryPdfUrl, $capturedPayload['pdf_attachment']['consolidated_challan_url']);
        $this->assertEquals('consolidated_fee_invoice', $capturedPayload['pdf_attachment']['document_type']);
        $this->assertEquals('pdf.consolidated-challan', $capturedPayload['pdf_attachment']['template']);

        // School contact & payment instructions included
        $this->assertEquals('IQRA Virtual School', $capturedPayload['school_details']['school_name']);
        $this->assertEquals('accounts@fictional-iqra.invalid', $capturedPayload['school_details']['contact_email']);
        $this->assertStringContainsString('authorized bank accounts', $capturedPayload['school_details']['payment_instructions']);

        // Open the PDF link as an unauthenticated parent and verify it renders the approved consolidated invoice
        auth()->logout();
        $pdfResponse = $this->get($primaryPdfUrl);
        $pdfResponse->assertStatus(200);
        $this->assertEquals('application/pdf', $pdfResponse->headers->get('Content-Type'));
    }

    /**
     * Test B: Already-generated future month
     * Previous SAR 500 + October SAR 350 + November already generated SAR 350.
     * Expected outgoing total: SAR 1,200.
     * All generated periods remain represented.
     */
    public function test_b_already_generated_future_month_is_included_in_1200_total_when_october_is_dispatched(): void
    {
        [$familyId, $studentId, $enrollmentId] = $this->createFictionalFamilyWithStudent(
            'FAM-P3-B',
            'GR-P3-B',
            'Maryam Al-Mansoor'
        );

        // Aug SAR 300 + Sep SAR 200 + Oct SAR 350 + Nov SAR 350 = SAR 1,200
        $this->createMonthlyInvoiceWithItem($familyId, $studentId, $enrollmentId, 'INV-P3-B-08', '2026-08-01', 'SAR', 30000, 0, '2026-08-10');
        $this->createMonthlyInvoiceWithItem($familyId, $studentId, $enrollmentId, 'INV-P3-B-09', '2026-09-01', 'SAR', 20000, 0, '2026-09-10');
        [$octInvoiceId] = $this->createMonthlyInvoiceWithItem($familyId, $studentId, $enrollmentId, 'INV-P3-B-10', '2026-10-01', 'SAR', 35000, 0, '2026-10-10');
        $this->createMonthlyInvoiceWithItem($familyId, $studentId, $enrollmentId, 'INV-P3-B-11', '2026-11-01', 'SAR', 35000, 0, '2026-11-10');

        $octInvoice = DB::table('invoices')->where('id', $octInvoiceId)->first();
        $fakeNotif = (object) [
            'id' => 9001,
            'event_type' => 'invoice.monthly',
            'kind' => 'monthly',
            'channels' => json_encode(['whatsapp', 'email']),
            'event_id' => 'evt-p3-b',
            'idempotency_key' => 'idemp-p3-b',
        ];

        $payload = app(InvoicePayloadBuilder::class)->build($fakeNotif, $octInvoice, 'invoice.monthly');

        // Campaign month is 2026-10, but total payable is SAR 1,200.00 (120000 minor)
        $this->assertEquals('2026-10', $payload['invoice']['billing_period']);
        $this->assertEquals('1,200.00', $payload['invoice']['total_amount']);
        $this->assertEquals('1,200.00', $payload['invoice']['remaining_amount']);
        $this->assertEquals('1,200.00', $payload['invoice']['total_payable_amount']);
        $this->assertEquals(120000, $payload['invoice']['total_payable_minor']);
        $this->assertEquals('500.00', $payload['invoice']['previous_dues_amount']);
        $this->assertEquals('350.00', $payload['invoice']['selected_month_amount']);
        $this->assertEquals('350.00', $payload['invoice']['later_period_amount']);

        // All 4 generated periods (2026-08, 2026-09, 2026-10, 2026-11) are represented in the student's itemized particulars
        $this->assertCount(1, $payload['students']);
        $periods = collect($payload['students'][0]['itemized_fee_particulars'])->pluck('billing_period')->all();
        $this->assertEquals(['2026-08', '2026-09', '2026-10', '2026-11'], $periods);

        // Also verify BatchDeliveryService selection snapshot for period=2026-10 uses 120000 minor
        $snapshot = app(BatchDeliveryService::class)->resolveFilteredSelection(
            ['period' => '2026-10', 'webhook_type' => 'monthly'],
            $this->superAdmin,
            ['whatsapp']
        );
        $this->assertEquals(1, $snapshot['total_eligible_recipients']);
        $this->assertEquals(120000, $snapshot['eligible_recipients'][0]['outstanding_minor']);
        $this->assertEquals(120000, $snapshot['currencies_summary']['SAR']['outstanding_minor']);
    }

    /**
     * Test C: Partial payment
     * Original SAR 300. Paid SAR 100. Remaining SAR 200.
     * Notification must use the remaining amount.
     */
    public function test_c_partial_payment_notification_uses_remaining_200_balance(): void
    {
        [$familyId, $studentId, $enrollmentId] = $this->createFictionalFamilyWithStudent(
            'FAM-P3-C',
            'GR-P3-C',
            'Fatima Al-Zahrani'
        );

        // Original SAR 300 (30000 minor), Paid SAR 100 (10000 minor) => Remaining SAR 200 (20000 minor)
        [$octInvoiceId] = $this->createMonthlyInvoiceWithItem(
            $familyId,
            $studentId,
            $enrollmentId,
            'INV-P3-C-10',
            '2026-10-01',
            'SAR',
            30000,
            10000,
            '2026-10-10'
        );

        $octInvoice = DB::table('invoices')->where('id', $octInvoiceId)->first();
        $fakeNotif = (object) [
            'id' => 9002,
            'event_type' => 'invoice.monthly',
            'kind' => 'monthly',
            'channels' => json_encode(['whatsapp']),
            'event_id' => 'evt-p3-c',
            'idempotency_key' => 'idemp-p3-c',
        ];

        $payload = app(InvoicePayloadBuilder::class)->build($fakeNotif, $octInvoice, 'invoice.monthly');

        $this->assertEquals('partial', $payload['invoice']['payment_status']);
        $this->assertEquals('200.00', $payload['invoice']['remaining_amount']);
        $this->assertEquals('200.00', $payload['invoice']['total_amount']);
        $this->assertEquals('200.00', $payload['invoice']['total_payable_amount']);
        $this->assertEquals('100.00', $payload['invoice']['amount_already_paid']);
        $this->assertEquals(20000, $payload['invoice']['total_payable_minor']);

        $itemParticular = $payload['students'][0]['itemized_fee_particulars'][0];
        $this->assertEquals('300.00', $itemParticular['net_amount']);
        $this->assertEquals('100.00', $itemParticular['paid_amount']);
        $this->assertEquals('200.00', $itemParticular['remaining_amount']);
    }

    /**
     * Test D: Multi-currency family
     * Separate SAR (850) and AED (400) balances.
     * No combined currency total (never 1,250).
     */
    public function test_d_multi_currency_family_preserves_separate_sar_and_aed_totals_without_combining(): void
    {
        [$familyId, $studentId, $enrollmentId] = $this->createFictionalFamilyWithStudent(
            'FAM-P3-D',
            'GR-P3-D',
            'Omar Al-Ghamdi'
        );

        // SAR obligations: Sep SAR 500 + Oct SAR 350 = SAR 850
        $this->createMonthlyInvoiceWithItem($familyId, $studentId, $enrollmentId, 'INV-P3-D-SAR-09', '2026-09-01', 'SAR', 50000);
        [$octSarInvId] = $this->createMonthlyInvoiceWithItem($familyId, $studentId, $enrollmentId, 'INV-P3-D-SAR-10', '2026-10-01', 'SAR', 35000);

        // AED obligations: Aug AED 150 + Oct AED 250 = AED 400
        $this->createMonthlyInvoiceWithItem($familyId, $studentId, $enrollmentId, 'INV-P3-D-AED-08', '2026-08-01', 'AED', 15000);
        $this->createMonthlyInvoiceWithItem($familyId, $studentId, $enrollmentId, 'INV-P3-D-AED-10', '2026-10-01', 'AED', 25000);

        $octSarInvoice = DB::table('invoices')->where('id', $octSarInvId)->first();
        $fakeNotif = (object) [
            'id' => 9003,
            'event_type' => 'invoice.monthly',
            'kind' => 'monthly',
            'channels' => json_encode(['whatsapp']),
            'event_id' => 'evt-p3-d',
            'idempotency_key' => 'idemp-p3-d',
        ];

        $payload = app(InvoicePayloadBuilder::class)->build($fakeNotif, $octSarInvoice, 'invoice.monthly');
        $payloadJson = json_encode($payload);

        $this->assertTrue($payload['invoice']['is_multi_currency']);
        $byCurrency = collect($payload['invoice']['currencies_breakdown'])->keyBy('currency');
        $this->assertCount(2, $byCurrency);

        $this->assertEquals('850.00', $byCurrency['SAR']['amount']);
        $this->assertEquals(85000, $byCurrency['SAR']['amount_minor']);
        $this->assertEquals('SAR 850.00', $byCurrency['SAR']['formatted_total_payable']);

        $this->assertEquals('400.00', $byCurrency['AED']['amount']);
        $this->assertEquals(40000, $byCurrency['AED']['amount_minor']);
        $this->assertEquals('AED 400.00', $byCurrency['AED']['formatted_total_payable']);

        // Never produce a combined 1,250 / 1250.00 / 125000 anywhere in the payload
        $this->assertStringNotContainsString('1,250', $payloadJson);
        $this->assertStringNotContainsString('1250.00', $payloadJson);
        $this->assertStringNotContainsString('125000', $payloadJson);
    }

    /**
     * Test E: Financial integrity hold
     * Create an inconsistent unpaid invoice header without valid unpaid items.
     * Verify sending is blocked through UI, direct dispatch service, queued job, and retry paths.
     */
    public function test_e_financial_integrity_hold_blocks_sending_across_ui_service_queued_job_and_retry(): void
    {
        Http::fake();
        Queue::fake([DispatchInvoiceToN8n::class, DispatchBatchToN8n::class]);

        [$familyId, $studentId, $enrollmentId] = $this->createFictionalFamilyWithStudent(
            'FAM-P3-E',
            'GR-P3-E',
            'Khalid Al-Qahtani'
        );

        $runId = $this->createBillingRun('2026-10-01');

        // Insert an inconsistent unpaid invoice header (SAR 450.00) with ZERO valid unpaid invoice_items
        $inconsistentInvId = DB::table('invoices')->insertGetId([
            'invoice_number' => 'INV-P3-E-INC-450',
            'family_id' => $familyId,
            'department_id' => $this->schoolDeptId,
            'campus_id' => $this->campusId,
            'billing_run_id' => $runId,
            'currency' => 'SAR',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-10',
            'total_minor' => 45000,
            'paid_minor' => 0,
            'status' => 'issued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 1. UI Ready to Send disables checkbox and flags data integrity issue
        $uiResponse = $this->actingAs($this->superAdmin)->get('/invoices?tab=ready&period=2026-10');
        $uiResponse->assertStatus(200);
        $uiResponse->assertSee('UNVERIFIED — Staff Review Required');
        $uiResponse->assertSee('Data Integrity Issue — Staff Review Required');

        // 2. Crafted POST request to individual/page send (/invoices/send) is blocked server-side
        $this->actingAs($this->superAdmin)
            ->from('/invoices?tab=ready&period=2026-10')
            ->post('/invoices/send', [
                'invoice_ids' => [$inconsistentInvId],
                'channels' => ['whatsapp'],
                'confirmed_grades' => ['Not assigned', 'Grade 3'],
                'review_confirmed' => '1',
                'webhook_type' => 'monthly',
                'period' => '2026-10',
            ])
            ->assertRedirect();

        Queue::assertNotPushed(DispatchInvoiceToN8n::class);
        $blockedNotif = DB::table('notifications')->where('invoice_id', $inconsistentInvId)->first();
        $this->assertNotNull($blockedNotif);
        $this->assertEquals('blocked', $blockedNotif->status);
        $this->assertStringContainsString('Data Integrity Issue', (string) $blockedNotif->error);
        $this->assertTrue(
            DB::table('audit_logs')
                ->where('action', 'notification.blocked_financial_integrity')
                ->where('subject_id', $blockedNotif->id)
                ->exists()
        );

        // 3. Direct BatchDeliveryService::createAndDispatchBatch invocation with stale/explicit ID is blocked
        $batchService = app(BatchDeliveryService::class);
        $snapshot = $batchService->resolveFilteredSelection(['period' => '2026-10', 'webhook_type' => 'monthly'], $this->superAdmin, ['whatsapp']);
        $this->assertEquals(0, $snapshot['total_eligible_recipients']);
        $this->assertEquals(1, $snapshot['excluded_breakdown']['data_integrity_hold']);

        $exceptionThrown = false;
        try {
            $batchService->createAndDispatchBatch(
                ['period' => '2026-10', 'webhook_type' => 'monthly'],
                ['Grade 3'],
                ['whatsapp'],
                $this->superAdmin,
                '2026-10',
                [$inconsistentInvId],
                null,
                true,
                true
            );
        } catch (RuntimeException $e) {
            $exceptionThrown = true;
            $this->assertStringContainsString('Data Integrity Issue', $e->getMessage());
        }
        $this->assertTrue($exceptionThrown, 'Direct service call must reject inconsistent invoice with RuntimeException');
        Queue::assertNotPushed(DispatchBatchToN8n::class);

        // 4. Queued job created BEFORE discrepancy appeared:
        // Simulate a job that was already in 'queued' status when the discrepancy appeared
        DB::table('notifications')->where('id', $blockedNotif->id)->update([
            'status' => 'queued',
            'error' => null,
        ]);

        (new DispatchInvoiceToN8n($blockedNotif->id))->handle();

        Http::assertNothingSent();
        $afterJobNotif = DB::table('notifications')->where('id', $blockedNotif->id)->first();
        $this->assertEquals('blocked', $afterJobNotif->status);
        $this->assertStringContainsString('Data Integrity Issue', (string) $afterJobNotif->error);

        // Also test DispatchBatchToN8n when discrepancy appears after batch creation
        $batchId = 'BATCH-P3-E-HOLD';
        DB::table('notification_batches')->insert([
            'batch_id' => $batchId,
            'webhook_type' => 'monthly',
            'event_type' => 'invoice.monthly.batch',
            'billing_period' => '2026-10',
            'channels' => json_encode(['whatsapp']),
            'status' => 'queued',
            'total_recipients' => 1,
            'total_invoices' => 1,
            'chunks_total' => 1,
            'chunks_sent' => 0,
            'approved_by' => $this->superAdmin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('notifications')->where('id', $blockedNotif->id)->update([
            'batch_id' => $batchId,
            'chunk_index' => 1,
            'status' => 'queued',
            'error' => null,
        ]);

        (new DispatchBatchToN8n($batchId))->handle();

        Http::assertNothingSent();
        $afterBatchJobNotif = DB::table('notifications')->where('id', $blockedNotif->id)->first();
        $this->assertEquals('blocked', $afterBatchJobNotif->status);
        $this->assertStringContainsString('Data Integrity Issue', (string) $afterBatchJobNotif->error);

        // 5. Retry operations (both individual retry endpoint and batch retry service) must block
        $this->actingAs($this->superAdmin)
            ->from('/invoices/queue')
            ->post("/invoices/notifications/{$blockedNotif->id}/retry")
            ->assertRedirect()
            ->assertSessionHasErrors('retry');

        Queue::assertNotPushed(DispatchInvoiceToN8n::class);
        $this->assertEquals('blocked', DB::table('notifications')->where('id', $blockedNotif->id)->value('status'));

        // Simulate 'failed' status in batch and call retryFailedBatchRecipients
        DB::table('notifications')->where('id', $blockedNotif->id)->update(['status' => 'failed']);
        $retryRes = $batchService->retryFailedBatchRecipients($batchId, $this->superAdmin);
        $this->assertEquals(0, $retryRes['retried_count']);
        $this->assertEquals('blocked', DB::table('notifications')->where('id', $blockedNotif->id)->value('status'));
        Http::assertNothingSent();
    }

    /**
     * Test F: Restricted staff
     * Verify a department cashier cannot send an unauthorized full-family statement.
     * Verify administrators retain authorized full-family delivery.
     */
    public function test_f_restricted_staff_cannot_dispatch_full_family_statement_while_admins_can(): void
    {
        Http::fake([
            $this->monthlyWebhookUrl => Http::response(['ok' => true, 'message_id' => 'msg-p3-test-f'], 200),
        ]);
        Queue::fake([DispatchInvoiceToN8n::class, DispatchBatchToN8n::class]);

        [$familyId, $student1Id, $enrollment1Id] = $this->createFictionalFamilyWithStudent(
            'FAM-P3-F',
            'GR-P3-F1',
            'Zayd Al-Harbi'
        );

        // Sibling in Hifz Department
        $student2Id = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => 'GR-P3-F2',
            'name' => 'Bilal Al-Harbi',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $enrollment2Id = DB::table('enrollments')->insertGetId([
            'student_id' => $student2Id,
            'campus_id' => $this->campusId,
            'department_id' => $this->hifzDeptId,
            'grade_label' => 'Hifz Track',
            'currency' => 'SAR',
            'monthly_fee_minor' => 25000,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // School Dept invoice: SAR 350
        [$schoolInvId] = $this->createMonthlyInvoiceWithItem(
            $familyId,
            $student1Id,
            $enrollment1Id,
            'INV-P3-F-SCH',
            '2026-10-01',
            'SAR',
            35000,
            0,
            '2026-10-10',
            $this->campusId,
            $this->schoolDeptId
        );

        // Hifz Dept invoice: SAR 250 (Full family total = SAR 600)
        $this->createMonthlyInvoiceWithItem(
            $familyId,
            $student2Id,
            $enrollment2Id,
            'INV-P3-F-HIFZ',
            '2026-10-01',
            'SAR',
            25000,
            0,
            '2026-10-10',
            $this->campusId,
            $this->hifzDeptId
        );

        // 1. Department cashier cannot send full-family monthly statement via POST /invoices/send (403)
        $this->actingAs($this->schoolCashier)
            ->post('/invoices/send', [
                'invoice_ids' => [$schoolInvId],
                'channels' => ['whatsapp'],
                'confirmed_grades' => ['Grade 3'],
                'review_confirmed' => '1',
                'webhook_type' => 'monthly',
            ])
            ->assertStatus(403);

        // 2. Department cashier cannot send batch via POST /invoices/send-batch (403)
        $this->actingAs($this->schoolCashier)
            ->post('/invoices/send-batch', [
                'selection_mode' => 'page',
                'invoice_ids' => [$schoolInvId],
                'channels' => ['whatsapp'],
                'confirmed_grades' => ['Grade 3'],
                'review_confirmed' => '1',
                'webhook_type' => 'monthly',
                'period' => '2026-10',
            ])
            ->assertStatus(403);

        // 3. Direct service call by department cashier is rejected
        $cashierException = false;
        try {
            app(BatchDeliveryService::class)->createAndDispatchBatch(
                ['period' => '2026-10', 'webhook_type' => 'monthly'],
                ['Grade 3'],
                ['whatsapp'],
                $this->schoolCashier,
                '2026-10',
                [$schoolInvId],
                null,
                true,
                true
            );
        } catch (RuntimeException $e) {
            $cashierException = true;
            $this->assertStringContainsString('Unauthorized', $e->getMessage());
        }
        $this->assertTrue($cashierException);
        Queue::assertNothingPushed();

        // 4. Finance Admin & Super Admin retain authorized full-family delivery (total SAR 600.00)
        $this->actingAs($this->financeAdmin)
            ->post('/invoices/send', [
                'invoice_ids' => [$schoolInvId],
                'channels' => ['whatsapp'],
                'confirmed_grades' => ['Grade 3'],
                'review_confirmed' => '1',
                'webhook_type' => 'monthly',
            ])
            ->assertRedirect()
            ->assertSessionHas('notice');

        Queue::assertPushed(DispatchInvoiceToN8n::class, 1);

        $notif = DB::table('notifications')->where('invoice_id', $schoolInvId)->first();
        $this->assertNotNull($notif);
        $this->assertEquals('queued', $notif->status);

        $capturedPayload = null;
        Http::fake([
            $this->monthlyWebhookUrl => function ($request) use (&$capturedPayload) {
                $capturedPayload = $request->data();
                return Http::response(['ok' => true, 'message_id' => 'msg-p3-admin-ok'], 200);
            },
        ]);

        (new DispatchInvoiceToN8n($notif->id))->handle();

        $this->assertNotNull($capturedPayload);
        $this->assertEquals('600.00', $capturedPayload['invoice']['total_amount']);
        $this->assertEquals('600.00', $capturedPayload['invoice']['remaining_amount']);
        $this->assertCount(2, $capturedPayload['students']);
    }

    /**
     * Test G: Secure parent PDF
     * Confirm valid family tokens work and invalid tokens are rejected.
     * Verify no unrelated family records are exposed.
     */
    public function test_g_secure_parent_pdf_accepts_valid_token_rejects_invalid_and_isolates_family_data(): void
    {
        [$family1Id, $student1Id, $enrollment1Id] = $this->createFictionalFamilyWithStudent(
            'FAM-P3-G1',
            'GR-P3-G1',
            'Yusuf Al-Ansari'
        );
        [$family2Id, $student2Id, $enrollment2Id] = $this->createFictionalFamilyWithStudent(
            'FAM-P3-G2',
            'GR-P3-G2',
            'Unrelated Student Secret',
            '+966500002222'
        );

        [$inv1Id] = $this->createMonthlyInvoiceWithItem($family1Id, $student1Id, $enrollment1Id, 'INV-P3-G1-10', '2026-10-01', 'SAR', 35000);
        $this->createMonthlyInvoiceWithItem($family2Id, $student2Id, $enrollment2Id, 'INV-P3-G2-10', '2026-10-01', 'SAR', 99000);

        $pdfService = app(PdfAccessService::class);
        $validFamily1Url = $pdfService->getFamilyChallanPdfUrl($family1Id);
        $validFamily2Token = $pdfService->getAccessKey('family', $family2Id);

        // 1. Valid token works without authentication
        auth()->logout();
        $resValid = $this->get($validFamily1Url);
        $resValid->assertStatus(200);
        $this->assertEquals('application/pdf', $resValid->headers->get('Content-Type'));

        // 2. Invalid/tampered token is rejected (403)
        $tamperedUrl = str_replace('/Monthly-Fee-Invoice.pdf', 'tampered/Monthly-Fee-Invoice.pdf', $validFamily1Url);
        $this->get($tamperedUrl)->assertStatus(403);

        // 3. Using Family 2's valid token on Family 1's URL is rejected (403)
        $crossTokenUrl = "/public/challans/{$family1Id}/{$validFamily2Token}/Monthly-Fee-Invoice.pdf";
        $this->get($crossTokenUrl)->assertStatus(403);

        // 4. Verify Family 1 view data & rendered HTML and notification payload contain ZERO records from Family 2
        $builder = app(ConsolidatedInvoiceBuilder::class);
        $fam1Obj = DB::table('families')->find($family1Id);
        $viewData = $builder->buildFamilyViewData($fam1Obj, null, true, $inv1Id, '2026-10');
        $renderedHtml = view('pdf.consolidated-challan', $viewData)->render();

        $this->assertStringContainsString('Yusuf Al-Ansari', $renderedHtml);
        $this->assertStringContainsString('350.00', $renderedHtml);
        $this->assertStringNotContainsString('Unrelated Student Secret', $renderedHtml);
        $this->assertStringNotContainsString('FAM-P3-G2', $renderedHtml);
        $this->assertStringNotContainsString('990.00', $renderedHtml);

        $inv1Obj = DB::table('invoices')->find($inv1Id);
        $notifPayload = app(InvoicePayloadBuilder::class)->build((object) ['id' => 9007, 'kind' => 'monthly'], $inv1Obj, 'invoice.monthly');
        $notifJson = json_encode($notifPayload);
        $this->assertStringNotContainsString('Unrelated Student Secret', $notifJson);
        $this->assertStringNotContainsString('FAM-P3-G2', $notifJson);
    }

    /**
     * Test H: Notification preview
     * Confirm the consolidated PDF referenced in a notification uses the same canonical financial rules
     * as the approved staff preview.
     */
    public function test_h_notification_consolidated_pdf_and_totals_match_approved_staff_preview(): void
    {
        [$familyId, $studentId, $enrollmentId] = $this->createFictionalFamilyWithStudent(
            'FAM-P3-H',
            'GR-P3-H',
            'Ibrahim Al-Dosari'
        );

        // Opening Balance SAR 150 + Sep Partial Remaining SAR 200 (300 - 100) + Oct SAR 350 + Nov SAR 350 = SAR 1,050
        $this->createMonthlyInvoiceWithItem($familyId, $studentId, $enrollmentId, 'INV-P3-H-OB', '2026-07-01', 'SAR', 15000, 0, '2026-07-10', null, null, 'opening_balance');
        $this->createMonthlyInvoiceWithItem($familyId, $studentId, $enrollmentId, 'INV-P3-H-09', '2026-09-01', 'SAR', 30000, 10000, '2026-09-10');
        [$octInvId] = $this->createMonthlyInvoiceWithItem($familyId, $studentId, $enrollmentId, 'INV-P3-H-10', '2026-10-01', 'SAR', 35000, 0, '2026-10-10');
        $this->createMonthlyInvoiceWithItem($familyId, $studentId, $enrollmentId, 'INV-P3-H-11', '2026-11-01', 'SAR', 35000, 0, '2026-11-10');

        // 1. Staff Ready to Send preview row
        $readyPage = app(InvoiceDeliveryQueueService::class)->getReadyToSendChallans(['period' => '2026-10'], $this->superAdmin);
        $this->assertCount(1, $readyPage->items());
        $staffRow = $readyPage->items()[0];

        // 2. Notification payload for October invoice
        $octInvoice = DB::table('invoices')->where('id', $octInvId)->first();
        $payload = app(InvoicePayloadBuilder::class)->build((object) ['id' => 9008, 'kind' => 'monthly'], $octInvoice, 'invoice.monthly');

        // 3. Canonical ConsolidatedInvoiceBuilder PDF view data
        $famObj = DB::table('families')->find($familyId);
        $pdfViewData = app(ConsolidatedInvoiceBuilder::class)->buildFamilyViewData($famObj, null, true, $octInvId, '2026-10', $octInvoice);
        $pdfSarGroup = $pdfViewData['currencyGroups']['SAR'];

        // Verify exact mathematical reconciliation across all 3 (105,000 minor = SAR 1,050.00)
        $this->assertEquals(105000, $staffRow->total_payable_minor);
        $this->assertEquals(105000, $payload['invoice']['total_payable_minor']);
        $this->assertEquals(105000, $pdfSarGroup['total_balance_minor']);

        $this->assertEquals(35000, $staffRow->previous_outstanding_minor); // 150 OB + 200 Sep remaining
        $this->assertEquals(35000, $payload['invoice']['previous_dues_minor']);

        $this->assertEquals(35000, $staffRow->selected_month_minor); // 350 Oct
        $this->assertEquals(35000, $payload['invoice']['selected_month_minor']);

        $this->assertEquals(35000, $staffRow->later_period_minor); // 350 Nov
        $this->assertEquals(35000, $payload['invoice']['later_period_minor']);

        // Staff preview URL (without query param) matches notification's primary PDF URL
        $staffBaseUrl = strtok($staffRow->consolidated_challan_url, '?');
        $this->assertEquals($staffBaseUrl, $payload['pdf_attachment']['pdf_url']);
    }

    /**
     * Test I: Delivery safety
     * Confirm no real WhatsApp, email, or n8n requests are sent during testing.
     * Use fake queues, fake HTTP clients, and fictional recipients.
     */
    public function test_i_delivery_safety_fakes_and_qa_suppression_guard_prevent_real_external_calls(): void
    {
        Http::fake();
        Queue::fake([DispatchInvoiceToN8n::class, DispatchBatchToN8n::class]);

        [$familyId, $studentId, $enrollmentId] = $this->createFictionalFamilyWithStudent(
            'FAM-P3-I',
            'GR-P3-I',
            'Noor Al-Farsi',
            '+966500009991'
        );
        [$octInvId] = $this->createMonthlyInvoiceWithItem($familyId, $studentId, $enrollmentId, 'INV-P3-I-10', '2026-10-01', 'SAR', 35000);

        // Enable notifications_disabled safety flag and verify jobs suppress external calls
        config(['services.notifications_disabled' => true]);

        $notifId = DB::table('notifications')->insertGetId([
            'invoice_id' => $octInvId,
            'channel' => 'whatsapp,email',
            'kind' => 'monthly',
            'webhook_type' => 'monthly',
            'event_type' => 'invoice.monthly',
            'channels' => json_encode(['whatsapp', 'email']),
            'idempotency_key' => 'idemp-p3-i-safe',
            'event_id' => 'evt-p3-i-safe',
            'status' => 'queued',
            'approved_by' => $this->superAdmin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new DispatchInvoiceToN8n($notifId))->handle();

        Http::assertNothingSent();
        $suppressed = DB::table('notifications')->where('id', $notifId)->first();
        $this->assertEquals('qa_suppressed', $suppressed->status);

        config(['services.notifications_disabled' => false]);
    }

    /**
     * Test J: Existing workflows & Live Statement Metadata + Paid Receipt preservation
     * Confirm that batch selection, pagination, scheduled/chunked dispatch, retry behavior,
     * delivery statuses, live-link metadata, and paid receipt notifications remain functional.
     */
    public function test_j_existing_workflows_batch_chunking_live_metadata_and_paid_receipts_remain_functional(): void
    {
        $sentRequests = [];
        Http::fake([
            $this->monthlyWebhookUrl => function ($request) use (&$sentRequests) {
                $sentRequests[] = ['url' => $request->url(), 'data' => $request->data()];
                return Http::response(['ok' => true, 'batch_id' => 'batch-msg-p3-j'], 200);
            },
            $this->paidWebhookUrl => function ($request) use (&$sentRequests) {
                $sentRequests[] = ['url' => $request->url(), 'data' => $request->data()];
                return Http::response(['ok' => true, 'batch_id' => 'paid-msg-p3-j'], 200);
            },
        ]);
        Queue::fake([DispatchBatchToN8n::class, DispatchInvoiceToN8n::class]);

        // 1. Create two unpaid families for October batch + chunking test
        [$fam1, $stu1, $enr1] = $this->createFictionalFamilyWithStudent('FAM-P3-J1', 'GR-P3-J1', 'Hamza Al-Otaibi', '+966500003331');
        $this->createMonthlyInvoiceWithItem($fam1, $stu1, $enr1, 'INV-P3-J1-09', '2026-09-01', 'SAR', 20000);
        [$inv1Oct] = $this->createMonthlyInvoiceWithItem($fam1, $stu1, $enr1, 'INV-P3-J1-10', '2026-10-01', 'SAR', 35000);

        [$fam2, $stu2, $enr2] = $this->createFictionalFamilyWithStudent('FAM-P3-J2', 'GR-P3-J2', 'Salma Al-Otaibi', '+966500003332');
        [$inv2Oct] = $this->createMonthlyInvoiceWithItem($fam2, $stu2, $enr2, 'INV-P3-J2-10', '2026-10-01', 'SAR', 40000);

        // Batch creation & dispatch
        $batchService = app(BatchDeliveryService::class);
        $batchRes = $batchService->createAndDispatchBatch(
            ['period' => '2026-10', 'webhook_type' => 'monthly'],
            ['Grade 3'],
            ['whatsapp', 'email'],
            $this->superAdmin,
            '2026-10',
            null,
            null,
            true,
            true
        );

        $this->assertEquals(2, $batchRes['eligible_count']);
        Queue::assertPushed(DispatchBatchToN8n::class, 1);

        // Run the batch worker job
        (new DispatchBatchToN8n($batchRes['batch_id']))->handle();

        $this->assertCount(1, $sentRequests);
        $this->assertEquals($this->monthlyWebhookUrl, $sentRequests[0]['url']);
        $this->assertEquals('invoice.monthly.batch', $sentRequests[0]['data']['event']);
        $dispatchedInvoices = collect($sentRequests[0]['data']['invoices'])->keyBy('invoice_id');

        // Family 1's dispatched notification in the batch has consolidated total SAR 550.00 (200 Sep + 350 Oct)
        $fam1Payload = $dispatchedInvoices[$inv1Oct];
        $this->assertEquals('550.00', $fam1Payload['invoice']['total_amount']);
        $this->assertEquals('550.00', $fam1Payload['invoice']['remaining_amount']);
        $this->assertStringContainsString("/public/challans/{$fam1}/", $fam1Payload['pdf_attachment']['pdf_url']);

        // Live statement metadata is recorded in payload and persisted on the notification record
        $this->assertEquals('live_consolidated_ledger', $fam1Payload['statement_metadata']['statement_mode']);
        $this->assertTrue($fam1Payload['statement_metadata']['is_live_statement']);
        $this->assertFalse($fam1Payload['statement_metadata']['is_immutable_snapshot']);
        $this->assertNotEmpty($fam1Payload['statement_metadata']['calculated_at']);
        $this->assertEquals('550.00', $fam1Payload['statement_metadata']['dispatched_totals_by_currency'][0]['amount']);

        // Delivery queue pagination & audit record retains dispatch-time calculated amount even if a payment is recorded later
        $queuePage = app(InvoiceDeliveryQueueService::class)->getPaginatedQueue(['batch_id' => $batchRes['batch_id']], $this->superAdmin, 25);
        $this->assertEquals(2, $queuePage->total());
        $fam1QueueRow = collect($queuePage->items())->firstWhere('invoice_id', $inv1Oct);
        $this->assertNotNull($fam1QueueRow->dispatched_calculated_at);
        $this->assertEquals('550.00', $fam1QueueRow->dispatched_totals_by_currency[0]['amount']);

        // Simulate n8n delivery status callback (delivered)
        config(['services.n8n.callback_secret' => $this->webhookSecret]);
        $notif1 = DB::table('notifications')->where('invoice_id', $inv1Oct)->first();
        $cbPayload = [
            'notification_id' => $notif1->id,
            'status' => 'delivered',
            'provider_message_id' => 'wamid.fictional.123',
        ];
        $cbSig = hash_hmac('sha256', json_encode($cbPayload), $this->webhookSecret);
        $this->postJson('/webhook/n8n/callback', $cbPayload, [
            'X-IQRA-Signature' => $cbSig,
        ])->assertStatus(200);

        $this->assertEquals('delivered', DB::table('notifications')->where('id', $notif1->id)->value('status'));

        // 2. Verify Paid Receipt notification workflow remains completely separate and references Paid-Receipt.pdf
        [$famPaid, $stuPaid, $enrPaid] = $this->createFictionalFamilyWithStudent('FAM-P3-JPAID', 'GR-P3-JPAID', 'Zainab Paid', '+966500003339');
        [$invPaidId, $itemPaidId] = $this->createMonthlyInvoiceWithItem($famPaid, $stuPaid, $enrPaid, 'INV-P3-JPAID-10', '2026-10-01', 'SAR', 35000, 35000);

        $paymentId = DB::table('payments')->insertGetId([
            'family_id' => $famPaid,
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'receipt_number' => 'RCP-P3-J-001',
            'received_on' => '2026-10-15',
            'amount_minor' => 35000,
            'unallocated_minor' => 0,
            'currency' => 'SAR',
            'note' => 'Bank Transfer',
            'idempotency_key' => 'idemp-pay-p3-j-001',
            'request_hash' => hash('sha256', 'pay-p3-j-001'),
            'received_by' => $this->superAdmin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payment_allocations')->insert([
            'payment_id' => $paymentId,
            'invoice_item_id' => $itemPaidId,
            'amount_minor' => 35000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invPaidObj = DB::table('invoices')->where('id', $invPaidId)->first();
        $paidPayload = app(InvoicePayloadBuilder::class)->build(
            (object) ['id' => 9010, 'kind' => 'paid', 'event_type' => 'invoice.paid'],
            $invPaidObj,
            'invoice.paid'
        );

        $this->assertEquals('invoice.paid', $paidPayload['event']);
        $this->assertEquals('payment_receipt', $paidPayload['pdf_attachment']['document_type']);
        $this->assertStringEndsWith('/Paid-Receipt.pdf', $paidPayload['pdf_attachment']['receipt_pdf_url']);
        $this->assertEquals(['RCP-P3-J-001'], $paidPayload['payment_details']['verified_receipt_numbers']);
        $this->assertStringEndsWith('/Paid-Receipt.pdf', $paidPayload['payment_details']['receipt_pdf_url']);
    }

    /**
     * Test K: No financial writes
     * Verify preparation, preview, and notification payload creation do not modify
     * invoices, invoice items, payments, or payment allocations.
     */
    public function test_k_preparation_preview_and_payload_creation_perform_zero_financial_writes(): void
    {
        [$familyId, $studentId, $enrollmentId] = $this->createFictionalFamilyWithStudent(
            'FAM-P3-K',
            'GR-P3-K',
            'Mariam Al-Subaie'
        );

        $this->createMonthlyInvoiceWithItem($familyId, $studentId, $enrollmentId, 'INV-P3-K-08', '2026-08-01', 'SAR', 30000, 10000);
        $this->createMonthlyInvoiceWithItem($familyId, $studentId, $enrollmentId, 'INV-P3-K-09', '2026-09-01', 'SAR', 20000, 0);
        [$octInvId] = $this->createMonthlyInvoiceWithItem($familyId, $studentId, $enrollmentId, 'INV-P3-K-10', '2026-10-01', 'SAR', 35000, 0);
        $this->createMonthlyInvoiceWithItem($familyId, $studentId, $enrollmentId, 'INV-P3-K-11', '2026-11-01', 'SAR', 35000, 0);

        $beforeInvoices = DB::table('invoices')->orderBy('id')->get()->toArray();
        $beforeItems = DB::table('invoice_items')->orderBy('id')->get()->toArray();
        $beforePayments = DB::table('payments')->orderBy('id')->get()->toArray();
        $beforeAllocations = DB::table('payment_allocations')->orderBy('id')->get()->toArray();

        // 1. Selection snapshot preparation
        app(BatchDeliveryService::class)->resolveFilteredSelection(
            ['period' => '2026-10', 'webhook_type' => 'monthly'],
            $this->superAdmin,
            ['whatsapp', 'email']
        );

        // 2. Ready to Send preview calculation
        $readyPage = app(InvoiceDeliveryQueueService::class)->getReadyToSendChallans(['period' => '2026-10'], $this->superAdmin);
        $readyRow = $readyPage->items()[0];

        // 3. Notification payload creation
        $octInvoice = DB::table('invoices')->where('id', $octInvId)->first();
        $payload = app(InvoicePayloadBuilder::class)->build(
            (object) ['id' => 9011, 'kind' => 'monthly', 'event_type' => 'invoice.monthly', 'channels' => json_encode(['whatsapp', 'email'])],
            $octInvoice,
            'invoice.monthly'
        );

        // 4. Open public consolidated PDF from notification payload
        $this->get($payload['pdf_attachment']['pdf_url'])->assertStatus(200);
        $this->get($readyRow->consolidated_challan_url)->assertStatus(200);

        // Verify zero mutations on all financial tables
        $this->assertEquals($beforeInvoices, DB::table('invoices')->orderBy('id')->get()->toArray());
        $this->assertEquals($beforeItems, DB::table('invoice_items')->orderBy('id')->get()->toArray());
        $this->assertEquals($beforePayments, DB::table('payments')->orderBy('id')->get()->toArray());
        $this->assertEquals($beforeAllocations, DB::table('payment_allocations')->orderBy('id')->get()->toArray());
    }
}
