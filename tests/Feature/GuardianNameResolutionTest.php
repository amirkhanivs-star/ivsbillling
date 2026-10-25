<?php

namespace Tests\Feature;

use App\Services\GuardianNameResolver;
use App\Services\InvoicePayloadBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GuardianNameResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_rejects_formulas_errors_and_placeholders()
    {
        // Valid names
        $this->assertTrue(GuardianNameResolver::isValid('Muhammad Usman'));
        $this->assertTrue(GuardianNameResolver::isValid('Dr. Fatima Al-Zahra'));
        $this->assertTrue(GuardianNameResolver::isValid('Ali'));

        // Formula values
        $this->assertFalse(GuardianNameResolver::isValid('=len(N3)'));
        $this->assertFalse(GuardianNameResolver::isValid('=SUM(A1:B2)'));
        $this->assertFalse(GuardianNameResolver::isValid('=LEN(N120)'));
        $this->assertFalse(GuardianNameResolver::isValid('="test"'));

        // Excel error codes
        $this->assertFalse(GuardianNameResolver::isValid('#REF!'));
        $this->assertFalse(GuardianNameResolver::isValid('#VALUE!'));
        $this->assertFalse(GuardianNameResolver::isValid('#N/A'));
        $this->assertFalse(GuardianNameResolver::isValid('#NAME?'));
        $this->assertFalse(GuardianNameResolver::isValid('#DIV/0!'));

        // Placeholders and empty
        $this->assertFalse(GuardianNameResolver::isValid(null));
        $this->assertFalse(GuardianNameResolver::isValid(''));
        $this->assertFalse(GuardianNameResolver::isValid('   '));
        $this->assertFalse(GuardianNameResolver::isValid('-'));
        $this->assertFalse(GuardianNameResolver::isValid('---'));
        $this->assertFalse(GuardianNameResolver::isValid('n/a'));
        $this->assertFalse(GuardianNameResolver::isValid('Not provided'));
    }

    public function test_clean_sanitizes_or_returns_null()
    {
        $this->assertSame('Tariq Mahmood', GuardianNameResolver::clean('  Tariq Mahmood  '));
        $this->assertNull(GuardianNameResolver::clean('=len(N3)'));
        $this->assertNull(GuardianNameResolver::clean('#REF!'));
        $this->assertNull(GuardianNameResolver::clean('-'));
        $this->assertNull(GuardianNameResolver::clean(''));
    }

    public function test_four_tier_resolution_priority_for_students()
    {
        // Priority 1: Student override takes precedence over family and father
        $res1 = GuardianNameResolver::resolveStudent(
            (object)['id' => 10, 'guardian_name_override' => 'Custom Guardian Override', 'father_name' => 'Original Father Name'],
            (object)['id' => 100, 'guardian_name' => 'Family Guardian']
        );
        $this->assertSame('Custom Guardian Override', $res1['name']);
        $this->assertSame('override', $res1['source']);
        $this->assertTrue($res1['is_override']);

        // Priority 2: Valid family guardian takes precedence over father when no override
        $res2 = GuardianNameResolver::resolveStudent(
            (object)['id' => 10, 'guardian_name_override' => null, 'father_name' => 'Original Father Name'],
            (object)['id' => 100, 'guardian_name' => 'Family Guardian']
        );
        $this->assertSame('Family Guardian', $res2['name']);
        $this->assertSame('family', $res2['source']);
        $this->assertFalse($res2['is_override']);

        // Priority 3: Father name used when family guardian is formula/invalid
        $res3 = GuardianNameResolver::resolveStudent(
            (object)['id' => 10, 'guardian_name_override' => null, 'father_name' => 'Original Father Name'],
            (object)['id' => 100, 'guardian_name' => '=len(N3)']
        );
        $this->assertSame('Original Father Name', $res3['name']);
        $this->assertSame('father', $res3['source']);
        $this->assertFalse($res3['is_override']);

        // Priority 4: 'Not provided' fallback when all sources are invalid/empty
        $res4 = GuardianNameResolver::resolveStudent(
            (object)['id' => 10, 'guardian_name_override' => null, 'father_name' => '-'],
            (object)['id' => 100, 'guardian_name' => '=len(N3)']
        );
        $this->assertSame('Not provided', $res4['name']);
        $this->assertSame('none', $res4['source']);
    }

    public function test_family_resolution_handles_conflicts_safely()
    {
        // Conflicting siblings produce safe combined presentation without picking arbitrarily
        $fam = (object)['id' => 101, 'guardian_name' => '=len(N3)'];
        $students = [
            (object)['id' => 1, 'name' => 'Child A', 'guardian_name_override' => 'Muhammad Noman', 'father_name' => 'Muhammad Noman'],
            (object)['id' => 2, 'name' => 'Child B', 'guardian_name_override' => 'Mohammed Noman', 'father_name' => 'Mohammed Noman'],
        ];

        $this->assertTrue(GuardianNameResolver::hasConflictingGuardians($fam, $students));
        $this->assertSame('Muhammad Noman / Mohammed Noman', GuardianNameResolver::forFamily($fam, $students));

        // When all siblings agree on guardian
        $sameStudents = [
            (object)['id' => 1, 'name' => 'Child A', 'guardian_name_override' => 'Muhammad Noman', 'father_name' => 'Muhammad Noman'],
            (object)['id' => 2, 'name' => 'Child B', 'guardian_name_override' => 'Muhammad Noman', 'father_name' => 'Muhammad Noman'],
        ];
        $this->assertFalse(GuardianNameResolver::hasConflictingGuardians($fam, $sameStudents));
        $this->assertSame('Muhammad Noman', GuardianNameResolver::forFamily($fam, $sameStudents));
    }

    public function test_database_driven_resolution_with_db_records()
    {
        $now = now()->toDateTimeString();

        // Create Family in test database with formula in guardian_name
        $familyId = DB::table('families')->insertGetId([
            'family_code' => 'FAM-TEST-01',
            'guardian_name' => '=len(N3)',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Student 1: Has individual override
        $s1Id = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => 'GR-001',
            'name' => 'Student One',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('student_registration_details')->insert([
            'student_id' => $s1Id,
            'father_name' => 'Father Name One',
            'guardian_name_override' => 'Override Name One',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Student 2: No override, but has father_name
        $s2Id = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => 'GR-002',
            'name' => 'Student Two',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('student_registration_details')->insert([
            'student_id' => $s2Id,
            'father_name' => 'Father Name Two',
            'guardian_name_override' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Test resolving by student ID from DB:
        // Student 1 should resolve to override
        $res1 = GuardianNameResolver::forStudent($s1Id);
        $this->assertSame('Override Name One', $res1);

        // Student 2 should resolve to father_name since family guardian is formula
        $res2 = GuardianNameResolver::forStudent($s2Id);
        $this->assertSame('Father Name Two', $res2);

        // Family should resolve to combined string since students have different guardians
        $resFam = GuardianNameResolver::forFamily($familyId);
        $this->assertSame('Override Name One / Father Name Two', $resFam);
    }

    public function test_mysql_database_records_verification_if_available(): void
    {
        try {
            $envFile = base_path('.env');
            if (file_exists($envFile)) {
                $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $envVars = [];
                foreach ($lines as $line) {
                    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
                    [$k, $v] = explode('=', $line, 2);
                    $envVars[trim($k)] = trim($v);
                }
                if (!empty($envVars['DB_DATABASE'])) {
                    config([
                        'database.connections.mysql.host' => $envVars['DB_HOST'] ?? '127.0.0.1',
                        'database.connections.mysql.port' => $envVars['DB_PORT'] ?? '3306',
                        'database.connections.mysql.database' => $envVars['DB_DATABASE'],
                        'database.connections.mysql.username' => $envVars['DB_USERNAME'] ?? 'root',
                        'database.connections.mysql.password' => $envVars['DB_PASSWORD'] ?? '',
                    ]);
                    DB::purge('mysql');
                }
            }

            $mysql = DB::connection('mysql');
            $mysql->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL connection not available in this test environment: ' . $e->getMessage());
            return;
        }

        // Verify the 223 students with overrides in the active MySQL database
        $overrideStudents = $mysql->table('student_registration_details as srd')
            ->join('students as s', 's.id', '=', 'srd.student_id')
            ->join('families as f', 'f.id', '=', 's.family_id')
            ->whereNotNull('srd.guardian_name_override')
            ->where('srd.guardian_name_override', '!=', '')
            ->select('s.id as student_id', 's.family_id', 'srd.guardian_name_override', 'srd.father_name', 'f.guardian_name as family_guardian')
            ->get();

        $this->assertCount(223, $overrideStudents, 'Expected exactly 223 student records with overrides in MySQL');

        foreach ($overrideStudents as $st) {
            // Must never contain formula or error tokens
            $this->assertStringNotContainsString('=', $st->guardian_name_override);
            $this->assertStringNotContainsString('len(', strtolower($st->guardian_name_override));
            $this->assertStringNotContainsString('#', $st->guardian_name_override);

            // Override must match the original father name
            $this->assertSame(trim($st->father_name), trim($st->guardian_name_override));
        }
    }
}
