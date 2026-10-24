<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('notification_batches')) {
            Schema::create('notification_batches', function (Blueprint $table) {
                $table->id();
                $table->string('batch_id', 64)->unique();
                $table->string('webhook_type', 30)->default('monthly'); // 'monthly', 'paid'
                $table->string('event_type', 50)->default('invoice.monthly.batch');
                $table->string('billing_period', 30)->nullable();
                $table->text('channels')->nullable();
                $table->json('filter_criteria')->nullable();
                $table->string('status', 30)->default('queued'); // 'queued', 'submitted', 'partially_submitted', 'failed', 'qa_suppressed'
                $table->unsignedInteger('total_recipients')->default(0);
                $table->unsignedInteger('total_invoices')->default(0);
                $table->unsignedInteger('chunks_total')->default(1);
                $table->unsignedInteger('chunks_sent')->default(0);
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedSmallInteger('response_code')->nullable();
                $table->text('response_body')->nullable();
                $table->text('error')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('notifications', function (Blueprint $table) {
            if (!Schema::hasColumn('notifications', 'batch_id')) {
                $table->string('batch_id', 64)->nullable()->after('event_id')->index();
            }
            if (!Schema::hasColumn('notifications', 'is_superseded')) {
                $table->boolean('is_superseded')->default(false)->after('error');
            }
            if (!Schema::hasColumn('notifications', 'superseded_by_invoice_id')) {
                $table->unsignedBigInteger('superseded_by_invoice_id')->nullable()->after('is_superseded');
            }
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            if (Schema::hasColumn('notifications', 'superseded_by_invoice_id')) {
                $table->dropColumn('superseded_by_invoice_id');
            }
            if (Schema::hasColumn('notifications', 'is_superseded')) {
                $table->dropColumn('is_superseded');
            }
            if (Schema::hasColumn('notifications', 'batch_id')) {
                $table->dropColumn('batch_id');
            }
        });

        Schema::dropIfExists('notification_batches');
    }
};
