<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('myskool_disputes')) {
            Schema::create('myskool_disputes', function (Blueprint $table) {
                $table->id();
                $table->string('dispute_code', 64)->unique('uq_myskool_disputes_code');
                $table->string('batch_id', 64)->nullable()->index();
                $table->string('source_type', 32)->default('myskool_collection')->index();
                $table->unsignedBigInteger('qa_staged_collection_id')->nullable()->index();
                $table->unsignedBigInteger('invoice_item_id')->nullable()->index();
                $table->unsignedBigInteger('invoice_id')->nullable()->index();
                $table->string('slip_number', 64)->nullable()->index();
                $table->unsignedBigInteger('source_row')->nullable();
                $table->unsignedBigInteger('student_id')->nullable()->index();
                $table->string('student_gr', 32)->index();
                $table->string('student_name', 191);
                $table->unsignedBigInteger('family_id')->nullable()->index();
                $table->string('family_code', 64)->nullable()->index();
                $table->string('fee_type', 120);
                $table->string('fee_particular', 191)->nullable();
                $table->string('billing_month', 64);
                $table->string('period_key', 64)->nullable();
                $table->string('currency', 8);
                $table->unsignedBigInteger('amount_minor')->default(0);
                $table->string('original_record_label', 191);
                $table->text('reason');
                $table->string('status', 24)->default('open')->index();
                $table->string('correction_id', 64)->nullable();
                $table->unsignedBigInteger('submitted_by')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('review_notes')->nullable();
                $table->unsignedBigInteger('resolved_by')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('myskool_disputes');
    }
};
