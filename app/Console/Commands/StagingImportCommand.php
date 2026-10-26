<?php

namespace App\Console\Commands;

use App\Exceptions\DatabaseSafetyException;
use App\Services\Staging\DatabaseSafetyGuard;
use App\Services\Staging\StagingImportService;
use Illuminate\Console\Command;

class StagingImportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'staging:import
                            {--zip= : Path to staging ZIP file}
                            {--database=mysql : Database connection to use (must be iqra_billing)}
                            {--dry-run : Validate and parse without persisting to database}
                            {--chunk-size=500 : Bulk insert chunk size}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ingest verified Google Sheets staging data into isolated staging tables in iqra_billing';

    /**
     * Execute the console command.
     */
    public function handle(StagingImportService $service): int
    {
        $connection = (string) $this->option('database');
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = (int) $this->option('chunk-size');

        $this->info("===================================================================");
        $this->info(" IQRA Billing: Phase 2 Staging Ingestion Pipeline");
        $this->info("===================================================================");

        // 1. Explicit database isolation check
        try {
            DatabaseSafetyGuard::assertTargetDatabase($connection);
            $activeDb = DatabaseSafetyGuard::getActiveDatabaseName($connection);
            $this->info(" [SECURITY] Target database verified: {$activeDb} (Isolation confirmed)");
        } catch (DatabaseSafetyException $e) {
            $this->error(" [SECURITY BREACH] " . $e->getMessage());
            return Command::FAILURE;
        }

        if ($dryRun) {
            $this->warn(" [MODE] DRY-RUN enabled: No records will be committed to the database.");
        }

        // 2. Locate zip file
        $zipPath = $this->option('zip');
        if (!$zipPath) {
            $defaultCandidate = base_path(StagingImportService::DEFAULT_ZIP_FILENAME);
            if (file_exists($defaultCandidate)) {
                $zipPath = $defaultCandidate;
            } else {
                $this->error("No zip file specified and default archive not found at: {$defaultCandidate}");
                return Command::FAILURE;
            }
        }

        $this->info(" [ARCHIVE] Source ZIP: " . basename($zipPath));
        $zipHash = hash_file('sha256', $zipPath);
        $this->line(" [HASH] SHA-256: {$zipHash}");

        // 3. Open archive & map files
        try {
            $zip = $service->openZip($zipPath);
            $fileMap = $service->getZipFileMap($zip);
            $this->info(" [ARCHIVE] Verified all required staging CSV files present in archive.");
        } catch (\Throwable $e) {
            $this->error("Failed to inspect zip archive: " . $e->getMessage());
            return Command::FAILURE;
        }

        // 4. Parse summary metadata
        $summary = $service->parseSummary($zip, $fileMap['summary']);
        $this->line(" [METADATA] Source tab batches verified: " . ($summary['All source batches verified'] ?? 'N/A'));
        $this->line(" [METADATA] Source export timestamp: " . ($summary['Completed at'] ?? 'N/A'));

        // 5. Ingest batches
        $this->info("\n--- 1. Ingesting Staging Batches (STAGING_batches.csv) ---");
        $batchResult = $service->importBatches($zip, $fileMap['batches'], $connection, $dryRun, $chunkSize);
        $this->line(" Processed: {$batchResult['processed']} batches | Staged: {$batchResult['staged']}");

        // 6. Ingest currency review
        $this->info("\n--- 2. Ingesting Currency Review (STAGING_currency_review.csv) ---");
        $currResult = $service->importCurrencyReviews($zip, $fileMap['currency_review'], $connection, $dryRun, $chunkSize);
        $this->line(" Processed: {$currResult['processed']} students | Staged: {$currResult['staged']}");
        $this->line(" Approved currencies: {$currResult['approved']} | Pending review: {$currResult['unresolved']}");
        foreach ($currResult['currencies'] as $curr => $cnt) {
            $this->line("   - {$curr}: {$cnt}");
        }

        // 7. Ingest students
        $this->info("\n--- 3. Ingesting Student Records (STAGING_students.csv) ---");
        $stuResult = $service->importStudents($zip, $fileMap['students'], $connection, $dryRun, $chunkSize);
        $this->line(" Processed: {$stuResult['processed']} students | Staged: {$stuResult['staged']}");

        // 8. Ingest dues
        $this->info("\n--- 4. Ingesting Outstanding Dues (STAGING_dues.csv) ---");
        $duesResult = $service->importDues($zip, $fileMap['dues'], $connection, $dryRun, $chunkSize);
        $this->line(" Processed: {$duesResult['processed']} dues rows | Staged: {$duesResult['staged']}");

        // 9. Ingest payments
        $this->info("\n--- 5. Ingesting Historical Payments (STAGING_paid.csv) ---");
        $payResult = $service->importPayments($zip, $fileMap['paid'], $connection, $dryRun, $chunkSize);
        $this->line(" Processed: {$payResult['processed']} payment rows | Staged: {$payResult['staged']}");

        // 10. Ingest exceptions
        $this->info("\n--- 6. Ingesting Pre-computed Exceptions (STAGING_exceptions.csv) ---");
        $excResult = $service->importExceptions($zip, $fileMap['exceptions'], $connection, $dryRun, $chunkSize);
        $this->line(" Processed: {$excResult['processed']} exception findings | Staged: {$excResult['staged']}");

        $zip->close();

        // 11. Log audit entry
        if (!$dryRun) {
            $service->logApprovalAction(
                $connection,
                'staging_pipeline',
                null,
                null,
                'STAGING_IMPORT_COMPLETED',
                null,
                [
                    'zip_file' => basename($zipPath),
                    'zip_sha256' => $zipHash,
                    'batches_staged' => $batchResult['staged'],
                    'students_staged' => $stuResult['staged'],
                    'currency_approved' => $currResult['approved'],
                    'currency_unresolved' => $currResult['unresolved'],
                    'dues_staged' => $duesResult['staged'],
                    'payments_staged' => $payResult['staged'],
                    'exceptions_staged' => $excResult['staged'],
                ],
                'artisan:staging:import',
                'Phase 2 verified Google Sheets staging data ingested into iqra_billing'
            );
        }

        // 12. Display Reconciliation Report
        $this->info("\n===================================================================");
        $this->info(" RECONCILIATION REPORT");
        $this->info("===================================================================");

        if (!$dryRun) {
            $report = $service->getReconciliationReport($connection);

            $tableRows = [];
            foreach ($report['tables'] as $tableName => $stats) {
                $status = ($stats['matched'] ?? false) ? '<info>MATCHED</info>' : '<error>MISMATCH</error>';
                $tableRows[] = [
                    $tableName,
                    $stats['expected'],
                    $stats['actual'],
                    $status,
                ];
            }

            $this->table(['Staging Table', 'Expected Count', 'Actual Count', 'Status'], $tableRows);

            if ($report['reconciled']) {
                $this->info("\n [RECONCILIATION RESULT] 100% SUCCESS: All staging records reconciled exactly.");
            } else {
                $this->warn("\n [RECONCILIATION RESULT] WARNING: Counts do not perfectly match expected baseline.");
            }
        } else {
            $this->info(" [DRY-RUN COMPLETE] Validation passed successfully without database changes.");
        }

        $this->info("===================================================================\n");
        return Command::SUCCESS;
    }
}
