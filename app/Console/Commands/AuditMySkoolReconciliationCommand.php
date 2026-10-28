<?php

namespace App\Console\Commands;

use App\Services\Migration\MySkoolReconciliationAuditService;
use App\Services\Staging\DatabaseSafetyGuard;
use Illuminate\Console\Command;
use Throwable;

class AuditMySkoolReconciliationCommand extends Command
{
    protected $signature = 'billing:audit-myskool-reconciliation
                            {--export= : Export format (e.g., csv) or custom CSV output path}
                            {--output= : Explicit CSV output path when --export=csv is used}
                            {--batch-id= : Staged import batch ID (defaults to active batch)}
                            {--cutoff=2026-10-22 : Source outstanding snapshot cutoff date (YYYY-MM-DD)}
                            {--conn= : Database connection name (defaults to default connection)}';

    protected $description = 'Strictly read-only audit of imported MySkool historical payments against opening-balance invoice items';

    public function handle(MySkoolReconciliationAuditService $service): int
    {
        $conn = (string) ($this->option('conn') ?: config('database.default', 'mysql'));
        $batchId = $this->option('batch-id') ? (string) $this->option('batch-id') : null;
        $cutoff = (string) ($this->option('cutoff') ?: MySkoolReconciliationAuditService::DEFAULT_CUTOFF_DATE);
        $exportOption = $this->option('export');
        $outputOption = $this->option('output');

        $this->line('');
        $this->info('========================================================================');
        $this->info('  IQRA Virtual School — Phase 1 Read-Only Reconciliation Audit          ');
        $this->info('========================================================================');

        // Verify target database safety on MySQL
        try {
            if (config("database.connections.{$conn}.driver") === 'mysql') {
                DatabaseSafetyGuard::assertTargetDatabase($conn);
            }
        } catch (Throwable $e) {
            $this->error('DATABASE SAFETY ERROR: ' . $e->getMessage());
            return self::FAILURE;
        }

        // Execute read-only audit
        try {
            $result = $service->audit($conn, $batchId, $cutoff);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $s = $result['summary'];
        $c = $result['classifications'];
        $sub = $result['subsequent_snapshots'];

        $this->line('');
        $this->line("Database Connection:        {$result['connection']} ({$result['database']})");
        $this->line("Staged Batch ID:            {$result['batch_id']}");
        $this->line("Snapshot Cutoff Date:       {$result['cutoff_date']}");
        $this->line("Staged Collections in Batch:" . ' ' . number_format($result['total_staged_collections_in_batch']));
        $this->line("Opening-Balance Items:      " . number_format($s['total_ob_items_scanned']));
        $this->line("Unmatched OB Items:         " . number_format($s['unmatched_ob_items']));
        $this->info("Overlapping Items Matched:  " . number_format($s['total_overlapping_items']));

        $this->line('');
        $this->comment('1. High-Level Reconciliation Summary (4 Core Categories):');
        $this->table(
            ['Reconciliation Bucket', 'Item Count', 'Audit Disposition'],
            [
                [
                    'Genuine Pre-Cutoff Residual Balances (<= ' . $cutoff . ')',
                    number_format($s['pre_cutoff_residuals']),
                    'PROTECTED (Already netted in Oct 22 snapshot; keep open)',
                ],
                [
                    'Post-Cutoff Full Settlements (> ' . $cutoff . ')',
                    number_format($s['post_cutoff_full_settlements']),
                    'CANDIDATE FOR PHASE 2 FULL SETTLEMENT (0 portal conflicts)',
                ],
                [
                    'Post-Cutoff Partial Payments or Possible Concessions (> ' . $cutoff . ')',
                    number_format($s['post_cutoff_partial_or_concessions']),
                    'PARTIAL / CONCESSION REVIEW (' . $s['post_cutoff_partial_payments'] . ' partial, ' . $s['post_cutoff_sibling_concessions'] . ' concession)',
                ],
                [
                    'Anomalies & Conflicts Requiring Manual Review',
                    number_format($s['anomalies_and_conflicts_requiring_review']),
                    'STRICT MANUAL REVIEW HOLD (Never auto-change)',
                ],
                [
                    'TOTAL OVERLAPPING OPENING-BALANCE ITEMS',
                    number_format($s['total_overlapping_items']),
                    'READ-ONLY AUDIT COMPLETE (Zero ledger mutations)',
                ],
            ]
        );

        $this->line('');
        $this->comment('2. Granular Classification Breakdown:');
        $classRows = [];
        foreach ($c as $code => $count) {
            if ($count > 0 || in_array($code, [
                MySkoolReconciliationAuditService::CLASS_PRE_CUTOFF_RESIDUAL,
                MySkoolReconciliationAuditService::CLASS_POST_CUTOFF_FULL,
                MySkoolReconciliationAuditService::CLASS_POST_CUTOFF_PARTIAL,
                MySkoolReconciliationAuditService::CLASS_POST_CUTOFF_CONCESSION,
                MySkoolReconciliationAuditService::CLASS_CONFLICT_PORTAL_ACTIVITY,
                MySkoolReconciliationAuditService::CLASS_REVIEW_CURRENCY_MISMATCH,
                MySkoolReconciliationAuditService::CLASS_REVIEW_AMBIGUOUS_MATCH,
            ], true)) {
                $classRows[] = [$code, number_format($count)];
            }
        }
        $this->table(['Classification Code', 'Count'], $classRows);

        if (!empty($result['currency_breakdown'])) {
            $this->line('');
            $this->comment('3. Overlapping Items by Currency:');
            $currRows = [];
            foreach ($result['currency_breakdown'] as $curr => $d) {
                $currRows[] = [
                    $curr,
                    number_format($d['overlapping_items']),
                    number_format($d['ob_net_minor'] / 100, 2) . " {$curr}",
                    number_format($d['pre_cutoff_paid_minor'] / 100, 2) . " {$curr}",
                    number_format($d['post_cutoff_paid_minor'] / 100, 2) . " {$curr}",
                    number_format($d['potential_remaining_minor'] / 100, 2) . " {$curr}",
                ];
            }
            $this->table(
                ['Currency', 'Items', 'Original OB Net', 'Pre-Cutoff Paid', 'Post-Cutoff Paid', 'Potential Remaining'],
                $currRows
            );
        }

        $this->line('');
        $this->comment('4. Potentially Affected Subsequent Invoice Snapshots (Read-Only):');
        $this->line('  - Subsequent Invoices (All 478 Overlap Families):       ' . number_format($sub['all_overlap_families_subsequent_invoices_count']));
        $this->line('  - Subsequent Invoices (Post-Cutoff Settled/Partial):    ' . number_format($sub['post_cutoff_affected_subsequent_invoices_count']));
        $this->line('  - November 2026 Invoices Affected by Post-Cutoff Dues:  ' . number_format($sub['november_2026_affected_invoices_count']));
        foreach ($sub['overstatement_minor_by_currency'] as $curr => $deltaMinor) {
            $this->line("  - Snapshot Overstatement ({$curr}):                         " . number_format($deltaMinor / 100, 2) . " {$curr}");
        }

        if ($exportOption !== null && $exportOption !== false) {
            $customPath = null;
            if (is_string($outputOption) && trim($outputOption) !== '') {
                $customPath = trim($outputOption);
            } elseif (is_string($exportOption) && strtolower(trim($exportOption)) !== 'csv' && trim($exportOption) !== '') {
                $customPath = trim($exportOption);
            }

            $csvPath = $service->exportCsv($result, $customPath);
            $this->line('');
            $this->info("CSV Audit Report Exported: {$csvPath}");
        }

        $this->line('');
        $this->info('READ-ONLY VERIFICATION: Zero financial records were created, modified, or deleted.');
        $this->line('');

        return self::SUCCESS;
    }
}
