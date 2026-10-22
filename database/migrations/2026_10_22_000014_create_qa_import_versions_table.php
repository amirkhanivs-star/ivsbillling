<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('qa_import_versions')) {
            Schema::create('qa_import_versions', function (Blueprint $table) {
                $table->id();
                $table->string('version_key', 64)->index();
                $table->string('batch_id', 64)->unique();
                $table->string('source_file', 255);
                $table->string('sha256', 64)->index();
                $table->unsignedInteger('registration_rows')->default(0);
                $table->unsignedInteger('collection_rows')->default(0);
                $table->unsignedInteger('dues_rows')->default(0);
                $table->unsignedInteger('unresolved_collections')->default(0);
                $table->unsignedInteger('unresolved_dues')->default(0);
                $table->unsignedInteger('exceptions_logged')->default(0);
                $table->string('status', 32)->default('imported')->index();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('qa_import_versions');
    }
};
