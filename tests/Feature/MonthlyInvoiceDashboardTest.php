<?php

namespace Tests\Feature;

use App\Services\InvoiceDeliveryQueueService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MonthlyInvoiceDashboardTest extends TestCase
{
    private InvoiceDeliveryQueueService $service;

    protected function setUp(): void
    {
        parent::setUp();
        // Dedicated in-memory fixtures: no migrations, seeders, or application records.
        config(['database.default' => 'dashboard_test', 'database.connections.dashboard_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        Schema::create('billing_runs', function (Blueprint $t) {
            $t->id();
            $t->string('frequency')->default('monthly');
            $t->string('period_start');
        });
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->integer('billing_run_id');
            $t->integer('family_id')->default(1);
            $t->integer('campus_id')->default(1);
            $t->integer('department_id')->default(1);
            $t->string('currency')->default('SAR');
            $t->string('invoice_number')->default('DASHBOARD-TEST');
        });
        foreach (['families', 'departments', 'campuses'] as $table) {
            Schema::create($table, fn (Blueprint $t) => $t->id());
        }
        Schema::create('notification_batches', function (Blueprint $t) {
            $t->string('batch_id')->primary();
            $t->string('billing_period')->nullable();
        });
        Schema::create('notifications', function (Blueprint $t) {
            $t->id();
            $t->integer('invoice_id');
            $t->string('batch_id')->nullable();
            $t->string('kind')->default('monthly');
            $t->string('webhook_type')->default('monthly');
            $t->string('status')->default('submitted');
            $t->boolean('is_superseded')->default(false);
        });
        Carbon::setTestNow(Carbon::parse('2026-10-31 12:00:00'));
        $this->service = app(InvoiceDeliveryQueueService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::purge('dashboard_test');
        parent::tearDown();
    }

    private function invoice(string $period, int $campus = 1): int
    {
        $run = DB::table('billing_runs')->insertGetId(['period_start' => "$period-01"]);
        return DB::table('invoices')->insertGetId(['billing_run_id' => $run, 'campus_id' => $campus]);
    }

    public function test_dropdown_months_are_unique_and_default_ignores_future_months(): void
    {
        foreach (['2026-09', '2026-10', '2026-10', '2026-11', '2027-01'] as $period) {
            $this->invoice($period);
        }
        $this->assertSame(['2027-01', '2026-11', '2026-10', '2026-09'], $this->service->getAvailableBillingMonths());
        $this->assertSame('2026-10', $this->service->getLatestBillingMonth());
    }

    public function test_default_uses_latest_past_month_or_current_month_when_none_exists(): void
    {
        $this->assertSame('2026-10', $this->service->getLatestBillingMonth());
        $this->invoice('2026-11');
        $this->assertSame('2026-10', $this->service->getLatestBillingMonth());
        $this->invoice('2026-09');
        $this->assertSame('2026-09', $this->service->getLatestBillingMonth());
    }

    public function test_actual_dropdown_selects_october_and_september_without_day_31_rollover(): void
    {
        $source = file_get_contents(resource_path('views/invoices.blade.php'));
        preg_match('/<select name="period".*?<\/select>/s', $source, $matches);
        $this->assertNotEmpty($matches);
        foreach (['2026-10' => 'October 2026', '2026-09' => 'September 2026'] as $period => $label) {
            $html = Blade::render($matches[0], [
                'availableMonths' => ['2026-11', '2026-10', '2026-09'], 'selectedPeriod' => $period,
            ]);
            $this->assertMatchesRegularExpression('/value="'.$period.'"\s+selected[^>]*>'.$label.'<\/option>/', $html);
            $this->assertSame(1, substr_count($html, 'selected'));
            $this->assertSame(1, substr_count($html, '>September 2026</option>'));
            $this->assertSame(1, substr_count($html, '>October 2026</option>'));
        }
    }

    public function test_campaign_totals_match_sent_query_including_old_paid_and_merge_history(): void
    {
        $oct = $this->invoice('2026-10');
        $sep = $this->invoice('2026-09');
        DB::table('notification_batches')->insert([
            ['batch_id' => 'oct', 'billing_period' => '2026-10'],
            ['batch_id' => 'sep', 'billing_period' => '2026-09'],
            ['batch_id' => 'legacy', 'billing_period' => ''],
        ]);
        foreach ([
            [$oct, 'oct', 'monthly', 'monthly', 'submitted', false],
            [$sep, 'oct', 'paid', 'paid', 'submitted', false],
            [$oct, 'oct', 'monthly_sup', 'monthly', 'delivered', true],
            [$sep, 'oct', 'paid_sup', 'paid', 'read', true],
            [$oct, null, 'monthly', 'monthly', 'submitted', false],
            [$oct, 'legacy', 'monthly', 'monthly', 'submitted', false],
            [$oct, 'sep', 'monthly', 'monthly', 'submitted', false],
            [$oct, 'oct', 'monthly', 'monthly', 'failed', false],
            [$oct, 'oct', 'monthly', 'monthly', 'queued', false],
        ] as [$invoice, $batch, $kind, $type, $status, $superseded]) {
            DB::table('notifications')->insert(['invoice_id' => $invoice, 'batch_id' => $batch,
                'kind' => $kind, 'webhook_type' => $type, 'status' => $status, 'is_superseded' => $superseded]);
        }
        $user = (object) ['role' => 'super_admin'];
        $stats = $this->service->getCampaignSubmissionStats('2026-10', $user);
        $this->assertSame(['submitted' => 6, 'paid_notifications' => 2, 'monthly_submissions' => 4,
            'verified_deliveries' => 2, 'invoice_period_submitted' => 5], $stats);
        $this->assertSame($stats['submitted'], $this->service->buildBaseQueueQuery([
            'period' => '2026-10', 'submission_status' => 'submitted',
        ], $user)->count());
        $this->assertSame(1, $this->service->getCampaignSubmissionStats('2026-09', $user)['submitted']);
        $this->assertSame(4, $this->service->buildBaseQueueQuery([
            'period' => '2026-10', 'submission_status' => 'all',
        ], $user)->where('n.status', 'submitted')->count());
    }

    public function test_sent_totals_share_role_campus_department_currency_and_superseded_filters(): void
    {
        foreach ([1, 1, 2] as $campus) {
            $invoice = $this->invoice('2026-10', $campus);
            DB::table('notifications')->insert(['invoice_id' => $invoice, 'is_superseded' => $invoice === 2]);
        }
        foreach ([
            [(object) ['role' => 'super_admin'], ['campus_id' => 2], 1],
            [(object) ['role' => 'staff', 'campus_id' => 1, 'department_id' => 1], [], 2],
            [(object) ['role' => 'staff', 'campus_id' => 1, 'department_id' => 2], [], 0],
            [(object) ['role' => 'super_admin'], ['department_id' => 2], 0],
            [(object) ['role' => 'super_admin'], ['currency' => 'USD'], 0],
            [(object) ['role' => 'super_admin'], ['is_superseded' => 'active'], 2],
        ] as [$user, $filters, $expected]) {
            $stats = $this->service->getCampaignSubmissionStats('2026-10', $user, $filters);
            $table = $this->service->buildBaseQueueQuery(array_merge($filters, [
                'period' => '2026-10', 'submission_status' => 'submitted',
            ]), $user)->count();
            $this->assertSame($expected, $stats['submitted']);
            $this->assertSame($stats['submitted'], $table);
        }
    }
}
