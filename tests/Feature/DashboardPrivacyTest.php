<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DashboardPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $mainCampusId = DB::table('campuses')->where('code', 'main')->value('id');

        $this->cashier = User::create([
            'name' => 'Campus Cashier',
            'email' => 'cashier_dash@iqra.test',
            'password' => Hash::make('SecretPass123!'),
            'role' => 'cashier',
            'campus_id' => $mainCampusId,
            'is_active' => true,
        ]);
    }

    public function test_super_admin_sees_interactive_eye_toggle_on_dashboard(): void
    {
        $response = $this->actingAs($this->superAdmin)->get('/dashboard');
        $response->assertStatus(200);
        $response->assertSee('id="btnToggleEye"', false);
        $response->assertSee('Hide Details', false);
        $response->assertDontSee('Hidden (Super Admin Only)', false);
    }

    public function test_non_super_admin_sees_clean_masked_financials_without_disclaimers(): void
    {
        $response = $this->actingAs($this->cashier)->get('/dashboard');
        $response->assertStatus(200);
        $response->assertDontSee('id="btnToggleEye"', false);
        $response->assertDontSee('Hidden (Super Admin Only)', false);
        $response->assertDontSee('Amounts Protected', false);
        $response->assertDontSee('Financial collections and outstanding balances are confidential', false);
        $response->assertSee('••••••', false);
    }
}
