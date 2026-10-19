<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fee_catalog_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_type_id')->constrained('fee_types');
            $table->foreignId('department_id')->constrained('departments');
            $table->string('class_label', 120);
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->string('frequency', 24)->default('monthly');
            $table->timestamps();
            $table->unique(['fee_type_id', 'department_id', 'class_label', 'currency'], 'fee_catalog_unique_rate');
        });
        Schema::create('fee_rate_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_type_id')->constrained('fee_types');
            $table->foreignId('user_id')->constrained('users');
            $table->string('target_kind', 32);
            $table->unsignedBigInteger('target_id');
            $table->char('currency', 3);
            $table->unsignedBigInteger('before_minor');
            $table->unsignedBigInteger('after_minor');
            $table->string('reason', 255)->nullable();
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('fee_rate_changes');
        Schema::dropIfExists('fee_catalog_rates');
    }
};
