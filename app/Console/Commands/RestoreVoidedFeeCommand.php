<?php

namespace App\Console\Commands;

use App\Services\VoidedFeeRestorationService;
use Illuminate\Console\Command;
use RuntimeException;

class RestoreVoidedFeeCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'billing:restore-voided-fee
        {invoice_item_id : The ID of the voided invoice item to inspect or restore}
        {--reason= : Mandatory administrative justification for restoring this fee}
        {--apply : Execute the safe restoration mutation (default is dry-run inspect mode)}
        {--confirm-owner : Explicit management/owner authorization confirmation}
        {--actor-id= : Mandatory ID or email of the authorized administrator executing the restoration}
        {--force : Force execution if required in production environment}';

    /**
     * The console command description.
     */
    protected $description = 'Safely inspect, validate, and restore an accidentally voided invoice item with historical settlement awareness';

    protected VoidedFeeRestorationService $restorationService;

    public function __construct(VoidedFeeRestorationService $restorationService)
    {
        parent::__construct();
        $this->restorationService = $restorationService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $itemId = (int) $this->argument('invoice_item_id');
        $isApply = (bool) $this->option('apply');
        $reason = trim((string) $this->option('reason'));
        $confirmOwner = (bool) $this->option('confirm-owner');
        $actorOption = $this->option('actor-id');

        $this->info("================================================================================");
        $this->info("IQRA BILLING — PHASE 2C: SAFE VOIDED FEE RESTORATION");
        $this->info("Target Invoice Item ID: #{$itemId} | Mode: " . ($isApply ? 'APPLY (MUTATION)' : 'DRY-RUN (INSPECT ONLY)'));
        $this->info("================================================================================");

        // 1. Run locked inspection
        $inspection = $this->restorationService->inspect($itemId);

        if (!$inspection['exists']) {
            $this->error("ERROR: " . ($inspection['error'] ?? 'Invoice item not found.'));
            return Command::FAILURE;
        }

        $item = $inspection['item'];
        $values = $inspection['original_approved_values'] ?? [];
        $proposed = $inspection['proposed_restoration'] ?? [];
        $voidEv = $inspection['void_evidence'] ?? [];

        $this->info("\nINVOICE ITEM FORENSICS:");
        $this->table(
            ['Field', 'Value'],
            [
                ['Invoice Item ID', "#{$itemId}"],
                ['Parent Invoice', $item->invoice_number . " (ID: {$item->invoice_id})"],
                ['Student', "{$item->student_name} (GR: {$item->gr_number})"],
                ['Fee Particular', $item->description],
                ['Billing Period', $item->period_key ?: $item->period_start],
                ['Currency', $item->currency],
                ['Current Item Status', $item->status],
                ['Original Approved Net', $item->currency . ' ' . ($values['net_formatted'] ?? '0.00')],
                ['Void Reason on Record', $voidEv['void_reason'] ?: 'None recorded'],
                ['Voided At', $voidEv['voided_at'] ?: 'Unknown'],
            ]
        );

        $this->info("\nSAFETY & RESTORATION CLASSIFICATION:");
        $this->table(
            ['Check', 'Result'],
            [
                ['Safety Status', $inspection['safety_status']],
                ['Safe to Restore?', $inspection['is_safe_to_restore'] ? 'YES' : 'NO'],
                ['Action Description', $inspection['action_description']],
                ['Proposed Target Status', $proposed['target_status'] ?? 'N/A'],
                ['Historical Settlement Amount', $item->currency . ' ' . number_format(($proposed['settled_paid_minor'] ?? 0) / 100, 2)],
                ['Remaining Collectible Dues', $item->currency . ' ' . number_format(($proposed['remaining_collectible_minor'] ?? 0) / 100, 2)],
                ['Requires Concession Review?', (!empty($proposed['requires_concession_review'])) ? 'YES (FLAGGED)' : 'NO'],
            ]
        );

        if (!$inspection['is_safe_to_restore']) {
            $this->error("\nRESTORATION BLOCKED:");
            $this->error($inspection['error'] ?? 'Item failed safety verification.');
            return Command::FAILURE;
        }

        if (!$isApply) {
            $this->comment("\nDRY-RUN COMPLETE: Item #{$itemId} is verified eligible for safe restoration.");
            $this->comment("To execute restoration, re-run with: --apply --reason=\"<explanation>\" --confirm-owner --actor-id=<ADMIN_ID_OR_EMAIL>");
            return Command::SUCCESS;
        }

        // Apply mode gates
        if (empty($reason)) {
            $this->error("\nCRITICAL GATE ERROR: Missing mandatory administrative reason ('--reason=\"...\"').");
            return Command::FAILURE;
        }

        if (!$confirmOwner) {
            $this->error("\nCRITICAL GATE ERROR: Missing required management sign-off confirmation ('--confirm-owner').");
            return Command::FAILURE;
        }

        if (($actorOption === null || trim((string) $actorOption) === '') && !auth()->id()) {
            $this->error("\nCRITICAL GATE ERROR: Missing required administrator attribution flag ('--actor-id=<ID_OR_EMAIL>').");
            return Command::FAILURE;
        }

        // Database/Production Guard
        $dbName = (string) config('database.connections.' . config('database.default') . '.database', '');
        $isTestingEnv = app()->environment('testing');
        if (($dbName === 'iqra_billing' || app()->environment('production')) && !$isTestingEnv && !$this->option('force')) {
            $this->error("\nCRITICAL PRODUCTION GUARD: Execution against live production database requires explicit '--force' flag.");
            return Command::FAILURE;
        }

        $this->warn("\nApplying safe atomic restoration on item #{$itemId}...");

        try {
            $result = $this->restorationService->restore(
                $itemId,
                [
                    'reason' => $reason,
                    'actor_id' => $actorOption ?: auth()->id(),
                ],
                is_numeric($actorOption) ? (int) $actorOption : auth()->id()
            );

            $this->info("\nRESTORATION SUCCESSFUL:");
            $this->table(
                ['Property', 'Restored State'],
                [
                    ['Invoice Item ID', "#{$result['invoice_item_id']}"],
                    ['Parent Invoice', $result['invoice_number']],
                    ['Item Status', $result['status']],
                    ['Restored Net', $item->currency . ' ' . number_format($result['restored_net_minor'] / 100, 2)],
                    ['Settled Paid', $item->currency . ' ' . number_format($result['restored_paid_minor'] / 100, 2)],
                    ['Historical Reconciliation ID', $result['reconciliation_id'] ?: 'N/A (Standard Restoration)'],
                    ['Adjustment Audit ID', "#{$result['adjustment_id']}"],
                    ['Parent Invoice Status', $result['parent_invoice']['status']],
                    ['Parent Invoice Total', $item->currency . ' ' . number_format($result['parent_invoice']['total_minor'] / 100, 2)],
                    ['Parent Invoice Paid', $item->currency . ' ' . number_format($result['parent_invoice']['paid_minor'] / 100, 2)],
                ]
            );

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("\nRESTORATION FAILED: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
