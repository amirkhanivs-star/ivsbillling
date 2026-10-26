<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AutoVerifyWhatsAppCommand extends Command
{
    protected $signature = 'billing:auto-verify-whatsapp
                            {--dry-run : Preview counts without making changes}
                            {--unblock-notifications : Also reset blocked notifications to queued status}';

    protected $description = 'Automatically verify contact and messaging consent for all families with an existing WhatsApp number';

    public function handle(): int
    {
        $this->info('=== Auto-Verify Existing WhatsApp Contacts ===');

        $baseQuery = DB::table('families')
            ->whereNotNull('whatsapp')
            ->whereRaw("TRIM(whatsapp) != ''");

        $totalWithPhone = (clone $baseQuery)->count();
        $unverifiedQuery = (clone $baseQuery)->where(function ($q) {
            $q->where('contact_verified', false)
              ->orWhere('whatsapp_verified', false)
              ->orWhere('messaging_opt_in', false);
        });

        $toVerifyCount = $unverifiedQuery->count();
        $alreadyVerified = $totalWithPhone - $toVerifyCount;

        $blockedNotifs = DB::table('notifications')
            ->where('status', 'blocked')
            ->count();

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Families with WhatsApp', $totalWithPhone],
                ['Already Fully Verified & Opted-In', $alreadyVerified],
                ['Families Needing Verification & Consent', $toVerifyCount],
                ['Currently Blocked Notifications', $blockedNotifs],
            ]
        );

        if ($this->option('dry-run')) {
            $this->comment('DRY RUN: No database changes were made.');
            return 0;
        }

        if ($toVerifyCount === 0 && $blockedNotifs === 0) {
            $this->info('All families with WhatsApp are already verified and opted in!');
            return 0;
        }

        $now = now();
        $adminUser = User::where('role', 'super_admin')->first();
        $adminId = $adminUser ? $adminUser->id : 1;

        $updatedFamilies = 0;
        $unblockedNotifs = 0;

        DB::transaction(function () use (&$updatedFamilies, &$unblockedNotifs, $now, $adminId) {
            $updatedFamilies = DB::table('families')
                ->whereNotNull('whatsapp')
                ->whereRaw("TRIM(whatsapp) != ''")
                ->where(function ($q) {
                    $q->where('contact_verified', false)
                      ->orWhere('whatsapp_verified', false)
                      ->orWhere('messaging_opt_in', false);
                })
                ->update([
                    'contact_verified' => true,
                    'whatsapp_verified' => true,
                    'messaging_opt_in' => true,
                    'updated_at' => $now,
                ]);

            $unblockedNotifs = DB::table('notifications')
                ->where('status', 'blocked')
                ->update([
                    'status' => 'queued',
                    'error' => null,
                    'updated_at' => $now,
                ]);

            DB::table('audit_logs')->insert([
                'actor_id' => $adminId,
                'action' => 'bulk_whatsapp_auto_verified',
                'subject_type' => 'system',
                'subject_id' => null,
                'metadata' => json_encode([
                    'families_verified' => $updatedFamilies,
                    'notifications_unblocked' => $unblockedNotifs,
                    'timestamp' => $now->toIso8601String(),
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        $this->newLine();
        $this->info("✅ Successfully verified {$updatedFamilies} families with existing WhatsApp numbers!");
        if ($unblockedNotifs > 0) {
            $this->info("✅ Reset {$unblockedNotifs} previously blocked notifications back to 'queued'.");
        }

        return 0;
    }
}
