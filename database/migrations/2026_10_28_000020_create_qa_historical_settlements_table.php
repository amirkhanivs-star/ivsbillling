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
        if (!Schema::hasTable('qa_historical_settlements')) {
            Schema::create('qa_historical_settlements', function (Blueprint $table) {
                $table->id();
                $table->string('reconciliation_id', 64)->unique();
                $table->string('batch_id', 64)->index();
                $table->string('cutoff_date', 10)->index();
                $table->unsignedBigInteger('invoice_item_id')->index();
                $table->unsignedBigInteger('active_invoice_item_id')->nullable()->unique('uq_qa_hist_settlements_active_item');
                $table->unsignedBigInteger('invoice_id')->index();
                $table->unsignedBigInteger('student_id')->nullable()->index();
                $table->unsignedBigInteger('family_id')->nullable()->index();
                $table->string('student_gr', 50)->index();
                $table->string('family_code', 50)->nullable()->index();
                $table->char('currency', 3)->index();
                $table->string('fee_description', 255);
                $table->string('period_key', 64)->nullable()->index();
                $table->unsignedBigInteger('original_obligation_minor');
                $table->unsignedBigInteger('settled_amount_minor');
                $table->unsignedBigInteger('remaining_obligation_minor');
                $table->unsignedBigInteger('unmatched_overpayment_minor')->default(0);
                $table->unsignedBigInteger('previous_item_paid_minor');
                $table->unsignedBigInteger('new_item_paid_minor');
                $table->string('previous_item_status', 32);
                $table->string('new_item_status', 32);
                $table->unsignedBigInteger('previous_invoice_paid_minor');
                $table->unsignedBigInteger('new_invoice_paid_minor');
                $table->string('previous_invoice_status', 32);
                $table->string('new_invoice_status', 32);
                $table->unsignedBigInteger('qa_staged_due_id')->nullable()->index();
                $table->unsignedBigInteger('active_primary_collection_id')->nullable()->unique('uq_qa_hist_settlements_active_primary_col');
                $table->text('qa_staged_collection_ids')->nullable();
                $table->text('myskool_slip_numbers')->nullable();
                $table->string('reconciliation_classification', 64)->index('idx_qa_hist_settlements_recon_class');
                $table->string('guard_class', 64)->index('idx_qa_hist_settlements_guard_class');
                $table->string('status', 32)->default('applied')->index('idx_qa_hist_settlements_status');
                $table->unsignedBigInteger('applied_by')->nullable()->index('idx_qa_hist_settlements_applied_by');
                $table->timestamp('applied_at')->nullable();
                $table->unsignedBigInteger('reversed_by')->nullable()->index('idx_qa_hist_settlements_reversed_by');
                $table->timestamp('reversed_at')->nullable();
                $table->text('affected_snapshots_json')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['invoice_item_id', 'status'], 'idx_qa_hist_settlements_item_status');
                $table->foreign('invoice_item_id', 'fk_qa_hist_settlements_invoice_item')
                    ->references('id')->on('invoice_items')->restrictOnDelete();
                $table->foreign('invoice_id', 'fk_qa_hist_settlements_invoice')
                    ->references('id')->on('invoices')->restrictOnDelete();
                $table->foreign('student_id', 'fk_qa_hist_settlements_student')
                    ->references('id')->on('students')->nullOnDelete();
                $table->foreign('family_id', 'fk_qa_hist_settlements_family')
                    ->references('id')->on('families')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('qa_historical_settlement_collections')) {
            Schema::create('qa_historical_settlement_collections', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('settlement_id')->index('idx_qa_hist_col_settlement_id');
                $table->string('reconciliation_id', 64)->index('idx_qa_hist_col_recon_id');
                $table->unsignedBigInteger('invoice_item_id')->index('idx_qa_hist_col_item_id');
                $table->unsignedBigInteger('qa_staged_collection_id')->index('idx_qa_hist_col_staged_col_id');
                $table->unsignedBigInteger('active_staged_collection_id')->nullable()->unique('uq_qa_hist_active_staged_collection');
                $table->string('status', 32)->default('applied')->index('idx_qa_hist_col_status');
                $table->unsignedBigInteger('applied_by')->nullable();
                $table->timestamp('applied_at')->nullable();
                $table->unsignedBigInteger('reversed_by')->nullable();
                $table->timestamp('reversed_at')->nullable();
                $table->timestamps();

                $table->unique(['settlement_id', 'qa_staged_collection_id'], 'uq_qa_hist_settlement_col_pair');
                $table->foreign('settlement_id', 'fk_qa_hist_col_settlement')
                    ->references('id')->on('qa_historical_settlements')->restrictOnDelete();
                $table->foreign('invoice_item_id', 'fk_qa_hist_col_invoice_item')
                    ->references('id')->on('invoice_items')->restrictOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('qa_historical_settlement_collections');
        Schema::dropIfExists('qa_historical_settlements');
    }
};
