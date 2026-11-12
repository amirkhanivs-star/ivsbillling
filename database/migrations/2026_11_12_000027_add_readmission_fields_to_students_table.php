<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            if (!Schema::hasColumn('students', 'date_of_readmission')) {
                $table->date('date_of_readmission')->nullable()->after('date_of_leaving');
            }
        });

        Schema::table('student_registration_details', function (Blueprint $table) {
            if (!Schema::hasColumn('student_registration_details', 'readmission_date')) {
                $table->date('readmission_date')->nullable()->after('date_of_leaving');
            }
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            if (Schema::hasColumn('students', 'date_of_readmission')) {
                $table->dropColumn('date_of_readmission');
            }
        });
    }
};
