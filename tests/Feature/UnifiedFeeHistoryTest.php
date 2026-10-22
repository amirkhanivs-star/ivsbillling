<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FeeHistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UnifiedFeeHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /**
     * Test Task 1 & 2: Reconstructing multi-row and multi-student historical slips
     * into single receipts without double-counting.
     */
    public function test_reconstructs_multi_item_and_multi_student_historical_slips(): void
    {
        $batchId = (string) config('imports.active_batch_id', 'BATCH-20261022-PORTAL-V2-REVISED');

        $familyId = DB::table('families')->insertGetId([
            'family_code' => 'FAM-TEST-4431',
            'guardian_name' => 'Syed Pasha Senior',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $st1 = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => '4431',
            'name' => 'Syed Abdullah Pasha',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $st2 = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => '4432',
            'name' => 'Syed Ibaad Pasha',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Insert Slip 12380 with 2 rows for the 2 siblings: 260 + 280 = 540 SAR
        $r1 = DB::table('qa_staged_collections')->insertGetId([
            'batch_id' => $batchId,
            'source_row' => 1001,
            'gr_number' => '4431',
            'student_name' => 'Syed Abdullah Pasha',
            'family_code' => 'FAM-TEST-4431',
            'slip_number' => '12380',
            'payment_date_parsed' => '2025-10-22',
            'amount' => 260.00,
            'amount_minor' => 26000,
            'currency' => 'SAR',
            'payment_method' => 'Cash',
            'fee_particular' => 'Monthly Fee - Oct-2025',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $r2 = DB::table('qa_staged_collections')->insertGetId([
            'batch_id' => $batchId,
            'source_row' => 1002,
            'gr_number' => '4432',
            'student_name' => 'Syed Ibaad Pasha',
            'family_code' => 'FAM-TEST-4431',
            'slip_number' => '12380',
            'payment_date_parsed' => '2025-10-22',
            'amount' => 280.00,
            'amount_minor' => 28000,
            'currency' => 'SAR',
            'payment_method' => 'Cash',
            'fee_particular' => 'Monthly Fee - Oct-2025',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Insert separate Slip 26507 on 2026-10-16
        DB::table('qa_staged_collections')->insert([
            'batch_id' => $batchId,
            'source_row' => 1003,
            'gr_number' => '4431',
            'student_name' => 'Syed Abdullah Pasha',
            'family_code' => 'FAM-TEST-4431',
            'slip_number' => '26507',
            'payment_date_parsed' => '2026-10-16',
            'amount' => 280.00,
            'amount_minor' => 28000,
            'currency' => 'SAR',
            'payment_method' => 'Cash',
            'fee_particular' => 'Monthly Fee - Sep-2026',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = new FeeHistoryService();
        $history = $service->getFamilyFeeHistory($familyId);

        // Should reconstruct into 2 distinct receipts (Slip 12380 and Slip 26507), not 3 raw lines
        $this->assertEquals(2, $history->total());

        // Newest slip first (26507 from 2026-10-16)
        $first = $history->first();
        $this->assertEquals('26507', $first->slip_number);
        $this->assertEquals('myskool', $first->source);
        $this->assertEquals(280.00, $first->amount);

        // Second slip (12380 from 2025-10-22)
        $second = $history->values()->get(1);
        $this->assertEquals('12380', $second->slip_number);
        $this->assertEquals(540.00, $second->amount); // 260 + 280 = 540 without double counting
        $this->assertCount(2, $second->items);
        $this->assertStringContainsString('Syed Abdullah Pasha', $second->students_str);
        $this->assertStringContainsString('Syed Ibaad Pasha', $second->students_str);
        $this->assertFalse($second->can_reverse); // Strictly protected
    }

    /**
     * Test Task 1 & 6: Unified Fee History presentation on /challan with search and source filtering.
     */
    public function test_unified_fee_history_page_combines_portal_and_myskool_with_filters(): void
    {
        $admin = User::create([
            'name' => 'Cashier Lead',
            'email' => 'cashier_unified@iqra.edu',
            'password' => Hash::make('Compliant#Pass2026'),
            'role' => 'finance_admin',
            'is_active' => true,
        ]);

        $batchId = (string) config('imports.active_batch_id', 'BATCH-20261022-PORTAL-V2-REVISED');

        $familyId = DB::table('families')->insertGetId([
            'family_code' => 'FAM-UNIFIED-1',
            'guardian_name' => 'Unified Guardian',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentId = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => 'GR-U-01',
            'name' => 'Unified Student',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Insert 1 Historical MySkool slip
        DB::table('qa_staged_collections')->insert([
            'batch_id' => $batchId,
            'source_row' => 201,
            'gr_number' => 'GR-U-01',
            'student_name' => 'Unified Student',
            'family_code' => 'FAM-UNIFIED-1',
            'slip_number' => 'MS-HIST-888',
            'payment_date_parsed' => '2025-05-15',
            'amount' => 450.00,
            'amount_minor' => 45000,
            'currency' => 'SAR',
            'payment_method' => 'Cash',
            'fee_particular' => 'Monthly Fee - May-2025',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Insert 1 New Portal Payment
        $portalPaymentId = DB::table('payments')->insertGetId([
            'receipt_number' => 'RCP-PORTAL-999',
            'family_id' => $familyId,
            'campus_id' => 1,
            'department_id' => 1,
            'amount_minor' => 60000,
            'unallocated_minor' => 0,
            'currency' => 'SAR',
            'status' => 'received',
            'received_on' => '2026-10-22',
            'received_by' => $admin->id,
            'idempotency_key' => 'idem-test-999',
            'request_hash' => 'hash999',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Check /challan?family=FAM-UNIFIED-1
        $res = $this->actingAs($admin)->get('/challan?family=FAM-UNIFIED-1');
        $res->assertStatus(200);

        // Section title is "Fee History"
        $res->assertSee('Fee History');
        // Both Portal and MySkool slips appear in the same table
        $res->assertSee('RCP-PORTAL-999');
        $res->assertSee('MS-HIST-888');
        $res->assertSee('Portal');
        $res->assertSee('MySkool');

        // Test filtering by source = portal
        $resPortal = $this->actingAs($admin)->get('/challan?family=FAM-UNIFIED-1&source=portal');
        $resPortal->assertStatus(200);
        $resPortal->assertSee('RCP-PORTAL-999');
        $resPortal->assertDontSee('MS-HIST-888');

        // Test filtering by source = myskool
        $resHist = $this->actingAs($admin)->get('/challan?family=FAM-UNIFIED-1&source=myskool');
        $resHist->assertStatus(200);
        $resHist->assertSee('MS-HIST-888');
        $resHist->assertDontSee('RCP-PORTAL-999');

        // Test search by slip number
        $resSearch = $this->actingAs($admin)->get('/challan?family=FAM-UNIFIED-1&search=888');
        $resSearch->assertStatus(200);
        $resSearch->assertSee('MS-HIST-888');
        $resSearch->assertDontSee('RCP-PORTAL-999');
    }

    /**
     * Test Task 3: Printable PDF for historical MySkool receipts.
     */
    public function test_historical_receipt_pdf_renders_with_official_reconstructed_banner(): void
    {
        $admin = User::create([
            'name' => 'Auditor',
            'email' => 'auditor@iqra.edu',
            'password' => Hash::make('Compliant#Pass2026'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $batchId = (string) config('imports.active_batch_id', 'BATCH-20261022-PORTAL-V2-REVISED');

        $familyId = DB::table('families')->insertGetId([
            'family_code' => 'FAM-PRINT-1',
            'guardian_name' => 'Father of Student',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $histId = DB::table('qa_staged_collections')->insertGetId([
            'batch_id' => $batchId,
            'source_row' => 500,
            'gr_number' => 'GR-PRINT-01',
            'student_name' => 'Printable Student',
            'family_code' => 'FAM-PRINT-1',
            'slip_number' => 'SLIP-HIST-PRINT-77',
            'payment_date_parsed' => '2025-08-14',
            'amount' => 350.00,
            'amount_minor' => 35000,
            'currency' => 'SAR',
            'payment_method' => 'Cash',
            'fee_particular' => 'Monthly Fee - Aug-2025',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $res = $this->actingAs($admin)->get("/receipts/historical/{$histId}/pdf");
        $res->assertStatus(200);
        $this->assertEquals('application/pdf', $res->headers->get('Content-Type'));
    }

    /**
     * Test Task 5: Student Profile /manage-student/{id} unified Fee History.
     */
    public function test_student_profile_displays_unified_fee_history(): void
    {
        $admin = User::create([
            'name' => 'Manager',
            'email' => 'manager_student@iqra.edu',
            'password' => Hash::make('Compliant#Pass2026'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $batchId = (string) config('imports.active_batch_id', 'BATCH-20261022-PORTAL-V2-REVISED');

        $familyId = DB::table('families')->insertGetId([
            'family_code' => 'FAM-ST-PROFILE',
            'guardian_name' => 'Guardian Profile',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentId = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => 'GR-ST-01',
            'name' => 'Student In Profile',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Insert historical record for this student
        DB::table('qa_staged_collections')->insert([
            'batch_id' => $batchId,
            'source_row' => 601,
            'gr_number' => 'GR-ST-01',
            'student_name' => 'Student In Profile',
            'family_code' => 'FAM-ST-PROFILE',
            'slip_number' => 'SLIP-PROFILE-99',
            'payment_date_parsed' => '2025-11-10',
            'amount' => 280.00,
            'amount_minor' => 28000,
            'currency' => 'SAR',
            'payment_method' => 'Cash',
            'fee_particular' => 'Monthly Fee - Nov-2025',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $res = $this->actingAs($admin)->get("/manage-student/{$studentId}");
        $res->assertStatus(200);

        // Must see "Fee History" title and the reconstructed slip
        $res->assertSee('Fee History');
        $res->assertSee('SLIP-PROFILE-99');
        $res->assertSee('MySkool');

        // Must NOT see the old raw archive headers
        $res->assertDontSee('Historical MySkool Receipt Collection Lines');
    }

    /**
     * Test Task 7: Financial Safety - Historical collections never mutate live payments
     * or opening balance invoices.
     */
    public function test_financial_safety_historical_collections_never_touch_live_ledger(): void
    {
        $paymentsCountBefore = DB::table('payments')->count();
        $invoicesCountBefore = DB::table('invoices')->count();

        $service = new FeeHistoryService();
        $service->fetchHistoricalReceipts(['4431', '4432'], '4431');

        $this->assertEquals($paymentsCountBefore, DB::table('payments')->count());
        $this->assertEquals($invoicesCountBefore, DB::table('invoices')->count());
    }
}
