<?php

namespace Tests\Feature;

use App\Services\BatchDeliveryService;
use App\Services\ConsolidatedInvoiceBuilder;
use App\Services\InvoiceDeliveryQueueService;
use App\Services\InvoiceSubmissionCoverageService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class InvoiceSubmissionCoverageTest extends TestCase
{
    private object $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'coverage_test', 'database.connections.coverage_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ], 'cache.default' => 'array']);
        // Deliberately no RefreshDatabase, migrations, or seeders: only isolated in-memory fixtures.
        $tables = [
            'families' => "id INTEGER PRIMARY KEY, family_code TEXT, guardian_name TEXT DEFAULT 'Guardian', whatsapp TEXT DEFAULT '+966501234567', email TEXT, contact_verified INT DEFAULT 1, whatsapp_verified INT DEFAULT 1, email_verified INT DEFAULT 0, messaging_opt_in INT DEFAULT 1",
            'billing_runs' => "id INTEGER PRIMARY KEY, frequency TEXT DEFAULT 'monthly', period_key TEXT, period_start TEXT, created_by INT",
            'invoices' => "id INTEGER PRIMARY KEY, family_id INT, billing_run_id INT, campus_id INT DEFAULT 1, department_id INT DEFAULT 1, invoice_number TEXT DEFAULT 'TEST', currency TEXT DEFAULT 'SAR', total_minor INT DEFAULT 10000, paid_minor INT DEFAULT 0, status TEXT DEFAULT 'generated', issued_on TEXT DEFAULT '2026-10-26', due_date TEXT DEFAULT '2026-11-10'",
            'notifications' => "id INTEGER PRIMARY KEY, invoice_id INT, batch_id TEXT, chunk_index INT DEFAULT 1, kind TEXT DEFAULT 'monthly', webhook_type TEXT DEFAULT 'monthly', channel TEXT DEFAULT 'whatsapp', status TEXT DEFAULT 'submitted', submitted_at TEXT DEFAULT '2026-10-30 10:00:00', response_code INT DEFAULT 200, provider_message_id TEXT, delivered_at TEXT, payload TEXT, is_superseded INT DEFAULT 0, event_id TEXT, idempotency_key TEXT",
            'notification_batches' => 'id INTEGER PRIMARY KEY, batch_id TEXT, billing_period TEXT',
            'campuses' => 'id INTEGER PRIMARY KEY, name TEXT',
            'departments' => 'id INTEGER PRIMARY KEY, name TEXT',
            'invoice_items' => 'id INTEGER PRIMARY KEY, invoice_id INT, student_id INT, enrollment_id INT, fee_plan_item_id INT, fee_type_id INT, currency TEXT',
            'students' => "id INTEGER PRIMARY KEY, family_id INT, name TEXT, gr_number TEXT, status TEXT DEFAULT 'active'",
            'enrollments' => 'id INTEGER PRIMARY KEY, student_id INT, grade_label TEXT, status TEXT',
            'payments' => 'id INTEGER PRIMARY KEY, status TEXT, reversed_at TEXT, received_on TEXT',
            'payment_allocations' => 'id INTEGER PRIMARY KEY, invoice_item_id INT, payment_id INT, amount_minor INT',
            'audit_logs' => 'id INTEGER PRIMARY KEY, action TEXT, subject_type TEXT, actor_id INT, subject_id INT',
            'qa_opening_balance_lineage' => 'id INTEGER PRIMARY KEY, invoice_id INT',
            'fee_plan_items' => 'id INTEGER PRIMARY KEY, enrollment_id INT, fee_type_id INT, currency TEXT',
            'student_registration_details' => 'id INTEGER PRIMARY KEY, student_id INT, guardian_name_override TEXT, father_name TEXT',
        ];
        foreach ($tables as $name => $columns) {
            DB::statement("CREATE TABLE $name ($columns)");
        }
        DB::table('campuses')->insert(['id' => 1, 'name' => 'Campus']);
        DB::table('departments')->insert(['id' => 1, 'name' => 'Department']);
        $builder = Mockery::mock(ConsolidatedInvoiceBuilder::class);
        $builder->shouldReceive('summarizeFamiliesOutstanding')->andReturnUsing(function ($ids) {
            return array_fill_keys($ids, ['has_data_integrity_issue' => false, 'is_payable_verified' => true,
                'currencies_map' => ['SAR' => ['currency' => 'SAR', 'total_payable_minor' => 10000]], 'students' => []]);
        });
        $this->app->instance(ConsolidatedInvoiceBuilder::class, $builder);
        Carbon::setTestNow('2026-10-31 12:00:00');
        $this->user = (object) ['role' => 'super_admin', 'id' => 1];
        Http::preventStrayRequests();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::purge('coverage_test');
        parent::tearDown();
    }

    private function invoice(int $family, string $month = '2026-11', array $attributes = []): int
    {
        DB::table('families')->insertOrIgnore(['id' => $family, 'family_code' => 'FAM-'.$family]);
        $run = DB::table('billing_runs')->insertGetId(['period_start' => $month.'-01']);
        return DB::table('invoices')->insertGetId(array_merge(['family_id' => $family, 'billing_run_id' => $run], $attributes));
    }

    private function notification(int $invoice, string $campaign = '2026-10', array $attributes = []): int
    {
        DB::table('notification_batches')->insertOrIgnore(['id' => $campaign === '2026-10' ? 1 : 2,
            'batch_id' => $campaign, 'billing_period' => $campaign]);
        return DB::table('notifications')->insertGetId(array_merge(['invoice_id' => $invoice, 'batch_id' => $campaign], $attributes));
    }

    private function entry(int $notification, string $month = '2026-11', int $amount = 10000): array
    {
        return ['notification_id' => $notification, 'family' => ['family_id' => 999999],
            'invoice' => ['billing_period' => '2026-10', 'later_dues_by_month' => [['month' => $month, 'dues_minor' => $amount]]]];
    }

    private function payload(int $notification, array $entries): void
    {
        DB::table('notifications')->where('id', $notification)->update(['payload' => json_encode(['invoices' => $entries])]);
    }

    public function test_cross_month_coverage_excludes_initial_send_without_changing_history(): void
    {
        $this->invoice(1);
        $n = $this->notification($this->invoice(1, '2026-10'));
        $this->payload($n, [$this->entry($n)]);
        $before = DB::table('notifications')->get()->toJson();
        $coverage = app(InvoiceSubmissionCoverageService::class);
        $this->assertTrue($coverage->forPeriod('2026-11', $this->user)[1]['earlier']);
        $s = app(BatchDeliveryService::class)->resolveFilteredSelection(['period' => '2026-11'], $this->user);
        $this->assertSame(0, $s['total_eligible_recipients']);
        $this->assertSame($before, DB::table('notifications')->get()->toJson());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_chunk_entry_is_matched_to_its_notification_and_only_accepted_rows_count(): void
    {
        $a = $this->notification($this->invoice(1, '2026-10'));
        $b = $this->notification($this->invoice(2, '2026-10'));
        $c = $this->notification($this->invoice(3, '2026-10'), '2026-10', ['status' => 'failed', 'submitted_at' => null, 'response_code' => 500]);
        $entries = [$this->entry($a), $this->entry($b, '2026-12'), $this->entry($c)];
        foreach ([$a, $b, $c] as $id) $this->payload($id, $entries);
        $map = app(InvoiceSubmissionCoverageService::class)->forPeriod('2026-11', $this->user);
        $this->assertSame([1], array_keys($map));
    }

    public function test_merged_renamed_history_follows_current_family_and_deduplicates_overlap(): void
    {
        $oct = $this->invoice(2, '2026-10');
        $a = $this->notification($oct, '2026-10', ['kind' => 'monthly_sup', 'is_superseded' => 1]);
        $this->payload($a, [$this->entry($a)]);
        $this->notification($this->invoice(2), '2026-11');
        $summary = app(InvoiceSubmissionCoverageService::class)->summarize('2026-11', $this->user);
        $this->assertSame(1, $summary['earlier_accepted_families']);
        $this->assertSame(1, $summary['specific_accepted_families']);
        $this->assertSame(1, $summary['covered_families']);
        $this->assertSame(1, $summary['overlap_families']);
        $this->assertSame(0, $summary['confirmed_families']);
    }

    public function test_acceptance_and_provider_delivery_evidence_are_distinct(): void
    {
        $this->notification($this->invoice(1), '2026-11');
        $this->notification($this->invoice(2), '2026-11', ['status' => 'delivered']);
        $this->notification($this->invoice(3), '2026-11', ['status' => 'read', 'provider_message_id' => 'wamid.test']);
        $this->notification($this->invoice(4), '2026-11', ['status' => 'delivered', 'provider_message_id' => 'email.test', 'channel' => 'email']);
        $s = app(InvoiceSubmissionCoverageService::class)->summarize('2026-11', $this->user);
        $this->assertSame(4, $s['covered_families']);
        $this->assertSame(1, $s['confirmed_families']);
    }

    public function test_seven_families_with_submission_on_another_settled_or_voided_invoice_are_excluded_everywhere(): void
    {
        for ($family = 1; $family <= 7; $family++) {
            $this->invoice($family);
            $old = $this->invoice($family, '2026-11', ['status' => $family % 2 ? 'paid' : 'voided', 'paid_minor' => 10000]);
            $this->notification($old, '2026-11');
        }
        $uncovered = $this->invoice(8);
        $a = $this->notification($this->invoice(9, '2026-10'));
        $this->invoice(9);
        $this->payload($a, [$this->entry($a)]);
        $batch = app(BatchDeliveryService::class)->resolveFilteredSelection(['period' => '2026-11'], $this->user);
        $queue = app(InvoiceDeliveryQueueService::class);
        $page = $queue->getReadyToSendChallans(['period' => '2026-11'], $this->user);
        $kpi = $queue->getDashboardKpiStats('2026-11', $this->user);
        $this->assertSame([$uncovered], $batch['eligible_invoice_ids']);
        $this->assertSame(1, $batch['total_eligible_recipients']);
        $this->assertSame(1, $page->total());
        $this->assertSame([8], $page->getCollection()->pluck('family_id')->all());
        $this->assertSame(1, $kpi['ready_families']);
    }

    public function test_pending_initial_is_blocked_but_unaccepted_failed_or_zero_dues_do_not_create_coverage(): void
    {
        $this->notification($this->invoice(1), '2026-11', ['status' => 'queued', 'submitted_at' => null, 'response_code' => null]);
        $b = $this->notification($this->invoice(2, '2026-10'));
        $this->payload($b, [$this->entry($b, '2026-11', 0)]);
        $c = $this->notification($this->invoice(3, '2026-10'), '2026-10', ['status' => 'failed', 'submitted_at' => null, 'response_code' => 500]);
        $this->payload($c, [$this->entry($c)]);
        $service = app(InvoiceSubmissionCoverageService::class);
        $map = $service->forPeriod('2026-11', $this->user);
        $this->assertSame([1], array_keys($map));
        $this->assertFalse($map[1]['accepted']);
        $this->assertTrue($map[1]['pending']);
    }

    public function test_accepted_then_failed_remains_covered_and_does_not_gain_a_reminder_bypass(): void
    {
        $n = $this->notification($this->invoice(1, '2026-10'), '2026-10', ['status' => 'failed']);
        $this->payload($n, [$this->entry($n)]);
        $this->invoice(1);
        $s = app(BatchDeliveryService::class)->resolveFilteredSelection(['period' => '2026-11', 'reminder' => true], $this->user);
        $this->assertSame(0, $s['total_eligible_recipients']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('separately authorized reminder');
        app(InvoiceSubmissionCoverageService::class)->assertInitialAllowed(1, '2026-11', $this->user);
    }

    public function test_coverage_respects_staff_scope(): void
    {
        $a = $this->notification($this->invoice(1, '2026-10', ['campus_id' => 2]));
        $this->payload($a, [$this->entry($a)]);
        $staff = (object) ['role' => 'staff', 'campus_id' => 1, 'department_id' => 1];
        $this->assertSame([], app(InvoiceSubmissionCoverageService::class)->forPeriod('2026-11', $staff));
    }
    public function test_covered_family_cannot_stage_an_initial_batch_or_mutate_notification_history(): void
    {
        $this->invoice(1);
        $n = $this->notification($this->invoice(1, '2026-10'));
        $this->payload($n, [$this->entry($n)]);
        $builder = $this->app->make(ConsolidatedInvoiceBuilder::class);
        $builder->shouldReceive('canDispatchFullFamilyStatement')->andReturnTrue();
        $webhooks = Mockery::mock(\App\Services\WebhookSettingService::class);
        $webhooks->shouldReceive('getSettings')->with('monthly')->andReturn(new \App\Models\WebhookSetting([
            'webhook_type' => 'monthly', 'url' => 'https://example.test/never-send', 'is_enabled' => true,
        ]));
        $this->app->instance(\App\Services\WebhookSettingService::class, $webhooks);
        $before = DB::table('notifications')->get()->toJson();
        $result = app(BatchDeliveryService::class)->createAndDispatchBatch(
            ['period' => '2026-11'], [], ['whatsapp'], $this->user, '2026-11'
        );
        $this->assertSame('empty', $result['status']);
        $this->assertNull($result['batch_id']);
        $this->assertSame($before, DB::table('notifications')->get()->toJson());
        $this->assertSame(1, DB::table('notification_batches')->count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_legacy_page_send_rejects_covered_family_before_any_write(): void
    {
        $nov = $this->invoice(1);
        $n = $this->notification($this->invoice(1, '2026-10'));
        $this->payload($n, [$this->entry($n)]);
        $this->app->make(ConsolidatedInvoiceBuilder::class)->shouldReceive('canDispatchFullFamilyStatement')->andReturnTrue();
        $webhooks = Mockery::mock(\App\Services\WebhookSettingService::class);
        $webhooks->shouldReceive('getSettings')->andReturn(new \App\Models\WebhookSetting([
            'webhook_type' => 'monthly', 'url' => 'https://example.test/never-send', 'is_enabled' => true,
        ]));
        $this->app->instance(\App\Services\WebhookSettingService::class, $webhooks);
        $user = new \App\Models\User();
        $user->setRawAttributes(['id' => 1, 'role' => 'super_admin', 'is_active' => true]);
        $before = DB::table('notifications')->get()->toJson();
        $response = $this->actingAs($user)->post('/invoices/send', [
            'invoice_ids' => [$nov], 'channels' => ['whatsapp'], 'webhook_type' => 'monthly',
            'confirmed_grades' => ['Not assigned'], 'review_confirmed' => '1',
        ]);
        $response->assertSessionHasErrors(['invoice_ids' => 'This family already has a submission covering 2026-11 dues or a pending initial submission. Review a separately authorized reminder instead.']);
        $this->assertSame($before, DB::table('notifications')->get()->toJson());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_table_kpi_and_selector_share_family_search_and_contact_rules(): void
    {
        $this->invoice(1);
        $this->invoice(2);
        DB::table('families')->where('id', 2)->update(['contact_verified' => 0, 'whatsapp_verified' => 0]);
        $filters = ['period' => '2026-11', 'search' => 'FAM-1'];
        $queue = app(InvoiceDeliveryQueueService::class);
        $this->assertSame(1, $queue->getDashboardKpiStats('2026-11', $this->user, $filters)['ready_families']);
        $this->assertSame(1, $queue->getReadyToSendChallans($filters, $this->user)->total());
        $this->assertSame(1, app(BatchDeliveryService::class)->resolveFilteredSelection($filters, $this->user)['total_eligible_recipients']);
        $this->assertSame(1, $queue->getDashboardKpiStats('2026-11', $this->user)['ready_families']);
    }

    public function test_malformed_payload_does_not_invent_cross_month_coverage_or_hide_direct_history(): void
    {
        $this->notification($this->invoice(1, '2026-10'), '2026-10', ['payload' => '"invalid payload"']);
        $this->notification($this->invoice(2), '2026-11', ['payload' => '{broken']);
        $map = app(InvoiceSubmissionCoverageService::class)->forPeriod('2026-11', $this->user);
        $this->assertSame([2], array_keys($map));
        $this->assertTrue($map[2]['specific']);
    }

}
