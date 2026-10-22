<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;

class AppServiceProvider extends ServiceProvider {
    public function register(): void {}
    public function boot(): void {
        Paginator::defaultView('vendor.pagination.myskool');

        // Critical database isolation: QA portal runtime must strictly target iqra_billing_qa
        if (!app()->runningUnitTests()) {
            $defaultConn = config('database.default');
            \App\Services\Staging\DatabaseSafetyGuard::assertQaDatabase($defaultConn);
            \App\Services\Staging\DatabaseSafetyGuard::assertNotQuarantineDatabase($defaultConn);
            \App\Services\Staging\DatabaseSafetyGuard::assertNotStagingDatabase($defaultConn);
        }
    }
}
