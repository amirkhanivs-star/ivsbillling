<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            if (!Schema::hasColumn('notifications', 'chunk_index')) {
                $table->unsignedSmallInteger('chunk_index')->nullable()->default(1)->after('batch_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            if (Schema::hasColumn('notifications', 'chunk_index')) {
                $table->dropColumn('chunk_index');
            }
        });
    }
};
