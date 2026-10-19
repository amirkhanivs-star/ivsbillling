<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Migration 000006 handles the nullable column in SQLite tests.
        // This extra repair is only needed for older MySQL installations.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $column = DB::selectOne(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'families' AND COLUMN_NAME = 'family_code'"
        );
        if ($column && strtoupper($column->IS_NULLABLE) === 'NO') {
            Schema::table('families', function (Blueprint $table) {
                $table->string('family_code', 80)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Do not restore NOT NULL: this could invalidate unnumbered families.
    }
};
