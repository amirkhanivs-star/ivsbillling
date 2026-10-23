<?php

namespace App\Console\Commands;

use App\Services\Migration\HistoricalOpeningBalanceService;
use Illuminate\Console\Command;

class QaHeldDuesReportCommand extends Command
{
    protected $signature = 'qa:held-dues-report
                            {--category= : Filter by exception category (review_hold_student, unknown_period, unresolved_currency)}
                            {--export= : File path to export full CSV report}
                            {--batch-id= : Staging batch ID}
                            {--conn= : Database connection (defaults to qa)}';

    protected $description = 'Generate a detailed staff-review report identifying all 790 held dues lines, reasons and required corrections';

    public function handle(HistoricalOpeningBalanceService $service): int
    {
        $this->line('');
        $this->info('========================================================================');
        $this->info('  IQRA Virtual School — Financial Exceptions: Held Dues Lines Report   ');
        $this->info('========================================================================');

        $conn = (string) ($this->option('conn') ?: config('database.default', 'mysql'));
        $batchId = $this->option('batch-id') ? (string) $this->option('batch-id') : null;

        $allHeld = $service->getHeldDuesReport($conn, $batchId);
        $totalCount = count($allHeld);

        $counts = [
            'total' => $totalCount,
            'review_hold_student' => count(array_filter($allHeld, fn($h) => $h['category'] === 'review_hold_student')),
            'unknown_period' => count(array_filter($allHeld, fn($h) => $h['category'] === 'unknown_period')),
            'unresolved_currency' => count(array_filter($allHeld, fn($h) => $h['category'] === 'unresolved_currency')),
        ];

        $this->line('');
        $this->info("Total Held Dues Lines:          {$counts['total']}");
        $this->warn("  - Review-Hold Students:        {$counts['review_hold_student']}");
        $this->warn("  - Unknown / Missing Period:    {$counts['unknown_period']}");
        $this->warn("  - Unresolved Currency:         {$counts['unresolved_currency']}");

        $cat = (string) $this->option('category');
        $filtered = $allHeld;
        if ($cat && $cat !== 'all') {
            $filtered = array_values(array_filter($allHeld, fn($h) => $h['category'] === $cat));
            $this->line("Filtering by category: '{$cat}' (Showing " . count($filtered) . " rows)");
        }

        // Export to CSV if requested
        if ($exportPath = $this->option('export')) {
            $f = @fopen($exportPath, 'w');
            if (!$f) {
                $this->error("Cannot open file for writing: {$exportPath}");
                return self::FAILURE;
            }

            fputcsv($f, [
                'Source Row',
                'GR Number',
                'Student Name',
                'Family Code',
                'Class',
                'Fee Particular',
                'Currency',
                'Outstanding Amount',
                'Hold Category',
                'Hold Reason',
                'Required Staff Correction',
                'Status',
            ]);

            foreach ($filtered as $row) {
                fputcsv($f, [
                    $row['source_row'],
                    $row['gr_number'],
                    $row['student_name'],
                    $row['family_code'],
                    $row['class_label'],
                    $row['fee_particular'],
                    $row['currency'],
                    $row['amount_decimal'],
                    $row['category_label'],
                    $row['reason'],
                    $row['required_correction'],
                    $row['status'],
                ]);
            }

            fclose($f);
            $this->line('');
            $this->info("Exported " . count($filtered) . " records to: {$exportPath}");
        }

        // Show table preview of the first 10 rows
        $this->line('');
        $this->info('--- Sample Held Dues Lines (First 10) ---');
        $headers = ['Row #', 'GR', 'Student', 'Particular', 'Amount', 'Hold Category', 'Required Correction'];
        $tableRows = [];

        foreach (array_slice($filtered, 0, 10) as $r) {
            $tableRows[] = [
                '#' . $r['source_row'],
                $r['gr_number'],
                substr($r['student_name'], 0, 15),
                substr($r['fee_particular'], 0, 24),
                $r['currency'] . ' ' . $r['amount_decimal'],
                $r['category_label'],
                substr($r['required_correction'], 0, 36) . '...',
            ];
        }

        $this->table($headers, $tableRows);
        $this->line('');
        $this->warn('POLICY: None of these held items can be automatically approved without verified source evidence.');
        $this->line('');

        return self::SUCCESS;
    }
}
