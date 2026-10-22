<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Migration\HistoricalOpeningBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class Phase3cFinalPortalSecurityAndAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /**
     * Requirement 1: Urgent Security Correction - Password Reset Command.
     * Verify that no fixed password or --password argument is accepted,
     * weak passwords are rejected, confirmation is enforced, and password is hash-updated.
     */
    public function test_reset_password_command_enforces_interactive_security_and_minimum_strength(): void
    {
        $admin = User::create([
            'name' => 'Administrator',
            'email' => 'admin@iqra.edu',
            'role' => 'super_admin',
            'is_active' => true,
            'password' => Hash::make('old_weak_password'),
            'remember_token' => 'old_token_sample',
        ]);

        // Attempt 1: Password too short (< 12 chars)
        $this->artisan('iqra:reset-password', ['user' => (string) $admin->id])
            ->expectsQuestion('Enter new secret password (minimum 12 characters):', 'short123')
            ->expectsOutputToContain('at least 12 characters')
            ->assertExitCode(1);

        // Attempt 2: Password matches common dictionary word
        $this->artisan('iqra:reset-password', ['user' => (string) $admin->id])
            ->expectsQuestion('Enter new secret password (minimum 12 characters):', 'administrator')
            ->expectsOutputToContain('Cannot use common default or easily guessable phrases')
            ->assertExitCode(1);

        // Attempt 3: Confirmation mismatch
        $this->artisan('iqra:reset-password', ['user' => (string) $admin->id])
            ->expectsQuestion('Enter new secret password (minimum 12 characters):', 'My$ecureSecret2026!')
            ->expectsQuestion('Confirm new secret password:', 'DifferentSecret2026!')
            ->expectsOutputToContain('Password confirmation did not match')
            ->assertExitCode(1);

        // Attempt 4: Successful reset with strong secret
        $this->artisan('iqra:reset-password', ['user' => (string) $admin->id])
            ->expectsQuestion('Enter new secret password (minimum 12 characters):', 'CompliantPassphrase#2026')
            ->expectsQuestion('Confirm new secret password:', 'CompliantPassphrase#2026')
            ->expectsOutputToContain("SUCCESS: Password for {$admin->name} ({$admin->email}) has been securely reset.")
            ->assertExitCode(0);

        // Verify database updates
        $admin->refresh();
        $this->assertTrue(Hash::check('CompliantPassphrase#2026', $admin->password));
        $this->assertNull($admin->remember_token);

        // Verify audit log entry
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'password_reset_interactive',
            'actor_id' => $admin->id,
            'subject_type' => 'user',
            'subject_id' => $admin->id,
        ]);

        // Ensure command definition does NOT define a --password argument or option
        $cmd = $this->app->make(\App\Console\Commands\ResetAdminPasswordCommand::class);
        $this->assertFalse($cmd->getDefinition()->hasOption('password'));
        $this->assertFalse($cmd->getDefinition()->hasArgument('password'));
    }

    /**
     * Requirement 2: Historical Collection Batch Filtering.
     * Verify that student and family historical collection views strictly display
     * only the approved batch BATCH-20261022-PORTAL-V2-REVISED and exclude older batches.
     */
    public function test_historical_collections_filter_by_approved_batch_only(): void
    {
        $admin = User::create([
            'name' => 'Super Admin',
            'email' => 'admin_batch@iqra.edu',
            'password' => Hash::make('SecretPass#2026'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $familyId = DB::table('families')->insertGetId([
            'family_code' => 'FAM-TEST-99',
            'guardian_name' => 'Staged Parent',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentId = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => 'GR-TEST-99',
            'name' => 'Approved Staged Student',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Insert duplicate row in older batch (audit) and row in revised batch (approved)
        DB::table('qa_staged_collections')->insert([
            [
                'batch_id' => 'BATCH-20261022-PORTAL-V2',
                'source_row' => 101,
                'gr_number' => 'GR-TEST-99',
                'student_name' => 'Audit Duplicate Student',
                'family_code' => 'FAM-TEST-99',
                'slip_number' => 'SLIP-AUDIT-01',
                'payment_date_parsed' => '2026-09-10',
                'amount' => 500.00,
                'amount_minor' => 50000,
                'currency' => 'SAR',
                'fee_particular' => 'Tuition Fee Sep 2026',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'batch_id' => 'BATCH-20261022-PORTAL-V2-REVISED',
                'source_row' => 102,
                'gr_number' => 'GR-TEST-99',
                'student_name' => 'Approved Staged Student',
                'family_code' => 'FAM-TEST-99',
                'slip_number' => 'SLIP-APPROVED-02',
                'payment_date_parsed' => '2026-09-10',
                'amount' => 500.00,
                'amount_minor' => 50000,
                'currency' => 'SAR',
                'fee_particular' => 'Tuition Fee Sep 2026',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Query student profile page
        $response = $this->actingAs($admin)->get("/manage-student/{$studentId}");
        $response->assertStatus(200);

        // Approved slip MUST appear
        $response->assertSee('SLIP-APPROVED-02');
        // Audit slip from old batch MUST NOT appear
        $response->assertDontSee('SLIP-AUDIT-01');

        // Query family challan/collection page
        $collResponse = $this->actingAs($admin)->get('/challan?family=FAM-TEST-99');
        $collResponse->assertStatus(200);
        $collResponse->assertSee('SLIP-APPROVED-02');
        $collResponse->assertDontSee('SLIP-AUDIT-01');
    }

    /**
     * Requirement 4: Held Dues Staff Review & Export.
     * Verify that getHeldDuesReport correctly categorizes held items and provides required corrections,
     * and that the web screens and CSV export endpoints are fully functional.
     */
    public function test_held_dues_report_and_export_endpoints(): void
    {
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin_held@iqra.edu',
            'password' => Hash::make('SecretPass#2026'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $familyId = DB::table('families')->insertGetId([
            'family_code' => 'FAM-HELD-01',
            'guardian_name' => 'Held Parent',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $campus = DB::table('campuses')->value('id');
        $deptSchool = DB::table('departments')->where('code', 'school')->value('id');

        $s1 = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => 'GR-HOLD-01',
            'name' => 'OnHold Student',
            'status' => 'review_hold',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('enrollments')->insertGetId([
            'student_id' => $s1,
            'campus_id' => $campus,
            'department_id' => $deptSchool,
            'currency' => 'SAR',
            'monthly_fee_minor' => 100000,
            'status' => 'review_hold',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $s2 = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => 'GR-NORM-01',
            'name' => 'Normal Student',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('enrollments')->insertGetId([
            'student_id' => $s2,
            'campus_id' => $campus,
            'department_id' => $deptSchool,
            'currency' => 'SAR',
            'monthly_fee_minor' => 50000,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $activeBatch = (string) config('imports.active_batch_id', 'BATCH-20261022-PORTAL-V2-REVISED');

        // Insert 3 test dues representing the 3 held categories
        DB::table('qa_staged_dues')->insert([
            [
                'batch_id' => $activeBatch,
                'source_row' => 10,
                'gr_number' => 'GR-HOLD-01',
                'student_name' => 'OnHold Student',
                'class_in_dues_report' => 'Grade 1',
                'fee_particular' => 'Monthly Fee - Oct-2026',
                'outstanding_amount' => 1000.00,
                'outstanding_amount_minor' => 100000,
                'currency' => 'SAR',
                'is_currency_unresolved' => false,
                'has_currency_conflict' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'batch_id' => $activeBatch,
                'source_row' => 20,
                'gr_number' => 'GR-NORM-01',
                'student_name' => 'Normal Student',
                'class_in_dues_report' => 'Grade 2',
                'fee_particular' => 'Miscellaneous Books Fee', // No date/period
                'outstanding_amount' => 250.00,
                'outstanding_amount_minor' => 25000,
                'currency' => 'SAR',
                'is_currency_unresolved' => false,
                'has_currency_conflict' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'batch_id' => $activeBatch,
                'source_row' => 30,
                'gr_number' => 'GR-NORM-01',
                'student_name' => 'Normal Student',
                'class_in_dues_report' => 'Grade 2',
                'fee_particular' => 'Monthly Fee - Oct-2026',
                'outstanding_amount' => 400.00,
                'outstanding_amount_minor' => 40000,
                'currency' => '', // Blank/unresolved currency
                'is_currency_unresolved' => true,
                'has_currency_conflict' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $service = app(HistoricalOpeningBalanceService::class);
        $report = $service->getHeldDuesReport();

        $this->assertCount(3, $report);

        $categories = collect($report)->pluck('category')->all();
        $this->assertContains('review_hold_student', $categories);
        $this->assertContains('unknown_period', $categories);
        $this->assertContains('unresolved_currency', $categories);

        // Web view test
        $response = $this->actingAs($admin)->get('/migration/held-dues');
        $response->assertStatus(200);
        $response->assertSee('Financial Exceptions — Held Dues Lines');
        $response->assertSee('Miscellaneous Books Fee');
        $response->assertSee('GR-HOLD-01');

        // CSV export test
        $exportResponse = $this->actingAs($admin)->get('/migration/held-dues/export');
        $exportResponse->assertStatus(200);
        $this->assertStringContainsString('text/csv', $exportResponse->headers->get('Content-Type'));
        $this->assertStringContainsString('held_dues_exceptions_report_', $exportResponse->headers->get('Content-Disposition'));

        // Artisan report command test
        $this->artisan('qa:held-dues-report')
            ->expectsOutputToContain('Total Held Dues Lines:')
            ->assertExitCode(0);
    }
}
