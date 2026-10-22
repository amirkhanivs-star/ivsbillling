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
        // 1. QA Staged Collections (Isolated from financial ledger)
        if (!Schema::hasTable('qa_staged_collections')) {
            Schema::create('qa_staged_collections', function (Blueprint $table) {
                $table->id();
                $table->string('batch_id', 64)->index();
                $table->unsignedInteger('source_row')->index();
                $table->string('slip_number', 64)->nullable()->index();
                $table->string('gr_number', 32)->index();
                $table->string('student_name', 200)->nullable();
                $table->string('registration_name', 200)->nullable();
                $table->string('family_code', 64)->nullable()->index();
                $table->string('present_at_22oct', 16)->nullable();
                $table->string('fee_particular', 255)->nullable();
                $table->string('payment_date_raw', 64)->nullable();
                $table->date('payment_date_parsed')->nullable()->index();
                $table->string('payment_method', 64)->nullable();
                $table->string('currency', 16)->nullable()->index();
                $table->string('currency_original', 64)->nullable();
                $table->decimal('amount', 14, 2)->default(0);
                $table->bigInteger('amount_minor')->default(0);
                $table->string('registration_currency', 16)->nullable();
                $table->string('currency_compare', 32)->nullable();
                $table->string('source_pdf_page', 32)->nullable();
                $table->string('source_pdf_y', 32)->nullable();
                $table->string('source_grn_original', 64)->nullable();
                $table->string('source_name_original', 255)->nullable();
                $table->string('review_flags', 255)->nullable();
                $table->boolean('is_currency_unresolved')->default(false)->index();
                $table->boolean('has_currency_conflict')->default(false)->index();
                $table->timestamps();

                $table->index(['batch_id', 'gr_number']);
            });
        }

        // 2. QA Staged Outstanding / Dues (Isolated from financial ledger)
        if (!Schema::hasTable('qa_staged_dues')) {
            Schema::create('qa_staged_dues', function (Blueprint $table) {
                $table->id();
                $table->string('batch_id', 64)->index();
                $table->unsignedInteger('source_row')->index();
                $table->string('gr_number', 32)->index();
                $table->string('student_name', 200)->nullable();
                $table->string('registration_name', 200)->nullable();
                $table->string('family_code_final', 64)->nullable()->index();
                $table->string('family_code_pdf', 64)->nullable();
                $table->string('family_code_registration', 64)->nullable();
                $table->string('present_at_22oct', 16)->nullable();
                $table->string('class_in_dues_report', 100)->nullable();
                $table->string('fee_particular', 255)->nullable();
                $table->string('currency', 16)->nullable()->index();
                $table->string('currency_original', 64)->nullable();
                $table->decimal('outstanding_amount', 14, 2)->default(0);
                $table->bigInteger('outstanding_amount_minor')->default(0);
                $table->string('registration_currency', 16)->nullable();
                $table->string('source_pdf_page', 32)->nullable();
                $table->string('source_pdf_y', 32)->nullable();
                $table->string('source_grn_original', 64)->nullable();
                $table->string('source_name_original', 255)->nullable();
                $table->string('review_flags', 255)->nullable();
                $table->boolean('is_currency_unresolved')->default(false)->index();
                $table->boolean('has_currency_conflict')->default(false)->index();
                $table->timestamps();

                $table->index(['batch_id', 'gr_number']);
            });
        }

        // 3. QA Staged Exceptions / Audit Table
        if (!Schema::hasTable('qa_staged_exceptions')) {
            Schema::create('qa_staged_exceptions', function (Blueprint $table) {
                $table->id();
                $table->string('batch_id', 64)->index();
                $table->string('sheet_name', 64)->index();
                $table->unsignedInteger('source_row');
                $table->string('gr_number', 32)->nullable()->index();
                $table->string('issue_code', 64)->index();
                $table->text('issue_message');
                $table->json('raw_data')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('qa_staged_exceptions');
        Schema::dropIfExists('qa_staged_dues');
        Schema::dropIfExists('qa_staged_collections');
    }
};
