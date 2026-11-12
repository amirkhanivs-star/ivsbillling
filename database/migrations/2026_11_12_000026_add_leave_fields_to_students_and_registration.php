<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            if (!Schema::hasColumn('students', 'date_of_leaving')) {
                $table->date('date_of_leaving')->nullable()->after('date_of_admission');
            }
        });

        Schema::table('student_registration_details', function (Blueprint $table) {
            if (!Schema::hasColumn('student_registration_details', 'date_of_leaving')) {
                $col = $table->date('date_of_leaving')->nullable();
                if (Schema::hasColumn('student_registration_details', 'readmission_date')) {
                    $col->after('readmission_date');
                }
            }
            if (!Schema::hasColumn('student_registration_details', 'leaving_reason')) {
                $table->text('leaving_reason')->nullable()->after('date_of_leaving');
            }
            if (!Schema::hasColumn('student_registration_details', 'leaving_category')) {
                $table->string('leaving_category', 100)->nullable()->after('leaving_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('student_registration_details', function (Blueprint $table) {
            if (Schema::hasColumn('student_registration_details', 'leaving_category')) {
                $table->dropColumn('leaving_category');
            }
            if (Schema::hasColumn('student_registration_details', 'leaving_reason')) {
                $table->dropColumn('leaving_reason');
            }
            if (Schema::hasColumn('student_registration_details', 'date_of_leaving')) {
                $table->dropColumn('date_of_leaving');
            }
        });

        Schema::table('students', function (Blueprint $table) {
            if (Schema::hasColumn('students', 'date_of_leaving')) {
                $table->dropColumn('date_of_leaving');
            }
        });
    }
};
