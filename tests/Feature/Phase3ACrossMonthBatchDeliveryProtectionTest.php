<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BatchDeliveryService;
use App\Services\WebhookSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class Phase3ACrossMonthBatchDeliveryProtectionTest extends TestCase
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
            'email' => 'admin_phase3a@example.test',
            'password' => bcrypt('password123'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $monthlyUrl = 'https://n8n.example.test/webhook/monthly-invoices';
        $paidUrl = 'https://n8n.example.test/webhook/paid-invoices';
        $secret = 'test-phase3a-secret-key';

        $svc = app(WebhookSettingService::class);
        $svc->updateSettings('monthly', ['url' => $monthlyUrl, 'secret' => $secret, 'is_enabled' => true], $superAdmin->id);
        $svc->updateSettings('paid', ['url' => $paidUrl, 'secret' => $secret, 'is_enabled' => true], $superAdmin->id);

        $decRun = DB::table('billing_runs')->insertGetId([
            'campus_id' => $campus,
            'department_id' => $dept,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-12-01..2026-12-31',
            'period_start' => '2026-12-01',
            'period_end' => '2026-12-31',
            'due_date' => '2026-12-25',
            'created_by' => $superAdmin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $janRun = DB::table('billing_runs')->insertGetId([
            'campus_id' => $campus,
            'department_id' => $dept,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2027-01-01..2027-01-31',
            'period_start' => '2027-01-01',
            'period_end' => '2027-01-31',
            'due_date' => '2027-01-25',
            'created_by' => $superAdmin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('superAdmin', 'campus', 'dept', 'program', 'decRun', 'janRun', 'monthlyUrl', 'paidUrl');
    }

    private function createFamilyWithInvoice(
        array $data,
        int $familyId,
        string $invoiceNumber,
        int $billingRunId,
        string $periodStart,
        int $amountMinor = 140000,
        int $paidMinor = 0,
        string $grade = 'Grade 1'
    ): array {
        $famExists = DB::table('families')->where('id', $familyId)->exists();
        if (!$famExists) {
            DB::table('families')->insert([
                'id' => $familyId,
                'family_code' => "FAM-{$familyId}",
                'guardian_name' => "Guardian {$familyId}",
                'whatsapp' => "+96650" . str_pad((string)$familyId, 7, '0', STR_PAD_LEFT),
                'contact_verified' => true,
                'whatsapp_verified' => true,
                'messaging_opt_in' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $stuId = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'name' => "Student {$familyId}",
            'gr_number' => "GR-{$familyId}-" . rand(100, 999),
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
            'billing_run_id' => $billingRunId,
            'family_id' => $familyId,
            'campus_id' => $data['campus'],
            'department_id' => $data['dept'],
            'currency' => 'SAR',
            'invoice_number' => $invoiceNumber,
            'issued_on' => $periodStart,
            'due_date' => now()->addDays(15)->toDateString(),
            'total_minor' => $amountMinor,
            'paid_minor' => $paidMinor,
            'status' => ($paidMinor >= $amountMinor && $amountMinor > 0) ? 'paid' : 'issued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('invoice_items')->insert([
            'invoice_id' => $invId,
            'student_id' => $stuId,
            'enrollment_id' => $enrId,
            'description' => "Tuition {$grade}",
            'period_key' => "monthly:{$periodStart}:{$familyId}",
            'period_start' => $periodStart,
            'period_end' => $periodStart,
            'currency' => 'SAR',
            'amount_minor' => $amountMinor,
            'discount_minor' => 0,
            'net_minor' => $amountMinor,
            'paid_minor' => $paidMinor,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('invId', 'stuId', 'enrId');
    }

    private function captureFinancialState(): array
    {
        return [
            'invoices_count' => DB::table('invoices')->count(),
            'invoices_checksum' => DB::table('invoices')->sum('total_minor') . ':' . DB::table('invoices')->sum('paid_minor'),
            'invoice_items_count' => DB::table('invoice_items')->count(),
            'invoice_items_checksum' => DB::table('invoice_items')->sum('net_minor') . ':' . DB::table('invoice_items')->sum('paid_minor'),
            'payments_count' => DB::table('payments')->count(),
            'payment_allocations_count' => DB::table('payment_allocations')->count(),
            'billing_runs_count' => DB::table('billing_runs')->count(),
        ];
    }

    /**
     * Test A: Cross-month duplicate prevention
     * Stage an initial December delivery for Family 5644.
     * Before completion, attempt another initial January delivery covering the same outstanding obligations.
     * Expected: The second overlapping initial send is safely rejected or skipped.
     */
    public function test_a_cross_month_duplicate_prevention(): void
    {
        Http::preventStrayRequests();
        Queue::fake();

        $data = $this->setupTestData();
        $batchService = app(BatchDeliveryService::class);

        // Family 5644 with December SCH-2026-0018720 and January SCH-2026-0018721
        $decInv = $this->createFamilyWithInvoice($data, 5644, 'SCH-2026-0018720', $data['decRun'], '2026-12-01', 140000, 0);
        $janInv = $this->createFamilyWithInvoice($data, 5644, 'SCH-2026-0018721', $data['janRun'], '2027-01-01', 140000, 0);

        // Stage December delivery
        $decBatch = $batchService->createAndDispatchBatch(
            ['period' => '2026-12'],
            ['Grade 1'],
            ['whatsapp'],
            $data['superAdmin'],
            '2026-12',
            [$decInv['invId']],
            null,
            true,
            false // do not worker-dispatch yet (before completion)
        );

        $this->assertSame('queued', $decBatch['status']);
        $this->assertNotNull($decBatch['batch_id']);

        // Verify December notification is queued
        $decNotif = DB::table('notifications')->where('invoice_id', $decInv['invId'])->first();
        $this->assertNotNull($decNotif);
        $this->assertSame('queued', $decNotif->status);

        // Attempt initial January delivery for the same family while December is in flight
        // 1. Selection resolution preview must safely exclude the family
        $janSnapshot = $batchService->resolveFilteredSelection(['period' => '2027-01'], $data['superAdmin']);
        $this->assertSame(0, $janSnapshot['total_eligible_recipients']);
        $this->assertArrayNotHasKey($janInv['invId'], array_flip($janSnapshot['eligible_invoice_ids']));
        $this->assertGreaterThan(0, $janSnapshot['excluded_breakdown']['already_queued']);

        // 2. Direct batch staging boundary must reject or return empty
        $janBatch = $batchService->createAndDispatchBatch(
            ['period' => '2027-01'],
            ['Grade 1'],
            ['whatsapp'],
            $data['superAdmin'],
            '2027-01',
            [$janInv['invId']],
            null,
            true,
            false
        );

        $this->assertSame('empty', $janBatch['status']);
        $this->assertNull($janBatch['batch_id']);

        // Assert: No notification was created for January invoice
        $janNotif = DB::table('notifications')->where('invoice_id', $janInv['invId'])->first();
        $this->assertNull($janNotif);
    }

    /**
     * Test B: Concurrent operators
     * Simulate two initial batch approvals for overlapping obligations.
     * Expected: Only one valid initial delivery is staged.
     */
    public function test_b_concurrent_operators(): void
    {
        Http::preventStrayRequests();
        Queue::fake();

        $data = $this->setupTestData();
        $batchService = app(BatchDeliveryService::class);

        $decInv = $this->createFamilyWithInvoice($data, 5644, 'SCH-2026-0018720', $data['decRun'], '2026-12-01', 140000, 0);
        $janInv = $this->createFamilyWithInvoice($data, 5644, 'SCH-2026-0018721', $data['janRun'], '2027-01-01', 140000, 0);

        // Operator 1 and Operator 2 both resolve snapshots before either commits
        $op1Snapshot = $batchService->resolveFilteredSelection(['period' => '2026-12'], $data['superAdmin']);
        $op2Snapshot = $batchService->resolveFilteredSelection(['period' => '2027-01'], $data['superAdmin']);

        $this->assertSame(1, $op1Snapshot['total_eligible_recipients']);
        $this->assertSame(1, $op2Snapshot['total_eligible_recipients']);

        // Operator 1 commits December
        $op1Result = $batchService->createAndDispatchBatch(
            ['period' => '2026-12'],
            ['Grade 1'],
            ['whatsapp'],
            $data['superAdmin'],
            '2026-12',
            [$decInv['invId']],
            $op1Snapshot['snapshot_id'],
            true,
            false
        );
        $this->assertSame('queued', $op1Result['status']);

        // Case 1: Operator 2 attempts January commit using pre-resolved snapshot ID
        // Rejected because snapshot is now stale due to overlapping delivery
        try {
            $batchService->createAndDispatchBatch(
                ['period' => '2027-01'],
                ['Grade 1'],
                ['whatsapp'],
                $data['superAdmin'],
                '2027-01',
                [$janInv['invId']],
                $op2Snapshot['snapshot_id'],
                true,
                false
            );
            $this->fail('Operator 2 staging should have been rejected.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('outdated', $e->getMessage());
        }

        // Case 2: Operator 2 attempts staging directly at the atomic transaction boundary
        // Staging transaction under lockForUpdate rejects active overlapping delivery
        try {
            // Re-resolve snapshot with explicit IDs to bypass initial preview check and test atomic boundary
            $batchService->createAndDispatchBatch(
                ['period' => '2027-01'],
                ['Grade 1'],
                ['whatsapp'],
                $data['superAdmin'],
                '2027-01',
                [$janInv['invId']],
                null,
                true,
                false
            );
            // If resolveFilteredSelection safely filtered it, status will be 'empty'
            $janNotifs = DB::table('notifications')->where('invoice_id', $janInv['invId'])->count();
            $this->assertSame(0, $janNotifs);
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Selection changed', $e->getMessage());
        }

        // Verify only one valid initial delivery was staged across both attempts
        $totalStaged = DB::table('notifications')->whereIn('invoice_id', [$decInv['invId'], $janInv['invId']])->count();
        $this->assertSame(1, $totalStaged);
    }

    /**
     * Test C: Legitimate future billing
     * After a previous accepted delivery, create a genuinely new obligation not covered by the earlier submission.
     * Expected: The family can receive the new invoice.
     */
    public function test_c_legitimate_future_billing(): void
    {
        Http::preventStrayRequests();
        Queue::fake();

        $data = $this->setupTestData();
        $batchService = app(BatchDeliveryService::class);

        // December invoice delivered and accepted
        $decInv = $this->createFamilyWithInvoice($data, 5644, 'SCH-2026-0018720', $data['decRun'], '2026-12-01', 140000, 0);
        $batchId = 'BATCH-TEST-DEC';
        DB::table('notification_batches')->insert([
            'batch_id' => $batchId,
            'webhook_type' => 'monthly',
            'billing_period' => '2026-12',
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $decNotifId = DB::table('notifications')->insertGetId([
            'invoice_id' => $decInv['invId'],
            'batch_id' => $batchId,
            'kind' => 'monthly',
            'webhook_type' => 'monthly',
            'channel' => 'whatsapp',
            'status' => 'submitted',
            'submitted_at' => now(),
            'response_code' => 200,
            'payload' => json_encode([
                'invoices' => [[
                    'notification_id' => 1,
                    'invoice' => [
                        'billing_period' => '2026-12',
                        'dues_by_month' => [['month' => '2026-12', 'dues_minor' => 140000]],
                    ],
                ]],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Update payload to reflect exact notification id
        DB::table('notifications')->where('id', $decNotifId)->update([
            'payload' => json_encode([
                'invoices' => [[
                    'notification_id' => $decNotifId,
                    'invoice' => [
                        'billing_period' => '2026-12',
                        'dues_by_month' => [['month' => '2026-12', 'dues_minor' => 140000]],
                    ],
                ]],
            ]),
        ]);

        // Now January arrives: genuinely new obligation not covered by December's submission
        $janInv = $this->createFamilyWithInvoice($data, 5644, 'SCH-2026-0018721', $data['janRun'], '2027-01-01', 140000, 0);

        // January must be eligible
        $janSnapshot = $batchService->resolveFilteredSelection(['period' => '2027-01'], $data['superAdmin']);
        $this->assertSame(1, $janSnapshot['total_eligible_recipients']);
        $this->assertContains($janInv['invId'], $janSnapshot['eligible_invoice_ids']);

        // Stage January delivery
        $janBatch = $batchService->createAndDispatchBatch(
            ['period' => '2027-01'],
            ['Grade 1'],
            ['whatsapp'],
            $data['superAdmin'],
            '2027-01',
            [$janInv['invId']],
            null,
            true,
            false
        );

        $this->assertSame('queued', $janBatch['status']);
        $this->assertNotNull($janBatch['batch_id']);
        $this->assertTrue(DB::table('notifications')->where('invoice_id', $janInv['invId'])->where('status', 'queued')->exists());
    }

    /**
     * Test D: Failed delivery retry
     * A failed attempt is retried using the existing authorized retry mechanism.
     * Expected: Valid retry behavior continues.
     */
    public function test_d_failed_delivery_retry(): void
    {
        Http::preventStrayRequests();
        Queue::fake();

        $data = $this->setupTestData();
        $batchService = app(BatchDeliveryService::class);

        $decInv = $this->createFamilyWithInvoice($data, 5644, 'SCH-2026-0018720', $data['decRun'], '2026-12-01', 140000, 0);
        $batchId = 'BATCH-FAILED-1';
        DB::table('notification_batches')->insert([
            'batch_id' => $batchId,
            'webhook_type' => 'monthly',
            'billing_period' => '2026-12',
            'status' => 'failed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $notifId = DB::table('notifications')->insertGetId([
            'invoice_id' => $decInv['invId'],
            'batch_id' => $batchId,
            'kind' => 'monthly',
            'webhook_type' => 'monthly',
            'channel' => 'whatsapp',
            'status' => 'failed',
            'error' => 'HTTP 500 n8n timeout',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $retryResult = $batchService->retryFailedBatchRecipients($batchId, $data['superAdmin']);
        $this->assertSame(1, $retryResult['retried_count']);

        $updatedNotif = DB::table('notifications')->where('id', $notifId)->first();
        $this->assertSame('queued', $updatedNotif->status);
        $this->assertNull($updatedNotif->error);
    }

    /**
     * Test E: Manual resend
     * An authorized operator intentionally resends an existing document.
     * Expected: Existing supported resend behavior remains functional and auditable.
     */
    public function test_e_manual_resend(): void
    {
        Http::preventStrayRequests();
        Queue::fake();

        $data = $this->setupTestData();

        $decInv = $this->createFamilyWithInvoice($data, 5644, 'SCH-2026-0018720', $data['decRun'], '2026-12-01', 140000, 0);
        $batchId = 'BATCH-BLOCKED-1';
        DB::table('notification_batches')->insert([
            'batch_id' => $batchId,
            'webhook_type' => 'monthly',
            'billing_period' => '2026-12',
            'status' => 'blocked',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $notifId = DB::table('notifications')->insertGetId([
            'invoice_id' => $decInv['invId'],
            'batch_id' => $batchId,
            'kind' => 'monthly',
            'webhook_type' => 'monthly',
            'channel' => 'whatsapp',
            'status' => 'blocked',
            'error' => 'Blocked: missing contact verification',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Fix contact verification
        DB::table('families')->where('id', 5644)->update(['contact_verified' => true, 'whatsapp_verified' => true, 'messaging_opt_in' => true]);

        // Operator retries through controller endpoint
        $response = $this->actingAs($data['superAdmin'])->post("/invoices/notifications/{$notifId}/retry");
        $response->assertSessionHasNoErrors();

        $updatedNotif = DB::table('notifications')->where('id', $notifId)->first();
        $this->assertSame('queued', $updatedNotif->status);

        // Audit log entry must exist
        $audit = DB::table('audit_logs')->where('action', 'notification.retry')->where('subject_id', $notifId)->first();
        $this->assertNotNull($audit);
        $this->assertSame($data['superAdmin']->id, (int)$audit->actor_id);
    }

    /**
     * Test F: Financial integrity
     * Before and after staging tests, verify that no unintended mutations occur in financial tables.
     */
    public function test_f_financial_integrity(): void
    {
        Http::preventStrayRequests();
        Queue::fake();

        $data = $this->setupTestData();
        $batchService = app(BatchDeliveryService::class);

        $decInv = $this->createFamilyWithInvoice($data, 5644, 'SCH-2026-0018720', $data['decRun'], '2026-12-01', 140000, 0);
        $janInv = $this->createFamilyWithInvoice($data, 5644, 'SCH-2026-0018721', $data['janRun'], '2027-01-01', 140000, 0);

        $beforeFinancialState = $this->captureFinancialState();

        // Perform staging attempt
        $batchService->createAndDispatchBatch(
            ['period' => '2026-12'],
            ['Grade 1'],
            ['whatsapp'],
            $data['superAdmin'],
            '2026-12',
            [$decInv['invId']],
            null,
            true,
            false
        );

        // Attempt duplicate January staging
        $batchService->createAndDispatchBatch(
            ['period' => '2027-01'],
            ['Grade 1'],
            ['whatsapp'],
            $data['superAdmin'],
            '2027-01',
            [$janInv['invId']],
            null,
            true,
            false
        );

        $afterFinancialState = $this->captureFinancialState();

        $this->assertSame($beforeFinancialState, $afterFinancialState, 'Financial ledger records must have zero unintended mutations.');
    }

    /**
     * Test G: Route and workflow regression
     * Verify all existing invoice routes remain unchanged.
     */
    public function test_g_route_and_workflow_regression(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'invoices') || str_contains($r->uri(), 'invoices'))
            ->map(fn ($r) => implode('|', $r->methods()) . ' ' . $r->uri())
            ->values();

        $this->assertCount(15, $routes);

        $expectedUris = [
            'invoices',
            'invoices/send',
            'invoices/send-batch',
            'invoices/selection-snapshot',
            'invoices/queue',
            'invoices/queue/export',
            'invoices/batches/{batch}',
            'invoices/batches/{batch}/retry-failed',
            'invoices/notifications/{notification}/retry',
            'invoices/notifications/process-queue',
            'invoices/paid/send-queue',
            'invoices/{invoice}/pdf',
            'public/invoices/{invoice}/pdf',
            'public/invoices/{invoice}/Monthly-Fee-Invoice.pdf',
            'public/invoices/{invoice}/{token}/Monthly-Fee-Invoice.pdf',
        ];

        foreach ($expectedUris as $uri) {
            $matched = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === $uri);
            $this->assertNotNull($matched, "Route with URI {$uri} must remain registered.");
        }
    }
}
