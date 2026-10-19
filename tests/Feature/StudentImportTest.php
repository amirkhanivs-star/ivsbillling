<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\StudentImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StudentImportTest extends TestCase
{
    use RefreshDatabase;

    private function setupAdmin(): User
    {
        $this->seed();
        $id = DB::table('users')->insertGetId([
            'name' => 'Admin User',
            'email' => 'admin_' . uniqid() . '@example.test',
            'password' => bcrypt('secret'),
            'role' => 'super_admin',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return User::find($id);
    }

    public function test_manage_student_shows_import_button_and_dialog_with_safety_lock(): void
    {
        $admin = $this->setupAdmin();

        $res = $this->actingAs($admin)->get('/manage-student');
        $res->assertStatus(200);
        $res->assertSee('Import');
        $res->assertSee('import-dialog');
        $res->assertSee('Direct student bulk import is disabled for Phase 1 Recovery Protection');
        $res->assertSee('Run Preloaded Import (Locked)');
        $res->assertSee('Upload & Import CSV (Locked)', false);
    }

    public function test_direct_bulk_student_import_is_rejected_and_normal_admission_remains_functional(): void
    {
        $admin = $this->setupAdmin();
        $deptId = DB::table('departments')->first()->id;
        $campusId = DB::table('campuses')->first()->id;

        $csvContent = <<<CSV
Students,,,,,,,,,,,,,,,,,,,,
Exported 2026-10-15,,,,,,,,,,,,,,,,,,,,
ID,GR No,Family Code,Student Name,Gender,Date of Birth,Religion,Contact (Father),Contact (Mother),Address,Date of Admission,Class,Class Admitted,Monthly Fee,Status,Guardian Name,RFID,Date of Leaving,Leaving Reason,Siblings,Siblings Class
1,99901,FAM-999,First Child,Male,01/01/2015,ISLAM,+966500000001,,Riyadh KSA,10/08/2023,5 - A,4,300,present,Tariq Khan,,,
CSV;

        $file = UploadedFile::fake()->createWithContent('test_students.csv', $csvContent);

        // 1. Direct CSV upload MUST be rejected
        $res = $this->actingAs($admin)->post('/manage-student/import', [
            'csv_file' => $file,
        ]);
        $res->assertRedirect('/manage-student');
        $res->assertSessionHasErrors(['error']);

        // Verify student from CSV was NOT inserted into production table
        $this->assertDatabaseMissing('students', [
            'gr_number' => '99901',
        ]);

        // 2. Preloaded direct import MUST be rejected
        $resPre = $this->actingAs($admin)->post('/manage-student/import-preloaded');
        $resPre->assertRedirect('/manage-student');
        $resPre->assertSessionHasErrors(['error']);

        // 3. Direct service call MUST throw RuntimeException
        $svc = new StudentImportService();
        $this->assertThrows(fn() => $svc->importFromFile('dummy.csv'), \RuntimeException::class);

        // 4. Verify NORMAL student admission remains 100% functional
        $resAdm = $this->actingAs($admin)->post('/manage-student/admission/new', [
            'name' => 'Fatima Noor',
            'father_name' => 'Noor Muhammad',
            'family_code' => 'FAM-8801',
            'family_email' => 'fatima.parent@example.test',
            'family_whatsapp' => '+923001234567',
            'guardian_name' => 'Noor Muhammad',
            'registration_number' => 'REG-8801',
            'campus_id' => $campusId,
            'department_id' => $deptId,
            'grade_label' => 'Grade 1',
            'class_admitted' => 'Grade 1',
            'section_admitted' => 'A',
            'session' => '2026-2027',
            'currency' => 'SAR',
            'monthly_fee' => '250.00',
        ]);

        $resAdm->assertRedirect('/manage-student');
        $this->assertDatabaseHas('students', [
            'name' => 'Fatima Noor',
        ]);
        $this->assertDatabaseHas('student_registration_details', [
            'registration_number' => 'REG-8801',
        ]);
        $this->assertDatabaseHas('enrollments', [
            'grade_label' => 'Grade 1',
            'currency' => 'SAR',
            'monthly_fee_minor' => 25000,
        ]);
    }

    public function test_manage_student_renders_smooth_pagination_and_preserves_running_status(): void
    {
        $admin = $this->setupAdmin();

        // Create 30 students to force pagination (perPage is 25)
        for ($i = 1; $i <= 30; $i++) {
            $famId = DB::table('families')->insertGetId([
                'family_code' => 'FAM-' . $i,
                'guardian_name' => 'Guardian ' . $i,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('students')->insert([
                'gr_number' => 'GR-' . (1000 + $i),
                'name' => 'Student ' . $i,
                'family_id' => $famId,
                'status' => 'present',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $res = $this->actingAs($admin)->get('/manage-student');
        $res->assertStatus(200);
        $res->assertSee('myskool-pagination');
        $res->assertSee('myskool-page-btn');
        $res->assertSee('Showing <strong>1</strong> to <strong>25</strong> of <strong>30</strong> entries', false);
        $res->assertSee('?page=2');
        $res->assertDontSee('w-5 h-5');
    }
}
