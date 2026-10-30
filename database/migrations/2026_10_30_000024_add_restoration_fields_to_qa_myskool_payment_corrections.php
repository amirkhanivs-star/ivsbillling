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
        if (Schema::hasTable('qa_myskool_payment_corrections')) {
            Schema::table('qa_myskool_payment_corrections', function (Blueprint $table) {
                if (!Schema::hasColumn('qa_myskool_payment_corrections', 'restoration_mode')) {
                    $table->string('restoration_mode', 24)->nullable()->after('resulting_status');
                }
                if (!Schema::hasColumn('qa_myskool_payment_corrections', 'reversed_amount_minor')) {
                    $table->unsignedBigInteger('reversed_amount_minor')->default(0)->after('restoration_mode');
                }
                if (!Schema::hasColumn('qa_myskool_payment_corrections', 'cumulative_reversed_minor')) {
                    $table->unsignedBigInteger('cumulative_reversed_minor')->default(0)->after('reversed_amount_minor');
                }
                if (!Schema::hasColumn('qa_myskool_payment_corrections', 'remaining_paid_minor')) {
                    $table->unsignedBigInteger('remaining_paid_minor')->nullable()->after('cumulative_reversed_minor');
                }
                if (!Schema::hasColumn('qa_myskool_payment_corrections', 'idempotency_key')) {
                    $table->string('idempotency_key', 100)->nullable()->unique('uq_qa_ms_corr_idempotency')->after('remaining_paid_minor');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('qa_myskool_payment_corrections')) {
            Schema::table('qa_myskool_payment_corrections', function (Blueprint $table) {
                if (Schema::hasColumn('qa_myskool_payment_corrections', 'idempotency_key')) {
                    $table->dropUnique('uq_qa_ms_corr_idempotency');
                    $table->dropColumn('idempotency_key');
                }
                if (Schema::hasColumn('qa_myskool_payment_corrections', 'remaining_paid_minor')) {
                    $table->dropColumn('remaining_paid_minor');
                }
                if (Schema::hasColumn('qa_myskool_payment_corrections', 'cumulative_reversed_minor')) {
                    $table->dropColumn('cumulative_reversed_minor');
                }
                if (Schema::hasColumn('qa_myskool_payment_corrections', 'reversed_amount_minor')) {
                    $table->dropColumn('reversed_amount_minor');
                }
                if (Schema::hasColumn('qa_myskool_payment_corrections', 'restoration_mode')) {
                    $table->dropColumn('restoration_mode');
                }
            });
        }
    }
};
