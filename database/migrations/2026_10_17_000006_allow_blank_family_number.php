<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
  public function up(): void {
    Schema::table('families', function (Blueprint $table) { $table->string('family_code', 80)->nullable()->change(); });
  }
  public function down(): void {
    if (DB::table('families')->whereNull('family_code')->exists()) {
      throw new RuntimeException('Assign family numbers to unnumbered families before reversing this migration.');
    }
    Schema::table('families', function (Blueprint $table) { $table->string('family_code', 80)->nullable(false)->change(); });
  }
};
