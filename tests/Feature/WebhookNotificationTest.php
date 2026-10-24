<?php

namespace Tests\Feature;

use App\Jobs\DispatchInvoiceToN8n;
use App\Models\User;
use App\Models\WebhookSetting;
use App\Services\InvoiceService;
use App\Services\MySkoolAuthorizationCarryForwardService;
use App\Services\WebhookSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebhookNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function setupBillingData(string $currency = 'SAR'): array
    {
        $this->seed();

        $campus = DB::table('campuses')->value('id');
        $dept = DB::table('departments')->where('code', 'school')->value('id');
        $feeType = DB::table('fee_types')->where('code', 'monthly')->value('id');

        $superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@example.test',
            'password' => bcrypt('password123'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $cashier = User::create([
            'name' => 'Cashier User',
            'email' => 'cashier@example.test',
            'password' => bcrypt('password123'),
            'role' => 'cashier',
            'campus_id' => $campus,
            'department_id' => $dept,
            'is_active' => true,
        ]);

        $family = DB::table('families')->insertGetId([
            'family_code' => 'FAM-500',
            'whatsapp' => '+966500000001',
            'email' => 'guardian500@example.test',
            'contact_verified' => true,
            'messaging_opt_in' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $student1 = DB::table('students')->insertGetId([
            'family_id' => $family,
            'gr_number' => 'GR-501',
            'name' => 'Student One',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enroll1 = DB::table('enrollments')->insertGetId([
            'student_id' => $student1,
            'campus_id' => $campus,
            'department_id' => $dept,
            'currency' => $currency,
            'monthly_fee_minor' => 30000,
            'grade_label' => 'Grade 1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('fee_plan_items')->insert([
            'enrollment_id' => $enroll1,
            'fee_type_id' => $feeType,
            'description' => 'Monthly Tuition',
            'frequency' => 'monthly',
            'currency' => $currency,
            'amount_minor' => 30000,
            'created_at' => now(),
            'updated_at' => now(),
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

        $result = app(InvoiceService::class)->generate($run);
        $invoice = DB::table('invoices')->where('billing_run_id', $run)->first();

        return compact('superAdmin', 'cashier', 'family', 'student1', 'enroll1', 'invoice', 'campus', 'dept');
    }

    private function configureWebhooks(): array
    {
        $monthlyUrl = 'https://n8n.example.com/webhook/monthly-invoices';
        $paidUrl = 'https://n8n.example.com/webhook/paid-invoices';
        $secret = 'super-secret-test-key';

        $svc = app(WebhookSettingService::class);
        $svc->updateSettings('monthly', ['url' => $monthlyUrl, 'secret' => $secret, 'is_enabled' => true], 1);
        $svc->updateSettings('paid', ['url' => $paidUrl, 'secret' => $secret, 'is_enabled' => true], 1);

        return compact('monthlyUrl', 'paidUrl', 'secret');
    }

    /**
     * 1. Monthly invoice routed ONLY to Monthly Invoice Webhook.
     */
    public function test_monthly_invoice_routed_only_to_monthly_webhook(): void
    {
        $data = $this->setupBillingData();
        $urls = $this->configureWebhooks();

        Http::fake([
            $urls['monthlyUrl'] => Http::response(['ok' => true, 'message_id' => 'msg-monthly-1'], 200),
            $urls['paidUrl'] => Http::response(['ok' => true, 'message_id' => 'msg-paid-1'], 200),
        ]);

        $this->actingAs($data['superAdmin'])
            ->post('/invoices/send', [
                'invoice_ids' => [$data['invoice']->id],
                'channels' => ['whatsapp'],
                'confirmed_grades' => ['Grade 1'],
                'review_confirmed' => '1',
                'webhook_type' => 'monthly',
            ])
            ->assertRedirect()
            ->assertSessionHas('notice');

        // Execute queued job
        $notification = DB::table('notifications')->where('invoice_id', $data['invoice']->id)->first();
        $this->assertNotNull($notification);
        $this->assertSame('monthly', $notification->webhook_type);
        $this->assertSame('invoice.monthly', $notification->event_type);

        (new DispatchInvoiceToN8n($notification->id))->handle();

        Http::assertSent(function ($request) use ($urls) {
            return $request->url() === $urls['monthlyUrl']
                && $request['event'] === 'invoice.monthly'
                && $request->hasHeader('X-IQRA-Signature');
        });

        Http::assertNotSent(function ($request) use ($urls) {
            return $request->url() === $urls['paidUrl'];
        });

        $this->assertSame('submitted', DB::table('notifications')->where('id', $notification->id)->value('status'));
    }

    /**
     * 2. Fully paid invoice routed ONLY to Paid Invoice Webhook.
     */
    public function test_fully_paid_invoice_routed_only_to_paid_webhook(): void
    {
        $data = $this->setupBillingData();
        $urls = $this->configureWebhooks();

        // Mark invoice as fully settled
        DB::table('invoices')->where('id', $data['invoice']->id)->update([
            'status' => 'paid',
            'paid_minor' => $data['invoice']->total_minor,
        ]);

        Http::fake([
            $urls['monthlyUrl'] => Http::response(['ok' => true], 200),
            $urls['paidUrl'] => Http::response(['ok' => true, 'message_id' => 'msg-paid-settled'], 200),
        ]);

        $this->actingAs($data['superAdmin'])
            ->post('/invoices/send', [
                'invoice_ids' => [$data['invoice']->id],
                'channels' => ['whatsapp'],
                'confirmed_grades' => ['Grade 1'],
                'review_confirmed' => '1',
                'webhook_type' => 'paid',
            ])
            ->assertRedirect()
            ->assertSessionHas('notice');

        $notification = DB::table('notifications')->where('invoice_id', $data['invoice']->id)->first();
        $this->assertNotNull($notification);
        $this->assertSame('paid', $notification->webhook_type);
        $this->assertSame('invoice.paid', $notification->event_type);

        (new DispatchInvoiceToN8n($notification->id))->handle();

        Http::assertSent(function ($request) use ($urls) {
            return $request->url() === $urls['paidUrl']
                && $request['event'] === 'invoice.paid'
                && isset($request['payment_details']);
        });

        Http::assertNotSent(function ($request) use ($urls) {
            return $request->url() === $urls['monthlyUrl'];
        });
    }

    /**
     * 3. Selection and staff approval requirements.
     */
    public function test_selection_and_staff_approval_requirements(): void
    {
        $data = $this->setupBillingData();
        $this->configureWebhooks();

        // Missing invoice_ids
        $this->actingAs($data['superAdmin'])
            ->post('/invoices/send', [
                'channels' => ['whatsapp'],
                'confirmed_grades' => ['Grade 1'],
                'review_confirmed' => '1',
            ])
            ->assertSessionHasErrors('invoice_ids');

        // Missing confirmed_grades
        $this->actingAs($data['superAdmin'])
            ->post('/invoices/send', [
                'invoice_ids' => [$data['invoice']->id],
                'channels' => ['whatsapp'],
                'review_confirmed' => '1',
            ])
            ->assertSessionHasErrors('confirmed_grades');

        // Missing review confirmation checkbox
        $this->actingAs($data['superAdmin'])
            ->post('/invoices/send', [
                'invoice_ids' => [$data['invoice']->id],
                'channels' => ['whatsapp'],
                'confirmed_grades' => ['Grade 1'],
            ])
            ->assertSessionHasErrors('review_confirmed');
    }

    /**
     * 4. Correct PDF URL included in payload and accessible.
     */
    public function test_correct_pdf_url_included_in_payload(): void
    {
        $data = $this->setupBillingData();
        $urls = $this->configureWebhooks();

        $capturedPayload = null;
        Http::fake([
            $urls['monthlyUrl'] => function ($request) use (&$capturedPayload) {
                $capturedPayload = $request->data();
                return Http::response(['ok' => true], 200);
            },
        ]);

        $this->actingAs($data['superAdmin'])
            ->post('/invoices/send', [
                'invoice_ids' => [$data['invoice']->id],
                'channels' => ['whatsapp'],
                'confirmed_grades' => ['Grade 1'],
                'review_confirmed' => '1',
                'webhook_type' => 'monthly',
            ]);

        $notification = DB::table('notifications')->where('invoice_id', $data['invoice']->id)->first();
        (new DispatchInvoiceToN8n($notification->id))->handle();

        $this->assertNotNull($capturedPayload);
        $this->assertArrayHasKey('pdf_attachment', $capturedPayload);
        $this->assertArrayHasKey('pdf_url', $capturedPayload['pdf_attachment']);

        $pdfUrl = $capturedPayload['pdf_attachment']['pdf_url'];
        $this->assertStringContainsString('/public/invoices/' . $data['invoice']->id . '/pdf', $pdfUrl);
        $this->assertStringContainsString('signature=', $pdfUrl);

        // Verify the signed URL resolves without login (public signed route)
        $response = $this->get($pdfUrl);
        $response->assertStatus(200);
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    /**
     * 5. Multi-student family invoices containing all correct students.
     */
    public function test_multi_student_family_invoices_contain_all_correct_students(): void
    {
        $data = $this->setupBillingData();
        $urls = $this->configureWebhooks();

        // Add second student to same family
        $student2 = DB::table('students')->insertGetId([
            'family_id' => $data['family'],
            'gr_number' => 'GR-502',
            'name' => 'Student Two',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $enroll2 = DB::table('enrollments')->insertGetId([
            'student_id' => $student2,
            'campus_id' => $data['campus'],
            'department_id' => $data['dept'],
            'currency' => 'SAR',
            'monthly_fee_minor' => 20000,
            'grade_label' => 'Grade 2',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('fee_plan_items')->insert([
            'enrollment_id' => $enroll2,
            'fee_type_id' => DB::table('fee_types')->where('code', 'monthly')->value('id'),
            'description' => 'Monthly Tuition Sibling',
            'frequency' => 'monthly',
            'currency' => 'SAR',
            'amount_minor' => 20000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Add line item to invoice for student 2
        DB::table('invoice_items')->insert([
            'invoice_id' => $data['invoice']->id,
            'student_id' => $student2,
            'enrollment_id' => $enroll2,
            'description' => 'Monthly Tuition Sibling',
            'period_key' => 'monthly:2026-10-01..2026-10-31:s2',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'currency' => 'SAR',
            'amount_minor' => 20000,
            'discount_minor' => 0,
            'net_minor' => 20000,
            'paid_minor' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('invoices')->where('id', $data['invoice']->id)->update(['total_minor' => 50000]);

        $capturedPayload = null;
        Http::fake([
            $urls['monthlyUrl'] => function ($request) use (&$capturedPayload) {
                $capturedPayload = $request->data();
                return Http::response(['ok' => true], 200);
            },
        ]);

        $this->actingAs($data['superAdmin'])
            ->post('/invoices/send', [
                'invoice_ids' => [$data['invoice']->id],
                'channels' => ['whatsapp'],
                'confirmed_grades' => ['Grade 1', 'Grade 2'],
                'review_confirmed' => '1',
                'webhook_type' => 'monthly',
            ]);

        $notification = DB::table('notifications')->where('invoice_id', $data['invoice']->id)->first();
        (new DispatchInvoiceToN8n($notification->id))->handle();

        $this->assertNotNull($capturedPayload);
        $this->assertCount(2, $capturedPayload['students']);
        $grs = collect($capturedPayload['students'])->pluck('gr_number')->all();
        $this->assertContains('GR-501', $grs);
        $this->assertContains('GR-502', $grs);
    }

    /**
     * 6. Multiple currencies preserved without conversion.
     */
    public function test_multiple_currencies_preserved_without_conversion(): void
    {
        $dataPkr = $this->setupBillingData('PKR');
        $urls = $this->configureWebhooks();

        $capturedPayload = null;
        Http::fake([
            $urls['monthlyUrl'] => function ($request) use (&$capturedPayload) {
                $capturedPayload = $request->data();
                return Http::response(['ok' => true], 200);
            },
        ]);

        $this->actingAs($dataPkr['superAdmin'])
            ->post('/invoices/send', [
                'invoice_ids' => [$dataPkr['invoice']->id],
                'channels' => ['whatsapp'],
                'confirmed_grades' => ['Grade 1'],
                'review_confirmed' => '1',
                'webhook_type' => 'monthly',
            ]);

        $notification = DB::table('notifications')->where('invoice_id', $dataPkr['invoice']->id)->first();
        (new DispatchInvoiceToN8n($notification->id))->handle();

        $this->assertNotNull($capturedPayload);
        $this->assertSame('PKR', $capturedPayload['invoice']['currency']);
        $this->assertSame('300.00', $capturedPayload['invoice']['total_amount']);
    }

    /**
     * 7. Missing or disabled webhook settings blocks dispatch with clear message.
     */
    public function test_missing_or_disabled_webhook_settings_blocks_dispatch(): void
    {
        $data = $this->setupBillingData();
        // Leave webhooks disabled

        $this->actingAs($data['superAdmin'])
            ->post('/invoices/send', [
                'invoice_ids' => [$data['invoice']->id],
                'channels' => ['whatsapp'],
                'confirmed_grades' => ['Grade 1'],
                'review_confirmed' => '1',
                'webhook_type' => 'monthly',
            ])
            ->assertSessionHasErrors('webhook');

        $this->assertSame(0, DB::table('notifications')->count());
    }

    /**
     * 8. Missing consent or unverified contact blocks notification.
     */
    public function test_missing_consent_or_unverified_contact_blocks_notification(): void
    {
        $data = $this->setupBillingData();
        $this->configureWebhooks();

        // Revoke WhatsApp messaging opt-in
        DB::table('families')->where('id', $data['family'])->update([
            'messaging_opt_in' => false,
        ]);

        $this->actingAs($data['superAdmin'])
            ->post('/invoices/send', [
                'invoice_ids' => [$data['invoice']->id],
                'channels' => ['whatsapp'],
                'confirmed_grades' => ['Grade 1'],
                'review_confirmed' => '1',
                'webhook_type' => 'monthly',
            ])
            ->assertRedirect();

        $notification = DB::table('notifications')->where('invoice_id', $data['invoice']->id)->first();
        $this->assertNotNull($notification);
        $this->assertSame('blocked', $notification->status);
        $this->assertStringContainsString('WhatsApp permission absent', $notification->error);
    }

    /**
     * 9. Duplicate submission protection.
     */
    public function test_duplicate_submission_protection(): void
    {
        $data = $this->setupBillingData();
        $this->configureWebhooks();

        // First send
        $this->actingAs($data['superAdmin'])
            ->post('/invoices/send', [
                'invoice_ids' => [$data['invoice']->id],
                'channels' => ['whatsapp'],
                'confirmed_grades' => ['Grade 1'],
                'review_confirmed' => '1',
                'webhook_type' => 'monthly',
            ]);
        $this->assertSame(1, DB::table('notifications')->where('invoice_id', $data['invoice']->id)->count());

        // Second duplicate send
        $this->actingAs($data['superAdmin'])
            ->post('/invoices/send', [
                'invoice_ids' => [$data['invoice']->id],
                'channels' => ['whatsapp'],
                'confirmed_grades' => ['Grade 1'],
                'review_confirmed' => '1',
                'webhook_type' => 'monthly',
            ]);

        // Still only 1 notification event created
        $this->assertSame(1, DB::table('notifications')->where('invoice_id', $data['invoice']->id)->count());
    }

    /**
     * 10. Failed webhook retry without creating new invoice.
     */
    public function test_failed_webhook_retry_without_creating_new_invoice(): void
    {
        $data = $this->setupBillingData();
        $urls = $this->configureWebhooks();

        Queue::fake([DispatchInvoiceToN8n::class]);

        $this->actingAs($data['superAdmin'])
            ->post('/invoices/send', [
                'invoice_ids' => [$data['invoice']->id],
                'channels' => ['whatsapp'],
                'confirmed_grades' => ['Grade 1'],
                'review_confirmed' => '1',
                'webhook_type' => 'monthly',
            ])
            ->assertRedirect()
            ->assertSessionHas('notice');

        Queue::assertPushed(DispatchInvoiceToN8n::class);

        $notification = DB::table('notifications')->where('invoice_id', $data['invoice']->id)->first();
        $this->assertNotNull($notification);

        // Mark notification as failed to simulate a failed prior dispatch attempt
        DB::table('notifications')->where('id', $notification->id)->update([
            'status' => 'failed',
            'error' => 'Webhook HTTP 500 error from n8n endpoint',
            'last_attempt_at' => now(),
        ]);

        $this->assertSame('failed', DB::table('notifications')->where('id', $notification->id)->value('status'));

        $initialInvoiceCount = DB::table('invoices')->count();

        // Staff calls Retry
        $this->actingAs($data['superAdmin'])
            ->post("/invoices/notifications/{$notification->id}/retry")
            ->assertRedirect()
            ->assertSessionHas('notice');

        // Confirms notification re-queued
        $this->assertSame('queued', DB::table('notifications')->where('id', $notification->id)->value('status'));
        $this->assertNull(DB::table('notifications')->where('id', $notification->id)->value('error'));

        Queue::assertPushed(DispatchInvoiceToN8n::class, 2);

        // Zero new invoices created
        $this->assertSame($initialInvoiceCount, DB::table('invoices')->count());
    }

    /**
     * 11. Super Admin-only access to Webhook Settings.
     */
    public function test_super_admin_only_access_to_webhook_settings(): void
    {
        $data = $this->setupBillingData();

        // Guest redirected to login
        $this->get('/settings/webhooks')->assertRedirect('/login');

        // Cashier user receives 403 Forbidden
        $this->actingAs($data['cashier'])
            ->get('/settings/webhooks')
            ->assertStatus(403);

        $this->actingAs($data['cashier'])
            ->post('/settings/webhooks/monthly', ['url' => 'https://example.com'])
            ->assertStatus(403);

        // Super Admin receives 200 OK
        $this->actingAs($data['superAdmin'])
            ->get('/settings/webhooks')
            ->assertStatus(200)
            ->assertSee('Monthly Invoice Webhook')
            ->assertSee('Paid Invoice Webhook');
    }

    /**
     * 12. Existing invoice PDF and review functionality remains intact.
     */
    public function test_existing_invoice_pdf_and_review_functionality(): void
    {
        $data = $this->setupBillingData();

        // Review page renders
        $this->actingAs($data['superAdmin'])
            ->get('/invoices')
            ->assertStatus(200)
            ->assertSee($data['invoice']->invoice_number);

        // Paid-only review filter renders
        $this->actingAs($data['superAdmin'])
            ->get('/invoices?paid_only=1')
            ->assertStatus(200)
            ->assertSee('Paid Invoices · Review & Outbound Delivery');

        // Standard PDF download route works
        $this->actingAs($data['superAdmin'])
            ->get("/invoices/{$data['invoice']->id}/pdf")
            ->assertStatus(200)
            ->assertHeader('content-type', 'application/pdf');
    }

    /**
     * 13. Monthly and Paid webhook settings save completely independently.
     */
    public function test_independent_webhook_settings_save_and_state(): void
    {
        $data = $this->setupBillingData();

        // 1. Save Monthly webhook
        $this->actingAs($data['superAdmin'])
            ->post('/settings/webhooks/monthly', [
                'url' => 'https://n8n.iqra.org/webhook/monthly-endpoint',
                'secret' => 'monthly-secret-123',
                'is_enabled' => '1',
            ])
            ->assertRedirect('/settings/webhooks')
            ->assertSessionHas('notice')
            ->assertSessionHas('saved_webhook_type', 'monthly');

        $monthlySetting = DB::table('webhook_settings')->where('webhook_type', 'monthly')->first();
        $paidSetting = DB::table('webhook_settings')->where('webhook_type', 'paid')->first();

        $this->assertSame('https://n8n.iqra.org/webhook/monthly-endpoint', $monthlySetting->url);
        $this->assertEquals(1, $monthlySetting->is_enabled);
        $this->assertNull($paidSetting->url);
        $this->assertEquals(0, $paidSetting->is_enabled);

        // 2. Save Paid webhook independently
        $this->actingAs($data['superAdmin'])
            ->post('/settings/webhooks/paid', [
                'url' => 'https://n8n.iqra.org/webhook-test/paid-receipts',
                'secret' => 'paid-secret-456',
                'is_enabled' => '0',
            ])
            ->assertRedirect('/settings/webhooks')
            ->assertSessionHas('notice')
            ->assertSessionHas('saved_webhook_type', 'paid');

        $monthlySettingAfter = DB::table('webhook_settings')->where('webhook_type', 'monthly')->first();
        $paidSettingAfter = DB::table('webhook_settings')->where('webhook_type', 'paid')->first();

        // Monthly is 100% untouched
        $this->assertSame('https://n8n.iqra.org/webhook/monthly-endpoint', $monthlySettingAfter->url);
        $this->assertEquals(1, $monthlySettingAfter->is_enabled);

        // Paid is updated
        $this->assertSame('https://n8n.iqra.org/webhook-test/paid-receipts', $paidSettingAfter->url);
        $this->assertEquals(0, $paidSettingAfter->is_enabled);

        // 3. Disable Monthly without affecting Paid URL
        $this->actingAs($data['superAdmin'])
            ->post('/settings/webhooks/monthly', [
                'url' => 'https://n8n.iqra.org/webhook/monthly-endpoint',
                'secret' => '', // blank to keep unchanged
                'is_enabled' => '0',
            ])
            ->assertRedirect('/settings/webhooks');

        $this->assertEquals(0, DB::table('webhook_settings')->where('webhook_type', 'monthly')->value('is_enabled'));
        $this->assertSame('https://n8n.iqra.org/webhook-test/paid-receipts', DB::table('webhook_settings')->where('webhook_type', 'paid')->value('url'));
    }

    /**
     * 14. URL validation accepts public HTTPS n8n URLs (/webhook/ & /webhook-test/) and rejects invalid / SSRF.
     */
    public function test_webhook_url_validation_accepts_valid_n8n_urls_and_rejects_invalid_with_endpoint_specific_errors(): void
    {
        $data = $this->setupBillingData();

        // 1. Accepts valid /webhook-test/ path and trims whitespace
        $this->actingAs($data['superAdmin'])
            ->post('/settings/webhooks/monthly', [
                'url' => '  https://flow.iqravirtualschool.com:5678/webhook-test/34827438-4384-3843-3848-384838483848?auth=token  ',
                'is_enabled' => '1',
            ])
            ->assertRedirect('/settings/webhooks')
            ->assertSessionHasNoErrors();

        $this->assertSame(
            'https://flow.iqravirtualschool.com:5678/webhook-test/34827438-4384-3843-3848-384838483848?auth=token',
            DB::table('webhook_settings')->where('webhook_type', 'monthly')->value('url')
        );

        // 2. Accepts valid /webhook/ path for Paid
        $this->actingAs($data['superAdmin'])
            ->post('/settings/webhooks/paid', [
                'url' => 'https://iqra.app.n8n.cloud/webhook/paid-invoices',
                'is_enabled' => '1',
            ])
            ->assertRedirect('/settings/webhooks')
            ->assertSessionHasNoErrors();

        $this->assertSame(
            'https://iqra.app.n8n.cloud/webhook/paid-invoices',
            DB::table('webhook_settings')->where('webhook_type', 'paid')->value('url')
        );

        // 3. Rejects missing scheme with specific Monthly message
        $responseMonthly = $this->actingAs($data['superAdmin'])
            ->from('/settings/webhooks')
            ->post('/settings/webhooks/monthly', [
                'url' => 'n8n.example.com/webhook/test',
            ]);

        $responseMonthly->assertRedirect('/settings/webhooks')
            ->assertSessionHasErrors(['url']);

        $errorsMonthly = session('errors')->get('url');
        $this->assertStringContainsString('The Monthly webhook URL must begin with https://', $errorsMonthly[0]);

        // 4. Rejects loopback / SSRF with specific Monthly message
        $responseSSRF = $this->actingAs($data['superAdmin'])
            ->from('/settings/webhooks')
            ->post('/settings/webhooks/monthly', [
                'url' => 'https://localhost:5678/webhook/test',
            ]);

        $responseSSRF->assertRedirect('/settings/webhooks')
            ->assertSessionHasErrors(['url']);

        $errorsSSRF = session('errors')->get('url');
        $this->assertStringContainsString('The Monthly webhook URL: Webhook URL cannot point to localhost or internal loopback.', $errorsSSRF[0]);

        // 5. Rejects private IP with specific Paid message
        $responsePrivateIP = $this->actingAs($data['superAdmin'])
            ->from('/settings/webhooks')
            ->post('/settings/webhooks/paid', [
                'url' => 'https://192.168.1.50:5678/webhook/paid',
            ]);

        $responsePrivateIP->assertRedirect('/settings/webhooks')
            ->assertSessionHasErrors(['url']);

        $errorsPrivateIP = session('errors')->get('url');
        $this->assertStringContainsString('The Paid webhook URL: Webhook URL cannot point to a private or reserved IP address.', $errorsPrivateIP[0]);
    }

    /**
     * 15. Validation failure preserves other card values without cross-population.
     */
    public function test_validation_failure_preserves_other_card_values_without_cross_population(): void
    {
        $data = $this->setupBillingData();

        // Pre-configure distinct database values
        DB::table('webhook_settings')->where('webhook_type', 'monthly')->update([
            'url' => 'https://n8n.iqra.org/webhook/monthly-saved',
            'is_enabled' => 1,
        ]);
        DB::table('webhook_settings')->where('webhook_type', 'paid')->update([
            'url' => 'https://n8n.iqra.org/webhook/paid-saved',
            'is_enabled' => 0,
        ]);

        // Submit invalid Monthly URL
        $response = $this->actingAs($data['superAdmin'])
            ->from('/settings/webhooks')
            ->post('/settings/webhooks/monthly', [
                'url' => 'invalid-monthly-url',
                'is_enabled' => '1',
            ]);

        $response->assertRedirect('/settings/webhooks')
            ->assertSessionHasErrors(['url']);

        // Follow redirect to GET /settings/webhooks
        $pageResponse = $this->actingAs($data['superAdmin'])
            ->get('/settings/webhooks');

        $pageResponse->assertStatus(200);

        // Monthly card shows the validation error under its field
        $pageResponse->assertSee('The Monthly webhook URL must begin with https://');

        // Paid card still displays its own saved database URL and is NOT cross-populated by invalid-monthly-url
        $pageResponse->assertSee('https://n8n.iqra.org/webhook/paid-saved');
        $pageResponse->assertDontSee('invalid-monthly-url" name="url" class="input" style="width:100%; box-sizing:border-box;', false);

        // Submit invalid Paid URL
        $responsePaid = $this->actingAs($data['superAdmin'])
            ->from('/settings/webhooks')
            ->post('/settings/webhooks/paid', [
                'url' => 'invalid-paid-url',
                'is_enabled' => '0',
            ]);

        $responsePaid->assertRedirect('/settings/webhooks')
            ->assertSessionHasErrors(['url']);

        $pageResponseAfterPaid = $this->actingAs($data['superAdmin'])
            ->get('/settings/webhooks');

        // Paid card shows error under its field
        $pageResponseAfterPaid->assertSee('The Paid webhook URL must begin with https://');

        // Monthly card still displays its own saved database URL
        $pageResponseAfterPaid->assertSee('https://n8n.iqra.org/webhook/monthly-saved');
    }

    /**
     * 16. Test Connection verifies external test endpoint without transmitting PII or financial data.
     */
    public function test_safe_test_connection_without_financial_or_student_pii(): void
    {
        $data = $this->setupBillingData();
        $testUrl = 'https://n8n.controlled-test.org/webhook-test/ping';

        DB::table('webhook_settings')->where('webhook_type', 'monthly')->update([
            'url' => $testUrl,
            'is_enabled' => 1,
        ]);

        Http::fake([
            $testUrl => Http::response(['status' => 'ok', 'message' => 'Ping received'], 200),
        ]);

        $this->actingAs($data['superAdmin'])
            ->post('/settings/webhooks/monthly/test')
            ->assertRedirect('/settings/webhooks')
            ->assertSessionHas('notice');

        // Verify request payload contained zero student or financial records
        Http::assertSent(function ($request) use ($testUrl) {
            $data = $request->data();
            return $request->url() === $testUrl
                && $data['event'] === 'test.connection'
                && $data['source_system'] === 'IQRA Virtual School'
                && !isset($data['invoice'])
                && !isset($data['students'])
                && !isset($data['family'])
                && !isset($data['total_amount'])
                && !isset($data['amount_due']);
        });

        // Verify Monthly test results updated while Paid test results remain null/untouched
        $monthlySetting = DB::table('webhook_settings')->where('webhook_type', 'monthly')->first();
        $paidSetting = DB::table('webhook_settings')->where('webhook_type', 'paid')->first();

        $this->assertSame('success', $monthlySetting->last_test_status);
        $this->assertNotNull($monthlySetting->last_test_at);
        $this->assertNull($paidSetting->last_test_status);
    }

    /**
     * 17. Regression: Saving Monthly and Paid webhook settings routes with n8n Test and Production URLs never throws HTTP 500.
     */
    public function test_webhook_settings_save_routes_with_n8n_test_and_production_urls_never_throws_500(): void
    {
        $data = $this->setupBillingData();

        // 1. Save Monthly with /webhook-test/ URL
        $resMonthlyTest = $this->actingAs($data['superAdmin'])
            ->post('/settings/webhooks/monthly', [
                'url' => 'https://n8n.example.com/webhook-test/testing-monthly',
                'secret' => 'secret-monthly-test',
                'is_enabled' => '1',
            ]);

        $resMonthlyTest->assertStatus(302);
        $resMonthlyTest->assertRedirect('/settings/webhooks');
        $resMonthlyTest->assertSessionHas('notice');
        $this->assertSame(
            'https://n8n.example.com/webhook-test/testing-monthly',
            DB::table('webhook_settings')->where('webhook_type', 'monthly')->value('url')
        );

        // 2. Save Monthly with /webhook/ production URL
        $resMonthlyProd = $this->actingAs($data['superAdmin'])
            ->post('/settings/webhooks/monthly', [
                'url' => 'https://n8n.example.com/webhook/testing-monthly',
                'secret' => '', // keep unchanged
                'is_enabled' => '1',
            ]);

        $resMonthlyProd->assertStatus(302);
        $resMonthlyProd->assertRedirect('/settings/webhooks');
        $resMonthlyProd->assertSessionHas('notice');
        $this->assertSame(
            'https://n8n.example.com/webhook/testing-monthly',
            DB::table('webhook_settings')->where('webhook_type', 'monthly')->value('url')
        );

        // 3. Save Paid with /webhook-test/ URL
        $resPaidTest = $this->actingAs($data['superAdmin'])
            ->post('/settings/webhooks/paid', [
                'url' => 'https://n8n.example.com/webhook-test/testing-paid',
                'secret' => 'secret-paid-test',
                'is_enabled' => '1',
            ]);

        $resPaidTest->assertStatus(302);
        $resPaidTest->assertRedirect('/settings/webhooks');
        $resPaidTest->assertSessionHas('notice');
        $this->assertSame(
            'https://n8n.example.com/webhook-test/testing-paid',
            DB::table('webhook_settings')->where('webhook_type', 'paid')->value('url')
        );

        // 4. Save Paid with /webhook/ production URL
        $resPaidProd = $this->actingAs($data['superAdmin'])
            ->post('/settings/webhooks/paid', [
                'url' => 'https://n8n.example.com/webhook/testing-paid',
                'secret' => '', // keep unchanged
                'is_enabled' => '0',
            ]);

        $resPaidProd->assertStatus(302);
        $resPaidProd->assertRedirect('/settings/webhooks');
        $resPaidProd->assertSessionHas('notice');
        $this->assertSame(
            'https://n8n.example.com/webhook/testing-paid',
            DB::table('webhook_settings')->where('webhook_type', 'paid')->value('url')
        );

        // Monthly remains intact from previous step
        $this->assertSame(
            'https://n8n.example.com/webhook/testing-monthly',
            DB::table('webhook_settings')->where('webhook_type', 'monthly')->value('url')
        );

        // 5. Invalid URL returns validation redirect with errors, NEVER a 500 or preg_match error
        $resInvalid = $this->actingAs($data['superAdmin'])
            ->post('/settings/webhooks/monthly', [
                'url' => 'invalid-scheme.com/webhook-test/testing-monthly',
            ]);

        $resInvalid->assertStatus(302);
        $resInvalid->assertRedirect('/settings/webhooks');
        $resInvalid->assertSessionHasErrors(['url']);
    }

    /**
     * 18. Contact eligibility: distinguishes missing, unverified, and consent absent failure reasons.
     */
    public function test_contact_eligibility_scenarios_and_specific_diagnostic_failure_reasons(): void
    {
        $data = $this->setupBillingData();
        $this->configureWebhooks();

        // A. Contact present but NOT verified
        DB::table('families')->where('id', $data['family'])->update([
            'whatsapp' => '+966500000001',
            'contact_verified' => false,
            'messaging_opt_in' => false,
        ]);

        $this->actingAs($data['superAdmin'])
            ->post('/invoices/send', [
                'invoice_ids' => [$data['invoice']->id],
                'channels' => ['whatsapp'],
                'confirmed_grades' => ['Grade 1'],
                'review_confirmed' => '1',
                'webhook_type' => 'monthly',
            ]);

        $notif1 = DB::table('notifications')->where('invoice_id', $data['invoice']->id)->first();
        $this->assertNotNull($notif1);
        $this->assertSame('blocked', $notif1->status);
        $this->assertSame('Unverified contact', $notif1->error);
        $this->assertStringNotContainsString('WhatsApp missing', $notif1->error);

        // B. Contact verified, but WhatsApp consent ABSENT
        DB::table('notifications')->truncate();
        DB::table('families')->where('id', $data['family'])->update([
            'contact_verified' => true,
            'messaging_opt_in' => false,
        ]);

        $this->actingAs($data['superAdmin'])
            ->post('/invoices/send', [
                'invoice_ids' => [$data['invoice']->id],
                'channels' => ['whatsapp'],
                'confirmed_grades' => ['Grade 1'],
                'review_confirmed' => '1',
                'webhook_type' => 'monthly',
            ]);

        $notif2 = DB::table('notifications')->where('invoice_id', $data['invoice']->id)->first();
        $this->assertNotNull($notif2);
        $this->assertSame('blocked', $notif2->status);
        $this->assertStringContainsString('Consent not recorded', $notif2->error);
        $this->assertStringContainsString('WhatsApp permission absent', $notif2->error);

        // C. Contact missing altogether
        DB::table('notifications')->truncate();
        DB::table('families')->where('id', $data['family'])->update([
            'whatsapp' => null,
            'contact_verified' => false,
            'messaging_opt_in' => false,
        ]);

        $this->actingAs($data['superAdmin'])
            ->post('/invoices/send', [
                'invoice_ids' => [$data['invoice']->id],
                'channels' => ['whatsapp'],
                'confirmed_grades' => ['Grade 1'],
                'review_confirmed' => '1',
                'webhook_type' => 'monthly',
            ]);

        $notif3 = DB::table('notifications')->where('invoice_id', $data['invoice']->id)->first();
        $this->assertNotNull($notif3);
        $this->assertSame('blocked', $notif3->status);
        $this->assertSame('WhatsApp missing', $notif3->error);

        // D. Verified contact with genuine consent -> Queued and submitted to webhook
        DB::table('notifications')->truncate();
        DB::table('families')->where('id', $data['family'])->update([
            'whatsapp' => '+966500000001',
            'contact_verified' => true,
            'messaging_opt_in' => true,
        ]);

        Http::fake([
            'https://n8n.example.com/*' => Http::response(['ok' => true, 'message_id' => 'msg-999'], 200),
        ]);

        Queue::fake([DispatchInvoiceToN8n::class]);

        $this->actingAs($data['superAdmin'])
            ->post('/invoices/send', [
                'invoice_ids' => [$data['invoice']->id],
                'channels' => ['whatsapp'],
                'confirmed_grades' => ['Grade 1'],
                'review_confirmed' => '1',
                'webhook_type' => 'monthly',
            ]);

        $notif4 = DB::table('notifications')->where('invoice_id', $data['invoice']->id)->first();
        $this->assertNotNull($notif4);
        $this->assertSame('queued', $notif4->status);
        Queue::assertPushed(DispatchInvoiceToN8n::class);

        // Execute queued job manually
        (new DispatchInvoiceToN8n($notif4->id))->handle();

        $notif4Submitted = DB::table('notifications')->where('id', $notif4->id)->first();
        $this->assertSame('submitted', $notif4Submitted->status);
        $this->assertNotSame('delivered', $notif4Submitted->status); // Submitted != delivered!
        $this->assertSame('msg-999', $notif4Submitted->provider_message_id);
    }

    /**
     * 19. Staff verification workflow, reverification policy on phone change, and queue worker execution.
     */
    public function test_staff_verification_workflow_reverification_policy_and_queue_processing(): void
    {
        $data = $this->setupBillingData();
        $this->configureWebhooks();

        // 1. Staff records contact verification and opt-in via POST /families/{id}/contact
        $this->actingAs($data['superAdmin'])
            ->post("/families/{$data['family']}/contact", [
                'guardian_name' => 'Guardian Verified',
                'email' => 'verified@example.test',
                'whatsapp' => '+966500000001',
                'contact_verified' => '1',
                'messaging_opt_in' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('notice');

        $family = DB::table('families')->where('id', $data['family'])->first();
        $this->assertTrue((bool)$family->contact_verified);
        $this->assertTrue((bool)$family->messaging_opt_in);

        // Audit log created with staff id
        $this->assertTrue(DB::table('audit_logs')
            ->where('actor_id', $data['superAdmin']->id)
            ->where('action', 'family_contact_updated')
            ->where('subject_id', $data['family'])
            ->exists());

        // 2. Reverification policy: changing phone number resets verification unless explicitly confirmed
        $this->actingAs($data['superAdmin'])
            ->post("/families/{$data['family']}/contact", [
                'guardian_name' => 'Guardian Verified',
                'email' => 'verified@example.test',
                'whatsapp' => '+966500000002', // changed phone
                // contact_verified not sent -> resets!
            ])
            ->assertRedirect();

        $familyUpdated = DB::table('families')->where('id', $data['family'])->first();
        $this->assertSame('+966500000002', $familyUpdated->whatsapp);
        $this->assertFalse((bool)$familyUpdated->contact_verified);
        $this->assertFalse((bool)$familyUpdated->messaging_opt_in);

        // 3. Process queue endpoint executes queued jobs safely
        $this->actingAs($data['superAdmin'])
            ->post('/invoices/notifications/process-queue')
            ->assertRedirect()
            ->assertSessionHas('notice');
    }

    /**
     * 20. Super Admin preview and bulk execution of MySkool WhatsApp authorization carry-forward.
     */
    public function test_myskool_whatsapp_authorizations_carry_forward_preview_and_execution(): void
    {
        $data = $this->setupBillingData();
        $myskoolImportDate = '2026-10-23 11:12:21';

        // Set up test families:
        // A. Valid MySkool family with valid WhatsApp
        $famValid = DB::table('families')->insertGetId([
            'family_code' => 'FAM-MS-001',
            'guardian_name' => 'Guardian MS 1',
            'email' => 'ms1@example.test',
            'whatsapp' => '+966511111111',
            'contact_verified' => false,
            'whatsapp_verified' => false,
            'email_verified' => false,
            'messaging_opt_in' => false,
            'created_at' => $myskoolImportDate,
            'updated_at' => $myskoolImportDate,
        ]);

        // B. MySkool family with missing WhatsApp
        $famMissing = DB::table('families')->insertGetId([
            'family_code' => 'FAM-MS-002',
            'guardian_name' => 'Guardian MS 2',
            'email' => 'ms2@example.test',
            'whatsapp' => null,
            'contact_verified' => false,
            'whatsapp_verified' => false,
            'email_verified' => false,
            'messaging_opt_in' => false,
            'created_at' => $myskoolImportDate,
            'updated_at' => $myskoolImportDate,
        ]);

        // C. MySkool family with invalid WhatsApp format (missing +)
        $famInvalid = DB::table('families')->insertGetId([
            'family_code' => 'FAM-MS-003',
            'guardian_name' => 'Guardian MS 3',
            'email' => 'ms3@example.test',
            'whatsapp' => '966533333333',
            'contact_verified' => false,
            'whatsapp_verified' => false,
            'email_verified' => false,
            'messaging_opt_in' => false,
            'created_at' => $myskoolImportDate,
            'updated_at' => $myskoolImportDate,
        ]);

        // D. Manual test family / post-import admission (created later)
        $famNew = DB::table('families')->insertGetId([
            'family_code' => 'FAM-NEW-004',
            'guardian_name' => 'Guardian New 4',
            'email' => 'new4@example.test',
            'whatsapp' => '+966544444444',
            'contact_verified' => false,
            'whatsapp_verified' => false,
            'email_verified' => false,
            'messaging_opt_in' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(MySkoolAuthorizationCarryForwardService::class);

        // 1. Preview checks
        $preview = $service->preview();
        $this->assertGreaterThanOrEqual(1, $preview['eligible_to_authorize_count']);
        $this->assertContains($famValid, $preview['eligible_ids']);
        $this->assertNotContains($famMissing, $preview['eligible_ids']);
        $this->assertNotContains($famInvalid, $preview['eligible_ids']);
        $this->assertNotContains($famNew, $preview['eligible_ids']);
        $this->assertGreaterThanOrEqual(1, $preview['excluded_counts']['missing_whatsapp']);
        $this->assertGreaterThanOrEqual(1, $preview['excluded_counts']['invalid_whatsapp']);
        $this->assertGreaterThanOrEqual(1, $preview['excluded_counts']['test_or_new_admission']);

        // 2. Execution rejected without explicit confirmation
        $this->actingAs($data['superAdmin'])
            ->post('/settings/webhooks/carry-forward', [])
            ->assertSessionHasErrors('carry_forward');

        // 3. Execution succeeded with confirmation
        $this->actingAs($data['superAdmin'])
            ->post('/settings/webhooks/carry-forward', [
                'confirmation' => 'CONFIRM_AUTHORIZATION',
                'confirm_carry_forward' => '1',
            ])
            ->assertRedirect('/settings/webhooks')
            ->assertSessionHas('notice');

        // Verify valid MySkool family was authorized
        $updatedValid = DB::table('families')->where('id', $famValid)->first();
        $this->assertTrue((bool)$updatedValid->contact_verified);
        $this->assertTrue((bool)$updatedValid->whatsapp_verified);
        $this->assertTrue((bool)$updatedValid->messaging_opt_in);
        $this->assertSame('+966511111111', $updatedValid->whatsapp); // contact preserved!
        $this->assertSame('ms1@example.test', $updatedValid->email); // email preserved!

        // Verify excluded families remain unverified
        $updatedMissing = DB::table('families')->where('id', $famMissing)->first();
        $this->assertFalse((bool)$updatedMissing->contact_verified);

        $updatedInvalid = DB::table('families')->where('id', $famInvalid)->first();
        $this->assertFalse((bool)$updatedInvalid->contact_verified);
        $this->assertFalse((bool)$updatedInvalid->whatsapp_verified);

        $updatedNew = DB::table('families')->where('id', $famNew)->first();
        $this->assertFalse((bool)$updatedNew->contact_verified);
        $this->assertFalse((bool)$updatedNew->whatsapp_verified);

        // 4. Audit log entry recorded with actor, timestamp, source, counts
        $audit = DB::table('audit_logs')
            ->where('action', 'myskool_whatsapp_authorizations_carried_forward')
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame($data['superAdmin']->id, $audit->actor_id);
        $meta = json_decode($audit->metadata, true);
        $this->assertSame('Previous MySkool verification and messaging consent', $meta['source']);
        $this->assertGreaterThanOrEqual(1, $meta['authorized_count']);
        $this->assertArrayHasKey('excluded_reasons', $meta);

        // 5. Artisan command dry-run and execution
        $this->artisan('billing:carry-forward-myskool-authorizations --dry-run')
            ->assertExitCode(0);
    }

    /**
     * 21. Carrying forward WhatsApp authorization does NOT falsely grant verification to email.
     */
    public function test_carrying_forward_whatsapp_does_not_verify_unrelated_channels_like_email(): void
    {
        $data = $this->setupBillingData();
        $myskoolImportDate = '2026-10-23 11:12:21';

        $familyId = DB::table('families')->insertGetId([
            'family_code' => 'FAM-MS-EMAIL-TEST',
            'guardian_name' => 'Guardian Email Test',
            'email' => 'parent@example.test',
            'whatsapp' => '+966599999999',
            'contact_verified' => false,
            'whatsapp_verified' => false,
            'email_verified' => false,
            'messaging_opt_in' => false,
            'created_at' => $myskoolImportDate,
            'updated_at' => $myskoolImportDate,
        ]);

        $service = app(MySkoolAuthorizationCarryForwardService::class);
        $service->execute($data['superAdmin']->id, 'Testing channel verification separation');

        $family = DB::table('families')->where('id', $familyId)->first();
        $this->assertTrue((bool)$family->whatsapp_verified);
        $this->assertTrue((bool)$family->messaging_opt_in);
        $this->assertFalse((bool)$family->email_verified); // EMAIL MUST REMAIN UNVERIFIED!

        // Eligible for WhatsApp
        $whatsappEligibility = WebhookSettingService::evaluateContactEligibility($family, ['whatsapp']);
        $this->assertNull($whatsappEligibility, 'WhatsApp should be eligible for delivery');

        // NOT eligible for Email
        $emailEligibility = WebhookSettingService::evaluateContactEligibility($family, ['email']);
        $this->assertSame('Unverified contact', $emailEligibility, 'Email must not be falsely verified by WhatsApp authorization');
    }
}

