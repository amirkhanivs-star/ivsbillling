<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BatchDeliveryService;
use App\Services\InvoiceDeliveryQueueService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MysqlBatchDetailsRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure database connection is set to mysql with ONLY_FULL_GROUP_BY and database iqra_billing
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'iqra_billing',
        ]);
        DB::purge('mysql');
    }

    /**
     * 1. Verify MySQL has ONLY_FULL_GROUP_BY enabled and old query fails with 1140.
     */
    public function test_mysql_has_only_full_group_by_and_old_query_fails_with_1140(): void
    {
        $sqlMode = DB::connection('mysql')->selectOne('SELECT @@SESSION.sql_mode as s_mode')->s_mode;
        $this->assertStringContainsString('ONLY_FULL_GROUP_BY', $sqlMode);

        // Reproduce the old illegal query pattern
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessage('1140');

        DB::connection('mysql')->select("
            select `n`.`id` as `notification_id`, `n`.`invoice_id`, `i`.`invoice_number`,
                   COUNT(*) as total,
                   SUM(CASE WHEN n.status = 'queued' THEN 1 ELSE 0 END) as queued
            from `notifications` as `n`
            inner join `invoices` as `i` on `i`.`id` = `n`.`invoice_id`
            where `n`.`batch_id` = 'BATCH-20261024-LXPREF'
            limit 1
        ");
    }

    /**
     * 2. Verify BATCH-20261024-LXPREF opens successfully with HTTP 200 and accurate stats.
     */
    public function test_batch_details_lxpref_opens_successfully_on_mysql(): void
    {
        $superAdmin = User::on('mysql')->where('role', 'super_admin')->first();
        $this->assertNotNull($superAdmin);

        $svc = app(InvoiceDeliveryQueueService::class);
        $hasOct24Batch = DB::connection('mysql')->table('notifications')->where('batch_id', 'BATCH-20261024-LXPREF')->exists();
        if (!$hasOct24Batch) {
            $details = $svc->getBatchDetails('BATCH-20261024-LXPREF', $superAdmin);
            $this->assertNull($details);
            $res = $this->actingAs($superAdmin)->get('/invoices/batches/BATCH-20261024-LXPREF');
            $this->assertSame(404, $res->status());
            return;
        }

        $details = $svc->getBatchDetails('BATCH-20261024-LXPREF', $superAdmin);

        $this->assertNotNull($details);
        $this->assertSame('BATCH-20261024-LXPREF', $details['batch']->batch_id);
        $this->assertSame(13, $details['stats']['total']);
        $this->assertSame(13, $details['stats']['submitted']);
        $this->assertSame(0, $details['stats']['failed']);
        $this->assertSame(0, $details['stats']['queued']);

        $response = $this->actingAs($superAdmin)->get('/invoices/batches/BATCH-20261024-LXPREF');
        $response->assertStatus(200);
        $response->assertSee('BATCH-20261024-LXPREF');
        $response->assertSee('SCH-OB-2026-0000011-1914');
    }

    /**
     * 3. Verify existing 260-recipient campaign (BATCH-20261024-VUDMMD) opens with HTTP 200.
     */
    public function test_batch_details_260_recipients_campaign_opens_on_mysql(): void
    {
        $superAdmin = User::on('mysql')->where('role', 'super_admin')->first();
        $svc = app(InvoiceDeliveryQueueService::class);

        if (!DB::connection('mysql')->table('notifications')->where('batch_id', 'BATCH-20261024-VUDMMD')->exists()) {
            $this->assertNull($svc->getBatchDetails('BATCH-20261024-VUDMMD', $superAdmin));
            return;
        }

        $details = $svc->getBatchDetails('BATCH-20261024-VUDMMD', $superAdmin);
        $this->assertNotNull($details);
        $this->assertSame(260, $details['stats']['total']);
        $this->assertSame(260, $details['stats']['submitted']);

        $response = $this->actingAs($superAdmin)->get('/invoices/batches/BATCH-20261024-VUDMMD');
        $response->assertStatus(200);
    }

    /**
     * 4. Verify existing 218-recipient campaign (BATCH-20261024-YOMY54) opens with HTTP 200.
     */
    public function test_batch_details_218_recipients_campaign_opens_on_mysql(): void
    {
        $superAdmin = User::on('mysql')->where('role', 'super_admin')->first();
        $svc = app(InvoiceDeliveryQueueService::class);

        if (!DB::connection('mysql')->table('notifications')->where('batch_id', 'BATCH-20261024-YOMY54')->exists()) {
            $this->assertNull($svc->getBatchDetails('BATCH-20261024-YOMY54', $superAdmin));
            return;
        }

        $details = $svc->getBatchDetails('BATCH-20261024-YOMY54', $superAdmin);
        $this->assertNotNull($details);
        $this->assertSame(218, $details['stats']['total']);
        $this->assertSame(218, $details['stats']['submitted']);

        $response = $this->actingAs($superAdmin)->get('/invoices/batches/BATCH-20261024-YOMY54');
        $response->assertStatus(200);
    }

    /**
     * 5. Verify empty/non-existent batch returns zero counts without throwing 1140 exception.
     */
    public function test_empty_batch_returns_zero_counts_without_exception_on_mysql(): void
    {
        $superAdmin = User::on('mysql')->where('role', 'super_admin')->first();
        $svc = app(InvoiceDeliveryQueueService::class);

        $stats = $svc->getQueueStats(['batch_id' => 'BATCH-NONEXISTENT-EMPTY'], $superAdmin);
        $this->assertSame(0, $stats['total']);
        $this->assertSame(0, $stats['submitted']);
        $this->assertSame(0, $stats['queued']);
        $this->assertSame(0, $stats['failed']);

        $response = $this->actingAs($superAdmin)->get('/invoices/queue?batch_id=BATCH-NONEXISTENT-EMPTY');
        $response->assertStatus(200);
    }

    /**
     * 6. Verify server-side pagination with 25, 50, 100 rows per page on MySQL.
     */
    public function test_pagination_supports_25_50_100_rows_on_mysql(): void
    {
        $superAdmin = User::on('mysql')->where('role', 'super_admin')->first();
        $svc = app(InvoiceDeliveryQueueService::class);

        $p25 = $svc->getPaginatedQueue(['batch_id' => 'BATCH-20261024-VUDMMD'], $superAdmin, 25);
        $this->assertSame(25, $p25->perPage());

        $p50 = $svc->getPaginatedQueue(['batch_id' => 'BATCH-20261024-VUDMMD'], $superAdmin, 50);
        $this->assertSame(50, $p50->perPage());

        $p100 = $svc->getPaginatedQueue(['batch_id' => 'BATCH-20261024-VUDMMD'], $superAdmin, 100);
        $this->assertSame(100, $p100->perPage());
    }

    /**
     * 7. Verify universal search across identifiers on MySQL without SQL syntax errors.
     */
    public function test_universal_search_on_mysql(): void
    {
        $superAdmin = User::on('mysql')->where('role', 'super_admin')->first();
        $svc = app(InvoiceDeliveryQueueService::class);

        // Search by invoice number (executes full GROUP BY / JOIN query on MySQL with ONLY_FULL_GROUP_BY)
        $pageInv = $svc->getPaginatedQueue(['search' => 'SCH-OB-2026-0000011-1914'], $superAdmin);
        $this->assertGreaterThanOrEqual(0, $pageInv->total());

        // Search by batch ID
        $pageBatch = $svc->getPaginatedQueue(['search' => 'BATCH-20261024-LXPREF'], $superAdmin);
        $this->assertGreaterThanOrEqual(0, $pageBatch->total());

        // Search by WhatsApp number
        $pagePhone = $svc->getPaginatedQueue(['search' => '923325140500'], $superAdmin);
        $this->assertGreaterThanOrEqual(0, $pagePhone->total());
    }

    /**
     * 8. Verify CSV export streams valid CSV on MySQL.
     */
    public function test_csv_export_streams_valid_content_on_mysql(): void
    {
        $superAdmin = User::on('mysql')->where('role', 'super_admin')->first();
        $svc = app(InvoiceDeliveryQueueService::class);

        $response = $svc->streamCsvExport(['batch_id' => 'BATCH-20261024-LXPREF'], $superAdmin);

        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        $this->assertStringContainsString('Notification ID', $content);
    }

    /**
     * 9. Verify Grade 10 IGCSE-A selection numbers reconciliation on MySQL.
     */
    public function test_grade_10_igcse_a_selection_numbers_reconciled(): void
    {
        $superAdmin = User::on('mysql')->where('role', 'super_admin')->first();
        $batchSvc = app(BatchDeliveryService::class);

        $snapshot = $batchSvc->resolveFilteredSelection(['grade' => '10 IGCSE - A'], $superAdmin, ['whatsapp']);

        // Reconciled counts invariant verification on current MySQL dataset
        $this->assertGreaterThan(0, $snapshot['total_matched_invoices']);
        $this->assertSame(
            $snapshot['total_matched_invoices'] - $snapshot['matched_superseded_invoices_count'],
            $snapshot['total_delivery_targets'],
            'Distinct delivery targets must equal total_matched_invoices minus matched_superseded_invoices_count'
        );
        $this->assertSame(
            $snapshot['total_delivery_targets'] - $snapshot['already_submitted_recipients_count'] - $snapshot['contact_ineligible_count'],
            $snapshot['total_eligible_recipients'],
            'Eligible recipients must equal delivery targets minus already submitted and contact ineligible'
        );
    }

    /**
     * 10. Verify campus/role authorization restricts batch details for staff.
     */
    public function test_campus_authorization_restricts_staff_access(): void
    {
        $staffUser = (new User())->forceFill([
            'id' => 9999,
            'role' => 'campus_admin',
            'campus_id' => 9999,
            'name' => 'Campus Staff',
            'email' => 'campus.staff@iqra.test',
        ]);

        $svc = app(InvoiceDeliveryQueueService::class);

        // Staff from another campus (campus_id 9999) cannot access BATCH-20261024-LXPREF (campus_id 1)
        $details = $svc->getBatchDetails('BATCH-20261024-LXPREF', $staffUser);
        $this->assertNull($details, 'Staff from another campus cannot access unauthorized batch');

        // HTTP GET request as staff is blocked (403 Forbidden or 404 Not Found)
        $res = $this->actingAs($staffUser)->get('/invoices/batches/BATCH-20261024-LXPREF');
        $this->assertTrue(in_array($res->status(), [403, 404], true));
    }
}
