<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // 1. Campuses
        DB::table('campuses')->updateOrInsert(
            ['code' => 'main'],
            ['name' => 'Main Campus', 'address' => 'IQRA Virtual Main Campus', 'updated_at' => $now, 'created_at' => $now]
        );
        $mainCampusId = DB::table('campuses')->where('code', 'main')->value('id');

        // 2. Departments
        foreach (['school' => 'School', 'quran' => 'Quran', 'tuition' => 'Tuition'] as $code => $name) {
            DB::table('departments')->updateOrInsert(
                ['code' => $code],
                ['name' => $name, 'updated_at' => $now, 'created_at' => $now]
            );
        }

        $schoolDeptId = DB::table('departments')->where('code', 'school')->value('id');
        $tuitionDeptId = DB::table('departments')->where('code', 'tuition')->value('id');

        // 3. Department Branding
        if ($schoolDeptId) {
            DB::table('department_branding')->updateOrInsert(
                ['department_id' => $schoolDeptId],
                [
                    'display_name' => 'IQRA Virtual School',
                    'payment_instructions' => 'IQRA Virtual School billing portal. Please deposit fees to authorized bank accounts.',
                    'footer_email' => 'accounts@iqra.edu.pk',
                    'footer_phone' => '+920000000000',
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        if ($tuitionDeptId) {
            DB::table('department_branding')->updateOrInsert(
                ['department_id' => $tuitionDeptId],
                [
                    'display_name' => 'IQRA Virtual Tuition',
                    'payment_instructions' => 'IQRA Virtual Tuition subject billing portal.',
                    'footer_email' => 'tuition@iqra.edu.pk',
                    'footer_phone' => '+920000000001',
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        // 4. Standard Fee Types
        $standardFeeTypes = [
            'monthly' => 'Monthly Fee',
            'registration' => 'Registration Fee',
            'admission' => 'Admission Fee',
            'books' => 'Books Charges',
            'slc_charges' => 'SLC Charges',
            'exam' => 'Exam Fee',
            're_exam' => 'Re-Exam',
            'custom' => 'Custom Charge',
        ];
        foreach ($standardFeeTypes as $code => $name) {
            DB::table('fee_types')->updateOrInsert(
                ['department_id' => null, 'code' => $code],
                ['name' => $name, 'updated_at' => $now, 'created_at' => $now]
            );
        }

        // 5. Clean Primary Super Administrator Account
        // Provision initial super admin without hardcoding secrets in source code.
        // If the administrator already exists, do NOT overwrite its existing password upon re-seeding!
        $existingAdmin = DB::table('users')->where('email', 'admin@iqra.edu.pk')->first();
        if ($existingAdmin) {
            DB::table('users')->where('id', $existingAdmin->id)->update([
                'name' => 'IQRA Super Administrator',
                'role' => 'super_admin',
                'campus_id' => $mainCampusId,
                'department_id' => null,
                'is_active' => true,
                'email_verified_at' => $existingAdmin->email_verified_at ?: $now,
                'updated_at' => $now,
            ]);
        } else {
            $initialPassword = env('INITIAL_ADMIN_PASSWORD') ?: (app()->environment('testing') ? 'IqraPortal2026!Master' : \Illuminate\Support\Str::random(32));
            DB::table('users')->insert([
                'name' => 'IQRA Super Administrator',
                'email' => 'admin@iqra.edu.pk',
                'password' => Hash::make($initialPassword),
                'role' => 'super_admin',
                'campus_id' => $mainCampusId,
                'department_id' => null,
                'is_active' => true,
                'email_verified_at' => $now,
                'updated_at' => $now,
                'created_at' => $now,
            ]);
        }
    }
}
