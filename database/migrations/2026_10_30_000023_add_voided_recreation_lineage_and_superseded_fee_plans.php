<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
                // Index may already exist.
            }

            try {
                Schema::table('invoices', function (Blueprint $table) {
                    $table->dropUnique('invoices_billing_run_id_family_id_currency_unique');
                });
            } catch (\Throwable $e) {
                // Unique index may already be dropped.
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

            // Keep a non-unique supporting index before dropping the unique index.
            try {
                Schema::table('invoice_items', function (Blueprint $table) {
                    $table->index(['fee_plan_item_id', 'period_key'], 'idx_inv_items_plan_period');
                });
            } catch (\Throwable $e) {
                // Index may already exist.
            }

            try {
                Schema::table('invoice_items', function (Blueprint $table) {
                    $table->dropUnique('invoice_items_fee_plan_item_id_period_key_unique');
                });
            } catch (\Throwable $e) {
                // Unique index may already be dropped.
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

            // Existing fee-plan rows are intentionally not modified here.
            // Effective-dated plans and historical fee/currency changes must remain intact.
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
