<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StudentAdmissionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role = 'super_admin'): User
    {
        $this->seed();
        return User::create(['name'=>'Test User', 'email'=>'test@example.test', 'password'=>'test-password', 'role'=>$role, 'is_active'=>true]);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'name'=>'Aisha Khan', 'father_name'=>'Khan', 'family_code'=>'FAM-100', 'family_email'=>'parent@example.test','family_whatsapp'=>'+923001234567',
            'guardian_name'=>'Khan', 'campus_id'=>DB::table('campuses')->value('id'),
            'department_id'=>DB::table('departments')->where('code','school')->value('id'),
            'grade_label'=>'Grade 5', 'currency'=>'SAR', 'monthly_fee'=>'100.00',
            'session'=>'2026-2027', 'registration_number'=>'REG-100', 'b_form'=>'12345', 'class_admitted'=>'Grade 5', 'section_admitted'=>'A',
        ], $overrides);
    }

    public function test_both_buttons_use_one_registration_page_and_save_student(): void
    {
        $this->actingAs($this->admin());
        $this->get('/manage-student')->assertOk()->assertSee('Registration')->assertSee('Admission');
        $this->get('/manage-student/admission/new')->assertOk()->assertSee('Student Information')->assertSee('Registration Information')->assertSee('Register Now');
        $this->post('/manage-student/admission/new', $this->payload())->assertRedirect('/manage-student');
        $this->assertSame(1,DB::table('families')->count());
        $this->assertSame(1,DB::table('students')->count());
        $this->assertSame('REG-100', DB::table('student_registration_details')->value('registration_number'));
        $this->assertSame(1,DB::table('enrollments')->count());
        $this->assertSame(1,DB::table('fee_plan_items')->count());
        $this->assertSame(0,DB::table('invoices')->count());
    }

    public function test_sibling_reuses_family_without_overwriting_guardian(): void
    {
        $this->actingAs($this->admin());
        $this->post('/manage-student/admission/new',$this->payload());
        $this->post('/manage-student/admission/new',$this->payload([
            'name'=>'Bilal Khan','registration_number'=>'REG-101','guardian_name'=>'', 'monthly_fee'=>'',
        ]))->assertRedirect('/manage-student');
        $this->assertSame(1,DB::table('families')->count());
        $this->assertSame(2,DB::table('students')->count());
        $this->assertSame('Khan',DB::table('families')->value('guardian_name'));
        $this->assertSame(1,DB::table('fee_plan_items')->count());
    }

    public function test_gr_number_collision_is_rejected(): void
    {
        $this->actingAs($this->admin());
        $this->post('/manage-student/admission/new',$this->payload());
        $this->from('/manage-student/admission/new')->post('/manage-student/admission/new',$this->payload())->assertRedirect('/manage-student/admission/new')->assertSessionHasErrors('registration_number');
        $this->assertSame(1,DB::table('students')->count());
    }

    public function test_blank_family_number_creates_unnumbered_family(): void
    {
        $this->actingAs($this->admin());
        $this->post('/manage-student/admission/new', $this->payload(['family_code'=>'']))
            ->assertRedirect('/manage-student');
        $this->assertNull(DB::table('families')->value('family_code'));
    }

    public function test_family_contacts_are_required(): void
    {
        $this->actingAs($this->admin());
        $this->post('/manage-student/admission/new', $this->payload(['family_email'=>'', 'family_whatsapp'=>'']))
            ->assertSessionHasErrors(['family_email','family_whatsapp']);
    }

    public function test_whatsapp_is_normalized_and_invalid_country_prefix_rejected(): void
    {
        $this->actingAs($this->admin());
        $this->post('/manage-student/admission/new', $this->payload(['family_whatsapp'=>'00 92 300-1234567']))
            ->assertRedirect('/manage-student');
        $this->assertSame('+923001234567', DB::table('families')->value('whatsapp'));
        $this->post('/manage-student/admission/new', $this->payload(['registration_number'=>'REG-101', 'family_code'=>'FAM-101', 'family_whatsapp'=>'03001234567']))
            ->assertSessionHasErrors('family_whatsapp');
    }

    public function test_family_contacts_saved_and_gr_matches_registration_number(): void
    {
        $this->actingAs($this->admin());
        $this->post('/manage-student/admission/new', $this->payload())->assertRedirect('/manage-student');
        $this->assertSame('REG-100', DB::table('students')->value('gr_number'));
        $this->assertSame('parent@example.test', DB::table('families')->value('email'));
        $this->assertSame('+923001234567', DB::table('families')->value('whatsapp'));
        $this->assertSame(0, DB::table('families')->value('contact_verified'));
        $this->assertSame(0, DB::table('families')->value('messaging_opt_in'));
    }

    public function test_registration_duplicate_lookup(): void
    {
        $this->actingAs($this->admin());
        $this->post('/manage-student/admission/new', $this->payload());
        $this->getJson('/manage-student/admission/check-number?number=REG-100')->assertOk()->assertJson(['exists'=>true]);
        $this->getJson('/manage-student/admission/check-number?number=REG-101')->assertOk()->assertJson(['exists'=>false]);
    }

    public function test_registration_number_is_required_and_unique(): void
    {
        $this->actingAs($this->admin());
        $this->post('/manage-student/admission/new', $this->payload(['registration_number'=>'']))
            ->assertSessionHasErrors('registration_number');
        $this->post('/manage-student/admission/new', $this->payload())->assertRedirect('/manage-student');
        $this->post('/manage-student/admission/new', $this->payload([
            'family_code'=>'FAM-101',
        ]))->assertSessionHasErrors('registration_number');
        $this->assertSame(1, DB::table('students')->count());
    }

    public function test_cashier_cannot_create_student(): void
    {
        $this->actingAs($this->admin('cashier'));
        $this->get('/manage-student/admission/new')->assertForbidden();
        $this->post('/manage-student/admission/new',$this->payload())->assertForbidden();
        $this->assertSame(0,DB::table('students')->count());
    }

    public function test_family_number_can_be_assigned_at_any_point_and_merges_invoices(): void
    {
        $this->actingAs($this->admin());

        // Create student 1 with family FAM-101
        $this->post('/manage-student/admission/new', $this->payload([
            'name' => 'First Child',
            'registration_number' => 'REG-101',
            'family_code' => 'FAM-101',
            'family_email' => 'fam101@example.test',
            'family_whatsapp' => '+923001111111',
        ]))->assertRedirect('/manage-student');

        $family1 = DB::table('families')->where('family_code', 'FAM-101')->first();
        $student1 = DB::table('students')->where('gr_number', 'REG-101')->first();

        // Create student 2 with NO family number (unnumbered family)
        $this->post('/manage-student/admission/new', $this->payload([
            'name' => 'Second Child',
            'registration_number' => 'REG-202',
            'family_code' => '',
            'family_email' => 'child2@example.test',
            'family_whatsapp' => '+923002222222',
        ]))->assertRedirect('/manage-student');

        $student2 = DB::table('students')->where('gr_number', 'REG-202')->first();
        $oldFamily2 = DB::table('families')->where('id', $student2->family_id)->first();
        $this->assertNull($oldFamily2->family_code);

        // Generate billing run and invoices for both families
        $enrollment1 = DB::table('enrollments')->where('student_id', $student1->id)->first();
        $enrollment2 = DB::table('enrollments')->where('student_id', $student2->id)->first();

        $runId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $enrollment1->campus_id,
            'department_id' => $enrollment1->department_id,
            'frequency' => 'monthly',
            'period_key' => '2026-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-25',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $inv1Id = DB::table('invoices')->insertGetId([
            'billing_run_id' => $runId,
            'family_id' => $family1->id,
            'campus_id' => $enrollment1->campus_id,
            'department_id' => $enrollment1->department_id,
            'currency' => 'SAR',
            'invoice_number' => 'SCH-2026-0000001',
            'status' => 'approved',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-25',
            'total_minor' => 10000,
            'paid_minor' => 0,
            'previous_dues_snapshot_minor' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('invoice_items')->insert([
            'invoice_id' => $inv1Id,
            'student_id' => $student1->id,
            'fee_type_id' => 1,
            'description' => 'Tuition Fee - October 2026',
            'period_key' => '2026-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'currency' => 'SAR',
            'amount_minor' => 10000,
            'discount_minor' => 0,
            'net_minor' => 10000,
            'paid_minor' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $inv2Id = DB::table('invoices')->insertGetId([
            'billing_run_id' => $runId,
            'family_id' => $oldFamily2->id,
            'campus_id' => $enrollment2->campus_id,
            'department_id' => $enrollment2->department_id,
            'currency' => 'SAR',
            'invoice_number' => 'SCH-2026-0000002',
            'status' => 'approved',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-25',
            'total_minor' => 15000,
            'paid_minor' => 0,
            'previous_dues_snapshot_minor' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('invoice_items')->insert([
            'invoice_id' => $inv2Id,
            'student_id' => $student2->id,
            'fee_type_id' => 1,
            'description' => 'Tuition Fee - October 2026',
            'period_key' => '2026-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'currency' => 'SAR',
            'amount_minor' => 15000,
            'discount_minor' => 0,
            'net_minor' => 15000,
            'paid_minor' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Now user adds Family Number 'FAM-101' to Student 2 via edit
        $updatePayload = [
            'name' => 'Second Child',
            'father_name' => 'Father Khan',
            'registration_number' => 'REG-202',
            'family_code' => 'FAM-101',
            'family_email' => 'fam101@example.test',
            'family_whatsapp' => '+923001111111',
            'grade_label' => 'Grade 5',
        ];

        $response = $this->put('/manage-student/' . $student2->id, $updatePayload);
        $response->assertRedirect('/manage-student/' . $student2->id);
        $response->assertSessionHasNoErrors();

        // Check that student 2 now belongs to Family 1 (FAM-101)
        $this->assertSame($family1->id, (int) DB::table('students')->where('id', $student2->id)->value('family_id'));

        // Check that old unnumbered family was cleaned up since it had no other students
        $this->assertNull(DB::table('families')->where('id', $oldFamily2->id)->first());

        // Invoices for the same billing run should have merged into inv1Id without duplicate key violation
        $this->assertNull(DB::table('invoices')->where('id', $inv2Id)->first());
        $mergedInv = DB::table('invoices')->where('id', $inv1Id)->first();
        $this->assertNotNull($mergedInv);
        $this->assertSame(25000, (int) $mergedInv->total_minor);

        // Check that invoice_items for student 2 are now linked to inv1Id
        $this->assertSame($inv1Id, (int) DB::table('invoice_items')->where('student_id', $student2->id)->value('invoice_id'));
    }

    public function test_family_number_change_merges_invoices_when_both_invoices_have_existing_notifications_without_collision(): void
    {
        $this->actingAs($this->admin());

        // Create student 1 with family FAM-301
        $this->post('/manage-student/admission/new', $this->payload([
            'name' => 'First Child',
            'registration_number' => 'REG-301',
            'family_code' => 'FAM-301',
            'family_email' => 'fam301@example.test',
            'family_whatsapp' => '+923003111111',
        ]))->assertRedirect('/manage-student');

        $family1 = DB::table('families')->where('family_code', 'FAM-301')->first();
        $student1 = DB::table('students')->where('gr_number', 'REG-301')->first();

        // Create student 2 with unnumbered family
        $this->post('/manage-student/admission/new', $this->payload([
            'name' => 'Second Child',
            'registration_number' => 'REG-302',
            'family_code' => '',
            'family_email' => 'fam302@example.test',
            'family_whatsapp' => '+923003222222',
        ]))->assertRedirect('/manage-student');

        $student2 = DB::table('students')->where('gr_number', 'REG-302')->first();
        $oldFamily2 = DB::table('families')->where('id', $student2->family_id)->first();

        // Create billing run and invoices for both families
        $enrollment1 = DB::table('enrollments')->where('student_id', $student1->id)->first();
        $enrollment2 = DB::table('enrollments')->where('student_id', $student2->id)->first();

        $runId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $enrollment1->campus_id,
            'department_id' => $enrollment1->department_id,
            'frequency' => 'monthly',
            'period_key' => '2026-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-25',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $inv1Id = DB::table('invoices')->insertGetId([
            'billing_run_id' => $runId,
            'family_id' => $family1->id,
            'campus_id' => $enrollment1->campus_id,
            'department_id' => $enrollment1->department_id,
            'currency' => 'SAR',
            'invoice_number' => 'SCH-2026-0000301',
            'status' => 'approved',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-25',
            'total_minor' => 10000,
            'paid_minor' => 0,
            'previous_dues_snapshot_minor' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('invoice_items')->insert([
            'invoice_id' => $inv1Id,
            'student_id' => $student1->id,
            'fee_type_id' => 1,
            'description' => 'Tuition Fee - October 2026',
            'period_key' => '2026-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'currency' => 'SAR',
            'amount_minor' => 10000,
            'discount_minor' => 0,
            'net_minor' => 10000,
            'paid_minor' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $inv2Id = DB::table('invoices')->insertGetId([
            'billing_run_id' => $runId,
            'family_id' => $oldFamily2->id,
            'campus_id' => $enrollment2->campus_id,
            'department_id' => $enrollment2->department_id,
            'currency' => 'SAR',
            'invoice_number' => 'SCH-2026-0000302',
            'status' => 'approved',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-25',
            'total_minor' => 15000,
            'paid_minor' => 0,
            'previous_dues_snapshot_minor' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('invoice_items')->insert([
            'invoice_id' => $inv2Id,
            'student_id' => $student2->id,
            'fee_type_id' => 1,
            'description' => 'Tuition Fee - October 2026',
            'period_key' => '2026-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'currency' => 'SAR',
            'amount_minor' => 15000,
            'discount_minor' => 0,
            'net_minor' => 15000,
            'paid_minor' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Insert conflicting notification on Inv 1 and Inv 2 (same channel='whatsapp', kind='monthly')
        $notif1Id = DB::table('notifications')->insertGetId([
            'invoice_id' => $inv1Id,
            'channel' => 'whatsapp',
            'kind' => 'monthly',
            'status' => 'submitted',
            'batch_id' => 'BATCH-TEST-301',
            'is_superseded' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $notif2Id = DB::table('notifications')->insertGetId([
            'invoice_id' => $inv2Id,
            'channel' => 'whatsapp',
            'kind' => 'monthly',
            'status' => 'submitted',
            'batch_id' => 'BATCH-TEST-302',
            'is_superseded' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Also insert non-conflicting notification on Inv 2 (channel='whatsapp', kind='paid')
        $notif3Id = DB::table('notifications')->insertGetId([
            'invoice_id' => $inv2Id,
            'channel' => 'whatsapp',
            'kind' => 'paid',
            'status' => 'delivered',
            'batch_id' => 'BATCH-TEST-303',
            'is_superseded' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Reassign student 2 to family 1
        $updatePayload = [
            'name' => 'Second Child',
            'father_name' => 'Father Khan',
            'registration_number' => 'REG-302',
            'family_code' => 'FAM-301',
            'family_email' => 'fam301@example.test',
            'family_whatsapp' => '+923003111111',
            'grade_label' => 'Grade 5',
        ];

        $response = $this->put('/manage-student/' . $student2->id, $updatePayload);
        $response->assertRedirect('/manage-student/' . $student2->id);
        $response->assertSessionHasNoErrors();

        // Check that student 2 now belongs to Family 1
        $this->assertSame($family1->id, (int) DB::table('students')->where('id', $student2->id)->value('family_id'));

        // Check old unnumbered family is cleaned up
        $this->assertNull(DB::table('families')->where('id', $oldFamily2->id)->first());

        // Invoices merged into inv1Id and inv2Id is cleanly deleted
        $this->assertNull(DB::table('invoices')->where('id', $inv2Id)->first());
        $mergedInv = DB::table('invoices')->where('id', $inv1Id)->first();
        $this->assertNotNull($mergedInv);
        $this->assertSame(25000, (int) $mergedInv->total_minor);

        // All 3 notifications are preserved (notification history never deleted)
        $this->assertSame(3, DB::table('notifications')->count());

        // Original notification for Inv 1 remains active
        $n1 = DB::table('notifications')->where('id', $notif1Id)->first();
        $this->assertSame($inv1Id, (int) $n1->invoice_id);
        $this->assertSame('monthly', $n1->kind);
        $this->assertFalse((bool) $n1->is_superseded);

        // Conflicting notification from Inv 2 is marked superseded and re-keyed to avoid unique constraint collision
        $n2 = DB::table('notifications')->where('id', $notif2Id)->first();
        $this->assertSame($inv1Id, (int) $n2->invoice_id);
        $this->assertSame('monthly_sup', $n2->kind);
        $this->assertTrue((bool) $n2->is_superseded);
        $this->assertSame($inv1Id, (int) $n2->superseded_by_invoice_id);

        // Non-conflicting notification from Inv 2 is transferred cleanly
        $n3 = DB::table('notifications')->where('id', $notif3Id)->first();
        $this->assertSame($inv1Id, (int) $n3->invoice_id);
        $this->assertSame('paid', $n3->kind);
        $this->assertFalse((bool) $n3->is_superseded);

        // Test repeated merges: create a third child in an unnumbered family with an invoice and conflicting notification
        $this->post('/manage-student/admission/new', $this->payload([
            'name' => 'Third Child',
            'registration_number' => 'REG-303',
            'family_code' => '',
            'family_email' => 'fam303@example.test',
            'family_whatsapp' => '+923003333333',
        ]))->assertRedirect('/manage-student');

        $student3 = DB::table('students')->where('gr_number', 'REG-303')->first();
        $enrollment3 = DB::table('enrollments')->where('student_id', $student3->id)->first();
        $inv3Id = DB::table('invoices')->insertGetId([
            'billing_run_id' => $runId,
            'family_id' => $student3->family_id,
            'campus_id' => $enrollment3->campus_id,
            'department_id' => $enrollment3->department_id,
            'currency' => 'SAR',
            'invoice_number' => 'SCH-2026-0000303',
            'status' => 'approved',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-25',
            'total_minor' => 12000,
            'paid_minor' => 0,
            'previous_dues_snapshot_minor' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('invoice_items')->insert([
            'invoice_id' => $inv3Id,
            'student_id' => $student3->id,
            'fee_type_id' => 1,
            'description' => 'Tuition Fee - October 2026',
            'period_key' => '2026-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'currency' => 'SAR',
            'amount_minor' => 12000,
            'discount_minor' => 0,
            'net_minor' => 12000,
            'paid_minor' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $notif4Id = DB::table('notifications')->insertGetId([
            'invoice_id' => $inv3Id,
            'channel' => 'whatsapp',
            'kind' => 'monthly',
            'status' => 'submitted',
            'batch_id' => 'BATCH-TEST-304',
            'is_superseded' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Reassign child 3 into family 1 as well (repeated merge into same target invoice)
        $response3 = $this->put('/manage-student/' . $student3->id, [
            'name' => 'Third Child',
            'father_name' => 'Father Khan',
            'registration_number' => 'REG-303',
            'family_code' => 'FAM-301',
            'family_email' => 'fam301@example.test',
            'family_whatsapp' => '+923003111111',
            'grade_label' => 'Grade 5',
        ]);
        $response3->assertRedirect('/manage-student/' . $student3->id);
        $response3->assertSessionHasNoErrors();

        // Inv 3 is merged and deleted
        $this->assertNull(DB::table('invoices')->where('id', $inv3Id)->first());

        // All 4 notifications exist
        $this->assertSame(4, DB::table('notifications')->count());

        // Second superseded notification gets 'monthly_s2' without unique key error
        $n4 = DB::table('notifications')->where('id', $notif4Id)->first();
        $this->assertSame($inv1Id, (int) $n4->invoice_id);
        $this->assertSame('monthly_s2', $n4->kind);
        $this->assertTrue((bool) $n4->is_superseded);
        $this->assertSame($inv1Id, (int) $n4->superseded_by_invoice_id);
    }

    public function test_student_profile_only_shows_unpaid_invoices_above_and_paid_history_below(): void
    {
        $this->actingAs($this->admin());

        $this->post('/manage-student/admission/new', $this->payload([
            'name' => 'Profile Test Student',
            'registration_number' => 'REG-999',
            'family_code' => 'FAM-999',
        ]))->assertRedirect('/manage-student');

        $student = DB::table('students')->where('gr_number', 'REG-999')->first();
        $enrollment = DB::table('enrollments')->where('student_id', $student->id)->first();

        $runId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $enrollment->campus_id,
            'department_id' => $enrollment->department_id,
            'frequency' => 'monthly',
            'period_key' => '2026-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-25',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invId = DB::table('invoices')->insertGetId([
            'billing_run_id' => $runId,
            'family_id' => $student->family_id,
            'campus_id' => $enrollment->campus_id,
            'department_id' => $enrollment->department_id,
            'currency' => 'SAR',
            'invoice_number' => 'SCH-2026-0000099',
            'status' => 'approved',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-25',
            'total_minor' => 20000,
            'paid_minor' => 17000,
            'previous_dues_snapshot_minor' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Item 1: Partially unpaid (Oct 2026) -> balance 30.00
        $item1Id = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invId,
            'student_id' => $student->id,
            'fee_type_id' => 1,
            'description' => 'Tuition Fee - October 2026',
            'period_key' => '2026-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'currency' => 'SAR',
            'amount_minor' => 10000,
            'discount_minor' => 0,
            'net_minor' => 10000,
            'paid_minor' => 7000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Item 2: Fully paid (Nov 2026) -> balance 0
        $item2Id = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invId,
            'student_id' => $student->id,
            'fee_type_id' => 1,
            'description' => 'Tuition Fee - November 2026',
            'period_key' => '2026-11',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'currency' => 'SAR',
            'amount_minor' => 10000,
            'discount_minor' => 0,
            'net_minor' => 10000,
            'paid_minor' => 10000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Payment for item 2
        $paymentId = DB::table('payments')->insertGetId([
            'family_id' => $student->family_id,
            'campus_id' => $enrollment->campus_id,
            'department_id' => $enrollment->department_id,
            'currency' => 'SAR',
            'receipt_number' => 'RCP-TEST-999',
            'amount_minor' => 10000,
            'unallocated_minor' => 0,
            'received_on' => '2026-10-17',
            'idempotency_key' => 'TEST-PAY-999',
            'request_hash' => 'hash999',
            'received_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('payment_allocations')->insert([
            'payment_id' => $paymentId,
            'invoice_item_id' => $item2Id,
            'amount_minor' => 10000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $res = $this->get('/manage-student/' . $student->id);
        $res->assertOk();

        // Must see unpaid item
        $res->assertSee('Oct-2026');
        $res->assertSee('30.00');

        // Must NOT see paid item in dues table
        $res->assertDontSee('Nov-2026');

        // Must see payment in payment history
        $res->assertSee('RCP-TEST-999');
        $res->assertSee('100.00');
    }

    public function test_exact_production_combination_source_submitted_dest_queued_with_payment_allocations(): void
    {
        $this->actingAs($this->admin());

        // 1. Destination family and student
        $this->post('/manage-student/admission/new', $this->payload([
            'name' => 'Destination Child',
            'registration_number' => 'REG-DEST-01',
            'family_code' => 'FAM-PROD-DEST',
            'family_email' => 'dest@example.test',
            'family_whatsapp' => '+923005555551',
        ]))->assertRedirect('/manage-student');

        $destFamily = DB::table('families')->where('family_code', 'FAM-PROD-DEST')->first();
        $destStudent = DB::table('students')->where('gr_number', 'REG-DEST-01')->first();
        $destEnroll = DB::table('enrollments')->where('student_id', $destStudent->id)->first();

        // 2. Source family and student (isolated unnumbered family)
        $this->post('/manage-student/admission/new', $this->payload([
            'name' => 'Source Child',
            'registration_number' => 'REG-SRC-01',
            'family_code' => '',
            'family_email' => 'src@example.test',
            'family_whatsapp' => '+923005555552',
        ]))->assertRedirect('/manage-student');

        $srcStudent = DB::table('students')->where('gr_number', 'REG-SRC-01')->first();
        $srcOldFamily = DB::table('families')->where('id', $srcStudent->family_id)->first();
        $srcEnroll = DB::table('enrollments')->where('student_id', $srcStudent->id)->first();

        // 3. Common billing run
        $runId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $destEnroll->campus_id,
            'department_id' => $destEnroll->department_id,
            'frequency' => 'monthly',
            'period_key' => '2026-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-25',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Destination Invoice (Dest Inv)
        $destInvId = DB::table('invoices')->insertGetId([
            'billing_run_id' => $runId,
            'family_id' => $destFamily->id,
            'campus_id' => $destEnroll->campus_id,
            'department_id' => $destEnroll->department_id,
            'currency' => 'SAR',
            'invoice_number' => 'SCH-2026-DEST01',
            'status' => 'approved',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-25',
            'total_minor' => 10000,
            'paid_minor' => 0,
            'previous_dues_snapshot_minor' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $destItemId = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $destInvId,
            'student_id' => $destStudent->id,
            'fee_type_id' => 1,
            'description' => 'Tuition Fee - October 2026',
            'period_key' => '2026-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'currency' => 'SAR',
            'amount_minor' => 10000,
            'discount_minor' => 0,
            'net_minor' => 10000,
            'paid_minor' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Source Invoice (Source Inv)
        $srcInvId = DB::table('invoices')->insertGetId([
            'billing_run_id' => $runId,
            'family_id' => $srcOldFamily->id,
            'campus_id' => $srcEnroll->campus_id,
            'department_id' => $srcEnroll->department_id,
            'currency' => 'SAR',
            'invoice_number' => 'SCH-2026-SRC01',
            'status' => 'approved',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-25',
            'total_minor' => 15000,
            'paid_minor' => 5000,
            'previous_dues_snapshot_minor' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $srcItemId = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $srcInvId,
            'student_id' => $srcStudent->id,
            'fee_type_id' => 1,
            'description' => 'Tuition Fee - October 2026',
            'period_key' => '2026-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'currency' => 'SAR',
            'amount_minor' => 15000,
            'discount_minor' => 0,
            'net_minor' => 15000,
            'paid_minor' => 5000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Payment and allocation on Source Item
        $paymentId = DB::table('payments')->insertGetId([
            'family_id' => $srcOldFamily->id,
            'campus_id' => $srcEnroll->campus_id,
            'department_id' => $srcEnroll->department_id,
            'currency' => 'SAR',
            'receipt_number' => 'RCP-PROD-SRC01',
            'amount_minor' => 5000,
            'unallocated_minor' => 0,
            'received_on' => '2026-10-18',
            'idempotency_key' => 'KEY-PROD-SRC01',
            'request_hash' => 'hash-prod-src',
            'received_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('payment_allocations')->insert([
            'payment_id' => $paymentId,
            'invoice_item_id' => $srcItemId,
            'amount_minor' => 5000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Exact production combination:
        // Destination notification = 'queued'
        // Source notification = 'submitted'
        // Same channel='whatsapp' and kind='monthly'
        $destNotifId = DB::table('notifications')->insertGetId([
            'invoice_id' => $destInvId,
            'channel' => 'whatsapp',
            'kind' => 'monthly',
            'status' => 'queued',
            'batch_id' => 'BATCH-DEST-QUEUED',
            'is_superseded' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $srcNotifId = DB::table('notifications')->insertGetId([
            'invoice_id' => $srcInvId,
            'channel' => 'whatsapp',
            'kind' => 'monthly',
            'status' => 'submitted',
            'batch_id' => 'BATCH-SRC-SUBMITTED',
            'submitted_at' => now()->subDay(),
            'is_superseded' => false,
            'payload' => json_encode(['original' => 'payload-data']),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        // Perform family reassignment: source student moves to destination family
        $response = $this->put('/manage-student/' . $srcStudent->id, [
            'name' => 'Source Child',
            'father_name' => 'Father Khan',
            'registration_number' => 'REG-SRC-01',
            'family_code' => 'FAM-PROD-DEST',
            'family_email' => 'dest@example.test',
            'family_whatsapp' => '+923005555551',
            'grade_label' => 'Grade 5',
        ]);
        $response->assertRedirect('/manage-student/' . $srcStudent->id);
        $response->assertSessionHasNoErrors();

        // 1. Verify old invoice deleted and old family cleaned up
        $this->assertNull(DB::table('invoices')->where('id', $srcInvId)->first());
        $this->assertNull(DB::table('families')->where('id', $srcOldFamily->id)->first());

        // 2. Verify destination invoice merged items and refreshed totals
        $mergedInv = DB::table('invoices')->where('id', $destInvId)->first();
        $this->assertSame(25000, (int) $mergedInv->total_minor);
        $this->assertSame(5000, (int) $mergedInv->paid_minor);

        // 3. Verify payment allocation remains intact referencing the invoice item
        $alloc = DB::table('payment_allocations')->where('payment_id', $paymentId)->first();
        $this->assertNotNull($alloc);
        $this->assertSame($srcItemId, (int) $alloc->invoice_item_id);
        $this->assertSame(5000, (int) $alloc->amount_minor);

        // 4. Verify payment re-parented to destination family
        $payment = DB::table('payments')->where('id', $paymentId)->first();
        $this->assertSame($destFamily->id, (int) $payment->family_id);

        // 5. Verify Destination notification remains active and queued
        $destNotif = DB::table('notifications')->where('id', $destNotifId)->first();
        $this->assertSame($destInvId, (int) $destNotif->invoice_id);
        $this->assertSame('monthly', $destNotif->kind);
        $this->assertSame('queued', $destNotif->status);
        $this->assertFalse((bool) $destNotif->is_superseded);

        // 6. Verify Source notification: marked superseded, points to destination invoice, kind re-keyed
        $srcNotif = DB::table('notifications')->where('id', $srcNotifId)->first();
        $this->assertSame($destInvId, (int) $srcNotif->invoice_id);
        $this->assertSame('monthly_sup', $srcNotif->kind);
        $this->assertSame('submitted', $srcNotif->status); // Historical status preserved
        $this->assertTrue((bool) $srcNotif->is_superseded);
        $this->assertSame($destInvId, (int) $srcNotif->superseded_by_invoice_id);

        // 7. Verify historical traceability preserved in payload
        $srcPayload = json_decode($srcNotif->payload, true);
        $this->assertArrayHasKey('_merge_source_invoice', $srcPayload);
        $this->assertSame($srcInvId, $srcPayload['_merge_source_invoice']['invoice_id']);
        $this->assertSame('SCH-2026-SRC01', $srcPayload['_merge_source_invoice']['invoice_number']);
        $this->assertSame('monthly', $srcPayload['_merge_source_invoice']['original_kind']);

        // 8. Verify audit log entry exists
        $auditLog = DB::table('audit_logs')
            ->where('action', 'notification.superseded_on_family_merge')
            ->where('subject_id', $srcNotifId)
            ->first();
        $this->assertNotNull($auditLog);
        $meta = json_decode($auditLog->metadata, true);
        $this->assertSame($srcInvId, $meta['source_invoice_id']);
        $this->assertSame('SCH-2026-SRC01', $meta['source_invoice_number']);
        $this->assertSame($destInvId, $meta['target_invoice_id']);

        // 9. Verify DispatchInvoiceToN8n cannot dispatch superseded notification
        // For an already submitted notification, it immediately aborts without re-dispatching
        \App\Jobs\DispatchInvoiceToN8n::dispatchSync($srcNotifId);
        $recheckSrcNotif = DB::table('notifications')->where('id', $srcNotifId)->first();
        $this->assertSame('submitted', $recheckSrcNotif->status);

        // For a queued superseded notification, it marks status as 'superseded' and aborts
        $queuedSupId = DB::table('notifications')->insertGetId([
            'invoice_id' => $destInvId,
            'channel' => 'whatsapp',
            'kind' => 'monthly_queued_sup',
            'status' => 'queued',
            'is_superseded' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \App\Jobs\DispatchInvoiceToN8n::dispatchSync($queuedSupId);
        $this->assertSame('superseded', DB::table('notifications')->where('id', $queuedSupId)->value('status'));

        // 10. Verify DispatchBatchToN8n also preserves submitted status of superseded notification
        $batchId = 'BATCH-TEST-SAFEGUARD-1';
        DB::table('notification_batches')->insert([
            'batch_id' => $batchId,
            'webhook_type' => 'monthly',
            'status' => 'queued',
            'total_recipients' => 1,
            'total_invoices' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('notifications')->where('id', $srcNotifId)->update(['batch_id' => $batchId]);
        \App\Jobs\DispatchBatchToN8n::dispatchSync($batchId, [$srcNotifId]);
        $this->assertSame('submitted', DB::table('notifications')->where('id', $srcNotifId)->value('status'));

        // 11. Verify Safeguard 2: Queued destination notification payload is invalidated and rebuilds with consolidated total
        $destNotifFresh = DB::table('notifications')->where('id', $destNotifId)->first();
        $this->assertNull($destNotifFresh->payload); // Pre-merge cached payload invalidated

        $builtPayload = app(\App\Services\InvoicePayloadBuilder::class)->build(
            $destNotifFresh,
            DB::table('invoices')->where('id', $destInvId)->first(),
            'invoice.monthly'
        );
        // Consolidated payload must include both students
        $studentIdsInPayload = array_column($builtPayload['students'] ?? [], 'student_id');
        $this->assertContains($destStudent->id, $studentIdsInPayload);
        $this->assertContains($srcStudent->id, $studentIdsInPayload);
        $this->assertSame('200.00', $builtPayload['invoice']['total_amount']);
        $this->assertSame('200.00', $builtPayload['invoice']['remaining_amount']);
    }

    public function test_department_manager_cannot_reassign_student_to_existing_family(): void
    {
        $this->actingAs($this->admin());
        $campusId = DB::table('campuses')->value('id');
        $deptId = DB::table('departments')->where('code', 'school')->value('id');

        // Create student 1 in FAM-101
        $this->post('/manage-student/admission/new', $this->payload([
            'name' => 'Student One',
            'registration_number' => 'REG-DM-101',
            'family_code' => 'FAM-DM-101',
            'family_email' => 'fam101@example.test',
            'family_whatsapp' => '+923001111111',
            'campus_id' => $campusId,
            'department_id' => $deptId,
        ]))->assertRedirect('/manage-student');

        // Create student 2 in FAM-202
        $this->post('/manage-student/admission/new', $this->payload([
            'name' => 'Student Two',
            'registration_number' => 'REG-DM-202',
            'family_code' => 'FAM-DM-202',
            'family_email' => 'fam202@example.test',
            'family_whatsapp' => '+923002222222',
            'campus_id' => $campusId,
            'department_id' => $deptId,
        ]))->assertRedirect('/manage-student');

        $student2 = DB::table('students')->where('gr_number', 'REG-DM-202')->first();
        $originalFamilyId2 = $student2->family_id;

        // Create Department Manager scoped to this campus and department
        $manager = User::create([
            'name' => 'Scoped Manager',
            'email' => 'scoped_mgr@example.test',
            'password' => 'test-password',
            'role' => 'department_manager',
            'campus_id' => $campusId,
            'department_id' => $deptId,
            'is_active' => true,
        ]);

        $this->actingAs($manager);

        // Attempt unauthorized reassignment of Student 2 to existing Family FAM-DM-101
        $response = $this->put('/manage-student/' . $student2->id, [
            'name' => 'Student Two Updated',
            'father_name' => 'Khan',
            'registration_number' => 'REG-DM-202',
            'family_code' => 'FAM-DM-101',
            'family_email' => 'fam101@example.test',
            'family_whatsapp' => '+923001111111',
            'grade_label' => 'Grade 5',
        ]);

        $response->assertForbidden();

        // Verify family was NOT changed
        $this->assertSame($originalFamilyId2, (int) DB::table('students')->where('id', $student2->id)->value('family_id'));
    }

    public function test_department_manager_can_update_student_profile_without_family_reassignment(): void
    {
        $this->actingAs($this->admin());
        $campusId = DB::table('campuses')->value('id');
        $deptId = DB::table('departments')->where('code', 'school')->value('id');

        $this->post('/manage-student/admission/new', $this->payload([
            'name' => 'Regular Student',
            'registration_number' => 'REG-MGR-303',
            'family_code' => 'FAM-MGR-303',
            'family_email' => 'mgr_family@example.test',
            'family_whatsapp' => '+923003333333',
            'campus_id' => $campusId,
            'department_id' => $deptId,
        ]))->assertRedirect('/manage-student');

        $student = DB::table('students')->where('gr_number', 'REG-MGR-303')->first();

        $manager = User::create([
            'name' => 'Dept Manager 2',
            'email' => 'dept_mgr_2@example.test',
            'password' => 'test-password',
            'role' => 'department_manager',
            'campus_id' => $campusId,
            'department_id' => $deptId,
            'is_active' => true,
        ]);

        $this->actingAs($manager);

        // Normal profile edit with same family code
        $response = $this->put('/manage-student/' . $student->id, [
            'name' => 'Regular Student Modified',
            'father_name' => 'Khan Modified',
            'registration_number' => 'REG-MGR-303',
            'family_code' => 'FAM-MGR-303',
            'family_email' => 'mgr_family_updated@example.test',
            'family_whatsapp' => '+923003333333',
            'grade_label' => 'Grade 5',
        ]);

        $response->assertRedirect('/manage-student/' . $student->id);
        $this->assertSame('Regular Student Modified', DB::table('students')->where('id', $student->id)->value('name'));
        $this->assertSame('mgr_family_updated@example.test', DB::table('families')->where('id', $student->family_id)->value('email'));
    }

    public function test_finance_admin_can_reassign_student_to_existing_family(): void
    {
        $this->actingAs($this->admin());
        $campusId = DB::table('campuses')->value('id');
        $deptId = DB::table('departments')->where('code', 'school')->value('id');

        $this->post('/manage-student/admission/new', $this->payload([
            'name' => 'Child A',
            'registration_number' => 'REG-FA-1',
            'family_code' => 'FAM-FA-1',
            'family_email' => 'fa1@example.test',
            'family_whatsapp' => '+923004444444',
            'campus_id' => $campusId,
            'department_id' => $deptId,
        ]))->assertRedirect('/manage-student');

        $this->post('/manage-student/admission/new', $this->payload([
            'name' => 'Child B',
            'registration_number' => 'REG-FA-2',
            'family_code' => 'FAM-FA-2',
            'family_email' => 'fa2@example.test',
            'family_whatsapp' => '+923005555555',
            'campus_id' => $campusId,
            'department_id' => $deptId,
        ]))->assertRedirect('/manage-student');

        $family1 = DB::table('families')->where('family_code', 'FAM-FA-1')->first();
        $student2 = DB::table('students')->where('gr_number', 'REG-FA-2')->first();

        // Finance Admin user
        $financeAdmin = User::create([
            'name' => 'Finance Admin User',
            'email' => 'finance_admin@example.test',
            'password' => 'test-password',
            'role' => 'finance_admin',
            'is_active' => true,
        ]);

        $this->actingAs($financeAdmin);

        $response = $this->put('/manage-student/' . $student2->id, [
            'name' => 'Child B',
            'father_name' => 'Khan',
            'registration_number' => 'REG-FA-2',
            'family_code' => 'FAM-FA-1',
            'family_email' => 'fa1@example.test',
            'family_whatsapp' => '+923004444444',
            'grade_label' => 'Grade 5',
        ]);

        $response->assertRedirect('/manage-student/' . $student2->id);
        $this->assertSame($family1->id, (int) DB::table('students')->where('id', $student2->id)->value('family_id'));
    }
}
