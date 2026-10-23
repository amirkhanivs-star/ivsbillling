<?php

namespace App\Console\Commands;

use App\Exceptions\DatabaseSafetyException;
use App\Services\Staging\DatabaseSafetyGuard;
use App\Services\Staging\StudentIdentityMappingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class StagingApproveCampusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'staging:approve-campus
                            {--database=staging : Target database connection (must be iqra_billing_clean)}
                            {--campus=main : Target campus code}
                            {--approver=Administrator : Name or title of the approving administrator}
                            {--notes= : Audit remarks for the approval}
                            {--dry-run : Simulate approval without persisting changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Perform controlled administrative bulk approval of student campus assignments';

    /**
     * Execute the console command.
     */
    public function handle(StudentIdentityMappingService $service): int
    {
        $connection = (string) $this->option('database');
        $campus = (string) $this->option('campus');
        $approver = (string) $this->option('approver');
        $notes = (string) ($this->option('notes') ?: 'Phase 3A.1 explicit administrative approval for Main Campus');
        $dryRun = (bool) $this->option('dry-run');

        $this->info("===================================================================");
        $this->info(" IQRA Billing: Controlled Campus Bulk Approval (Phase 3A.1)");
        $this->info("===================================================================");

        try {
            DatabaseSafetyGuard::assertTargetDatabase($connection);
            $activeDb = DatabaseSafetyGuard::getActiveDatabaseName($connection);
            $this->info(" [SECURITY] Target database verified: {$activeDb} (Isolation confirmed)");
        } catch (DatabaseSafetyException $e) {
            $this->error(" [SECURITY BREACH] " . $e->getMessage());
            return Command::FAILURE;
        }

        $c = DB::connection($connection);

        // 1. Verify that campus exists in campus master
        $campusRecord = $c->table('campuses')
            ->where('code', $campus)
            ->orWhere('name', $campus)
            ->first();

        if (!$campusRecord) {
            $this->error(" [ERROR] Campus '{$campus}' does not exist in the campuses master table.");
            return Command::FAILURE;
        }
        $this->info(" [VERIFIED] Target campus: {$campusRecord->name} (Code: {$campusRecord->code}, ID: {$campusRecord->id})");

        // 2. Generate preview of proposed assignments
        $totalProposals = $c->table('staging_campus_assignments')->count();
        $pendingProposals = $c->table('staging_campus_assignments')->where('status', 'PENDING')->count();
        $alreadyApproved = $c->table('staging_campus_assignments')->where('status', 'APPROVED')->count();
        $distinctGrs = $c->table('staging_campus_assignments')->distinct()->count('gr_number');
        $totalStagingStudents = $c->table('staging_students')->count();

        $this->info("\n--- PREVIEW OF ASSIGNMENTS ---");
        $this->line(" Total Campus Assignment Records: {$totalProposals}");
        $this->line(" Distinct GR Numbers in Assignments: {$distinctGrs}");
        $this->line(" Total Active Staging Students: {$totalStagingStudents}");
        $this->line(" Currently Pending: {$pendingProposals}");
        $this->line(" Already Approved: {$alreadyApproved}");

        // 3. Confirm that no source student is duplicated or omitted
        if ($totalProposals !== $totalStagingStudents || $distinctGrs !== $totalStagingStudents) {
            $this->error(" [VALIDATION ERROR] Student count discrepancy detected! Proposals={$totalProposals}, Students={$totalStagingStudents}, Distinct GRs={$distinctGrs}");
            return Command::FAILURE;
        }
        $this->info(" [VERIFIED] 100% of staging students accounted for. Zero duplicates, zero omissions.");

        if ($pendingProposals === 0) {
            $this->warn(" [IDEMPOTENT] No pending campus assignments to approve. All {$alreadyApproved} records are already approved.");
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $this->warn("\n [DRY-RUN] Simulation complete. {$pendingProposals} assignments would be approved as '{$campusRecord->code}'. No changes saved.");
            return Command::SUCCESS;
        }

        // 4 & 5. Execute controlled bulk approval
        $this->info("\n--- EXECUTING CONTROLLED BULK APPROVAL ---");
        $approvedCount = $service->approveCampusBulk(
            $connection,
            [], // All pending
            $campusRecord->code,
            $approver,
            $notes
        );

        // 6. Verify result
        $postApproved = $c->table('staging_campus_assignments')->where('status', 'APPROVED')->where('approved_campus', $campusRecord->code)->count();
        $postPending = $c->table('staging_campus_assignments')->where('status', 'PENDING')->count();
        $postStudentsApproved = $c->table('staging_students')->where('campus_approved', $campusRecord->code)->count();

        $this->info(" Successfully approved: {$approvedCount} assignments");
        $this->line(" Post-Approval Campus Status in staging_campus_assignments:");
        $this->line("   - Approved: {$postApproved}");
        $this->line("   - Pending:  {$postPending}");
        $this->line(" Synchronized in staging_students (campus_approved): {$postStudentsApproved}");

        // 7. Verify audit log entry
        $auditLog = $c->table('staging_approval_logs')
            ->where('entity_type', 'campus')
            ->where('action', 'CAMPUS_BULK_APPROVAL')
            ->latest('id')
            ->first();

        if ($auditLog) {
            $this->info(" [AUDIT TRAIL] Recorded Log ID #{$auditLog->id} by '{$auditLog->performed_by}' at {$auditLog->created_at}");
        }

        return Command::SUCCESS;
    }
}
