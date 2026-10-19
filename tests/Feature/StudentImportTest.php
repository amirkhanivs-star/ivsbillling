<?php

namespace Tests\Feature;

use App\Models\User;
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

    public function test_manage_student_shows_import_button_and_dialog(): void
    {
        $admin = $this->setupAdmin();

        $res = $this->actingAs($admin)->get('/manage-student');
        $res->assertStatus(200);
        $res->assertSee('Import');
        $res->assertSee('import-dialog');
        $res->assertSee('Run Preloaded Import');
        $res->assertSee('Upload & Import CSV', false);
    }

    public function test_csv_upload_imports_students_and_families_correctly(): void
    {
        $admin = $this->setupAdmin();

        $csvContent = <<<CSV
Students,,,,,,,,,,,,,,,,,,,,
Exported 2026-10-15,,,,,,,,,,,,,,,,,,,,
ID,GR No,Family Code,Student Name,Gender,Date of Birth,Religion,Contact (Father),Contact (Mother),Address,Date of Admission,Class,Class Admitted,Monthly Fee,Status,Guardian Name,RFID,Date of Leaving,Leaving Reason,Siblings,Siblings Class
1,99901,FAM-999,First Child,Male,01/01/2015,ISLAM,+966500000001,,Riyadh KSA,10/08/2023,5 - A,4,300,present,Tariq Khan,,,
2,99902,FAM-999,Second Child,Female,05/05/2018,ISLAM,+966500000001,,Riyadh KSA,10/08/2023,2 - A,1,250,present,Tariq Khan,,,
CSV;

        $file = UploadedFile::fake()->createWithContent('test_students.csv', $csvContent);

        $res = $this->actingAs($admin)->post('/manage-student/import', [
            'csv_file' => $file,
        ]);

        $res->assertRedirect('/manage-student');
        $res->assertSessionHas('notice');

        // Check students created
        $this->assertDatabaseHas('students', [
            'gr_number' => '99901',
            'name' => 'First Child',
            'gender' => 'Male',
            'status' => 'present',
        ]);
        $this->assertDatabaseHas('students', [
            'gr_number' => '99902',
            'name' => 'Second Child',
            'gender' => 'Female',
            'status' => 'present',
        ]);

        // Verify siblings share the SAME family_id
        $s1 = DB::table('students')->where('gr_number', '99901')->first();
        $s2 = DB::table('students')->where('gr_number', '99902')->first();
        $this->assertEquals($s1->family_id, $s2->family_id);

        // Verify family record
        $this->assertDatabaseHas('families', [
            'id' => $s1->family_id,
            'family_code' => 'FAM-999',
            'guardian_name' => 'Tariq Khan',
            'whatsapp' => '+966500000001',
        ]);

        // Verify enrollments and monthly fee plans
        $this->assertDatabaseHas('enrollments', [
            'student_id' => $s1->id,
            'grade_label' => '5 - A',
            'monthly_fee_minor' => 30000,
        ]);
        $this->assertDatabaseHas('enrollments', [
            'student_id' => $s2->id,
            'grade_label' => '2 - A',
            'monthly_fee_minor' => 25000,
        ]);

        $this->assertDatabaseHas('fee_plan_items', [
            'amount_minor' => 30000,
            'frequency' => 'monthly',
        ]);
        $this->assertDatabaseHas('fee_plan_items', [
            'amount_minor' => 25000,
            'frequency' => 'monthly',
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
