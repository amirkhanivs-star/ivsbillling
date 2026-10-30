<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table) {
                if (!Schema::hasColumn('invoices', 'replaces_invoice_id')) {
                    $table->unsignedBigInteger('replaces_invoice_id')
                        ->nullable()
                        ->index('idx_invoices_replaces_id');
                }
                if (!Schema::hasColumn('invoices', 'replaced_by_invoice_id')) {
                    $table->unsignedBigInteger('replaced_by_invoice_id')
                        ->nullable()
                        ->index('idx_invoices_replaced_by_id');
                }
            });

            try {
                Schema::table('invoices', function (Blueprint $table) {
                    $table->index(['billing_run_id', 'family_id', 'currency'], 'idx_invoices_run_family_currency');
                });
            } catch (\Throwable $e) {
                // Index may already exist
            }

            try {
                Schema::table('invoices', function (Blueprint $table) {
                    $table->dropUnique('invoices_billing_run_id_family_id_currency_unique');
                });
            } catch (\Throwable $e) {
                // Unique index may already be dropped
            }
        }

        if (Schema::hasTable('invoice_items')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                if (!Schema::hasColumn('invoice_items', 'replaces_invoice_item_id')) {
                    $table->unsignedBigInteger('replaces_invoice_item_id')
                        ->nullable()
                        ->index('idx_inv_items_replaces_id');
                }
                if (!Schema::hasColumn('invoice_items', 'replaced_by_invoice_item_id')) {
                    $table->unsignedBigInteger('replaced_by_invoice_item_id')
                        ->nullable()
                        ->index('idx_inv_items_replaced_by_id');
                }
                if (!Schema::hasColumn('invoice_items', 'replaced_at')) {
                    $table->timestamp('replaced_at')->nullable();
                }
                if (!Schema::hasColumn('invoice_items', 'replacement_reason')) {
                    $table->text('replacement_reason')->nullable();
                }
            });

            // Add non-unique index on (fee_plan_item_id, period_key) first so MySQL foreign key
            // on fee_plan_item_id remains backed by an index when dropping the unique constraint.
            try {
                Schema::table('invoice_items', function (Blueprint $table) {
                    $table->index(['fee_plan_item_id', 'period_key'], 'idx_inv_items_plan_period');
                });
            } catch (\Throwable $e) {
                // Index may already exist
            }

            try {
                Schema::table('invoice_items', function (Blueprint $table) {
                    $table->dropUnique('invoice_items_fee_plan_item_id_period_key_unique');
                });
            } catch (\Throwable $e) {
                // Unique index may already be dropped
            }
        }

        if (Schema::hasTable('fee_plan_items')) {
            Schema::table('fee_plan_items', function (Blueprint $table) {
                if (!Schema::hasColumn('fee_plan_items', 'status')) {
                    $table->string('status', 24)
                        ->default('active')
                        ->index('idx_fee_plan_items_status');
                }
                if (!Schema::hasColumn('fee_plan_items', 'superseded_by_plan_id')) {
                    $table->unsignedBigInteger('superseded_by_plan_id')
                        ->nullable()
                        ->index('idx_fee_plan_items_superseded_by');
                }
                if (!Schema::hasColumn('fee_plan_items', 'superseded_at')) {
                    $table->timestamp('superseded_at')->nullable();
                }
            });

            // Sync status column for existing inactive plans
            DB::table('fee_plan_items')
                ->where('is_active', false)
                ->where('status', 'active')
                ->update([
                    'status' => 'superseded',
                ]);

            // Remediate any duplicate active fee plans per (enrollment_id, fee_type_id, frequency, subject_name)
            // while preserving all historical fee_plan_items rows for audit.
            $activePlans = DB::table('fee_plan_items as fpi')
                ->leftJoin('enrollments as e', 'e.id', '=', 'fpi.enrollment_id')
                ->where('fpi.is_active', true)
                ->select(
                    'fpi.id',
                    'fpi.enrollment_id',
                    'fpi.fee_type_id',
                    'fpi.frequency',
                    'fpi.subject_name',
                    'fpi.currency',
                    'fpi.amount_minor',
                    'fpi.effective_from',
                    'fpi.effective_to',
                    'e.currency as enrollment_currency',
                    'e.monthly_fee_minor as enrollment_monthly_fee_minor'
                )
                ->get();

            $grouped = $activePlans->groupBy(function ($row) {
                return sprintf(
                    '%d|%d|%s|%s',
                    (int) $row->enrollment_id,
                    (int) ($row->fee_type_id ?? 0),
                    strtolower((string) ($row->frequency ?? 'monthly')),
                    strtolower(trim((string) ($row->subject_name ?? '')))
                );
            });

            foreach ($grouped as $groupRows) {
                if ($groupRows->count() <= 1) {
                    continue;
                }

                $sorted = $groupRows->sort(function ($a, $b) {
                    $aOpen = empty($a->effective_to) ? 1 : 0;
                    $bOpen = empty($b->effective_to) ? 1 : 0;
                    if ($aOpen !== $bOpen) {
                        return $bOpen <=> $aOpen;
                    }

                    $aCurrMatch = (!empty($a->enrollment_currency) && strtoupper((string) $a->currency) === strtoupper((string) $a->enrollment_currency)) ? 1 : 0;
                    $bCurrMatch = (!empty($b->enrollment_currency) && strtoupper((string) $b->currency) === strtoupper((string) $b->enrollment_currency)) ? 1 : 0;
                    if ($aCurrMatch !== $bCurrMatch) {
                        return $bCurrMatch <=> $aCurrMatch;
                    }

                    $aFrom = (string) ($a->effective_from ?? '0000-00-00');
                    $bFrom = (string) ($b->effective_from ?? '0000-00-00');
                    if ($aFrom !== $bFrom) {
                        return strcmp($bFrom, $aFrom);
                    }

                    return ((int) $b->id) <=> ((int) $a->id);
                })->values();

                $authoritative = $sorted->first();
                $supersededRows = $sorted->slice(1);

                foreach ($supersededRows as $oldRow) {
                    $effectiveTo = $oldRow->effective_to;
                    if (empty($effectiveTo) && !empty($authoritative->effective_from)) {
                        $priorDay = date('Y-m-d', strtotime($authoritative->effective_from . ' -1 day'));
                        $effectiveTo = (!empty($oldRow->effective_from) && $priorDay < $oldRow->effective_from)
                            ? $oldRow->effective_from
                            : $priorDay;
                    }

                    DB::table('fee_plan_items')
                        ->where('id', $oldRow->id)
                        ->update([
                            'is_active' => false,
                            'status' => 'superseded',
                            'effective_to' => $effectiveTo,
                            'superseded_by_plan_id' => $authoritative->id,
                            'superseded_at' => now(),
                            'updated_at' => now(),
                        ]);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('fee_plan_items')) {
            Schema::table('fee_plan_items', function (Blueprint $table) {
                $cols = [];
                foreach (['status', 'superseded_by_plan_id', 'superseded_at'] as $col) {
                    if (Schema::hasColumn('fee_plan_items', $col)) {
                        $cols[] = $col;
                    }
                }
                if (!empty($cols)) {
                    $table->dropColumn($cols);
                }
            });
        }

        if (Schema::hasTable('invoice_items')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $cols = [];
                foreach (['replaces_invoice_item_id', 'replaced_by_invoice_item_id', 'replaced_at', 'replacement_reason'] as $col) {
                    if (Schema::hasColumn('invoice_items', $col)) {
                        $cols[] = $col;
                    }
                }
                if (!empty($cols)) {
                    $table->dropColumn($cols);
                }
            });
        }

        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table) {
                $cols = [];
                foreach (['replaces_invoice_id', 'replaced_by_invoice_id'] as $col) {
                    if (Schema::hasColumn('invoices', $col)) {
                        $cols[] = $col;
                    }
                }
                if (!empty($cols)) {
                    $table->dropColumn($cols);
                }
            });
        }
    }
};
