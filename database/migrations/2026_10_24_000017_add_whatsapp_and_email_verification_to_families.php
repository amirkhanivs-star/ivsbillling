<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('families', function (Blueprint $table) {
            if (!Schema::hasColumn('families', 'whatsapp_verified')) {
                $table->boolean('whatsapp_verified')->default(false)->after('contact_verified');
            }
            if (!Schema::hasColumn('families', 'email_verified')) {
                $table->boolean('email_verified')->default(false)->after('whatsapp_verified');
            }
        });

        // Initialize whatsapp_verified from existing contact_verified
        DB::table('families')
            ->where('contact_verified', true)
            ->update(['whatsapp_verified' => true]);
    }

    public function down(): void
    {
        Schema::table('families', function (Blueprint $table) {
            if (Schema::hasColumn('families', 'email_verified')) {
                $table->dropColumn('email_verified');
            }
            if (Schema::hasColumn('families', 'whatsapp_verified')) {
                $table->dropColumn('whatsapp_verified');
            }
        });
    }
};
