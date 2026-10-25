<?php

namespace Tests\Feature;

use App\Services\PaidCollectionImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaidCollectionImportTest extends TestCase
{
    use RefreshDatabase;

    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup base database entities in SQLite memory
        $now = now()->toDateTimeString();

        DB::table('users')->insert([
            'id' => 1,
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('secret'),
            'role' => 'super_admin',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('campuses')->insert([
            'id' => 1,
            'name' => 'Main Campus',
            'code' => 'main',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('departments')->insert([
            ['id' => 1, 'name' => 'School', 'code' => 'school', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'name' => 'Tuition', 'code' => 'tuition', 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::table('fee_types')->insert([
            ['id' => 1, 'department_id' => 1, 'name' => 'Monthly Fee', 'code' => 'monthly', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'department_id' => 1, 'name' => 'Admission Fee', 'code' => 'admission', 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Create sample family and student
        $famId = DB::table('families')->insertGetId([
            'family_code' => 'FAM-TEST-001',
            'guardian_name' => 'Test Guardian',
            'whatsapp' => '+966500000001',
            'contact_verified' => 1,
            'messaging_opt_in' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $stuId = DB::table('students')->insertGetId([
            'family_id' => $famId,
            'gr_number' => '7131',
            'name' => 'Muhammad Konain Haider',
            'status' => 'present',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $enId = DB::table('enrollments')->insertGetId([
            'student_id' => $stuId,
            'campus_id' => 1,
            'department_id' => 1,
            'currency' => 'SAR',
            'grade_label' => 'Grade 1',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Create billing run and invoice for Oct 2026
        $runId = DB::table('billing_runs')->insertGetId([
            'campus_id' => 1,
            'department_id' => 1,
            'frequency' => 'monthly',
            'period_key' => '2026-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-25',
            'status' => 'generated',
            'created_by' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $invId = DB::table('invoices')->insertGetId([
            'billing_run_id' => $runId,
            'family_id' => $famId,
            'campus_id' => 1,
            'department_id' => 1,
            'currency' => 'SAR',
            'invoice_number' => 'SCH-2026-0000001',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-25',
            'total_minor' => 25000,
            'paid_minor' => 0,
            'previous_dues_snapshot_minor' => 0,
            'status' => 'generated',
            'approved_to_send' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('invoice_items')->insert([
            'invoice_id' => $invId,
            'student_id' => $stuId,
            'enrollment_id' => $enId,
            'fee_type_id' => 1,
            'description' => 'Monthly Fee - Oct-2026',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'currency' => 'SAR',
            'amount_minor' => 25000,
            'discount_minor' => 0,
            'exemption_minor' => 0,
            'net_minor' => 25000,
            'paid_minor' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Create sample CSV fixture
        $this->fixturePath = storage_path('app/testing_paid_sample.csv');
        $csvContent = "Cash,Slip #,GRN,Student Name,Particular,Date,Currency,Amount\n"
                    . ",27167,7131,Muhammad Konain Haider,Monthly Fee - Oct-2026, 23-Oct-26,SAR,250\n";
        file_put_contents($this->fixturePath, $csvContent);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->fixturePath)) {
            unlink($this->fixturePath);
        }
        parent::tearDown();
    }

    public function test_dry_run_does_not_persist_records(): void
    {
        $service = app(PaidCollectionImportService::class);
        $stats = $service->import($this->fixturePath, commit: false);

        $this->assertEquals(1, $stats['total_rows']);
        $this->assertEquals(1, $stats['payments_created']);
        $this->assertEquals(1, $stats['existing_items_settled']);

        // In database, payments and staged collections must remain 0 due to rollback
        $this->assertEquals(0, DB::table('payments')->count());
        $this->assertEquals(0, DB::table('qa_staged_collections')->count());
        $this->assertEquals('generated', DB::table('invoices')->value('status'));
    }

    public function test_commit_persists_staged_collections_payments_and_settles_invoice(): void
    {
        $service = app(PaidCollectionImportService::class);
        $stats = $service->import($this->fixturePath, commit: true);

        $this->assertEquals(1, $stats['total_rows']);
        $this->assertEquals(1, $stats['payments_created']);
        $this->assertEquals(1, $stats['staged_created']);
        $this->assertEquals(1, $stats['existing_items_settled']);

        // Check qa_staged_collections
        $staged = DB::table('qa_staged_collections')->first();
        $this->assertNotNull($staged);
        $this->assertEquals('27167', $staged->slip_number);
        $this->assertEquals('7131', $staged->gr_number);
        $this->assertEquals('SAR', $staged->currency);
        $this->assertEquals(25000, $staged->amount_minor);

        // Check payments
        $payment = DB::table('payments')->first();
        $this->assertNotNull($payment);
        $this->assertEquals('REC-27167', $payment->receipt_number);
        $this->assertEquals(25000, $payment->amount_minor);

        // Check payment_allocations
        $alloc = DB::table('payment_allocations')->first();
        $this->assertNotNull($alloc);
        $this->assertEquals($payment->id, $alloc->payment_id);
        $this->assertEquals(25000, $alloc->amount_minor);

        // Check invoice is now paid
        $invoice = DB::table('invoices')->first();
        $this->assertEquals('paid', $invoice->status);
        $this->assertEquals(25000, $invoice->paid_minor);
    }

    public function test_idempotent_import_on_repeated_execution(): void
    {
        $service = app(PaidCollectionImportService::class);

        // Run 1
        $service->import($this->fixturePath, commit: true);
        $this->assertEquals(1, DB::table('payments')->count());
        $this->assertEquals(1, DB::table('qa_staged_collections')->count());

        // Run 2
        $service->import($this->fixturePath, commit: true);
        $this->assertEquals(1, DB::table('payments')->count());
        $this->assertEquals(1, DB::table('qa_staged_collections')->count());
        $this->assertEquals(1, DB::table('payment_allocations')->count());
    }

    public function test_artisan_command_execution(): void
    {
        $this->artisan('iqra:import-paid-collections', [
            'path' => 'storage/app/testing_paid_sample.csv',
            '--commit' => true,
        ])
        ->expectsOutputToContain('MySkool Paid Collection Importer')
        ->expectsOutputToContain('SUCCESS: All collection records and paid invoices have been successfully committed')
        ->assertExitCode(0);

        $this->assertEquals(1, DB::table('payments')->count());
    }

    public function test_reconciles_multiple_identical_installments_on_same_slip(): void
    {
        // Update the invoice item to 270 SAR (27000 minor)
        DB::table('invoice_items')->where('id', 1)->update([
            'amount_minor' => 27000,
            'net_minor' => 27000,
            'paid_minor' => 0,
            'status' => 'active',
        ]);
        DB::table('invoices')->where('id', 1)->update([
            'total_minor' => 27000,
            'paid_minor' => 0,
            'status' => 'generated',
        ]);

        // Create 2 identical installment rows on slip 27173 (135 SAR each)
        $csvPath = storage_path('app/duplicate_installments_sample.csv');
        $csvContent = "Cash,Slip #,GRN,Student Name,Particular,Date,Currency,Amount\n"
                    . ",27173,7131,Muhammad Konain Haider,Monthly Fee - Oct-2026, 23-Oct-26,SAR,135\n"
                    . ",27173,7131,Muhammad Konain Haider,Monthly Fee - Oct-2026, 23-Oct-26,SAR,135\n";
        file_put_contents($csvPath, $csvContent);

        try {
            $service = app(PaidCollectionImportService::class);
            $stats = $service->import($csvPath, commit: true);

            $this->assertEquals(2, $stats['total_rows']);
            $this->assertEquals(2, $stats['staged_created']);
            $this->assertEquals(2, $stats['payments_created']);
            $this->assertEquals(2, $stats['allocations_created']);

            // Staging should have exactly 2 records
            $this->assertEquals(2, DB::table('qa_staged_collections')->where('slip_number', '27173')->count());

            // Payments should have exactly 2 records
            $this->assertEquals(2, DB::table('payments')->where('note', 'like', '%27173%')->count());

            // Allocations should total 27000
            $totalAlloc = DB::table('payment_allocations')->sum('amount_minor');
            $this->assertEquals(27000, $totalAlloc);

            // Invoice is fully paid
            $invoice = DB::table('invoices')->where('id', 1)->first();
            $this->assertEquals('paid', $invoice->status);
            $this->assertEquals(27000, $invoice->paid_minor);

            // Verify idempotency on second run
            $stats2 = $service->import($csvPath, commit: true);
            $this->assertEquals(0, $stats2['staged_created']);
            $this->assertEquals(0, $stats2['payments_created']);
            $this->assertEquals(0, $stats2['allocations_created']);
            $this->assertEquals(2, DB::table('qa_staged_collections')->where('slip_number', '27173')->count());
            $this->assertEquals(2, DB::table('payments')->where('note', 'like', '%27173%')->count());
        } finally {
            if (file_exists($csvPath)) {
                unlink($csvPath);
            }
        }
    }
}
