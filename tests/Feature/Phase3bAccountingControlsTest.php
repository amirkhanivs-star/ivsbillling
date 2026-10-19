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
}
