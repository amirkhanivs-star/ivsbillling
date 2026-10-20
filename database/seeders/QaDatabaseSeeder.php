<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Services\Staging\DatabaseSafetyGuard;

class QaDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $conn = $this->command?->getOutput() ? 'qa' : config('database.default');
        if ($conn === 'mysql' && config('database.connections.mysql.database') === 'iqra_billing_qa') {
            $conn = 'mysql';
        }

        // Assert strictly QA or testing database
        if (config("database.connections.{$conn}.driver") === 'mysql') {
            DatabaseSafetyGuard::assertQaDatabase($conn);
        }

        $now = now();

        // 1. Core Campuses
        DB::connection($conn)->table('campuses')->updateOrInsert(
            ['code' => 'main'],
            ['name' => 'Main Campus', 'address' => 'IQRA Virtual Main Campus', 'updated_at' => $now, 'created_at' => $now]
        );
        $mainCampusId = DB::connection($conn)->table('campuses')->where('code', 'main')->value('id');

        // 2. Core Departments
        $deptMap = [
            'school' => 'School',
            'tuition' => 'Tuition',
            'quran' => 'Quran',
        ];
        foreach ($deptMap as $code => $name) {
            DB::connection($conn)->table('departments')->updateOrInsert(
                ['code' => $code],
                ['name' => $name, 'updated_at' => $now, 'created_at' => $now]
            );
        }

        // 3. Department Branding
        $schoolDeptId = DB::connection($conn)->table('departments')->where('code', 'school')->value('id');
        $tuitionDeptId = DB::connection($conn)->table('departments')->where('code', 'tuition')->value('id');

        if ($schoolDeptId) {
            DB::connection($conn)->table('department_branding')->updateOrInsert(
                ['department_id' => $schoolDeptId],
                [
                    'display_name' => 'IQRA Virtual School (QA)',
                    'payment_instructions' => 'QA Test Portal - Simulated payments only.',
                    'footer_email' => 'qa_support@iqra.test',
                    'footer_phone' => '+920000000000',
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        if ($tuitionDeptId) {
            DB::connection($conn)->table('department_branding')->updateOrInsert(
                ['department_id' => $tuitionDeptId],
                [
                    'display_name' => 'IQRA Virtual Tuition (QA)',
                    'payment_instructions' => 'QA Test Portal - Simulated tuition fees.',
                    'footer_email' => 'qa_tuition@iqra.test',
                    'footer_phone' => '+920000000001',
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        // 4. Fee Types
        $feeTypes = [
            'monthly' => 'Monthly Fee',
            'registration' => 'Registration Fee',
            'admission' => 'Admission Fee',
            'books' => 'Books Charges',
            'slc_charges' => 'SLC Charges',
            'exam' => 'Exam Fee',
            're_exam' => 'Re-Exam',
            'custom' => 'Custom Charge',
        ];
        foreach ($feeTypes as $code => $name) {
            DB::connection($conn)->table('fee_types')->updateOrInsert(
                ['department_id' => null, 'code' => $code],
                ['name' => $name, 'updated_at' => $now, 'created_at' => $now]
            );
        }

        // 5. Dedicated QA Users (Independent fresh passwords, never copied from production)
        DB::connection($conn)->table('users')->updateOrInsert(
            ['email' => 'qa_admin@iqra.test'],
            [
                'name' => 'QA Super Administrator',
                'password' => Hash::make('IqraQa2026!SecurePortal'),
                'role' => 'super_admin',
                'campus_id' => $mainCampusId,
                'department_id' => null,
                'is_active' => true,
                'email_verified_at' => $now,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        DB::connection($conn)->table('users')->updateOrInsert(
            ['email' => 'qa_cashier@iqra.test'],
            [
                'name' => 'QA Cashier Officer',
                'password' => Hash::make('IqraCashier2026!'),
                'role' => 'cashier',
                'campus_id' => $mainCampusId,
                'department_id' => $schoolDeptId,
                'is_active' => true,
                'email_verified_at' => $now,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }
}
