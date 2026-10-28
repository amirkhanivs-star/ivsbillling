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
        if (!Schema::hasTable('qa_myskool_payment_corrections')) {
            Schema::create('qa_myskool_payment_corrections', function (Blueprint $table) {
                $table->id();
                $table->string('correction_id', 64)->unique('uq_qa_ms_corr_correction_id');
                $table->string('batch_id', 64)->index('idx_qa_ms_corr_batch_id');
                $table->unsignedBigInteger('qa_staged_collection_id')->index('idx_qa_ms_corr_staged_col_id');
                $table->unsignedInteger('sequence_no')->default(1);
                $table->string('previous_correction_id', 64)->nullable()->index('idx_qa_ms_corr_prev_id');
                $table->string('slip_number', 100)->nullable()->index('idx_qa_ms_corr_slip_no');
                $table->unsignedBigInteger('source_row')->nullable();
                $table->unsignedBigInteger('student_id')->nullable()->index('idx_qa_ms_corr_student_id');
                $table->unsignedBigInteger('family_id')->nullable()->index('idx_qa_ms_corr_family_id');
                $table->string('student_gr', 50)->index('idx_qa_ms_corr_student_gr');
                $table->string('family_code', 50)->nullable()->index('idx_qa_ms_corr_family_code');
                $table->char('currency', 3)->index('idx_qa_ms_corr_currency');
                $table->unsignedBigInteger('original_amount_minor');
                $table->string('payment_date', 32)->nullable();
                $table->boolean('is_pre_cutoff')->default(false);
                $table->string('case_classification', 64)->index('idx_qa_ms_corr_case_class');
                $table->string('action_type', 64)->index('idx_qa_ms_corr_action_type');
                $table->string('resulting_status', 32)->index('idx_qa_ms_corr_result_status');
                $table->string('reconciliation_id', 64)->nullable()->index('idx_qa_ms_corr_recon_id');
                $table->unsignedBigInteger('settlement_id')->nullable()->index('idx_qa_ms_corr_settlement_id');
                $table->unsignedBigInteger('invoice_item_id')->nullable()->index('idx_qa_ms_corr_item_id');
                $table->unsignedBigInteger('invoice_id')->nullable()->index('idx_qa_ms_corr_invoice_id');
                $table->string('proposed_student_gr', 50)->nullable();
                $table->string('proposed_period_key', 64)->nullable();
                $table->string('proposed_fee_type', 120)->nullable();
                $table->unsignedBigInteger('proposed_compensation_minor')->nullable();
                $table->text('before_state_json');
                $table->text('after_state_json');
                $table->text('reason');
                $table->unsignedBigInteger('acted_by')->index('idx_qa_ms_corr_acted_by');
                $table->timestamp('acted_at');
                $table->timestamps();

                $table->unique(['qa_staged_collection_id', 'sequence_no'], 'uq_qa_ms_corr_col_seq');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('qa_myskool_payment_corrections');
    }
};
