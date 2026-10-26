<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\InvoicePayloadBuilder;
use App\Services\PdfAccessService;
use App\Services\WebhookSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PermanentPublicPdfLinksTest extends TestCase
{
    use RefreshDatabase;

    private function setupBillingData(): array
    {
        $this->seed();

        $campus = DB::table('campuses')->value('id');
        $dept = DB::table('departments')->where('code', 'school')->value('id');
        $program = DB::table('programs')->value('id');

        $superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'admin_pdf_test@example.test',
            'password' => bcrypt('password123'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $run = DB::table('billing_runs')->insertGetId([
            'campus_id' => $campus,
            'department_id' => $dept,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-25',
            'created_by' => $superAdmin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $famId = DB::table('families')->insertGetId([
            'family_code' => 'FAM-PDF-001',
            'guardian_name' => 'Guardian PDF Test',
            'whatsapp' => '+966501112233',
            'contact_verified' => true,
            'whatsapp_verified' => true,
            'messaging_opt_in' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stuId = DB::table('students')->insertGetId([
            'family_id' => $famId,
            'name' => 'Student PDF Test',
            'gr_number' => 'GR-PDF-001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrId = DB::table('enrollments')->insertGetId([
            'student_id' => $stuId,
            'campus_id' => $campus,
            'department_id' => $dept,
            'program_id' => $program,
            'grade_label' => 'Grade 1',
            'currency' => 'SAR',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invId = DB::table('invoices')->insertGetId([
            'family_id' => $famId,
            'campus_id' => $campus,
            'department_id' => $dept,
            'billing_run_id' => $run,
            'invoice_number' => 'SCH-2026-PDF-001',
            'currency' => 'SAR',
            'total_minor' => 15000,
            'paid_minor' => 15000,
            'status' => 'paid',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-25',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invId,
            'student_id' => $stuId,
            'enrollment_id' => $enrId,
            'description' => 'Monthly Tuition Fee',
            'amount_minor' => 15000,
            'discount_minor' => 0,
            'net_minor' => 15000,
            'paid_minor' => 15000,
            'currency' => 'SAR',
            'period_key' => '2026-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $paymentId = DB::table('payments')->insertGetId([
            'family_id' => $famId,
            'campus_id' => $campus,
            'department_id' => $dept,
            'receipt_number' => 'RCP-PDF-001',
            'received_on' => '2026-10-24',
            'amount_minor' => 15000,
            'unallocated_minor' => 0,
            'currency' => 'SAR',
            'note' => 'Full Bank Transfer',
            'idempotency_key' => 'IDEMP-PDF-' . uniqid(),
            'request_hash' => hash('sha256', 'PDF-TEST'),
            'received_by' => $superAdmin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payment_allocations')->insert([
            'payment_id' => $paymentId,
            'invoice_item_id' => $itemId,
            'amount_minor' => 15000,
            'created_at' => now(),
        ]);

        return compact('superAdmin', 'famId', 'invId', 'paymentId', 'run');
    }

    /**
     * Test 1: Valid monthly invoice public link opens without login and has no expires param.
     */
    public function test_valid_monthly_invoice_public_link_opens_without_login_and_has_no_expiry(): void
    {
        $data = $this->setupBillingData();
        $pdfService = app(PdfAccessService::class);

        $url = $pdfService->getInvoicePdfUrl($data['invId']);

        // Assert URL ends cleanly in .pdf, has no query string, and no expires param
        $this->assertStringEndsWith('/Monthly-Fee-Invoice.pdf', $url);
        $this->assertStringNotContainsString('?', $url);
        $this->assertStringNotContainsString('expires=', $url);

        // Access as unauthenticated guest
        $response = $this->get($url);

        $response->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type') ?? '');
    }

    /**
     * Test 2: Valid paid receipt public link opens without login and has no expires param.
     */
    public function test_valid_paid_receipt_public_link_opens_without_login_and_has_no_expiry(): void
    {
        $data = $this->setupBillingData();
        $pdfService = app(PdfAccessService::class);

        $url = $pdfService->getReceiptPdfUrl($data['paymentId']);

        // Assert URL ends cleanly in .pdf, has no query string, and no expires param
        $this->assertStringEndsWith('/Paid-Receipt.pdf', $url);
        $this->assertStringNotContainsString('?', $url);
        $this->assertStringNotContainsString('expires=', $url);

        // Access as unauthenticated guest
        $response = $this->get($url);

        $response->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type') ?? '');
    }

    /**
     * Test 3: Invalid, tampered, or revoked links are rejected.
     */
    public function test_invalid_tampered_or_revoked_links_are_rejected(): void
    {
        $data = $this->setupBillingData();
        $pdfService = app(PdfAccessService::class);

        $invoiceUrl = $pdfService->getInvoicePdfUrl($data['invId']);
        $receiptUrl = $pdfService->getReceiptPdfUrl($data['paymentId']);

        // Tampered token in clean path URL
        $tamperedInvoiceUrl = str_replace('/Monthly-Fee-Invoice.pdf', 'bad/Monthly-Fee-Invoice.pdf', $invoiceUrl);
        $this->get($tamperedInvoiceUrl)->assertStatus(403);

        $tamperedReceiptUrl = str_replace('/Paid-Receipt.pdf', 'bad/Paid-Receipt.pdf', $receiptUrl);
        $this->get($tamperedReceiptUrl)->assertStatus(403);

        // Guest directly guessing an ID without token or signature
        $this->get("/public/invoices/{$data['invId']}/pdf")->assertStatus(403);
        $this->get("/public/receipts/{$data['paymentId']}/pdf")->assertStatus(403);

        // Authenticated route without login redirects or denies
        $this->get("/receipts/{$data['paymentId']}/pdf")->assertRedirect('/login');

        // Revocation / Regeneration test
        $oldInvoiceUrl = $pdfService->getInvoicePdfUrl($data['invId']);
        $pdfService->regenerateAccessKey('invoice', $data['invId']);

        // Old invoice link is now rejected
        $this->get($oldInvoiceUrl)->assertStatus(403);

        // New invoice link works
        $newInvoiceUrl = $pdfService->getInvoicePdfUrl($data['invId']);
        $this->get($newInvoiceUrl)->assertStatus(200);

        // Revoking receipt access
        $oldReceiptUrl = $pdfService->getReceiptPdfUrl($data['paymentId']);
        $pdfService->revokeAccess('receipt', $data['paymentId']);
        $this->get($oldReceiptUrl)->assertStatus(403);

        // Backward compatibility: Old signed routes with valid signature still work
        $legacySignedChallan = URL::signedRoute('families.signed_challan_pdf', [
            'family' => $data['famId'],
            'k' => $pdfService->getAccessKey('family', $data['famId']),
        ]);
        $this->get($legacySignedChallan)->assertStatus(200);
    }

    /**
     * Test 4: Both webhook payloads contain the correct corresponding non-expiring PDF links.
     */
    public function test_both_webhook_payloads_contain_correct_corresponding_pdf_links_without_expires_at(): void
    {
        $data = $this->setupBillingData();
        $builder = app(InvoicePayloadBuilder::class);
        $invoice = DB::table('invoices')->where('id', $data['invId'])->first();

        // 1. Monthly Webhook Payload
        $monthlyNotif = (object)[
            'id' => 101,
            'event_type' => 'invoice.monthly',
            'kind' => 'monthly',
            'channels' => json_encode(['whatsapp']),
            'event_id' => 'evt-monthly-test-01',
            'idempotency_key' => 'idemp-monthly-test-01',
        ];

        $monthlyPayload = $builder->build($monthlyNotif, $invoice, 'invoice.monthly');

        $this->assertArrayHasKey('pdf_attachment', $monthlyPayload);
        $this->assertArrayNotHasKey('expires_at', $monthlyPayload['pdf_attachment'], 'Permanent monthly links must not have expires_at');
        $this->assertStringEndsWith('/Monthly-Fee-Invoice.pdf', $monthlyPayload['pdf_attachment']['pdf_url']);
        $this->assertStringNotContainsString('?', $monthlyPayload['pdf_attachment']['pdf_url']);
        $this->assertStringEndsWith('/Monthly-Fee-Invoice.pdf', $monthlyPayload['pdf_attachment']['consolidated_challan_url']);
        $this->assertStringNotContainsString('?', $monthlyPayload['pdf_attachment']['consolidated_challan_url']);

        // 2. Paid Webhook Payload
        $paidNotif = (object)[
            'id' => 102,
            'event_type' => 'invoice.paid',
            'kind' => 'paid',
            'channels' => json_encode(['whatsapp']),
            'event_id' => 'evt-paid-test-01',
            'idempotency_key' => 'idemp-paid-test-01',
        ];

        $paidPayload = $builder->build($paidNotif, $invoice, 'invoice.paid');

        $this->assertArrayHasKey('pdf_attachment', $paidPayload);
        $this->assertArrayNotHasKey('expires_at', $paidPayload['pdf_attachment'], 'Permanent paid links must not have expires_at');
        $this->assertStringEndsWith('/Monthly-Fee-Invoice.pdf', $paidPayload['pdf_attachment']['pdf_url']);
        $this->assertStringNotContainsString('?', $paidPayload['pdf_attachment']['pdf_url']);

        // Paid payload must include the permanent paid receipt PDF URL
        $this->assertArrayHasKey('receipt_pdf_url', $paidPayload['pdf_attachment']);
        $this->assertStringEndsWith('/Paid-Receipt.pdf', $paidPayload['pdf_attachment']['receipt_pdf_url']);
        $this->assertStringNotContainsString('?', $paidPayload['pdf_attachment']['receipt_pdf_url']);

        $this->assertArrayHasKey('payment_details', $paidPayload);
        $this->assertArrayHasKey('receipt_pdf_url', $paidPayload['payment_details']);
        $this->assertStringEndsWith('/Paid-Receipt.pdf', $paidPayload['payment_details']['receipt_pdf_url']);
        $this->assertStringNotContainsString('?', $paidPayload['payment_details']['receipt_pdf_url']);

        $this->assertNotEmpty($paidPayload['payment_details']['related_receipt_pdf_links']);
        $related = $paidPayload['payment_details']['related_receipt_pdf_links'][0];
        $this->assertStringEndsWith('/Paid-Receipt.pdf', $related['receipt_pdf_url']);
        $this->assertStringNotContainsString('?', $related['receipt_pdf_url']);
    }
}
