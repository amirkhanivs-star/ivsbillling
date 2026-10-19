<?php

namespace App\Console\Commands;

use App\Exceptions\DatabaseSafetyException;
use App\Services\Staging\DatabaseSafetyGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class StagingRemediateCredentialsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'staging:remediate-credentials
                            {--database=staging : Target database connection (must be iqra_billing_clean)}
                            {--authorized-by=Mustafa <mustafa@gmail.com> (super_admin) : Business administrator who authorized policy}
                            {--executor= : Technical executor context (defaults to current system CLI user)}
                            {--dry-run : Simulate remediation without persisting changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Safely isolate staging credentials by neutralizing live production password hashes in iqra_billing_clean';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $connection = (string) $this->option('database');
        $authorizedBy = (string) $this->option('authorized-by');
        $dryRun = (bool) $this->option('dry-run');

        $currentUser = get_current_user() ?: 'cli_user';
        $hostname = gethostname() ?: 'localhost';
        $executor = (string) ($this->option('executor') ?: "{$currentUser}@{$hostname} (CLI)");

        $this->info("===================================================================");
        $this->info(" IQRA Billing: Staging Credential Isolation Remediation (Phase 3B.1)");
        $this->info("===================================================================");

        // 1. Safety Guard: Enforce clean staging database
        try {
            DatabaseSafetyGuard::assertStagingDatabase($connection);
            $activeDb = DatabaseSafetyGuard::getActiveDatabaseName($connection);
            $this->info(" [SECURITY] Target database verified: {$activeDb} (Clean database confirmed)");
        } catch (DatabaseSafetyException $e) {
            $this->error(" [SECURITY BREACH] " . $e->getMessage());
            return Command::FAILURE;
        }

        $c = DB::connection($connection);

        // 2. Verify Quarantined Database is 100% untouched
        if ($c->getDriverName() !== 'sqlite') {
            $quarantineConn = DB::connection('mysql');
            $qStudents = $quarantineConn->table('students')->count();
            $qInvoices = $quarantineConn->table('invoices')->count();
            $qPayments = $quarantineConn->table('payments')->count();
            if ($qStudents !== 4349 || $qInvoices !== 8558 || $qPayments !== 31086) {
                $this->error(" [SECURITY ALERT] Quarantined database iqra_billing counts do not match original baseline!");
                return Command::FAILURE;
            }
            $this->info(" [SECURITY] Quarantined database iqra_billing verified untouched");
        }

        // 3. Inspect Staging Users
        $users = $c->table('users')->get();
        if ($users->isEmpty()) {
            $this->warn(" [NOTICE] No user accounts found in {$activeDb}.users. Nothing to remediate.");
            return Command::SUCCESS;
        }

        $this->info(" Found {$users->count()} account(s) in staging database:");
        $exposedCount = 0;
        $remediatedUsers = [];

        foreach ($users as $user) {
            $isBcrypt = str_starts_with((string)$user->password, '$2y$') || str_starts_with((string)$user->password, '$2a$');
            $isLocked = str_starts_with((string)$user->password, '!LOCKED_STAGING_');

            $status = $isLocked ? 'ISOLATED' : ($isBcrypt ? 'LIVE_HASH_EXPOSED' : 'UNKNOWN');
            $this->line("   - User ID: {$user->id} | {$user->name} <{$user->email}> | Role: {$user->role} | Status: {$status}");

            if (!$isLocked) {
                $exposedCount++;
                $remediatedUsers[] = $user;
            }
        }

        if ($exposedCount === 0) {
            $this->info("\n [ALL SECURED] All staging accounts are already credential-isolated. Zero live hashes exposed.");
            return Command::SUCCESS;
        }

        $this->warn("\n [RISK IDENTIFIED] {$exposedCount} account(s) contain live password hashes in staging.");

        if ($dryRun) {
            $this->warn(" [DRY-RUN] Simulation only. {$exposedCount} account(s) would be neutralized and logged. No changes persisted.");
            return Command::SUCCESS;
        }

        // 4. Execute Remediation inside Transaction
        $c->beginTransaction();
        try {
            $now = now();
            foreach ($remediatedUsers as $user) {
                // Generate a non-computable, locked staging token placeholder
                $lockedPlaceholder = '!LOCKED_STAGING_ISOLATED_' . substr(hash('sha256', "staging_lock_{$user->id}_{$user->email}"), 0, 32);

                // Neutralize password hash in staging users table
                $c->table('users')->where('id', $user->id)->update([
                    'password' => $lockedPlaceholder,
                    'remember_token' => null,
                    'updated_at' => $now,
                ]);

                // Record append-only audit trail in staging_approval_logs
                $c->table('staging_approval_logs')->insert([
                    'entity_type' => 'USER',
                    'entity_id' => $user->id,
                    'gr_number' => null,
                    'action' => 'CREDENTIAL_ISOLATION_REMEDIATED',
                    'old_value' => json_encode([
                        'user_id' => $user->id,
                        'email' => $user->email,
                        'credential_status' => 'LIVE_BCRYPT_EXPOSED',
                        'hash_redacted' => true,
                    ]),
                    'new_value' => json_encode([
                        'user_id' => $user->id,
                        'email' => $user->email,
                        'credential_status' => 'ISOLATED_LOCKED_STAGING',
                        'placeholder_prefix' => '!LOCKED_STAGING_ISOLATED_',
                    ]),
                    'performed_by' => "authorized_by: {$authorizedBy} | executed_by: {$executor}",
                    'reason' => 'Phase 3B.1 Task 5: Neutralize live production bcrypt hash in staging database to enforce strict credential isolation',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $this->info("   [REMEDIATED] User ID {$user->id} ({$user->email}) neutralized and logged to staging_approval_logs.");
            }

            $c->commit();
            $this->info("\n [COMPLETED] Staging credential isolation successfully executed. Live credentials eradicated from staging.");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $c->rollBack();
            $this->error(" [FAILED] Remediation rolled back due to error: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
