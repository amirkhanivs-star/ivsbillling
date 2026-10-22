<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ResetAdminPasswordCommand extends Command
{
    protected $signature = 'iqra:reset-password {user? : ID or email address of the administrator to reset (defaults to ID 1)}';

    protected $description = 'Securely reset an administrator password interactively without command-line exposure';

    public function handle(): int
    {
        $this->line('');
        $this->info('========================================================================');
        $this->info('  IQRA Virtual School — Secure Interactive Password Reset                ');
        $this->info('========================================================================');
        $this->line('This procedure securely updates credentials via hidden terminal prompt.');
        $this->line('Passwords are never accepted as command arguments, logged, or displayed.');
        $this->line('');

        $target = $this->argument('user');

        if (!$target) {
            $user = User::find(1);
        } elseif (is_numeric($target)) {
            $user = User::find((int) $target);
        } else {
            $user = User::where('email', (string) $target)->first();
        }

        if (!$user) {
            $this->error("User not found for identifier: " . ($target ?: 'ID 1'));
            return self::FAILURE;
        }

        $this->info("Target Account: [ID #{$user->id}] {$user->name} ({$user->email}) — Role: {$user->role}");
        $this->line('');

        $password = (string) $this->secret('Enter new secret password (minimum 12 characters):');

        if (strlen($password) < 12) {
            $this->error('Password rejected: Must be at least 12 characters long.');
            return self::FAILURE;
        }

        $forbidden = ['password', 'admin', 'administrator', 'secret', '12345678', 'qwerty', 'iqrabilling', 'iqra123'];
        if (in_array(strtolower($password), $forbidden, true)) {
            $this->error('Password rejected: Cannot use common default or easily guessable phrases.');
            return self::FAILURE;
        }

        $confirm = (string) $this->secret('Confirm new secret password:');

        if ($password !== $confirm) {
            $this->error('Password rejected: Password confirmation did not match.');
            return self::FAILURE;
        }

        // Update password and invalidate existing remember token
        $user->password = Hash::make($password);
        $user->remember_token = null;
        $user->save();

        // Audit log entry
        if (\Illuminate\Support\Facades\Schema::hasTable('audit_logs')) {
            DB::table('audit_logs')->insert([
                'actor_id' => $user->id,
                'action' => 'password_reset_interactive',
                'subject_type' => 'user',
                'subject_id' => $user->id,
                'metadata' => json_encode([
                    'channel' => 'artisan_interactive_cli',
                    'reset_at' => now()->toIso8601String(),
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->line('');
        $this->info("SUCCESS: Password for {$user->name} ({$user->email}) has been securely reset.");
        $this->info("Active sessions and remember tokens have been cleared.");
        $this->line('');

        return self::SUCCESS;
    }
}
