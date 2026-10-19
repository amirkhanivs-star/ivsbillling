<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('payments', function (Blueprint $table) {
            $table->char('selected_period', 7)->nullable()->after('received_on');
        });
    }

    public function down(): void {
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('selected_period'));
    }
};
