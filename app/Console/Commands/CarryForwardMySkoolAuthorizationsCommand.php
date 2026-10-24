<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\MySkoolAuthorizationCarryForwardService;
use Illuminate\Console\Command;

class CarryForwardMySkoolAuthorizationsCommand extends Command
{
    protected $signature = 'billing:carry-forward-myskool-authorizations
                            {--dry-run : Preview eligible and excluded counts without modifying the database}
                            {--confirm : Explicitly confirm execution of the bulk authorization}
                            {--admin-id= : ID of the administrator authorizing the action (defaults to first Super Admin)}';

    protected $description = 'Carry forward pre-existing MySkool verified WhatsApp contacts and messaging consent for imported families';

    public function handle(MySkoolAuthorizationCarryForwardService $service): int
    {
        $this->info('=== MySkool WhatsApp Authorizations Carry-Forward ===');
        $this->line('School Policy: Parents previously verified and agreed to receive school fee notices in MySkool.');

        $preview = $service->preview();

        $this->newLine();
        $this->table(
            ['Metric', 'Count', 'Notes'],
            [
                ['Total Families in System', $preview['total_families'], 'All family records'],
                ['Eligible MySkool Families to Authorize', $preview['eligible_to_authorize_count'], 'Valid WhatsApp, MySkool import dataset, no opt-out'],
                ['Already Authorized Families', $preview['already_authorized_count'], 'Previously verified in portal'],
                ['Total Eligible MySkool Families', $preview['total_eligible_count'], '100% of valid MySkool parents'],
                ['Excluded: Missing WhatsApp', $preview['excluded_counts']['missing_whatsapp'], 'No phone number in record'],
                ['Excluded: Invalid Phone Format', $preview['excluded_counts']['invalid_whatsapp'], 'Missing international prefix or malformed digits'],
                ['Excluded: Manual / New Admission', $preview['excluded_counts']['test_or_new_admission'], 'Created outside MySkool import batch'],
                ['Excluded: Consent Withdrawn', $preview['excluded_counts']['consent_withdrawn'], 'Explicit opt-out recorded in audit log'],
                ['Total Excluded Families', $preview['excluded_counts']['total'], 'Will require individual staff verification'],
            ]
        );

        $this->newLine();
        $this->info('=== Projected Invoice Delivery Impact ===');
        $this->table(
            ['Metric', 'Before Carry-Forward', 'Projected After Carry-Forward'],
            [
                ['Eligible Invoices (WhatsApp)', $preview['invoice_impact']['before_eligible'], $preview['invoice_impact']['after_eligible']],
                ['Blocked Invoices (WhatsApp)', $preview['invoice_impact']['before_blocked'], $preview['invoice_impact']['after_blocked']],
            ]
        );

        if ($this->option('dry-run') || !$this->option('confirm')) {
            $this->newLine();
            if ($this->option('dry-run')) {
                $this->comment('DRY RUN completed. Database was NOT modified.');
            } else {
                $this->warn('Confirmation flag (--confirm) was not provided. Run with --confirm to execute.');
            }
            return 0;
        }

        $adminId = (int)$this->option('admin-id');
        if (!$adminId) {
            $adminUser = User::where('role', 'super_admin')->first();
            $adminId = $adminUser ? $adminUser->id : 1;
        }

        $this->info("Executing bulk authorization under Admin User ID #{$adminId}...");
        $result = $service->execute($adminId, 'Executed via artisan billing:carry-forward-myskool-authorizations --confirm');

        $this->newLine();
        $this->info('✅ Bulk Authorization Completed Successfully!');
        $this->line("Families newly authorized: {$result['authorized_count']}");
        $this->line("Eligible invoices now ready: {$result['invoice_impact']['after_eligible']}");
        $this->line("Remaining blocked invoices: {$result['invoice_impact']['remaining_blocked']}");
        $this->line("Audit Log timestamp: {$result['timestamp']}");

        return 0;
    }
}
