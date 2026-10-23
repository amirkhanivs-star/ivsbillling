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

        // 1. staging_student_identities
        Schema::create('staging_student_identities', function (Blueprint $table) {
            $table->id();
            $table->string('source_key', 50)->unique();
            $table->string('raw_id', 50)->nullable();
            $table->string('gr_number', 50)->index();
            $table->string('family_code', 50)->nullable()->index();
            $table->string('provisional_family_code', 50)->nullable()->index();
            $table->string('student_name', 255);
            $table->string('father_name', 255)->nullable();
            $table->string('guardian_name', 255)->nullable();
            $table->string('status', 50)->nullable();
            $table->string('date_of_admission', 50)->nullable();
            $table->string('date_of_leaving', 100)->nullable();
            $table->text('leaving_reason')->nullable();
            $table->string('class_name', 100)->nullable();
            $table->string('class_admitted', 100)->nullable();
            $table->boolean('is_sibling')->default(false);
            $table->integer('sibling_count')->default(1);
            $table->text('sibling_gr_numbers')->nullable();
            $table->boolean('has_multi_currency_siblings')->default(false);
            $table->string('mapping_status', 50)->default('VERIFIED');
            $table->timestamps();

            $table->index(['mapping_status', 'is_sibling'], 'idx_identity_status');
        });

        // 2. staging_campus_assignments
        Schema::create('staging_campus_assignments', function (Blueprint $table) {
            $table->id();
            $table->string('source_key', 50)->unique();
            $table->string('gr_number', 50)->index();
            $table->string('class_name', 100)->nullable();
            $table->string('proposed_campus', 50)->default('main');
            $table->string('approved_campus', 50)->nullable()->index();
            $table->string('status', 50)->default('PENDING');
            $table->string('approved_by', 100)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'approved_campus'], 'idx_campus_status');
        });

        // 3. staging_department_assignments
        Schema::create('staging_department_assignments', function (Blueprint $table) {
            $table->id();
            $table->string('source_key', 50)->index();
            $table->string('gr_number', 50)->index();
            $table->string('class_name', 100)->nullable();
            $table->string('department_code', 50);
            $table->string('evidence_source', 100);
            $table->string('status', 50)->default('PROPOSED');
            $table->boolean('is_primary')->default(true);
            $table->string('approved_by', 100)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['source_key', 'department_code'], 'uniq_stu_dept');
            $table->index(['department_code', 'status'], 'idx_dept_status');
        });

        // 4. staging_gr_reconciliations
        Schema::create('staging_gr_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->string('source_type', 20); // 'due' or 'payment'
            $table->string('source_key', 50)->unique();
            $table->integer('source_row');
            $table->string('raw_gr_number', 50)->nullable()->index();
            $table->string('raw_student_name', 255)->nullable();
            $table->decimal('amount', 12, 2)->default(0.00);
            $table->string('currency_iso', 10)->nullable();
            $table->string('slip_number', 255)->nullable()->index();
            $table->text('particular_raw')->nullable();
            $table->string('transaction_date', 50)->nullable();
            $table->string('suggested_match_gr', 50)->nullable()->index();
            $table->string('suggested_match_name', 255)->nullable();
            $table->string('match_confidence', 50)->default('UNRESOLVED');
            $table->string('reconciliation_status', 50)->default('UNRESOLVED');
            $table->string('resolved_gr_number', 50)->nullable()->index();
            $table->string('reviewed_by', 100)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'match_confidence'], 'idx_recon_confidence');
            $table->index(['source_type', 'reconciliation_status'], 'idx_recon_status');
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

        Schema::dropIfExists('staging_gr_reconciliations');
        Schema::dropIfExists('staging_department_assignments');
        Schema::dropIfExists('staging_campus_assignments');
        Schema::dropIfExists('staging_student_identities');
    }
};
