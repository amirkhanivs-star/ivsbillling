<?php

namespace App\Console\Commands;

use App\Services\Migration\HistoricalPaymentReconciliationService;
use App\Services\Migration\MySkoolReconciliationAuditService;
use Illuminate\Console\Command;
use RuntimeException;

class ReconcileMySkoolHistoricalPaymentsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'reconciliation:myskool-historical
        {--dry-run : Read-only preview without financial mutations (default)}
        {--apply : Execute guarded reconciliation on eligible items}
        {--rollback : Reverse previously applied historical settlements}
        {--reconcile-id= : Target a specific reconciliation ID for rollback}
        {--cutoff=2026-10-22 : Snapshot cutoff date (YYYY-MM-DD)}
        {--batch-id= : Target staged collection batch ID}
        {--confirm-cutoff : Operator confirmation that snapshot cutoff is verified}
        {--confirm-owner : Explicit management/owner sign-off for financial mutation}
        {--actor-id= : Mandatory ID or email of the authorized administrator executing the mutation}
        {--gr= : Target a specific student GR number}
        {--item-id= : Target a specific opening balance invoice item ID}
        {--currency= : Target a specific currency (SAR, AED, PKR, QAR)}
        {--class= : Target a specific reconciliation classification or guard class}
        {--export-csv= : Export preview or results to CSV path}
        {--allow-void-recovery : Explicitly authorizes safe atomic recovery and historical settlement of voided items}
        {--force-authorized-live : Bypass local database guard if explicitly authorized}';

    /**
     * The console command description.
     */
    protected $description = 'Safely preview, reconcile, or reverse MySkool post-cutoff historical opening-balance payments';

    protected HistoricalPaymentReconciliationService $reconService;
    protected MySkoolReconciliationAuditService $auditService;

    public function __construct(
        HistoricalPaymentReconciliationService $reconService,
        MySkoolReconciliationAuditService $auditService
    ) {
        parent::__construct();
        $this->reconService = $reconService;
        $this->auditService = $auditService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $cutoff = (string) $this->option('cutoff');
        $batchId = $this->option('batch-id') ? (string) $this->option('batch-id') : null;
        $isApply = (bool) $this->option('apply');
        $isRollback = (bool) $this->option('rollback');
        $reconcileId = $this->option('reconcile-id') ? (string) $this->option('reconcile-id') : null;

        $filters = array_filter([
            'gr' => $this->option('gr'),
            'item_id' => $this->option('item-id'),
            'currency' => $this->option('currency'),
            'class' => $this->option('class'),
        ]);

        $this->info("================================================================================");
        $this->info("IQRA BILLING — PHASE 2B: HISTORICAL PAYMENT RECONCILIATION");
        $this->info("Cutoff Date: {$cutoff} | Target Batch: " . ($batchId ?: 'AUTO-RESOLVE'));
        $this->info("================================================================================");

        // 1. Rollback mode
        if ($isRollback) {
            return $this->handleRollback($reconcileId);
        }

        // 2. Dry-Run Preview (executed for preview OR before apply)
        $this->line("Executing read-only preview and conflict analysis...");

        try {
            $preview = $this->reconService->preview($filters, $cutoff, $batchId);
        } catch (\Throwable $e) {
            $this->error("Preview failed: " . $e->getMessage());
            return Command::FAILURE;
        }

        $this->renderSummaryTables($preview);

        // Export CSV if requested
        $exportCsvOption = $this->option('export-csv');
        if ($exportCsvOption !== null) {
            $customPath = is_string($exportCsvOption) && trim($exportCsvOption) !== '' ? $exportCsvOption : null;
            $auditRes = $this->auditService->audit(null, $preview['batch_id'], $cutoff);
            // If filtered, replace audit records
            if (!empty($filters)) {
                $auditRes['records'] = $preview['records'];
            }
            $csvPath = $this->auditService->exportCsv($auditRes, $customPath);
            $this->info("CSV Export written to: {$csvPath}");
        }

        // If apply was not requested, stop here (default read-only preview)
        if (!$isApply) {
            $this->info("\n[DRY-RUN PREVIEW COMPLETE] Zero financial database writes performed.");
            $this->line("To execute mutations on an authorized environment, pass: --apply --confirm-cutoff --confirm-owner --actor-id=<ADMIN_ID_OR_EMAIL>");
            return Command::SUCCESS;
        }

        // 3. Guarded Apply Execution
        return $this->handleApply($filters, $cutoff, $batchId);
    }

    protected function handleRollback(?string $reconcileId): int
    {
        if (empty($reconcileId)) {
            $this->error("Rollback requires targeting a specific reconciliation ID using --reconcile-id=<ID>.");
            return Command::FAILURE;
        }

        if (!(bool) $this->option('confirm-owner')) {
            $this->error("CRITICAL GATE ERROR: Rollback requires explicit management sign-off ('--confirm-owner').");
            return Command::FAILURE;
        }

        $actorOption = $this->option('actor-id');
        if (($actorOption === null || trim((string) $actorOption) === '') && !auth()->id()) {
            $this->error("CRITICAL GATE ERROR: Rollback requires an explicit authorized administrator ('--actor-id=<ID_OR_EMAIL>').");
            return Command::FAILURE;
        }

        $this->warn("Initiating audited compensating reversal for reconciliation ID: {$reconcileId}...");

        try {
            $result = $this->reconService->reverse(
                $reconcileId,
                is_numeric($actorOption) ? (int) $actorOption : auth()->id(),
                null,
                ['actor_id' => $actorOption]
            );
            $this->info("REVERSAL SUCCESSFUL:");
            $this->table(
                ['Field', 'Restored Value'],
                [
                    ['Reconciliation ID', $result['reconciliation_id']],
                    ['Invoice Item ID', $result['invoice_item_id']],
                    ['Reversed By Admin ID', $result['reversed_by'] ?? 'N/A'],
                    ['Restored Paid Minor', $result['restored_item_paid_minor']],
                    ['Restored Item Status', $result['restored_item_status']],
                    ['Audit Status', 'reversed (audit record preserved)'],
                ]
            );
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Rollback failed: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    protected function handleApply(array $filters, string $cutoff, ?string $batchId): int
    {
        $this->warn("\n[APPLY MODE REQUESTED] Verifying mandatory approval gates and environment protections...");

        $actorOption = $this->option('actor-id');
        if (($actorOption === null || trim((string) $actorOption) === '') && !auth()->id()) {
            $this->error("RECONCILIATION ABORTED: Missing required administrator attribution flag ('--actor-id=<ID_OR_EMAIL>').");
            return Command::FAILURE;
        }

        $options = array_merge($filters, [
            'cutoff_date' => $cutoff,
            'batch_id' => $batchId,
            'confirm_cutoff' => (bool) $this->option('confirm-cutoff'),
            'confirm_owner' => (bool) $this->option('confirm-owner'),
            'actor_id' => $actorOption ?: auth()->id(),
            'user_id' => is_numeric($actorOption) ? (int) $actorOption : auth()->id(),
            'allow_void_recovery' => (bool) $this->option('allow-void-recovery'),
            'force_authorized_live' => (bool) $this->option('force-authorized-live'),
        ]);

        try {
            $applyResult = $this->reconService->apply($options);

            $this->info("RECONCILIATION APPLY EXECUTION COMPLETE:");
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Total Candidates Evaluated', $applyResult['total_candidates']],
                    ['Successfully Applied Settlements', $applyResult['applied_count']],
                    ['Skipped / Held Items', $applyResult['skipped_count']],
                    ['Failed / Conflict Items', $applyResult['failed_count']],
                ]
            );

            if (!empty($applyResult['applied_settlements'])) {
                $this->info("\nApplied Historical Settlements (Sample):");
                $sample = array_slice($applyResult['applied_settlements'], 0, 10);
                $this->table(
                    ['Reconciliation ID', 'Item ID', 'Currency', 'Settled Minor', 'Overpayment Minor'],
                    array_map(fn($s) => [
                        $s['reconciliation_id'],
                        $s['invoice_item_id'],
                        $s['currency'],
                        $s['settled_amount_minor'],
                        $s['unmatched_overpayment_minor'],
                    ], $sample)
                );
            }

            if (!empty($applyResult['skipped_holds'])) {
                $this->warn("\nSkipped Items / Review Holds (Sample):");
                $sampleHolds = array_slice($applyResult['skipped_holds'], 0, 10);
                $this->table(['Item ID', 'Reason'], array_map(fn($h) => [$h['invoice_item_id'], $h['reason']], $sampleHolds));
            }

            if (!empty($applyResult['failed_errors'])) {
                $this->error("\nPre-Apply Conflict Aborts:");
                $this->table(['Item ID', 'Student GR', 'Conflict / Error'], array_map(fn($e) => [$e['invoice_item_id'], $e['student_gr'], $e['error']], $applyResult['failed_errors']));
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("RECONCILIATION ABORTED: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    protected function renderSummaryTables(array $preview): void
    {
        // 1. Guarded Class Distribution Table
        $this->info("\n--- 1. Guarded Reconciliation Classification Distribution ---");
        $guardRows = [];
        foreach ($preview['guard_distribution'] as $guardClass => $count) {
            $guardRows[] = [$guardClass, $count];
        }
        $this->table(['Guarded Classification', 'Item Count'], $guardRows);

        // 2. Per-Currency Exact Reconciliation Table
        $this->info("\n--- 2. Per-Currency Exact Reconciliation Summary (Integer Minor Units) ---");
        $this->line("Formula: Original Obligation = Verified Settlement + Remaining Obligation + Approved Adjustments");

        $currRows = [];
        foreach ($preview['currency_breakdown'] as $curr => $data) {
            $obMinor = $data['original_obligation_minor'];
            $settledMinor = $data['verified_settlement_minor'];
            $remMinor = $data['remaining_obligation_minor'];
            $adjMinor = $data['approved_adjustments_minor'];
            $overMinor = $data['unmatched_overpayment_minor'];
            $status = ($obMinor === ($settledMinor + $remMinor + $adjMinor)) ? 'MATCH (100%)' : 'MISMATCH';

            $currRows[] = [
                $curr,
                $data['items_count'],
                number_format($obMinor / 100, 2) . " ({$obMinor})",
                number_format($settledMinor / 100, 2) . " ({$settledMinor})",
                number_format($remMinor / 100, 2) . " ({$remMinor})",
                number_format($adjMinor / 100, 2) . " ({$adjMinor})",
                number_format($overMinor / 100, 2) . " ({$overMinor})",
                $status,
            ];
        }

        $this->table(
            [
                'Currency',
                'Items',
                'Original Obligation',
                'Verified Settlement',
                'Remaining Obligation',
                'Approved Adjustments',
                'Unmatched Overpayment',
                'Reconciliation Invariant',
            ],
            $currRows
        );
    }
}
