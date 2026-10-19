<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('student_registration_details', function (Blueprint $table) {
            $table->string('phone_number', 30)->nullable();
        });
    }
    public function down(): void {
        Schema::table('student_registration_details', fn (Blueprint $table) => $table->dropColumn('phone_number'));
    }
};
