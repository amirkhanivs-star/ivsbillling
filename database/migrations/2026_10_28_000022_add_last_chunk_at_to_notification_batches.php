<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('notification_batches', function (Blueprint $table) {
            if (!Schema::hasColumn('notification_batches', 'last_chunk_at')) {
                $table->timestamp('last_chunk_at')->nullable()->after('chunks_sent');
            }
        });
    }

    public function down(): void
    {
        Schema::table('notification_batches', function (Blueprint $table) {
            if (Schema::hasColumn('notification_batches', 'last_chunk_at')) {
                $table->dropColumn('last_chunk_at');
            }
        });
    }
};
