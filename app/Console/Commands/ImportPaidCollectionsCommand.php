<?php

namespace App\Console\Commands;

use App\Services\PaidCollectionImportService;
use Illuminate\Console\Command;

class ImportPaidCollectionsCommand extends Command
{
    protected $signature = 'iqra:import-paid-collections
                            {path? : Path to the paid collections CSV file}
                            {--commit : Execute and persist the import to database (default is dry-run)}';

    protected $description = 'Import paid collection data from MySkool into staging and the financial ledger';

    public function handle(PaidCollectionImportService $service): int
    {
        $this->line('');
        $this->info('================================================================');
        $this->info('  IQRA Virtual School — MySkool Paid Collection Importer        ');
        $this->info('================================================================');

        $defaultPath = base_path('storage/app/imports/My skool paid - Sheet1.csv');
        $filePath = $this->argument('path') ? base_path($this->argument('path')) : $defaultPath;
        $commit = (bool) $this->option('commit');

        if (!file_exists($filePath)) {
            $this->error("File not found at: {$filePath}");
            return self::FAILURE;
        }

        $this->line("Target File: {$filePath}");
        $this->line("Mode: " . ($commit ? 'COMMIT (Persisting to database)' : 'DRY-RUN (Preview only)'));
        $this->line('');

        try {
            $stats = $service->import($filePath, $commit);

            $this->info('=== Import Execution Results ===');
            $this->table(['Metric', 'Count / Value'], [
                ['Total CSV Rows', number_format($stats['total_rows'])],
                ['Distinct Collection Slips', number_format($stats['slips_count'])],
                ['Staged Collection Records', number_format($stats['staged_created'])],
                ['Payments Created', number_format($stats['payments_created'])],
                ['Payment Allocations Created', number_format($stats['allocations_created'])],
                ['Existing Invoices Items Settled', number_format($stats['existing_items_settled'])],
                ['New Invoice Items Created & Settled', number_format($stats['new_items_created'])],
                ['Invoices Refreshed', number_format($stats['affected_invoices_count'])],
                ['Fully Paid Invoices (Settled)', number_format($stats['fully_paid_invoices_count'])],
            ]);

            $this->line('');
            $this->info('=== Totals by Currency ===');
            $currRows = [];
            foreach ($stats['totals_by_currency'] as $curr => $amt) {
                $currRows[] = [$curr, number_format($amt, 2)];
            }
            $this->table(['Currency', 'Total Collected'], $currRows);

            if ($commit) {
                $this->info('SUCCESS: All collection records and paid invoices have been successfully committed to the database!');
            } else {
                $this->warn('DRY RUN COMPLETE: Transaction rolled back. Run with --commit to persist changes to the database.');
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('ERROR during import: ' . $e->getMessage());
            $this->line($e->getTraceAsString());
            return self::FAILURE;
        }
    }
}
