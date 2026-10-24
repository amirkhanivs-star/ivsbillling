<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\InvoiceDeliveryQueueService;
use App\Services\WebhookSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvoiceUiHotfixTest extends TestCase
{
    use RefreshDatabase;

    private function setupTestData(): array
    {
        $this->seed();

        $campus = DB::table('campuses')->value('id');
        $dept = DB::table('departments')->value('id');
        $program = DB::table('programs')->value('id');

        $superAdmin = User::create([
            'name' => 'Super Admin Hotfix',
            'email' => 'admin_hotfix_' . uniqid() . '@example.test',
            'password' => bcrypt('password123'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $svc = app(WebhookSettingService::class);
        $svc->updateSettings('monthly', ['url' => 'https://n8n.example.com/webhook/test', 'secret' => 'test-secret-123', 'is_enabled' => true], $superAdmin->id);

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

        return compact('superAdmin', 'campus', 'dept', 'program', 'run');
    }

    private function createFamilyWithInvoice(
        array $data,
        int $index,
        string $grade = 'Grade 1',
        bool $verified = true,
        bool $consented = true,
        ?string $whatsapp = null,
        string $period = '2026-10'
    ): array {
        $phone = $whatsapp ?? ("+96650" . str_pad((string)$index, 7, '0', STR_PAD_LEFT));

        $famId = DB::table('families')->insertGetId([
            'family_code' => "FAM-HF-{$index}",
            'guardian_name' => "Guardian {$index}",
            'whatsapp' => $phone,
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
            'gr_number' => "GR-HF-{$index}",
            'status' => 'active',
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

        $runId = $data['run'];
        if ($period !== '2026-10') {
            $runId = DB::table('billing_runs')->insertGetId([
                'campus_id' => $data['campus'],
                'department_id' => $data['dept'],
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
            'campus_id' => $data['campus'],
            'department_id' => $data['dept'],
            'currency' => 'SAR',
            'invoice_number' => "INV-HF-{$index}",
            'issued_on' => "{$period}-01",
            'due_date' => "{$period}-25",
            'total_minor' => 10000,
            'paid_minor' => 0,
            'status' => 'generated',
            'approved_to_send' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invId,
            'student_id' => $stuId,
            'enrollment_id' => $enrId,
            'description' => 'Monthly Tuition',
            'period_key' => "monthly:{$period}:test{$index}",
            'currency' => 'SAR',
            'amount_minor' => 10000,
            'net_minor' => 10000,
            'paid_minor' => 0,
            'period_start' => "{$period}-01",
            'period_end' => "{$period}-30",
            'status' => 'issued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('famId', 'stuId', 'enrId', 'invId', 'runId');
    }

    /**
     * Bug 1: Test Processing tab opens without HTTP 500 when notifications exist.
     */
    public function test_processing_tab_renders_with_queued_notifications_without_created_at_mismatch(): void
    {
        $data = $this->setupTestData();
        $f = $this->createFamilyWithInvoice($data, 101);

        // Insert a queued notification
        $notifId = DB::table('notifications')->insertGetId([
            'invoice_id' => $f['invId'],
            'channel' => 'whatsapp',
            'channels' => json_encode(['whatsapp']),
            'kind' => 'monthly',
            'webhook_type' => 'monthly',
            'event_type' => 'invoice.monthly',
            'status' => 'queued',
            'idempotency_key' => 'test-idemp-101',
            'event_id' => 'evt-101',
            'batch_id' => 'BATCH-TEST-101',
            'chunk_index' => 1,
            'created_at' => '2026-10-24 10:00:00',
            'updated_at' => '2026-10-24 10:00:00',
        ]);

        $res = $this->actingAs($data['superAdmin'])->get('/invoices?tab=processing&period=2026-10');
        $res->assertStatus(200);
        $res->assertSee('BATCH-TEST-101');
        $res->assertSee('QUEUED');
        $res->assertSee('2026-10-24 10:00');
    }

    /**
     * Bug 1: Test Processing tab renders cleanly when zero notifications exist.
     */
    public function test_processing_tab_renders_when_zero_notifications_exist(): void
    {
        $data = $this->setupTestData();
        $res = $this->actingAs($data['superAdmin'])->get('/invoices?tab=processing&period=2026-10');
        $res->assertStatus(200);
        $res->assertSee('No background batch jobs currently in-flight');
    }

    /**
     * Bug 2: Test Needs Attention displays contact-ineligible families even if no notification exists.
     */
    public function test_needs_attention_shows_contact_ineligible_families_without_notifications(): void
    {
        $data = $this->setupTestData();

        // 1. Missing phone
        $f1 = $this->createFamilyWithInvoice($data, 201, 'Grade 1', true, true, '');
        // 2. Unverified
        $f2 = $this->createFamilyWithInvoice($data, 202, 'Grade 1', false, true, '+966501234567');
        // 3. No opt-in
        $f3 = $this->createFamilyWithInvoice($data, 203, 'Grade 1', true, false, '+966501234568');
        // 4. Eligible (should NOT appear in Needs Attention)
        $f4 = $this->createFamilyWithInvoice($data, 204, 'Grade 1', true, true, '+966501234569');

        $res = $this->actingAs($data['superAdmin'])->get('/invoices?tab=attention&period=2026-10');
        $res->assertStatus(200);

        $res->assertSee('FAM-HF-201');
        $res->assertSee('Missing WhatsApp contact number');

        $res->assertSee('FAM-HF-202');
        $res->assertSee('Contact number has not been verified');

        $res->assertSee('FAM-HF-203');
        $res->assertSee('Parent has not provided messaging permission');

        $res->assertDontSee('FAM-HF-204');
    }

    /**
     * Bug 2: Test Needs Attention displays failed and blocked notifications.
     */
    public function test_needs_attention_shows_failed_and_blocked_notifications(): void
    {
        $data = $this->setupTestData();
        $f = $this->createFamilyWithInvoice($data, 301, 'Grade 1', true, true);

        // Notification failed
        DB::table('notifications')->insert([
            'invoice_id' => $f['invId'],
            'channel' => 'whatsapp',
            'kind' => 'monthly',
            'status' => 'failed',
            'error' => 'HTTP 500 n8n downstream failure',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $res = $this->actingAs($data['superAdmin'])->get('/invoices?tab=attention&period=2026-10');
        $res->assertStatus(200);
        $res->assertSee('FAM-HF-301');
        $res->assertSee('FAILED');
        $res->assertSee('HTTP 500 n8n downstream failure');
    }

    /**
     * Bug 2: Test KPI count and table pagination total agree and deduplicate overlapping cases.
     */
    public function test_needs_attention_kpi_and_table_totals_agree_with_deduplication(): void
    {
        $data = $this->setupTestData();

        // Family has contact issue AND a blocked notification (overlapping case)
        $f = $this->createFamilyWithInvoice($data, 401, 'Grade 1', false, false, '+966501234567');

        DB::table('notifications')->insert([
            'invoice_id' => $f['invId'],
            'channel' => 'whatsapp',
            'kind' => 'monthly',
            'status' => 'blocked',
            'error' => 'Unverified contact',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $qs = app(InvoiceDeliveryQueueService::class);
        $kpi = $qs->getDashboardKpiStats('2026-10', $data['superAdmin']);
        $page = $qs->getNeedsAttentionCases(['period' => '2026-10'], $data['superAdmin'], 25);

        // Must be exactly 1, not 2!
        $this->assertSame(1, $kpi['attention']);
        $this->assertSame(1, $page->total());
        $this->assertSame($kpi['attention'], $page->total());
    }

    /**
     * Bug 2: Resolving contact issue removes family from active Needs Attention.
     */
    public function test_resolving_contact_issue_removes_family_from_needs_attention(): void
    {
        $data = $this->setupTestData();
        $f = $this->createFamilyWithInvoice($data, 501, 'Grade 1', false, false, '+966501234567');

        $qs = app(InvoiceDeliveryQueueService::class);
        $this->assertSame(1, $qs->getDashboardKpiStats('2026-10', $data['superAdmin'])['attention']);

        // Update contact via route
        $res = $this->actingAs($data['superAdmin'])->post("/families/{$f['famId']}/contact", [
            'guardian_name' => 'Guardian Resolved',
            'whatsapp' => '+966501234567',
            'contact_verified' => 1,
            'messaging_opt_in' => 1,
        ]);
        $res->assertSessionHasNoErrors();

        // Family is now verified and opted in -> disappears from Needs Attention!
        $this->assertSame(0, $qs->getDashboardKpiStats('2026-10', $data['superAdmin'])['attention']);
        $page = $qs->getNeedsAttentionCases(['period' => '2026-10'], $data['superAdmin'], 25);
        $this->assertSame(0, $page->total());
    }
}
