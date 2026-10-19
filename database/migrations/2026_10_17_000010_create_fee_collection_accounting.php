<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('status', 24)->default('received')->after('request_hash');
            $table->timestamp('reversed_at')->nullable()->after('status');
            $table->foreignId('reversed_by')->nullable()->after('reversed_at')->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable()->after('reversed_by');
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->string('status', 24)->default('active')->after('paid_minor');
            $table->unsignedBigInteger('exemption_minor')->default(0)->after('discount_minor');
        });

        Schema::create('fee_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_item_id')->constrained('invoice_items')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->string('kind', 24); // discount, exemption, void
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('previous_amount_minor');
            $table->unsignedBigInteger('new_amount_minor');
            $table->text('reason');
            $table->timestamps();
        });

        Schema::create('fee_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_item_id')->constrained('invoice_items')->cascadeOnDelete();
            $table->unsignedTinyInteger('installment_number');
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('paid_minor')->default(0);
            $table->date('due_date');
            $table->string('status', 24)->default('pending'); // pending, partial, paid, cancelled
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_installments');
        Schema::dropIfExists('fee_adjustments');

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn(['status', 'exemption_minor']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['reversed_by']);
            $table->dropColumn(['status', 'reversed_at', 'reversed_by', 'reversal_reason']);
        });
    }
};
