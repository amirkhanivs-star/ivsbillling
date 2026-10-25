<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_registration_details', function (Blueprint $table) {
            $table->string('guardian_name_override')->nullable()->after('father_name');
        });
    }

    public function down(): void
    {
        Schema::table('student_registration_details', function (Blueprint $table) {
            $table->dropColumn('guardian_name_override');
        });
    }
};