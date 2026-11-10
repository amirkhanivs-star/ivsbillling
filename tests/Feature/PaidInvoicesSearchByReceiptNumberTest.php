<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\WebhookSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaidInvoicesSearchByReceiptNumberTest extends TestCase
{
    use RefreshDatabase;

    private function setupTestData(): array
    {
        $this->seed();

        $campus = DB::table('campuses')->value('id');
        $dept = DB::table('departments')->where('code', 'school')->value('id');
        $program = DB::table('programs')->value('id');

        $superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'admin_receipt_search@example.test',
            'password' => bcrypt('password123'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $svc = app(WebhookSettingService::class);
        $svc->updateSettings('paid', ['url' => 'https://n8n.example.com/webhook/paid-invoices', 'secret' => 'test-secret', 'is_enabled' => true], $superAdmin->id);

        // Billing run for September 2026
        $septRunId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $campus,
            'department_id' => $dept,
            'frequency' => 'opening_balance',
            'period_key' => 'monthly:2026-09-01..2026-09-30',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'due_date' => '2026-09-25',
            'created_by' => $superAdmin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Billing run for November 2026
        $novRunId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $campus,
            'department_id' => $dept,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-11-01..2026-11-30',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'due_date' => '2026-11-25',
            'created_by' => $superAdmin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('superAdmin', 'campus', 'dept', 'program', 'septRunId', 'novRunId');
    }

    public function test_search_by_receipt_number_finds_associated_invoice_across_periods(): void
    {
        $data = $this->setupTestData();

        // Create family 4417 with Amna (4417) and Aisha (4418)
        $famId = DB::table('families')->insertGetId([
            'family_code' => '4417',
            'guardian_name' => 'Guardian Amna & Aisha',
            'whatsapp' => '+966500004417',
            'contact_verified' => true,
            'messaging_opt_in' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $amnaId = DB::table('students')->insertGetId([
            'family_id' => $famId,
            'name' => 'Amna',
            'gr_number' => '4417',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $aishaId = DB::table('students')->insertGetId([
            'family_id' => $famId,
            'name' => 'Aisha',
            'gr_number' => '4418',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrAmna = DB::table('enrollments')->insertGetId([
            'student_id' => $amnaId,
            'campus_id' => $data['campus'],
            'department_id' => $data['dept'],
            'program_id' => $data['program'],
            'grade_label' => '9th (FED) - A',
            'currency' => 'SAR',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrAisha = DB::table('enrollments')->insertGetId([
            'student_id' => $aishaId,
            'campus_id' => $data['campus'],
            'department_id' => $data['dept'],
            'program_id' => $data['program'],
            'grade_label' => '6 - A',
            'currency' => 'SAR',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Invoice in September 2026
        $invId = DB::table('invoices')->insertGetId([
            'billing_run_id' => $data['septRunId'],
            'family_id' => $famId,
            'campus_id' => $data['campus'],
            'department_id' => $data['dept'],
            'currency' => 'SAR',
            'invoice_number' => 'SCH-OB-2026-0000086-80',
            'issued_on' => '2026-09-01',
            'due_date' => '2026-09-25',
            'total_minor' => 93500,
            'paid_minor' => 66500,
            'status' => 'partial',
            'approved_to_send' => 0,
            'created_at' => '2026-10-26 00:41:01',
            'updated_at' => '2026-11-09 14:41:54',
        ]);

        $feeType = DB::table('fee_types')->value('id');

        $item1 = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invId,
            'student_id' => $amnaId,
            'enrollment_id' => $enrAmna,
            'fee_type_id' => $feeType,
            'description' => 'Monthly Fee - Sep-2026',
            'period_key' => 'monthly:2026-09-01..2026-09-30',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'currency' => 'SAR',
            'amount_minor' => 35000,
            'net_minor' => 35000,
            'paid_minor' => 35000,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $item2 = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invId,
            'student_id' => $aishaId,
            'enrollment_id' => $enrAisha,
            'fee_type_id' => $feeType,
            'description' => 'Monthly Fee - Sep-2026',
            'period_key' => 'monthly:2026-09-01..2026-09-30',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'currency' => 'SAR',
            'amount_minor' => 31500,
            'net_minor' => 31500,
            'paid_minor' => 31500,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Payment receipt RCP-2026-0002072
        $paymentId = DB::table('payments')->insertGetId([
            'family_id' => $famId,
            'campus_id' => $data['campus'],
            'department_id' => $data['dept'],
            'currency' => 'SAR',
            'receipt_number' => 'RCP-2026-0002072',
            'amount_minor' => 66500,
            'unallocated_minor' => 0,
            'received_on' => '2026-11-09',
            'selected_period' => '2026-09',
            'idempotency_key' => 'idemp-rcp-0002072',
            'request_hash' => hash('sha256', 'RCP-2026-0002072'),
            'received_by' => $data['superAdmin']->id,
            'status' => 'received',
            'created_at' => '2026-11-09 14:41:54',
            'updated_at' => '2026-11-09 14:41:54',
        ]);

        DB::table('payment_allocations')->insert([
            ['payment_id' => $paymentId, 'invoice_item_id' => $item1, 'amount_minor' => 35000, 'created_at' => now(), 'updated_at' => now()],
            ['payment_id' => $paymentId, 'invoice_item_id' => $item2, 'amount_minor' => 31500, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // While November 2026 is selected, searching RCP-2026-0002072 finds September invoice
        $response = $this->actingAs($data['superAdmin'])->get('/invoices?tab=paid&period=2026-11&search=RCP-2026-0002072');
        $response->assertStatus(200);

        // Asserts matching receipt and invoice details are displayed
        $response->assertSee('RCP-2026-0002072');
        $response->assertSee('SCH-OB-2026-0000086-80');
        $response->assertSee('4417');
        $response->assertSee('Amna');
        $response->assertSee('Aisha');
        $response->assertSee('Sep 2026');
        $response->assertSee('665.00');
        $response->assertSee('Partially Paid');

        // Checkbox must be disabled since this invoice is partial and ineligible for WhatsApp batch
        $response->assertSee('disabled', false);

        // Search by GR 4418
        $grResponse = $this->actingAs($data['superAdmin'])->get('/invoices?tab=paid&period=2026-11&search=4418');
        $grResponse->assertStatus(200);
        $grResponse->assertSee('SCH-OB-2026-0000086-80');
        $grResponse->assertSee('Aisha');

        // Search by Family Code 4417
        $famResponse = $this->actingAs($data['superAdmin'])->get('/invoices?tab=paid&period=2026-11&search=4417');
        $famResponse->assertStatus(200);
        $famResponse->assertSee('SCH-OB-2026-0000086-80');

        // Search by Invoice Number
        $invResponse = $this->actingAs($data['superAdmin'])->get('/invoices?tab=paid&period=2026-11&search=SCH-OB-2026-0000086-80');
        $invResponse->assertStatus(200);
        $invResponse->assertSee('SCH-OB-2026-0000086-80');

        // Search by Student Name
        $nameResponse = $this->actingAs($data['superAdmin'])->get('/invoices?tab=paid&period=2026-11&search=Amna');
        $nameResponse->assertStatus(200);
        $nameResponse->assertSee('SCH-OB-2026-0000086-80');
    }

    public function test_invalid_receipt_number_shows_clear_not_found_message(): void
    {
        $data = $this->setupTestData();

        $response = $this->actingAs($data['superAdmin'])->get('/invoices?tab=paid&period=2026-11&search=RCP-9999-9999999');
        $response->assertStatus(200);
        $response->assertSee('No receipt or paid invoices found matching');
        $response->assertSee('RCP-9999-9999999');
    }

    public function test_receipt_with_no_qualifying_invoice_shows_clear_explanation(): void
    {
        $data = $this->setupTestData();

        $famId = DB::table('families')->insertGetId([
            'family_code' => '5555',
            'guardian_name' => 'Guardian Standalone',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Payment receipt with no invoice allocations
        DB::table('payments')->insertGetId([
            'family_id' => $famId,
            'campus_id' => $data['campus'],
            'department_id' => $data['dept'],
            'currency' => 'SAR',
            'receipt_number' => 'RCP-STANDALONE-001',
            'amount_minor' => 15000,
            'unallocated_minor' => 15000,
            'received_on' => '2026-11-05',
            'idempotency_key' => 'idemp-rcp-standalone-001',
            'request_hash' => hash('sha256', 'RCP-STANDALONE-001'),
            'received_by' => $data['superAdmin']->id,
            'status' => 'received',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($data['superAdmin'])->get('/invoices?tab=paid&period=2026-11&search=RCP-STANDALONE-001');
        $response->assertStatus(200);
        $response->assertSee('Receipt RCP-STANDALONE-001 found');
        $response->assertSee('has no qualifying invoice record in the Paid Invoices section');
        $response->assertSee('View in Fee Collection');
    }

    public function test_empty_search_preserves_period_filtering(): void
    {
        $data = $this->setupTestData();

        // Without search, only November invoices appear for November
        $response = $this->actingAs($data['superAdmin'])->get('/invoices?tab=paid&period=2026-11');
        $response->assertStatus(200);
        $response->assertSee('No paid invoices found matching the current filters for November 2026');
    }

    public function test_whatsapp_selection_after_search_only_allows_eligible_invoices(): void
    {
        $data = $this->setupTestData();

        $famId = DB::table('families')->insertGetId([
            'family_code' => '8888',
            'guardian_name' => 'Guardian Eligible',
            'whatsapp' => '+966500008888',
            'contact_verified' => true,
            'whatsapp_verified' => true,
            'messaging_opt_in' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stuId = DB::table('students')->insertGetId([
            'family_id' => $famId,
            'name' => 'Student Eligible',
            'gr_number' => '8888',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrId = DB::table('enrollments')->insertGetId([
            'student_id' => $stuId,
            'campus_id' => $data['campus'],
            'department_id' => $data['dept'],
            'program_id' => $data['program'],
            'grade_label' => 'Grade 1',
            'currency' => 'SAR',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Fully paid portal invoice in October
        $octRunId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $data['campus'],
            'department_id' => $data['dept'],
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-25',
            'created_by' => $data['superAdmin']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invId = DB::table('invoices')->insertGetId([
            'billing_run_id' => $octRunId,
            'family_id' => $famId,
            'campus_id' => $data['campus'],
            'department_id' => $data['dept'],
            'currency' => 'SAR',
            'invoice_number' => 'SCH-PAID-008888',
            'issued_on' => '2026-10-26',
            'due_date' => '2026-10-25',
            'total_minor' => 20000,
            'paid_minor' => 20000,
            'status' => 'paid',
            'approved_to_send' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $feeType = DB::table('fee_types')->value('id');

        $itemId = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invId,
            'student_id' => $stuId,
            'enrollment_id' => $enrId,
            'fee_type_id' => $feeType,
            'description' => 'Monthly Fee',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'currency' => 'SAR',
            'amount_minor' => 20000,
            'net_minor' => 20000,
            'paid_minor' => 20000,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pId = DB::table('payments')->insertGetId([
            'family_id' => $famId,
            'campus_id' => $data['campus'],
            'department_id' => $data['dept'],
            'currency' => 'SAR',
            'receipt_number' => 'RCP-PAID-008888',
            'amount_minor' => 20000,
            'unallocated_minor' => 0,
            'received_on' => '2026-10-26',
            'idempotency_key' => 'idemp-rcp-paid-008888',
            'request_hash' => hash('sha256', 'RCP-PAID-008888'),
            'received_by' => $data['superAdmin']->id,
            'status' => 'received',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payment_allocations')->insert([
            'payment_id' => $pId,
            'invoice_item_id' => $itemId,
            'amount_minor' => 20000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($data['superAdmin'])->get('/invoices?tab=paid&period=2026-11&search=RCP-PAID-008888');
        $response->assertStatus(200);
        $response->assertSee('SCH-PAID-008888');
        $response->assertSee('Fully Paid');
        // This eligible fully paid invoice has an ENABLED checkbox
        $response->assertSee('name="invoice_ids[]" value="' . $invId . '"', false);
    }
}
