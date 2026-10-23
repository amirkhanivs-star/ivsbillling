<?php

namespace App\Console\Commands;

use App\Exceptions\DatabaseSafetyException;
use App\Services\Staging\DatabaseSafetyGuard;
use App\Services\Staging\StudentIdentityMappingService;
use Illuminate\Console\Command;

class StagingProcessMappingsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'staging:process-mappings
                            {--database=staging : Database connection to use (must be iqra_billing_clean)}
                            {--chunk-size=500 : Batch size for database writes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process student identity, sibling groups, campus/department proposals, and historical GR reconciliation';

    /**
     * Execute the console command.
     */
    public function handle(StudentIdentityMappingService $service): int
    {
        $connection = (string) $this->option('database');
        $chunkSize = (int) $this->option('chunk-size');

        $this->info("===================================================================");
        $this->info(" IQRA Billing: Phase 3A Mapping & Reconciliation Engine");
        $this->info("===================================================================");

        try {
            DatabaseSafetyGuard::assertTargetDatabase($connection);
            $activeDb = DatabaseSafetyGuard::getActiveDatabaseName($connection);
            $this->info(" [SECURITY] Target database verified: {$activeDb} (Isolation confirmed)");
        } catch (DatabaseSafetyException $e) {
            $this->error(" [SECURITY BREACH] " . $e->getMessage());
            return Command::FAILURE;
        }

        // 1. Process Student Identities & Families
        $this->info("\n--- 1. Processing Student Identities & Family Groupings ---");
        $idStats = $service->processStudentIdentities($connection, $chunkSize);
        $this->line(" Total Students: {$idStats['total_students']}");
        $this->line(" Verified Family Mappings: {$idStats['verified_family_mappings']}");
        $this->line(" Provisional Family Mappings (Missing Family Code): {$idStats['provisional_family_mappings']}");
        $this->line(" Sibling Families: {$idStats['sibling_families_count']} (Encompassing {$idStats['sibling_students_count']} students)");
        $this->line(" Multi-Currency Sibling Families: {$idStats['multi_currency_families_count']}");

        // 2. Process Campus Proposals
        $this->info("\n--- 2. Processing Campus Assignment Proposals ---");
        $campusStats = $service->processCampusProposals($connection, $chunkSize);
        $this->line(" Total Campus Proposals: {$campusStats['total_proposals']}");
        $this->line(" Pending Administrative Approval: {$campusStats['pending_approval']}");
        $this->line(" Automatically Approved: {$campusStats['approved']} (Zero auto-approved)");

        // 3. Process Department Proposals
        $this->info("\n--- 3. Processing Department Classification Proposals ---");
        $deptStats = $service->processDepartmentProposals($connection, $chunkSize);
        $this->line(" Total Department Proposals: {$deptStats['total_proposals']}");
        $this->line("   - School: {$deptStats['school_proposed']}");
        $this->line("   - Tuition: {$deptStats['tuition_proposed']}");
        $this->line(" Pending Administrative Approval: {$deptStats['pending_approval']} (Zero auto-approved)");

        // 4. Process Historical GR Reconciliations
        $this->info("\n--- 4. Processing Historical GR Reconciliation ---");
        $reconStats = $service->processHistoricalGrReconciliations($connection, $chunkSize);
        $this->line(" Unknown Dues Entries: {$reconStats['unknown_dues_entries']} (Exact Name Matches: {$reconStats['dues_suggested_matches']})");
        $this->line(" Unknown Payment Entries: {$reconStats['unknown_payment_entries']} (Exact Name Matches: {$reconStats['payment_suggested_matches']})");
        $this->line(" Unlinked Payment Entries (Blank GR): {$reconStats['unlinked_payment_entries']}");
        $this->line(" Total Staged Reconciliation Records: {$reconStats['total_reconciliation_records']}");

        $this->info("\n===================================================================");
        $this->info(" [SUCCESS] Phase 3A Mapping & Reconciliation Tables Generated");
        $this->info("===================================================================\n");

        return Command::SUCCESS;
    }
}
