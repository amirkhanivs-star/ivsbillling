<?php

use App\Services\Staging\DatabaseSafetyGuard;
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
        // Enforce database identity guard (approved target iqra_billing or test sqlite)
        DatabaseSafetyGuard::assertAllowedMigration($this->getConnection());

        // 1. staging_batches
        Schema::create('staging_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_category', 50);
            $table->string('source_sheet', 150);
            $table->integer('start_row')->nullable();
            $table->integer('end_row')->nullable();
            $table->string('source_batch_sha256', 64)->nullable();
            $table->integer('detail_rows_staged')->default(0);
            $table->integer('issue_findings')->default(0);
            $table->integer('non_detail_rows')->default(0);
            $table->string('verification_status', 30)->default('PENDING');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['batch_category', 'source_sheet'], 'idx_batch_category_sheet');
            $table->unique(['batch_category', 'source_sheet', 'start_row', 'end_row'], 'uniq_batch_window');
        });

        // 2. staging_students
        Schema::create('staging_students', function (Blueprint $table) {
            $table->id();
            $table->integer('source_row');
            $table->string('source_key', 50)->unique();
            $table->string('raw_id', 50)->nullable();
            $table->string('gr_number', 50)->index();
            $table->string('family_code', 50)->nullable()->index();
            $table->string('student_name', 255)->nullable();
            $table->string('father_name', 255)->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('date_of_birth', 50)->nullable();
            $table->string('religion', 50)->nullable();
            $table->string('contact_father', 100)->nullable();
            $table->string('contact_mother', 100)->nullable();
            $table->text('address')->nullable();
            $table->string('date_of_admission', 50)->nullable();
            $table->string('class_name', 100)->nullable();
            $table->string('class_admitted', 100)->nullable();
            $table->string('monthly_fee_raw', 50)->nullable();
            $table->string('currency_raw', 20)->nullable();
            $table->string('status_raw', 50)->nullable();
            $table->string('guardian_name', 255)->nullable();
            $table->string('rfid', 100)->nullable();
            $table->string('date_of_leaving', 100)->nullable();
            $table->text('leaving_reason')->nullable();
            $table->text('siblings')->nullable();
            $table->text('siblings_class')->nullable();
            $table->string('department_hint', 100)->nullable();
            $table->string('department_approved', 100)->nullable();
            $table->string('campus_approved', 100)->nullable();
            $table->string('currency_suggested', 20)->nullable();
            $table->string('currency_approved', 20)->nullable();
            $table->text('review_issues')->nullable();
            $table->string('staging_status', 50)->default('REVIEW');
            $table->json('raw_row_json')->nullable();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->timestamps();

            $table->index(['department_approved', 'campus_approved'], 'idx_student_dept_campus');
        });

        // 3. staging_currency_reviews
        Schema::create('staging_currency_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('gr_number', 50)->unique();
            $table->string('student_name', 255)->nullable();
            $table->string('family_code', 50)->nullable();
            $table->string('currency_suggested', 20)->nullable();
            $table->string('currency_approved_manual', 20)->nullable();
            $table->string('match_status', 50)->nullable();
            $table->integer('paid_detail_lines')->default(0);
            $table->integer('dues_detail_lines')->default(0);
            $table->string('recognized_currency_counts', 255)->nullable();
            $table->string('unrecognized_source_currency_labels', 255)->nullable();
            $table->text('action_needed')->nullable();
            $table->string('review_status', 50)->default('PENDING');
            $table->timestamps();

            $table->index('match_status', 'idx_curr_match_status');
            $table->index('currency_approved_manual', 'idx_curr_approved');
        });

        // 4. staging_dues
        Schema::create('staging_dues', function (Blueprint $table) {
            $table->id();
            $table->integer('source_row');
            $table->string('source_key', 50)->unique();
            $table->string('report_class', 100)->nullable();
            $table->string('gr_number', 50)->index();
            $table->string('student_name', 255)->nullable();
            $table->string('contact_raw', 100)->nullable();
            $table->text('particular_raw')->nullable();
            $table->string('currency_raw', 50)->nullable();
            $table->string('currency_iso', 10)->nullable();
            $table->decimal('outstanding_amount', 12, 2)->default(0.00);
            $table->string('period_key', 50)->nullable()->index();
            $table->text('fee_description')->nullable();
            $table->string('department_hint', 100)->nullable();
            $table->string('department_approved', 100)->nullable();
            $table->string('campus_approved', 100)->nullable();
            $table->string('family_code_approved', 100)->nullable();
            $table->text('review_issues')->nullable();
            $table->string('staging_status', 50)->default('STAGED');
            $table->json('raw_row_json')->nullable();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->timestamps();

            $table->index(['currency_iso', 'outstanding_amount'], 'idx_dues_curr_amt');
        });

        // 5. staging_payments
        Schema::create('staging_payments', function (Blueprint $table) {
            $table->id();
            $table->integer('source_row');
            $table->string('source_key', 50)->unique();
            $table->string('payment_method', 50)->nullable();
            $table->string('slip_number', 255)->nullable()->index();
            $table->string('gr_number', 50)->index();
            $table->string('student_name', 255)->nullable();
            $table->text('particular_raw')->nullable();
            $table->string('payment_date_raw', 50)->nullable();
            $table->date('payment_date_iso')->nullable()->index();
            $table->string('currency_raw', 50)->nullable();
            $table->string('currency_iso', 10)->nullable();
            $table->decimal('paid_amount', 12, 2)->default(0.00);
            $table->string('period_key', 50)->nullable()->index();
            $table->text('fee_description')->nullable();
            $table->string('department_hint', 100)->nullable();
            $table->string('department_approved', 100)->nullable();
            $table->string('campus_approved', 100)->nullable();
            $table->string('family_code_approved', 100)->nullable();
            $table->text('review_issues')->nullable();
            $table->string('staging_status', 50)->default('STAGED');
            $table->json('raw_row_json')->nullable();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->timestamps();

            $table->index(['currency_iso', 'paid_amount'], 'idx_pay_curr_amt');
        });

        // 6. staging_exceptions
        Schema::create('staging_exceptions', function (Blueprint $table) {
            $table->id();
            $table->string('source_sheet', 100);
            $table->integer('source_row');
            $table->string('source_key', 50)->index();
            $table->string('gr_number', 50)->nullable()->index();
            $table->string('issue_code', 100)->index();
            $table->json('raw_row_json')->nullable();
            $table->string('resolution_status', 50)->default('OPEN');
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->unique(['source_key', 'issue_code'], 'uniq_exc_key_code');
            $table->index(['issue_code', 'resolution_status'], 'idx_exc_code_status');
        });

        // 7. staging_approval_logs
        Schema::create('staging_approval_logs', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 50);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('gr_number', 50)->nullable()->index();
            $table->string('action', 100);
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->string('performed_by', 100);
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'action'], 'idx_log_type_action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DatabaseSafetyGuard::isQaDatabase($this->getConnection())) {
            return;
        }

        DatabaseSafetyGuard::assertAllowedMigration($this->getConnection());

        Schema::dropIfExists('staging_approval_logs');
        Schema::dropIfExists('staging_exceptions');
        Schema::dropIfExists('staging_payments');
        Schema::dropIfExists('staging_dues');
        Schema::dropIfExists('staging_currency_reviews');
        Schema::dropIfExists('staging_students');
        Schema::dropIfExists('staging_batches');
    }
};
