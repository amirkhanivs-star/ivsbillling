<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CleanPortalEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private function getAdminUser(): User
    {
        $this->seed();
        return User::where('email', 'admin@iqra.edu.pk')->firstOrFail();
    }

    public function test_admin_can_login_with_seeded_credentials(): void
    {
        $this->seed();

        $response = $this->post('/login', [
            'email' => 'admin@iqra.edu.pk',
            'password' => 'IqraPortal2026!Master',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticated();
    }

    public function test_admin_can_access_dashboard(): void
    {
        $admin = $this->getAdminUser();
        $response = $this->actingAs($admin)->get('/dashboard');
        $response->assertStatus(200);
    }

    public function test_admin_can_access_student_management(): void
    {
        $admin = $this->getAdminUser();
        $response = $this->actingAs($admin)->get('/manage-student');
        $response->assertStatus(200);
    }

    public function test_admin_can_access_new_student_admission(): void
    {
        $admin = $this->getAdminUser();
        $response = $this->actingAs($admin)->get('/manage-student/admission/new');
        $response->assertStatus(200);
    }

    public function test_admin_can_access_branches_and_classes(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin)->get('/manage-branches')->assertStatus(200);
        $this->actingAs($admin)->get('/manage-classes')->assertStatus(200);
    }

    public function test_admin_can_access_fee_management_and_tuition_configuration(): void
    {
        $admin = $this->getAdminUser();
        $response = $this->actingAs($admin)->get('/manage-fee');
        $response->assertStatus(200);
    }

    public function test_admin_can_access_fee_creator(): void
    {
        $admin = $this->getAdminUser();
        $response = $this->actingAs($admin)->get('/fee-creator');
        $response->assertStatus(200);
    }

    public function test_admin_can_access_invoices_challans_and_reports(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin)->get('/invoices')->assertStatus(200);
        $this->actingAs($admin)->get('/challan')->assertStatus(200);
        $this->actingAs($admin)->get('/reports')->assertStatus(200);
    }

    public function test_admin_can_access_migration_review_hub(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin)->get('/migration')->assertStatus(200);
        $this->actingAs($admin)->get('/migration/campus')->assertStatus(200);
        $this->actingAs($admin)->get('/migration/department')->assertStatus(200);
        $this->actingAs($admin)->get('/migration/currency')->assertStatus(200);
        $this->actingAs($admin)->get('/migration/qa-collections')->assertStatus(200);
        $this->actingAs($admin)->get('/migration/qa-dues')->assertStatus(200);
        $this->actingAs($admin)->get('/migration/opening-balances')->assertStatus(200);
        $this->actingAs($admin)->get('/migration/exceptions')->assertStatus(200);
        $this->actingAs($admin)->get('/migration/held-dues')->assertStatus(200);
    }

    public function test_migration_dashboard_schema_and_queries_align_with_database_schema(): void
    {
        $admin = $this->getAdminUser();

        // 1. Assert invoices schema has no invoice_type column (prevent future regression)
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('invoices', 'invoice_type'),
            "Regression failure: 'invoices' table must not have an 'invoice_type' column."
        );

        // 2. Assert the correct opening-balance invoice query executes properly
        $count = DB::table('invoices as i')
            ->join('billing_runs as br', 'br.id', '=', 'i.billing_run_id')
            ->where('br.frequency', 'opening_balance')
            ->count('i.id');
        $this->assertSame(0, $count);

        // 3. Assert /migration endpoint renders HTTP 200 with expected dashboard content
        $response = $this->actingAs($admin)->get('/migration');
        $response->assertStatus(200);
        $response->assertSee('Approved MySkool Workbook');
        $response->assertSee('Actual Portal Data');
        $response->assertSee('Not Imported');
    }

    public function test_financial_tables_remain_empty_after_endpoint_access(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin)->get('/dashboard');
        $this->actingAs($admin)->get('/challan');
        $this->actingAs($admin)->get('/invoices');

        $this->assertSame(0, DB::table('students')->count());
        $this->assertSame(0, DB::table('families')->count());
        $this->assertSame(0, DB::table('invoices')->count());
        $this->assertSame(0, DB::table('payments')->count());
    }
}
