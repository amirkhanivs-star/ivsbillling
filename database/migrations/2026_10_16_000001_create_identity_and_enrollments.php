<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('campuses',function(Blueprint $t){$t->id();$t->string('name');$t->string('code',24)->unique();$t->string('address')->nullable();$t->timestamps();});
  Schema::create('departments',function(Blueprint $t){$t->id();$t->string('name');$t->string('code',24)->unique();$t->timestamps();});
  Schema::create('users',function(Blueprint $t){$t->id();$t->string('name');$t->string('email')->unique();$t->timestamp('email_verified_at')->nullable();$t->string('password');$t->string('role',32)->default('cashier');$t->foreignId('campus_id')->nullable()->constrained()->nullOnDelete();$t->foreignId('department_id')->nullable()->constrained()->nullOnDelete();$t->boolean('is_active')->default(true);$t->rememberToken();$t->timestamps();});
  Schema::create('password_reset_tokens',function(Blueprint $t){$t->string('email')->primary();$t->string('token');$t->timestamp('created_at')->nullable();});
  Schema::create('sessions',function(Blueprint $t){$t->string('id')->primary();$t->foreignId('user_id')->nullable()->index();$t->string('ip_address',45)->nullable();$t->text('user_agent')->nullable();$t->longText('payload');$t->integer('last_activity')->index();});
  Schema::create('families',function(Blueprint $t){$t->id();$t->string('family_code',80)->unique();$t->string('guardian_name')->nullable();$t->string('email')->nullable();$t->string('whatsapp',30)->nullable();$t->boolean('contact_verified')->default(false);$t->boolean('messaging_opt_in')->default(false);$t->boolean('is_provisional')->default(false);$t->timestamps();});
  Schema::create('students',function(Blueprint $t){$t->id();$t->string('gr_number',80)->unique();$t->string('name');$t->foreignId('family_id')->constrained();$t->string('legacy_myskool_id')->nullable()->unique();$t->string('status',30)->default('present');$t->date('date_of_admission')->nullable();$t->string('gender',30)->nullable();$t->timestamps();});
  Schema::create('programs',function(Blueprint $t){$t->id();$t->foreignId('department_id')->constrained();$t->string('name');$t->unique(['department_id','name']);$t->timestamps();});
  Schema::create('sections',function(Blueprint $t){$t->id();$t->foreignId('program_id')->constrained();$t->string('name');$t->unique(['program_id','name']);$t->timestamps();});
  Schema::create('enrollments',function(Blueprint $t){$t->id();$t->foreignId('student_id')->constrained();$t->foreignId('campus_id')->constrained();$t->foreignId('department_id')->constrained();$t->foreignId('program_id')->nullable()->constrained();$t->foreignId('section_id')->nullable()->constrained();$t->string('grade_label')->nullable();$t->string('subject_name')->nullable();$t->unsignedTinyInteger('class_days_per_week')->nullable();$t->char('currency',3);$t->unsignedBigInteger('monthly_fee_minor')->default(0);$t->json('fee_history')->nullable();$t->json('legacy_billing')->nullable();$t->date('started_on')->nullable();$t->date('ended_on')->nullable();$t->string('status',24)->default('active');$t->timestamps();$t->index(['campus_id','department_id','status']);});
 }
 public function down():void {foreach(['enrollments','sections','programs','students','families','sessions','password_reset_tokens','users','departments','campuses'] as $table)Schema::dropIfExists($table);}
};
