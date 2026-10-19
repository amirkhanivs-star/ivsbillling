<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FeeStructureTest extends TestCase
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

    public function test_fee_structure_initializes_default_zero_entry_for_every_class(): void
    {
        $admin = $this->setupAdmin();

        // Get count of unique classes from programs and enrollments
        $classes = DB::table('programs')->select('name')
            ->union(DB::table('enrollments')->whereNotNull('grade_label')->selectRaw('grade_label as name'))
            ->pluck('name')->filter()->unique();

        $feeType = DB::table('fee_types')->where('code', 'registration')->first();

        // Access fee structure for registration fee
        $res = $this->actingAs($admin)->get("/manage-fee?type={$feeType->id}");
        $res->assertStatus(200);

        // Verify that every class has an entry with by-default amount 0 (or pre-seeded value)
        foreach ($classes as $cls) {
            $this->assertDatabaseHas('fee_catalog_rates', [
                'fee_type_id' => $feeType->id,
                'class_label' => $cls,
            ]);
        }

        // Verify HTML contains the edit and delete buttons
        $res->assertSee('fc-edit');
        $res->assertSee('fc-delete');
        $res->assertSee('🗑');
    }

    public function test_delete_fee_rate_removes_entry_and_records_audit(): void
    {
        $admin = $this->setupAdmin();

        $feeType = DB::table('fee_types')->where('code', 'registration')->first();
        $dept = DB::table('departments')->first()->id;

        $rateId = DB::table('fee_catalog_rates')->insertGetId([
            'fee_type_id' => $feeType->id,
            'department_id' => $dept,
            'class_label' => 'Test Class Grade 9',
            'currency' => 'SAR',
            'amount_minor' => 15000,
            'frequency' => 'one_time',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $res = $this->actingAs($admin)->delete("/manage-fee/catalog/{$rateId}");
        $res->assertRedirect();
        $res->assertSessionHas('notice');

        // Audit log must exist
        $this->assertDatabaseHas('fee_rate_changes', [
            'fee_type_id' => $feeType->id,
            'target_kind' => 'catalog_deleted',
            'target_id' => $rateId,
            'before_minor' => 15000,
            'after_minor' => 0,
        ]);

        // Rate is removed from database
        $this->assertDatabaseMissing('fee_catalog_rates', [
            'id' => $rateId,
        ]);
    }

    public function test_custom_fee_type_can_be_deleted(): void
    {
        $admin = $this->setupAdmin();

        $customTypeId = DB::table('fee_types')->insertGetId([
            'name' => 'Lab Fee Custom',
            'code' => 'lab_fee_custom',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $res = $this->actingAs($admin)->delete("/manage-fee/types/{$customTypeId}");
        $res->assertRedirect();

        $this->assertDatabaseMissing('fee_types', [
            'id' => $customTypeId,
        ]);
    }
}
