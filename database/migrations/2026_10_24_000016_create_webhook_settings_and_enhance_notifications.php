<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('webhook_settings')) {
            Schema::create('webhook_settings', function (Blueprint $table) {
                $table->id();
                $table->string('webhook_type', 30)->unique(); // 'monthly', 'paid'
                $table->string('url', 500)->nullable();
                $table->text('secret')->nullable(); // Encrypted string
                $table->boolean('is_enabled')->default(false);
                $table->timestamp('last_test_at')->nullable();
                $table->string('last_test_status', 30)->nullable(); // 'success', 'failed'
                $table->text('last_test_message')->nullable();
                $table->unsignedSmallInteger('last_test_response_code')->nullable();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });

            // Initialize default disabled records for both webhooks
            DB::table('webhook_settings')->insert([
                [
                    'webhook_type' => 'monthly',
                    'url' => null,
                    'secret' => null,
                    'is_enabled' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'webhook_type' => 'paid',
                    'url' => null,
                    'secret' => null,
                    'is_enabled' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }

        // Enhance notifications table with Phase 9.1 columns
        Schema::table('notifications', function (Blueprint $table) {
            if (!Schema::hasColumn('notifications', 'webhook_type')) {
                $table->string('webhook_type', 30)->default('monthly')->after('kind')->index();
            }
            if (!Schema::hasColumn('notifications', 'event_type')) {
                $table->string('event_type', 40)->default('invoice.monthly')->after('webhook_type');
            }
            if (!Schema::hasColumn('notifications', 'channels')) {
                $table->text('channels')->nullable()->after('channel');
            }
            if (!Schema::hasColumn('notifications', 'idempotency_key')) {
                $table->string('idempotency_key', 100)->nullable()->unique()->after('status');
            }
            if (!Schema::hasColumn('notifications', 'event_id')) {
                $table->string('event_id', 100)->nullable()->after('idempotency_key');
            }
            if (!Schema::hasColumn('notifications', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()->after('event_id')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('notifications', 'last_attempt_at')) {
                $table->timestamp('last_attempt_at')->nullable()->after('attempts');
            }
            if (!Schema::hasColumn('notifications', 'response_code')) {
                $table->unsignedSmallInteger('response_code')->nullable()->after('last_attempt_at');
            }
            if (!Schema::hasColumn('notifications', 'response_body')) {
                $table->text('response_body')->nullable()->after('response_code');
            }
            if (!Schema::hasColumn('notifications', 'payload')) {
                $table->longText('payload')->nullable()->after('response_body');
            }
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $cols = ['webhook_type', 'event_type', 'channels', 'idempotency_key', 'event_id', 'approved_by', 'last_attempt_at', 'response_code', 'response_body', 'payload'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('notifications', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::dropIfExists('webhook_settings');
    }
};
