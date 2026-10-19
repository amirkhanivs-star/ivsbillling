<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class Phase3bAccountingControlsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that future-period payments (period_key > '2026-10') are strictly grouped by currency
     * and never combined into an invalid cross-currency aggregate.
     */
    public function test_phase3b_future_period_payments_currency_isolation(): void
    {
        $this->seed();
        $now = now();

        // Seed fictional future-period payments across SAR, AED, and BHD
        DB::table('staging_payments')->insert([
            // 2026-11
            [
                'source_row' => 10,
                'source_key' => 'PAY:10',
                'slip_number' => 'SLIP-F1',
                'gr_number' => '9001',
                'student_name' => 'Future Student 1',
                'payment_date_iso' => '2026-09-15',
                'currency_iso' => 'SAR',
                'paid_amount' => 4236.00,
                'period_key' => '2026-11',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'source_row' => 11,
                'source_key' => 'PAY:11',
                'slip_number' => 'SLIP-F2',
                'gr_number' => '9002',
                'student_name' => 'Future Student 2',
                'payment_date_iso' => '2026-09-15',
                'currency_iso' => 'AED',
                'paid_amount' => 1270.00,
                'period_key' => '2026-11',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'source_row' => 12,
                'source_key' => 'PAY:12',
                'slip_number' => 'SLIP-F3',
                'gr_number' => '9003',
                'student_name' => 'Future Student 3',
                'payment_date_iso' => '2026-09-15',
                'currency_iso' => 'BHD',
                'paid_amount' => 84.00,
                'period_key' => '2026-11',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            // 2026-12 through 2027-07 lines
            [
                'source_row' => 13,
                'source_key' => 'PAY:13',
                'slip_number' => 'SLIP-F4',
                'gr_number' => '9001',
                'student_name' => 'Future Student 1',
                'payment_date_iso' => '2026-09-15',
                'currency_iso' => 'SAR',
                'paid_amount' => 2836.00, // 4236 + 2836 = 7072 SAR
                'period_key' => '2026-12',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'source_row' => 14,
                'source_key' => 'PAY:14',
                'slip_number' => 'SLIP-F5',
                'gr_number' => '9002',
                'student_name' => 'Future Student 2',
                'payment_date_iso' => '2026-09-15',
                'currency_iso' => 'AED',
                'paid_amount' => 1375.00, // 1270 + 1375 = 2645 AED
                'period_key' => '2027-01',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'source_row' => 15,
                'source_key' => 'PAY:15',
                'slip_number' => 'SLIP-F6',
                'gr_number' => '9003',
                'student_name' => 'Future Student 3',
                'payment_date_iso' => '2026-09-15',
                'currency_iso' => 'BHD',
                'paid_amount' => 84.00, // 84 + 84 = 168 BHD
                'period_key' => '2026-12',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // Query future payments strictly by currency
        $results = DB::table('staging_payments')
            ->where('period_key', '>', '2026-10')
            ->select('currency_iso', DB::raw('sum(paid_amount) as total'))
            ->groupBy('currency_iso')
            ->pluck('total', 'currency_iso');

        $this->assertEquals(7072.00, (float) $results['SAR']);
        $this->assertEquals(2645.00, (float) $results['AED']);
        $this->assertEquals(168.00, (float) $results['BHD']);

        // Verify that November alone equals the 5,590 amount that was erroneously reported as a cross-currency grand total
        $novSum = DB::table('staging_payments')
            ->where('period_key', '2026-11')
            ->sum('paid_amount');
        $this->assertEquals(5590.00, (float) $novSum);
    }

    /**
     * Test that slips with multiple currencies are identified as mixed-currency receipt vouchers
     * and cannot be merged into a single currency amount.
     */
    public function test_phase3b_mixed_currency_receipt_groups_detection(): void
    {
        $this->seed();
        $now = now();

        DB::table('staging_payments')->insert([
            [
                'source_row' => 20,
                'source_key' => 'PAY:20',
                'slip_number' => 'SLIP-MIXED-1',
                'gr_number' => '9101',
                'student_name' => 'Sibling 1',
                'currency_iso' => 'AED',
                'currency_raw' => 'AED',
                'paid_amount' => 385.00,
                'period_key' => '2025-07',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'source_row' => 21,
                'source_key' => 'PAY:21',
                'slip_number' => 'SLIP-MIXED-1',
                'gr_number' => '9102',
                'student_name' => 'Sibling 2',
                'currency_iso' => 'SAR',
                'currency_raw' => 'SAR',
                'paid_amount' => 240.00,
                'period_key' => '2025-07',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            // Slip with recognized + unresolved raw label
            [
                'source_row' => 22,
                'source_key' => 'PAY:22',
                'slip_number' => 'SLIP-UNRESOLVED-1',
                'gr_number' => '9103',
                'student_name' => 'Sibling 3',
                'currency_iso' => 'SAR',
                'currency_raw' => 'SAR',
                'paid_amount' => 200.00,
                'period_key' => '2025-03',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'source_row' => 23,
                'source_key' => 'PAY:23',
                'slip_number' => 'SLIP-UNRESOLVED-1',
                'gr_number' => '9104',
                'student_name' => 'Sibling 4',
                'currency_iso' => null,
                'currency_raw' => 'SAE', // Typo
                'paid_amount' => 200.00,
                'period_key' => '2025-03',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // Slip 1 has 2 distinct recognized currencies
        $slip1Currencies = DB::table('staging_payments')
            ->where('slip_number', 'SLIP-MIXED-1')
            ->distinct()
            ->pluck('currency_iso');
        $this->assertCount(2, $slip1Currencies);
        $this->assertTrue($slip1Currencies->contains('AED'));
        $this->assertTrue($slip1Currencies->contains('SAR'));

        // Slip 2 has recognized SAR and unresolved null ISO
        $slip2Rows = DB::table('staging_payments')
            ->where('slip_number', 'SLIP-UNRESOLVED-1')
            ->get();
        $this->assertTrue($slip2Rows->contains(fn($r) => $r->currency_iso === 'SAR'));
        $this->assertTrue($slip2Rows->contains(fn($r) => is_null($r->currency_iso) && $r->currency_raw === 'SAE'));
    }

    /**
     * Test that multiple legitimate dues items in the same period are preserved by source_key
     * and not suppressed by period-level header deduplication.
     */
    public function test_phase3b_multiple_dues_items_same_period_preserved(): void
    {
        $this->seed();
        $now = now();

        // One student with 3 distinct subjects in the same period (e.g. 2026-10)
        DB::table('staging_dues')->insert([
            [
                'source_row' => 101,
                'source_key' => 'DUE:101',
                'gr_number' => '9201',
                'student_name' => 'High School Student',
                'particular_raw' => 'Grade 8- (Maths) IGCSE - Oct-2026',
                'currency_iso' => 'SAR',
                'outstanding_amount' => 180.00,
                'period_key' => '2026-10',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'source_row' => 102,
                'source_key' => 'DUE:102',
                'gr_number' => '9201',
                'student_name' => 'High School Student',
                'particular_raw' => 'Grade 8- (Computer) IGCSE - Oct-2026',
                'currency_iso' => 'SAR',
                'outstanding_amount' => 180.00,
                'period_key' => '2026-10',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'source_row' => 103,
                'source_key' => 'DUE:103',
                'gr_number' => '9201',
                'student_name' => 'High School Student',
                'particular_raw' => 'Grade 8- (Chemistry) IGCSE - Oct-2026',
                'currency_iso' => 'SAR',
                'outstanding_amount' => 180.00,
                'period_key' => '2026-10',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $duesRows = DB::table('staging_dues')
            ->where('gr_number', '9201')
            ->where('period_key', '2026-10')
            ->get();

        // Must preserve all 3 items (540 SAR total)
        $this->assertCount(3, $duesRows);
        $this->assertEquals(540.00, (float) $duesRows->sum('outstanding_amount'));

        // Each row has a unique source_key
        $sourceKeys = $duesRows->pluck('source_key');
        $this->assertCount(3, $sourceKeys->unique());
        $this->assertTrue($sourceKeys->contains('DUE:101'));
        $this->assertTrue($sourceKeys->contains('DUE:102'));
        $this->assertTrue($sourceKeys->contains('DUE:103'));
    }

    /**
     * Test that neutralized staging password hash cannot be authenticated against.
     */
    public function test_phase3b_neutralized_password_hash_cannot_authenticate(): void
    {
        $this->seed();

        $user = User::create([
            'name' => 'Staging Locked Admin',
            'email' => 'locked_admin@staging.local',
            'password' => '!LOCKED_STAGING_ISOLATED_' . hash('sha256', 'sample_locked_key'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        // Attempting to check any password against this placeholder must fail
        $this->assertFalse(Hash::check('password', $user->password));
        $this->assertFalse(Hash::check('secret', $user->password));
        $this->assertFalse(Hash::check('SuperSecretLivePass123!', $user->password));
        $this->assertFalse(password_verify('password', $user->password));
    }

    /**
     * Test Phase 3B.3 SnapshotReconciliationService multi-currency delta analysis.
     */
    public function test_phase3b3_snapshot_reconciliation_service_detects_deltas_by_currency(): void
    {
        $service = app(\App\Services\Staging\SnapshotReconciliationService::class);

        // Fictional Version 1 Dues
        $v1Dues = [
            // Unchanged SAR
            ['gr_number' => '1001', 'period_key' => '2026-10', 'particular_raw' => 'Monthly Fee - Oct-2026', 'currency_iso' => 'SAR', 'outstanding_amount' => 200.00],
            // Decreased SAR (partially paid in legacy between snapshots)
            ['gr_number' => '1002', 'period_key' => '2026-10', 'particular_raw' => 'Monthly Fee - Oct-2026', 'currency_iso' => 'SAR', 'outstanding_amount' => 300.00],
            // Disappeared AED (settled in full in legacy)
            ['gr_number' => '1003', 'period_key' => '2026-10', 'particular_raw' => 'Monthly Fee - Oct-2026', 'currency_iso' => 'AED', 'outstanding_amount' => 250.00],
            // Currency conflict item (V1 has SAR, V2 will have AED)
            ['gr_number' => '1004', 'period_key' => '2026-10', 'particular_raw' => 'Monthly Fee - Oct-2026', 'currency_iso' => 'SAR', 'outstanding_amount' => 150.00],
        ];

        // Fictional Version 2 Dues
        $v2Dues = [
            // Unchanged SAR
            ['gr_number' => '1001', 'period_key' => '2026-10', 'particular_raw' => 'Monthly Fee - Oct-2026', 'currency_iso' => 'SAR', 'outstanding_amount' => 200.00],
            // Decreased SAR (now 100 instead of 300 -> -200 delta)
            ['gr_number' => '1002', 'period_key' => '2026-10', 'particular_raw' => 'Monthly Fee - Oct-2026', 'currency_iso' => 'SAR', 'outstanding_amount' => 100.00],
            // Newly appearing SAR
            ['gr_number' => '1005', 'period_key' => '2026-11', 'particular_raw' => 'Monthly Fee - Nov-2026', 'currency_iso' => 'SAR', 'outstanding_amount' => 220.00],
            // Currency conflict item (now AED)
            ['gr_number' => '1004', 'period_key' => '2026-10', 'particular_raw' => 'Monthly Fee - Oct-2026', 'currency_iso' => 'AED', 'outstanding_amount' => 150.00],
        ];

        $results = $service->compareSnapshots($v1Dues, $v2Dues);

        // Check SAR metrics
        $sar = $results['currencies']['SAR'];
        $this->assertEquals(1, $sar['unchanged_count']);
        $this->assertEquals(200.00, $sar['unchanged_amount']);
        $this->assertEquals(1, $sar['decreased_count']);
        $this->assertEquals(200.00, $sar['decreased_delta']);
        $this->assertEquals(1, $sar['newly_appearing_count']);
        $this->assertEquals(220.00, $sar['newly_appearing_amount']);

        // Check AED metrics
        $aed = $results['currencies']['AED'];
        $this->assertEquals(1, $aed['disappearing_count']);
        $this->assertEquals(250.00, $aed['disappearing_amount']);

        // Check Classification Conflicts
        $this->assertGreaterThanOrEqual(1, $results['summary']['total_classification_conflicts']);
        $this->assertTrue(collect($results['conflicts'])->contains(fn($c) => $c['type'] === 'CURRENCY_MISMATCH' && $c['gr_number'] === '1004'));
    }

    /**
     * Test command staging:reconcile-snapshot safety checks, missing files, and cutoff certification.
     */
    public function test_phase3b3_reconcile_command_safety_checks(): void
    {
        $this->seed();
        $conn = config('database.default');

        // 1. Command with no arguments succeeds and notifies that V1 is frozen
        $this->artisan('staging:reconcile-snapshot', [
            '--v1-connection' => $conn,
        ])->assertSuccessful();

        // 2. Providing non-existent V2 path fails with nonzero exit code
        $this->artisan('staging:reconcile-snapshot', [
            '--v1-connection' => $conn,
            '--v2-dues' => 'non_existent_file.csv',
        ])->assertFailed();

        // 3. Providing dues without payments fails (both required for synchronized comparison)
        $tmpDues = tempnam(sys_get_temp_dir(), 'dues_');
        file_put_contents($tmpDues, "Class,GRN,Student Name,Contact #,Particular,Currency,Balance\nGeneral,101,Test,,Monthly Fee - Oct-2026,SAR,200\n");

        $this->artisan('staging:reconcile-snapshot', [
            '--v1-connection' => $conn,
            '--v2-dues' => $tmpDues,
        ])->assertFailed();

        // 4. Providing both files but unauthenticated admin fails
        $tmpPay = tempnam(sys_get_temp_dir(), 'pay_');
        file_put_contents($tmpPay, "Cash,Slip #,GRN,Student Name,Particular,Date,Currency,Amount\n,S1,101,Test,Monthly Fee - Oct-2026,2026-10-01,SAR,200\n");

        $this->artisan('staging:reconcile-snapshot', [
            '--v1-connection' => $conn,
            '--v2-dues' => $tmpDues,
            '--v2-payments' => $tmpPay,
            '--cutoff-timestamp' => '2026-10-19T23:59:59',
            '--timezone' => 'Asia/Riyadh',
            '--authorized-by' => 'unauthenticated_random_person@example.com',
            '--freeze-evidence' => 'Signed freeze batch #49',
        ])->assertFailed();

        // 5. Valid cutoff certification with authorized active super_admin succeeds
        DB::table('users')->insert([
            'name' => 'Super Certifier',
            'email' => 'super_certifier@iqra.edu',
            'password' => Hash::make('secret_pass'),
            'role' => 'super_admin',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('staging:reconcile-snapshot', [
            '--v1-connection' => $conn,
            '--v2-dues' => $tmpDues,
            '--v2-payments' => $tmpPay,
            '--cutoff-timestamp' => '2026-10-19T23:59:59',
            '--timezone' => 'Asia/Riyadh',
            '--authorized-by' => 'super_certifier@iqra.edu',
            '--freeze-evidence' => 'Signed freeze batch #49 verified by finance board',
        ])->assertSuccessful();

        @unlink($tmpDues);
        @unlink($tmpPay);
    }

    /**
     * Test MySkoolReportParser handling of class headings, student continuations,
     * subtotal resets, blank rows, and thousands separators.
     */
    public function test_phase3b31_myskool_report_parser_hierarchical_structure(): void
    {
        $parser = new \App\Services\Staging\MySkoolReportParser();

        $fictionalCsv = implode("\n", [
            'IQRA VIRTUAL SCHOOL,Agha Meer Jani Road Wazir Bagh Peshawar,Outstanding Report,,,,,',
            ',,,,,,,',
            'FS-1 - A,GRN,Student Name,Contact #,Particular,Currency,Balance,',
            ',,,,,,,',
            ',5525,Muhammad Abdullah,,Monthly Fee - Jul-2025,AED,200,',
            ',,,,Monthly Fee - Nov-2025,AED,200,',
            ',,,,Monthly Fee - Dec-2025,AED,200,',
            ',"2,400",,,,,,', // Subtotal line with thousands separator
            ',,,,,,,',
            'FS-1 - A 171 students - Rs 293,086,,,,,,,', // Summary row
            ',,,,,,,',
            ',5409,Mahrosh Sohaib,,Monthly Fee - May-2025,SAR,"1,200",', // Thousands separated balance
            ',,,,Monthly Fee - Jun-2025,SAR,185,',
            ',,,,,,,',
            ',9999,Unresolved Student,,Monthly Fee - Oct-2026,SAFR,100,', // Ambiguous currency SAFR
        ]);

        $tmpFile = tempnam(sys_get_temp_dir(), 'test_dues_');
        file_put_contents($tmpFile, $fictionalCsv);

        $parsed = $parser->parseDuesReport($tmpFile);
        @unlink($tmpFile);

        // Expected detail rows: 3 for 5525 + 2 for 5409 + 1 for 9999 = 6 detail rows
        $this->assertCount(6, $parsed);

        // Subtotals and summary lines must NEVER become dues
        foreach ($parsed as $item) {
            $this->assertNotEquals('2400', $item['gr_number']);
            $this->assertNotEquals('2,400', $item['gr_number']);
            $this->assertFalse(str_contains(strtolower($item['particular_raw']), 'students'));
        }

        // Student 5525 continuation check
        $this->assertEquals('5525', $parsed[0]['gr_number']);
        $this->assertEquals('FS-1 - A', $parsed[0]['report_class']);
        $this->assertEquals('2025-07', $parsed[0]['period_key']);
        $this->assertEquals('AED', $parsed[0]['currency_iso']);
        $this->assertEquals(200.00, $parsed[0]['outstanding_amount']);

        $this->assertEquals('5525', $parsed[1]['gr_number']); // Inherited
        $this->assertEquals('Muhammad Abdullah', $parsed[1]['student_name']); // Inherited
        $this->assertEquals('2025-11', $parsed[1]['period_key']);

        // Thousands separator parsed properly on 5409
        $this->assertEquals('5409', $parsed[3]['gr_number']);
        $this->assertEquals(1200.00, $parsed[3]['outstanding_amount']);

        // Unresolved currency preserved as empty without guessing to SAR
        $this->assertEquals('9999', $parsed[5]['gr_number']);
        $this->assertEquals('', $parsed[5]['currency_iso']);
    }

    /**
     * Test MySkoolReportParser collection report parsing: slip continuity,
     * resetting student context on new slip, and mixed-currency receipt preservation.
     */
    public function test_phase3b31_myskool_collection_parser_continuity(): void
    {
        $parser = new \App\Services\Staging\MySkoolReportParser();

        $fictionalPaid = implode("\n", [
            'IQRA VIRTUAL SCHOOL,Agha Meer Jani Road Wazir Bagh Peshawar,Collection Report (01/Mar/2025 - 17/Oct/2026),,,,,,',
            ',,,,,,,,',
            'Cash,Slip #,GRN,Student Name,Particular,Date,Currency,Amount,',
            ',,,,,,,,',
            ',6467,5735,Muhammad,Monthly Fee - Jan-2025, 1-Mar-25,SAR,220,',
            ',6467,,,Monthly Fee - Feb-2025, 1-Mar-25,SAR,220,', // Continuation on same slip
            ',,,,,,,,',
            ',6467,5734,Yahya Naveed,Monthly Fee - Feb-2025, 1-Mar-25,SAR,180,',
            ',,,,,,,,',
            ',6489,,,Monthly Fee - Mar-2025, 1-Mar-25,SAR,300,', // New slip with empty GR: must NOT inherit 5734!
            ',,,,,,,,',
            ',7000,8801,Mixed Child,Monthly Fee - Jan-2025, 1-Mar-25,SAR,200,',
            ',7000,8801,Mixed Child,Transport Fee - Jan-2025, 1-Mar-25,AED,150,', // Mixed-currency slip line
        ]);

        $tmpFile = tempnam(sys_get_temp_dir(), 'test_paid_');
        file_put_contents($tmpFile, $fictionalPaid);

        $parsed = $parser->parsePaymentsReport($tmpFile);
        @unlink($tmpFile);

        $this->assertCount(6, $parsed);

        // Continuation on same slip
        $this->assertEquals('6467', $parsed[0]['slip_number']);
        $this->assertEquals('5735', $parsed[0]['gr_number']);
        $this->assertEquals('2025-03-01', $parsed[0]['payment_date_iso']);

        $this->assertEquals('6467', $parsed[1]['slip_number']);
        $this->assertEquals('5735', $parsed[1]['gr_number']); // Inherited

        // New slip 6489 must have empty GR, not 5734
        $this->assertEquals('6489', $parsed[3]['slip_number']);
        $this->assertEquals('', $parsed[3]['gr_number']);

        // Mixed-currency slip 7000 lines kept strictly separate
        $this->assertEquals('7000', $parsed[4]['slip_number']);
        $this->assertEquals('SAR', $parsed[4]['currency_iso']);
        $this->assertEquals(200.00, $parsed[4]['paid_amount']);

        $this->assertEquals('7000', $parsed[5]['slip_number']);
        $this->assertEquals('AED', $parsed[5]['currency_iso']);
        $this->assertEquals(150.00, $parsed[5]['paid_amount']);
    }

    /**
     * Test that comparing identical V1 and V2 datasets produces 100% unchanged items,
     * zero unexplained variance, and zero conflicts.
     */
    public function test_phase3b31_identical_v1_v2_comparison_zero_unexplained_variance(): void
    {
        $service = app(\App\Services\Staging\SnapshotReconciliationService::class);

        $v1Dues = [
            ['gr_number' => '1001', 'period_key' => '2026-10', 'fee_description' => 'Monthly Fee', 'particular_raw' => 'Monthly Fee - Oct-2026', 'currency_iso' => 'SAR', 'outstanding_amount' => 200.00],
            ['gr_number' => '1002', 'period_key' => '2026-10', 'fee_description' => 'Monthly Fee', 'particular_raw' => 'Monthly Fee - Oct-2026', 'currency_iso' => 'AED', 'outstanding_amount' => 300.00],
            ['gr_number' => '1003', 'period_key' => '2026-10', 'fee_description' => 'Tuition Fee', 'particular_raw' => 'Tuition Fee - Oct-2026', 'currency_iso' => 'PKR', 'outstanding_amount' => 15000.00],
        ];

        $v1Payments = [
            ['slip_number' => 'S1', 'gr_number' => '1001', 'period_key' => '2026-09', 'fee_description' => 'Monthly Fee', 'particular_raw' => 'Monthly Fee - Sep-2026', 'currency_iso' => 'SAR', 'paid_amount' => 200.00, 'payment_date_iso' => '2026-09-15'],
            ['slip_number' => 'S2', 'gr_number' => '1002', 'period_key' => '2026-09', 'fee_description' => 'Monthly Fee', 'particular_raw' => 'Monthly Fee - Sep-2026', 'currency_iso' => 'AED', 'paid_amount' => 300.00, 'payment_date_iso' => '2026-09-15'],
        ];

        // V2 has identical data but V2 dues only have particular_raw (simulating raw CSV parse)
        $v2Dues = [
            ['gr_number' => '1001', 'period_key' => '2026-10', 'particular_raw' => 'Monthly Fee - Oct-2026', 'currency_iso' => 'SAR', 'outstanding_amount' => 200.00],
            ['gr_number' => '1002', 'period_key' => '2026-10', 'particular_raw' => 'Monthly Fee - Oct-2026', 'currency_iso' => 'AED', 'outstanding_amount' => 300.00],
            ['gr_number' => '1003', 'period_key' => '2026-10', 'particular_raw' => 'Tuition Fee - Oct-2026', 'currency_iso' => 'PKR', 'outstanding_amount' => 15000.00],
        ];

        $v2Payments = $v1Payments;

        $results = $service->compareSnapshots($v1Dues, $v2Dues, $v1Payments, $v2Payments);

        $this->assertEquals(0, $results['summary']['total_classification_conflicts']);
        $this->assertEquals(0, $results['summary']['new_payments_total_count']);
        $this->assertCount(0, $results['missing_payments']);
        $this->assertCount(0, $results['changed_payments']);

        $sar = $results['currencies']['SAR'];
        $this->assertEquals(1, $sar['unchanged_count']);
        $this->assertEquals(200.00, $sar['unchanged_amount']);
        $this->assertEquals(0, $sar['increased_count']);
        $this->assertEquals(0, $sar['decreased_count']);
        $this->assertEquals(0, $sar['disappearing_count']);
        $this->assertEquals(0, $sar['newly_appearing_count']);
        $this->assertEquals(0.00, $sar['net_variance']);

        $aed = $results['currencies']['AED'];
        $this->assertEquals(1, $aed['unchanged_count']);
        $this->assertEquals(300.00, $aed['unchanged_amount']);
        $this->assertEquals(0.00, $aed['net_variance']);

        $pkr = $results['currencies']['PKR'];
        $this->assertEquals(1, $pkr['unchanged_count']);
        $this->assertEquals(15000.00, $pkr['unchanged_amount']);
        $this->assertEquals(0.00, $pkr['net_variance']);
    }

    /**
     * Test line-level payment matching across multiple details per slip and GR.
     */
    public function test_phase3b31_line_level_payment_matching_multiplicity(): void
    {
        $service = app(\App\Services\Staging\SnapshotReconciliationService::class);

        // V1 has 2 lines for same slip and GR (Jan and Feb)
        $v1Payments = [
            ['slip_number' => '6467', 'gr_number' => '5735', 'period_key' => '2025-01', 'fee_description' => 'Monthly Fee', 'currency_iso' => 'SAR', 'paid_amount' => 220.00, 'payment_date_iso' => '2025-03-01'],
            ['slip_number' => '6467', 'gr_number' => '5735', 'period_key' => '2025-02', 'fee_description' => 'Monthly Fee', 'currency_iso' => 'SAR', 'paid_amount' => 220.00, 'payment_date_iso' => '2025-03-01'],
        ];

        // V2 has: Jan (unchanged), Feb missing, and a newly added March line
        $v2Payments = [
            ['slip_number' => '6467', 'gr_number' => '5735', 'period_key' => '2025-01', 'particular_raw' => 'Monthly Fee - Jan-2025', 'currency_iso' => 'SAR', 'paid_amount' => 220.00, 'payment_date_iso' => '2025-03-01'],
            ['slip_number' => '6467', 'gr_number' => '5735', 'period_key' => '2025-03', 'particular_raw' => 'Monthly Fee - Mar-2025', 'currency_iso' => 'SAR', 'paid_amount' => 250.00, 'payment_date_iso' => '2025-03-15'],
        ];

        $results = $service->compareSnapshots([], [], $v1Payments, $v2Payments);

        // One payment line was newly added (March)
        $this->assertEquals(1, $results['summary']['new_payments_total_count']);
        $this->assertEquals(250.00, $results['summary']['new_payments_total_amount']);
        $this->assertEquals('6467', $results['new_payments'][0]['slip_number']);
        $this->assertEquals('5735', $results['new_payments'][0]['gr_number']);

        // One payment line was missing (Feb)
        $this->assertCount(1, $results['missing_payments']);
        $this->assertEquals('6467', $results['missing_payments'][0]['slip_number']);
        $this->assertEquals(220.00, $results['missing_payments'][0]['amount']);
    }

    /**
     * Test zero financial ledger posting during snapshot reconciliation.
     */
    public function test_phase3b31_zero_financial_ledger_posting(): void
    {
        $this->seed();
        $conn = config('database.default');

        $invoicesBefore = DB::table('invoices')->count();
        $invoiceItemsBefore = DB::table('invoice_items')->count();
        $paymentsBefore = DB::table('payments')->count();
        $allocationsBefore = DB::table('payment_allocations')->count();

        // Run reconciliation command preview
        $this->artisan('staging:reconcile-snapshot', [
            '--v1-connection' => $conn,
        ])->assertSuccessful();

        $this->assertEquals($invoicesBefore, DB::table('invoices')->count());
        $this->assertEquals($invoiceItemsBefore, DB::table('invoice_items')->count());
        $this->assertEquals($paymentsBefore, DB::table('payments')->count());
        $this->assertEquals($allocationsBefore, DB::table('payment_allocations')->count());
    }

    /**
     * Test Phase 3B.3.1A: Strict per-currency monetary totals verification.
     */
    public function test_phase3b31a_per_currency_monetary_totals_exact_reconciliation(): void
    {
        $service = app(\App\Services\Staging\SnapshotReconciliationService::class);

        // Fictional snapshot representing all seven active currencies with exact decimal values
        $v1Dues = [
            ['gr_number' => '101', 'period_key' => '2026-10', 'particular_raw' => 'Monthly Fee - Oct-2026', 'currency_iso' => 'SAR', 'outstanding_amount' => 2827415.00],
            ['gr_number' => '102', 'period_key' => '2026-10', 'particular_raw' => 'Monthly Fee - Oct-2026', 'currency_iso' => 'AED', 'outstanding_amount' => 1609993.00],
            ['gr_number' => '103', 'period_key' => '2026-10', 'particular_raw' => 'Monthly Fee - Oct-2026', 'currency_iso' => 'PKR', 'outstanding_amount' => 6528345.00],
            ['gr_number' => '104', 'period_key' => '2026-10', 'particular_raw' => 'Monthly Fee - Oct-2026', 'currency_iso' => 'QAR', 'outstanding_amount' => 11595.00],
            ['gr_number' => '105', 'period_key' => '2026-10', 'particular_raw' => 'Monthly Fee - Oct-2026', 'currency_iso' => 'USD', 'outstanding_amount' => 2452.00],
            ['gr_number' => '106', 'period_key' => '2026-10', 'particular_raw' => 'Monthly Fee - Oct-2026', 'currency_iso' => 'OMR', 'outstanding_amount' => 174.00],
            ['gr_number' => '107', 'period_key' => '2026-10', 'particular_raw' => 'Monthly Fee - Oct-2026', 'currency_iso' => 'UNRESOLVED', 'outstanding_amount' => 13635.00],
        ];

        $results = $service->compareSnapshots($v1Dues, $v1Dues);

        $this->assertEquals(2827415.00, $results['currencies']['SAR']['unchanged_amount']);
        $this->assertEquals(1609993.00, $results['currencies']['AED']['unchanged_amount']);
        $this->assertEquals(6528345.00, $results['currencies']['PKR']['unchanged_amount']);
        $this->assertEquals(11595.00, $results['currencies']['QAR']['unchanged_amount']);
        $this->assertEquals(2452.00, $results['currencies']['USD']['unchanged_amount']);
        $this->assertEquals(174.00, $results['currencies']['OMR']['unchanged_amount']);
        $this->assertEquals(13635.00, $results['currencies']['UNRESOLVED']['unchanged_amount']);
        $this->assertEquals(0, $results['summary']['total_classification_conflicts']);
    }

    /**
     * Test Phase 3B.3.1A: Same row counts with different amounts must detect variances.
     */
    public function test_phase3b31a_same_row_counts_different_amounts_detection(): void
    {
        $service = app(\App\Services\Staging\SnapshotReconciliationService::class);

        // V1 has 2 rows of 100.00 SAR
        $v1Dues = [
            ['gr_number' => '201', 'period_key' => '2026-10', 'particular_raw' => 'Monthly Fee - Oct-2026', 'currency_iso' => 'SAR', 'outstanding_amount' => 100.00],
            ['gr_number' => '202', 'period_key' => '2026-10', 'particular_raw' => 'Monthly Fee - Oct-2026', 'currency_iso' => 'SAR', 'outstanding_amount' => 100.00],
        ];

        // V2 has 2 rows of 150.00 SAR (same row count, different amounts)
        $v2Dues = [
            ['gr_number' => '201', 'period_key' => '2026-10', 'particular_raw' => 'Monthly Fee - Oct-2026', 'currency_iso' => 'SAR', 'outstanding_amount' => 150.00],
            ['gr_number' => '202', 'period_key' => '2026-10', 'particular_raw' => 'Monthly Fee - Oct-2026', 'currency_iso' => 'SAR', 'outstanding_amount' => 150.00],
        ];

        $results = $service->compareSnapshots($v1Dues, $v2Dues);

        $sar = $results['currencies']['SAR'];
        $this->assertEquals(0, $sar['unchanged_count']);
        $this->assertEquals(2, $sar['increased_count']);
        $this->assertEquals(100.00, $sar['increased_delta']); // (150-100) + (150-100) = 100.00
        $this->assertEquals(100.00, $sar['net_variance']);
    }

    /**
     * Test Phase 3B.3.1A: Same amounts assigned to different currencies are strictly isolated.
     */
    public function test_phase3b31a_same_amounts_different_currencies_isolation(): void
    {
        $service = app(\App\Services\Staging\SnapshotReconciliationService::class);

        // V1 has 500.00 SAR
        $v1Dues = [
            ['gr_number' => '301', 'period_key' => '2026-10', 'particular_raw' => 'Monthly Fee - Oct-2026', 'currency_iso' => 'SAR', 'outstanding_amount' => 500.00],
        ];

        // V2 has 500.00 AED for the exact same student and period
        $v2Dues = [
            ['gr_number' => '301', 'period_key' => '2026-10', 'particular_raw' => 'Monthly Fee - Oct-2026', 'currency_iso' => 'AED', 'outstanding_amount' => 500.00],
        ];

        $results = $service->compareSnapshots($v1Dues, $v2Dues);

        // Must NOT match as unchanged
        $this->assertEquals(0, $results['currencies']['SAR']['unchanged_count']);
        $this->assertEquals(0, $results['currencies']['AED']['unchanged_count']);

        // Must record as disappearing in SAR and newly appearing in AED
        $this->assertEquals(1, $results['currencies']['SAR']['disappearing_count']);
        $this->assertEquals(500.00, $results['currencies']['SAR']['disappearing_amount']);
        $this->assertEquals(-500.00, $results['currencies']['SAR']['net_variance']);

        $this->assertEquals(1, $results['currencies']['AED']['newly_appearing_count']);
        $this->assertEquals(500.00, $results['currencies']['AED']['newly_appearing_amount']);
        $this->assertEquals(500.00, $results['currencies']['AED']['net_variance']);

        // Must record CURRENCY_MISMATCH conflict
        $this->assertEquals(1, $results['summary']['total_classification_conflicts']);
        $this->assertEquals('CURRENCY_MISMATCH', $results['conflicts'][0]['type']);
    }

    /**
     * Test Phase 3B.3.1A: Source row identity and multiplicity preservation.
     */
    public function test_phase3b31a_source_row_identity_and_multiplicity_preservation(): void
    {
        $service = app(\App\Services\Staging\SnapshotReconciliationService::class);

        // V1 has two identical legitimate fee lines of 200.00 SAR for the same student & period
        $v1Dues = [
            ['source_row' => 10, 'gr_number' => '401', 'period_key' => '2026-10', 'particular_raw' => 'Monthly Fee - Oct-2026', 'currency_iso' => 'SAR', 'outstanding_amount' => 200.00],
            ['source_row' => 11, 'gr_number' => '401', 'period_key' => '2026-10', 'particular_raw' => 'Monthly Fee - Oct-2026', 'currency_iso' => 'SAR', 'outstanding_amount' => 200.00],
        ];

        // Scenario 1: V2 also has two identical lines -> both must be unchanged
        $results1 = $service->compareSnapshots($v1Dues, $v1Dues);
        $this->assertEquals(2, $results1['currencies']['SAR']['unchanged_count']);
        $this->assertEquals(400.00, $results1['currencies']['SAR']['unchanged_amount']);
        $this->assertEquals(0, $results1['currencies']['SAR']['disappearing_count']);

        // Scenario 2: V2 only has one line -> 1 unchanged (200), 1 disappearing (200)
        $v2Single = [
            ['source_row' => 10, 'gr_number' => '401', 'period_key' => '2026-10', 'particular_raw' => 'Monthly Fee - Oct-2026', 'currency_iso' => 'SAR', 'outstanding_amount' => 200.00],
        ];
        $results2 = $service->compareSnapshots($v1Dues, $v2Single);
        $this->assertEquals(1, $results2['currencies']['SAR']['unchanged_count']);
        $this->assertEquals(200.00, $results2['currencies']['SAR']['unchanged_amount']);
        $this->assertEquals(1, $results2['currencies']['SAR']['disappearing_count']);
        $this->assertEquals(200.00, $results2['currencies']['SAR']['disappearing_amount']);
        $this->assertEquals(-200.00, $results2['currencies']['SAR']['net_variance']);
    }

    /**
     * Test Phase 3B.3.1A: Payment incorrect amount detection.
     */
    public function test_phase3b31a_payment_incorrect_amount_detection(): void
    {
        $service = app(\App\Services\Staging\SnapshotReconciliationService::class);

        $v1Payments = [
            ['slip_number' => 'SLIP-101', 'gr_number' => '501', 'particular_raw' => 'Monthly Fee - Oct-2026', 'currency_iso' => 'SAR', 'paid_amount' => 250.00, 'payment_date_iso' => '2026-10-15'],
        ];

        // V2 has same slip and GR, but altered amount of 300.00
        $v2Payments = [
            ['slip_number' => 'SLIP-101', 'gr_number' => '501', 'particular_raw' => 'Monthly Fee - Oct-2026', 'currency_iso' => 'SAR', 'paid_amount' => 300.00, 'payment_date_iso' => '2026-10-15'],
        ];

        $results = $service->compareSnapshots([], [], $v1Payments, $v2Payments);

        $this->assertCount(1, $results['changed_payments']);
        $this->assertEquals(250.00, $results['changed_payments'][0]['v1_amount']);
        $this->assertEquals(300.00, $results['changed_payments'][0]['v2_amount']);
        $this->assertEquals(0, $results['currencies']['SAR']['unchanged_payments_count']);
    }

    /**
     * Test Phase 3B.3.1A: Payment incorrect currency detection.
     */
    public function test_phase3b31a_payment_incorrect_currency_detection(): void
    {
        $service = app(\App\Services\Staging\SnapshotReconciliationService::class);

        $v1Payments = [
            ['slip_number' => 'SLIP-102', 'gr_number' => '502', 'particular_raw' => 'Monthly Fee - Oct-2026', 'currency_iso' => 'SAR', 'paid_amount' => 500.00],
        ];

        // V2 assigns same slip and GR to AED instead of SAR
        $v2Payments = [
            ['slip_number' => 'SLIP-102', 'gr_number' => '502', 'particular_raw' => 'Monthly Fee - Oct-2026', 'currency_iso' => 'AED', 'paid_amount' => 500.00],
        ];

        $results = $service->compareSnapshots([], [], $v1Payments, $v2Payments);

        $this->assertEquals(1, $results['currencies']['SAR']['missing_payments_count']);
        $this->assertEquals(500.00, $results['currencies']['SAR']['missing_payments_amount']);
        $this->assertEquals(1, $results['currencies']['AED']['new_payments_count']);
        $this->assertEquals(500.00, $results['currencies']['AED']['new_payments_amount']);
        $this->assertEquals(0, $results['currencies']['SAR']['unchanged_payments_count']);
        $this->assertEquals(0, $results['currencies']['AED']['unchanged_payments_count']);
    }

    /**
     * Test Phase 3B.3.1A: Missing and duplicated payment details multiplicity.
     */
    public function test_phase3b31a_payment_missing_and_duplicated_details(): void
    {
        $service = app(\App\Services\Staging\SnapshotReconciliationService::class);

        // V1 has two identical legitimate payment lines of 100 SAR on the same slip
        $v1Payments = [
            ['slip_number' => 'SLIP-103', 'gr_number' => '503', 'particular_raw' => 'Tuition Fee - Oct-2026', 'currency_iso' => 'SAR', 'paid_amount' => 100.00],
            ['slip_number' => 'SLIP-103', 'gr_number' => '503', 'particular_raw' => 'Tuition Fee - Oct-2026', 'currency_iso' => 'SAR', 'paid_amount' => 100.00],
        ];

        // Scenario 1: V2 drops one duplicate line -> 1 unchanged, 1 missing
        $v2Single = [
            ['slip_number' => 'SLIP-103', 'gr_number' => '503', 'particular_raw' => 'Tuition Fee - Oct-2026', 'currency_iso' => 'SAR', 'paid_amount' => 100.00],
        ];
        $results1 = $service->compareSnapshots([], [], $v1Payments, $v2Single);
        $this->assertEquals(1, $results1['currencies']['SAR']['unchanged_payments_count']);
        $this->assertEquals(100.00, $results1['currencies']['SAR']['unchanged_payments_amount']);
        $this->assertEquals(1, $results1['currencies']['SAR']['missing_payments_count']);
        $this->assertEquals(100.00, $results1['currencies']['SAR']['missing_payments_amount']);

        // Scenario 2: V2 adds an extra duplicate line -> 2 unchanged, 1 new
        $v2Triple = [
            ['slip_number' => 'SLIP-103', 'gr_number' => '503', 'particular_raw' => 'Tuition Fee - Oct-2026', 'currency_iso' => 'SAR', 'paid_amount' => 100.00],
            ['slip_number' => 'SLIP-103', 'gr_number' => '503', 'particular_raw' => 'Tuition Fee - Oct-2026', 'currency_iso' => 'SAR', 'paid_amount' => 100.00],
            ['slip_number' => 'SLIP-103', 'gr_number' => '503', 'particular_raw' => 'Tuition Fee - Oct-2026', 'currency_iso' => 'SAR', 'paid_amount' => 100.00],
        ];
        $results2 = $service->compareSnapshots([], [], $v1Payments, $v2Triple);
        $this->assertEquals(2, $results2['currencies']['SAR']['unchanged_payments_count']);
        $this->assertEquals(200.00, $results2['currencies']['SAR']['unchanged_payments_amount']);
        $this->assertEquals(1, $results2['currencies']['SAR']['new_payments_count']);
        $this->assertEquals(100.00, $results2['currencies']['SAR']['new_payments_amount']);
    }

    /**
     * Test Phase 3B.3.1A: Matching row count but different currency distribution.
     */
    public function test_phase3b31a_payment_matching_row_count_different_currency_distribution(): void
    {
        $service = app(\App\Services\Staging\SnapshotReconciliationService::class);

        // V1: 4 rows total (3 SAR @ 250, 1 AED @ 250) = 1,000 total nominal
        $v1Payments = [
            ['slip_number' => 'S1', 'gr_number' => '601', 'particular_raw' => 'Fee', 'currency_iso' => 'SAR', 'paid_amount' => 250.00],
            ['slip_number' => 'S2', 'gr_number' => '602', 'particular_raw' => 'Fee', 'currency_iso' => 'SAR', 'paid_amount' => 250.00],
            ['slip_number' => 'S3', 'gr_number' => '603', 'particular_raw' => 'Fee', 'currency_iso' => 'SAR', 'paid_amount' => 250.00],
            ['slip_number' => 'S4', 'gr_number' => '604', 'particular_raw' => 'Fee', 'currency_iso' => 'AED', 'paid_amount' => 250.00],
        ];

        // V2: 4 rows total (1 SAR @ 250, 3 AED @ 250) = 1,000 total nominal
        $v2Payments = [
            ['slip_number' => 'S1', 'gr_number' => '601', 'particular_raw' => 'Fee', 'currency_iso' => 'SAR', 'paid_amount' => 250.00],
            ['slip_number' => 'S2', 'gr_number' => '602', 'particular_raw' => 'Fee', 'currency_iso' => 'AED', 'paid_amount' => 250.00],
            ['slip_number' => 'S3', 'gr_number' => '603', 'particular_raw' => 'Fee', 'currency_iso' => 'AED', 'paid_amount' => 250.00],
            ['slip_number' => 'S4', 'gr_number' => '604', 'particular_raw' => 'Fee', 'currency_iso' => 'AED', 'paid_amount' => 250.00],
        ];

        $results = $service->compareSnapshots([], [], $v1Payments, $v2Payments);

        // Both row counts are 4
        $this->assertEquals(4, $results['summary']['v1_payments_total_rows']);
        $this->assertEquals(4, $results['summary']['v2_payments_total_rows']);

        // SAR: 1 unchanged, 2 missing
        $this->assertEquals(1, $results['currencies']['SAR']['unchanged_payments_count']);
        $this->assertEquals(2, $results['currencies']['SAR']['missing_payments_count']);
        $this->assertEquals(500.00, $results['currencies']['SAR']['missing_payments_amount']);

        // AED: 1 unchanged, 2 new
        $this->assertEquals(1, $results['currencies']['AED']['unchanged_payments_count']);
        $this->assertEquals(2, $results['currencies']['AED']['new_payments_count']);
        $this->assertEquals(500.00, $results['currencies']['AED']['new_payments_amount']);
    }

    /**
     * Test Phase 3B.3.1A: Multi-currency receipt slip handling.
     */
    public function test_phase3b31a_payment_multi_currency_receipt_handling(): void
    {
        $service = app(\App\Services\Staging\SnapshotReconciliationService::class);

        // A single receipt slip containing multiple lines in different currencies
        $v1Payments = [
            ['slip_number' => 'SLIP-MULTI-99', 'gr_number' => '701', 'particular_raw' => 'Monthly Fee - Oct-2026', 'currency_iso' => 'SAR', 'paid_amount' => 250.00],
            ['slip_number' => 'SLIP-MULTI-99', 'gr_number' => '702', 'particular_raw' => 'Exam Fee - Oct-2026', 'currency_iso' => 'USD', 'paid_amount' => 70.00],
        ];

        $results = $service->compareSnapshots([], [], $v1Payments, $v1Payments);

        $this->assertEquals(1, $results['currencies']['SAR']['unchanged_payments_count']);
        $this->assertEquals(250.00, $results['currencies']['SAR']['unchanged_payments_amount']);
        $this->assertEquals(1, $results['currencies']['USD']['unchanged_payments_count']);
        $this->assertEquals(70.00, $results['currencies']['USD']['unchanged_payments_amount']);
        $this->assertEquals(0, $results['currencies']['SAR']['new_payments_count']);
        $this->assertEquals(0, $results['currencies']['USD']['new_payments_count']);
    }

    /**
     * Test Phase 3B.3.1A: Unresolved currency preservation in payments.
     */
    public function test_phase3b31a_payment_unresolved_currency_preservation(): void
    {
        $service = app(\App\Services\Staging\SnapshotReconciliationService::class);

        // Malformed currency labels ('SAFR', '0', blank, 'SAT')
        $v1Payments = [
            ['slip_number' => 'S1', 'gr_number' => '801', 'particular_raw' => 'Fee', 'currency_raw' => 'SAFR', 'paid_amount' => 730.00],
            ['slip_number' => 'S2', 'gr_number' => '802', 'particular_raw' => 'Fee', 'currency_raw' => '0', 'paid_amount' => 1160.00],
            ['slip_number' => 'S3', 'gr_number' => '803', 'particular_raw' => 'Fee', 'currency_raw' => '', 'paid_amount' => 50.00],
            ['slip_number' => 'S4', 'gr_number' => '804', 'particular_raw' => 'Fee', 'currency_raw' => 'SAT', 'paid_amount' => 50.00],
        ];

        $results = $service->compareSnapshots([], [], $v1Payments, $v1Payments);

        // All 4 malformed currency lines must be preserved under UNRESOLVED
        $this->assertEquals(4, $results['currencies']['UNRESOLVED']['v1_payments_count']);
        $this->assertEquals(1990.00, $results['currencies']['UNRESOLVED']['v1_payments_amount']);
        $this->assertEquals(4, $results['currencies']['UNRESOLVED']['unchanged_payments_count']);
        $this->assertEquals(1990.00, $results['currencies']['UNRESOLVED']['unchanged_payments_amount']);

        // None should leak into SAR or AED
        $this->assertEquals(0, $results['currencies']['SAR']['v1_payments_count']);
        $this->assertEquals(0, $results['currencies']['AED']['v1_payments_count']);
    }
}

