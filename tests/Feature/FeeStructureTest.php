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

    public function test_fee_structure_get_is_idempotent_and_does_not_insert_database_records(): void
    {
        $admin = $this->setupAdmin();

        $feeType = DB::table('fee_types')->where('code', 'registration')->first();

        // Count rates before GET request
        $ratesBefore = DB::table('fee_catalog_rates')->count();

        // Access fee structure for registration fee
        $res = $this->actingAs($admin)->get("/manage-fee?type={$feeType->id}");
        $res->assertStatus(200);

        // Verify that GET request did NOT insert any records into fee_catalog_rates
        $ratesAfter = DB::table('fee_catalog_rates')->count();
        $this->assertEquals($ratesBefore, $ratesAfter);
        $this->assertDatabaseMissing('fee_catalog_rates', [
            'fee_type_id' => $feeType->id,
        ]);

        // Seed one rate to verify normal rendering of edit/delete actions
        $dept = DB::table('departments')->first()->id;
        DB::table('fee_catalog_rates')->insert([
            'fee_type_id' => $feeType->id,
            'department_id' => $dept,
            'class_label' => 'Grade 1',
            'currency' => 'SAR',
            'amount_minor' => 10000,
            'frequency' => 'monthly',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $res2 = $this->actingAs($admin)->get("/manage-fee?type={$feeType->id}");
        $res2->assertStatus(200);
        $res2->assertSee('fc-edit');
        $res2->assertSee('fc-delete');
        $res2->assertSee('🗑');
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
