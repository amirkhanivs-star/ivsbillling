<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $cashier;
    private int $mainCampusId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $this->mainCampusId = DB::table('campuses')->where('code', 'main')->value('id');

        $this->cashier = User::create([
            'name' => 'Campus Cashier',
            'email' => 'cashier@iqra.test',
            'password' => Hash::make('CashierSecret123!'),
            'role' => 'cashier',
            'campus_id' => $this->mainCampusId,
            'is_active' => true,
        ]);
    }

    public function test_guest_cannot_access_user_management(): void
    {
        $response = $this->get('/settings/users');
        $response->assertRedirect('/login');
    }

    public function test_cashier_cannot_access_user_management(): void
    {
        $response = $this->actingAs($this->cashier)->get('/settings/users');
        $response->assertStatus(403);
    }

    public function test_super_admin_can_view_user_management_screen(): void
    {
        $response = $this->actingAs($this->superAdmin)->get('/settings/users');
        $response->assertStatus(200);
        $response->assertSee('Users & Staff Management', false);
        $response->assertSee('Create New User');
        $response->assertSee($this->superAdmin->email);
        $response->assertSee($this->cashier->email);
    }

    public function test_super_admin_can_create_new_cashier_user(): void
    {
        $payload = [
            'name' => 'Brother Bilal',
            'email' => 'bilal@iqra.edu.pk',
            'password' => 'SecurePass1234!',
            'role' => 'cashier',
            'campus_id' => $this->mainCampusId,
            'is_active' => '1',
        ];

        $response = $this->actingAs($this->superAdmin)->post('/settings/users', $payload);
        $response->assertRedirect('/settings/users');
        $response->assertSessionHas('notice');

        $this->assertDatabaseHas('users', [
            'name' => 'Brother Bilal',
            'email' => 'bilal@iqra.edu.pk',
            'role' => 'cashier',
            'campus_id' => $this->mainCampusId,
            'is_active' => 1,
        ]);

        $created = User::where('email', 'bilal@iqra.edu.pk')->first();
        $this->assertNotNull($created);
        $this->assertTrue(Hash::check('SecurePass1234!', $created->password));
    }

    public function test_cannot_create_branch_user_without_campus(): void
    {
        $payload = [
            'name' => 'Missing Campus Staff',
            'email' => 'nocampus@iqra.edu.pk',
            'password' => 'SecurePass1234!',
            'role' => 'cashier',
            'campus_id' => '',
        ];

        $response = $this->actingAs($this->superAdmin)->post('/settings/users', $payload);
        $response->assertSessionHasErrors(['campus_id']);
        $this->assertDatabaseMissing('users', ['email' => 'nocampus@iqra.edu.pk']);
    }

    public function test_cannot_create_user_with_duplicate_email(): void
    {
        $payload = [
            'name' => 'Duplicate Cashier',
            'email' => $this->cashier->email,
            'password' => 'SecurePass1234!',
            'role' => 'cashier',
            'campus_id' => $this->mainCampusId,
        ];

        $response = $this->actingAs($this->superAdmin)->post('/settings/users', $payload);
        $response->assertSessionHasErrors(['email']);
    }

    public function test_super_admin_can_update_user(): void
    {
        $updatePayload = [
            'name' => 'Updated Cashier Name',
            'email' => 'cashier_updated@iqra.test',
            'role' => 'cashier',
            'campus_id' => $this->mainCampusId,
            'is_active' => '1',
        ];

        $response = $this->actingAs($this->superAdmin)->put("/settings/users/{$this->cashier->id}", $updatePayload);
        $response->assertRedirect('/settings/users');
        $response->assertSessionHas('notice');

        $this->assertDatabaseHas('users', [
            'id' => $this->cashier->id,
            'name' => 'Updated Cashier Name',
            'email' => 'cashier_updated@iqra.test',
        ]);
    }

    public function test_super_admin_can_toggle_user_status(): void
    {
        $this->assertTrue($this->cashier->is_active);

        $response = $this->actingAs($this->superAdmin)->post("/settings/users/{$this->cashier->id}/toggle-status");
        $response->assertRedirect();
        $response->assertSessionHas('notice');

        $this->assertFalse($this->cashier->fresh()->is_active);

        // Toggle back to active
        $response = $this->actingAs($this->superAdmin)->post("/settings/users/{$this->cashier->id}/toggle-status");
        $this->assertTrue($this->cashier->fresh()->is_active);
    }

    public function test_user_cannot_deactivate_own_account(): void
    {
        $response = $this->actingAs($this->superAdmin)->post("/settings/users/{$this->superAdmin->id}/toggle-status");
        $response->assertSessionHas('error');
        $this->assertTrue($this->superAdmin->fresh()->is_active);
    }

    public function test_super_admin_can_reset_user_password(): void
    {
        $response = $this->actingAs($this->superAdmin)->post("/settings/users/{$this->cashier->id}/reset-password", [
            'password' => 'BrandNewPassword2026!',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('notice');

        $this->assertTrue(Hash::check('BrandNewPassword2026!', $this->cashier->fresh()->password));
    }
}
