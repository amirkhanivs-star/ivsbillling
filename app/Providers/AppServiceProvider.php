<?php

namespace App\Providers;

use App\Exceptions\DatabaseSafetyException;
use App\Services\Staging\DatabaseSafetyGuard;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        Paginator::defaultView('vendor.pagination.myskool');

        $appUrl = config('app.url', '');
        if (str_starts_with($appUrl, 'https://') || app()->environment('production')) {
            URL::forceScheme('https');
        }
        if (!empty($appUrl)) {
            URL::forceRootUrl($appUrl);
        }

        // Fail-closed database safety boot guard:
        // Startup and mutating operations are rejected if physical MySQL database is not iqra_billing.
        // Permit in-memory SQLite only in the isolated automated testing environment.
        $defaultConn = config('database.default', 'mysql');

        try {
            DatabaseSafetyGuard::assertTargetDatabase($defaultConn);
        } catch (DatabaseSafetyException $e) {
            // Preserve Laravel's normal error logging before failing closed
            try {
                Log::critical("DATABASE SAFETY BOOT GUARD REJECTION: " . $e->getMessage(), [
                    'connection' => $defaultConn,
                    'configured_database' => config("database.connections.{$defaultConn}.database"),
                    'exception' => $e,
                ]);
            } catch (\Throwable) {
                // If logger is unavailable during early boot, continue to fail closed
            }

            // FAIL CLOSED: Never silently swallow database-safety exceptions
            throw $e;
        }
    }
}
