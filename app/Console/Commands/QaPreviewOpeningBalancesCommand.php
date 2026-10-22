<?php

namespace App\Console\Commands;

use App\Services\Migration\HistoricalOpeningBalanceService;
use Illuminate\Console\Command;

class QaPreviewOpeningBalancesCommand extends Command
{
    protected $signature = 'qa:preview-opening-balances
                            {--conn= : Database connection to use (defaults to qa)}';

    protected $description = 'Generate a detailed currency-isolated preview of historical opening balances from qa_staged_dues';

    public function handle(HistoricalOpeningBalanceService $service): int
    {
        $this->line('');
        $this->info('========================================================================');
        $this->info('  IQRA Virtual School — QA Historical Opening Balances Preview         ');
        $this->info('========================================================================');

        $conn = (string) ($this->option('conn') ?: config('database.default'));
        $preview = $service->preview($conn);

        if ($preview['status'] === 'empty') {
            $this->warn($preview['message']);
            return self::SUCCESS;
        }

        $s = $preview['summary'];
        $this->line('');
        $this->info("Total Staged Dues Rows:     {$s['total_staged_dues']}");
        $this->info("Eligible Rows for Invoices: {$s['eligible_rows']}");
        $this->warn("Excluded / Held Rows:       {$s['excluded_rows_count']}");
        $this->info("Invoices to be Created:     {$s['invoices_to_create']}");

        $this->line('');
        $this->info('--- Eligible Opening Balances by Currency ---');
        $headers = ['Currency', 'Lines', 'Total Amount'];
        $currRows = [];
        foreach ($preview['totals_by_currency'] as $curr => $data) {
            $currRows[] = [
                $curr,
                number_format($data['lines']),
                number_format((float) $data['total_decimal'], 2) . " {$curr}",
            ];
        }
        $this->table($headers, $currRows);

        $this->line('');
        $this->info('--- Eligible Opening Balances by Department ---');
        $deptHeaders = ['Department', 'Currency', 'Total Amount'];
        $deptRows = [];
        foreach ($preview['totals_by_department'] as $dept => $currs) {
            foreach ($currs as $curr => $data) {
                $deptRows[] = [
                    $dept,
                    $curr,
                    number_format((float) $data['total_decimal'], 2) . " {$curr}",
                ];
            }
        }
        $this->table($deptHeaders, $deptRows);

        $this->line('');
        $this->warn('--- Excluded / Held Dues Breakdown ---');
        $ex = $preview['exclusions'];
        $this->line("  - Unresolved Currency Rows:         {$ex['unresolved_currency_count']}");
        $this->line("  - Review-Hold Students' Dues:       {$ex['review_hold_student_count']}");
        $this->line("  - Historical-Hold Students' Dues:   {$ex['historical_hold_student_count']}");
        $this->line("  - Missing GR / Student Dues:        {$ex['missing_student_count']}");
        $this->line("  - Invalid / Non-Positive Amounts:   {$ex['invalid_amount_count']}");
        $this->line("  - Currency Mismatches:              {$ex['currency_mismatch_count']}");

        $this->line('');
        $this->warn('========================================================================');
        $this->warn('  ' . $preview['safety_status']['message']);
        $this->warn('========================================================================');
        $this->line('');

        return self::SUCCESS;
    }
}
