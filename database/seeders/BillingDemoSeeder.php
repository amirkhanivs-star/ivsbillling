<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Explicit opt-in demo, never included in DatabaseSeeder or production imports. */
final class BillingDemoSeeder extends Seeder {
    public function run(): void {
        if (!app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Billing demo data is allowed only in local or testing environments.');
        }
        if (DB::table('students')->exists() || DB::table('invoices')->exists() || DB::table('payments')->exists()) {
            throw new RuntimeException('Billing demo requires an empty test ledger. It will not mix with existing student or financial records.');
        }
        $campus = DB::table('campuses')->where('code', 'main')->value('id');
        $monthly = DB::table('fee_types')->whereNull('department_id')->where('code', 'monthly')->value('id');
        $departments = DB::table('departments')->pluck('id', 'code');
        if (!$campus || !$monthly || !$departments->has('school') || !$departments->has('tuition') || !$departments->has('quran')) {
            throw new RuntimeException('Run php artisan db:seed first.');
        }

        DB::transaction(function () use ($campus, $monthly, $departments) {
            $now = now();
            $family = DB::table('families')->insertGetId([
                'family_code' => 'IQRA-DEMO-001', 'guardian_name' => 'Demo Guardian',
                'contact_verified' => false, 'messaging_opt_in' => false,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $students = [];
            foreach (['001' => 'Demo Student One', '002' => 'Demo Student Two'] as $suffix => $name) {
                $students[$suffix] = DB::table('students')->insertGetId([
                    'family_id' => $family, 'gr_number' => 'DEMO-GR-'.$suffix, 'name' => $name,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $plans = [
                ['001', 'school', 'Grade 5', 'Monthly School Fee', 30000, null, null],
                ['002', 'school', 'Grade 7', 'Monthly School Fee', 25000, null, null],
                ['001', 'tuition', 'Grade 5', 'Mathematics Tuition', 15000, 'Mathematics', null],
                ['002', 'quran', 'Quran Level 2', 'Quran (2 days/week)', 10000, null, 2],
            ];
            foreach ($plans as [$suffix, $code, $grade, $description, $minor, $subject, $days]) {
                $department = $departments[$code];
                $program = DB::table('programs')->where('department_id', $department)->where('name', $grade)->value('id');
                if (!$program) $program = DB::table('programs')->insertGetId([
                    'department_id' => $department, 'name' => $grade, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $enrollment = DB::table('enrollments')->insertGetId([
                    'student_id' => $students[$suffix], 'campus_id' => $campus,
                    'department_id' => $department, 'program_id' => $program, 'grade_label' => $grade,
                    'subject_name' => $subject, 'class_days_per_week' => $days, 'currency' => 'SAR',
                    'monthly_fee_minor' => $minor, 'status' => 'active',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('fee_plan_items')->insert([
                    'enrollment_id' => $enrollment, 'fee_type_id' => $monthly,
                    'description' => $description, 'frequency' => 'monthly', 'currency' => 'SAR',
                    'amount_minor' => $minor, 'subject_name' => $subject, 'class_days_per_week' => $days,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        });
        $this->command?->info('Created one demo family, two siblings and 4 fee plans (School/Tuition/Quran). No verified messaging contacts.');
    }
}
