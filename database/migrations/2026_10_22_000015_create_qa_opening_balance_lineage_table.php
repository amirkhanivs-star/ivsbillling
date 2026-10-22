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
        if (!Schema::hasTable('qa_opening_balance_lineage')) {
            Schema::create('qa_opening_balance_lineage', function (Blueprint $table) {
                $table->id();
                $table->string('batch_id', 64)->index();
                $table->unsignedBigInteger('qa_staged_due_id')->unique();
                $table->unsignedInteger('source_row');
                $table->string('gr_number', 50)->index();
                $table->unsignedBigInteger('invoice_id')->index();
                $table->unsignedBigInteger('invoice_item_id')->index();
                $table->unsignedBigInteger('family_id')->index();
                $table->unsignedBigInteger('student_id')->index();
                $table->unsignedBigInteger('enrollment_id')->index();
                $table->char('currency', 3)->index();
                $table->unsignedBigInteger('amount_minor');
                $table->string('period_key', 64)->index();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('qa_opening_balance_lineage');
    }
};
