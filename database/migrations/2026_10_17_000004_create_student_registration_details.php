<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('student_registration_details', function (Blueprint $table) {
            $table->foreignId('student_id')->primary()->constrained('students')->cascadeOnDelete();
            $table->string('father_name', 255)->nullable();
            $table->string('surname', 255)->nullable();
            $table->string('religion', 255)->nullable();
            $table->string('last_institute', 255)->nullable();
            $table->string('dob_words', 255)->nullable();
            $table->string('place_of_birth', 255)->nullable();
            $table->string('mother_tongue', 255)->nullable();
            $table->string('nationality', 255)->nullable();
            $table->string('area', 255)->nullable();
            $table->string('current_address', 1000)->nullable();
            $table->string('permanent_address', 1000)->nullable();
            $table->string('physical_handicap', 255)->nullable();
            $table->string('student_email', 255)->nullable();
            $table->string('registration_number', 255)->nullable();
            $table->string('session', 255)->nullable();
            $table->string('class_admitted', 255)->nullable();
            $table->string('section_admitted', 255)->nullable();
            $table->string('section_current', 255)->nullable();
            $table->string('b_form', 255)->nullable();
            $table->string('roll_number', 255)->nullable();
            $table->string('house', 255)->nullable();
            $table->string('last_class', 255)->nullable();
            $table->string('last_session', 255)->nullable();
            $table->string('last_result', 255)->nullable();
            $table->date('dob')->nullable();
            $table->date('registration_date')->nullable();
            $table->date('challan_due_date')->nullable();
            $table->date('readmission_date')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('student_registration_details'); }
};
