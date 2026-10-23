<?php

namespace Tests\Feature;

use App\Exceptions\DatabaseSafetyException;
use App\Providers\AppServiceProvider;
use App\Services\Staging\DatabaseSafetyGuard;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class DatabaseBootSafetyGuardTest extends TestCase
{
    /**
     * In-memory SQLite is strictly permitted when running automated tests in isolated test environment.
     */
    public function test_boot_guard_permits_sqlite_in_testing_environment(): void
    {
        $this->assertTrue(app()->environment('testing') || app()->runningUnitTests());

        // Default test connection is sqlite
        DatabaseSafetyGuard::assertTargetDatabase('sqlite');
        $this->assertTrue(DatabaseSafetyGuard::isTargetDatabase('sqlite'));
    }

    /**
     * SQLite outside testing environment must be rejected to prevent accidental non-canonical runtime setups.
     */
    public function test_boot_guard_rejects_sqlite_outside_testing_environment(): void
    {
        $originalEnv = app()['env'];
        try {
            app()['env'] = 'production';

            $this->expectException(DatabaseSafetyException::class);
            $this->expectExceptionMessage('SQLite database driver is only permitted in the isolated automated testing environment.');

            DatabaseSafetyGuard::assertTargetDatabase('sqlite');
        } finally {
            app()['env'] = $originalEnv;
        }
    }

    /**
     * Quarantine connection name must always be rejected immediately.
     */
    public function test_boot_guard_rejects_quarantine_connection(): void
    {
        $this->expectException(DatabaseSafetyException::class);
        $this->expectExceptionMessage('CRITICAL DATABASE ISOLATION BREACH: Connection \'quarantine\' is connected to quarantined database.');

        DatabaseSafetyGuard::assertTargetDatabase('quarantine');
    }

    /**
     * Retired databases (iqra_billing_qa, iqra_billing_clean) must fail closed.
     */
    public function test_boot_guard_rejects_retired_databases(): void
    {
        // Mock a connection configuration pretending to be iqra_billing_qa
        Config::set('database.connections.fake_retired_qa', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'iqra_billing_qa',
            'username' => 'root',
            'password' => '',
        ]);

        $this->expectException(DatabaseSafetyException::class);
        DatabaseSafetyGuard::assertTargetDatabase('fake_retired_qa');
    }

    /**
     * System databases (mysql, information_schema, etc.) must fail closed with system breach exception.
     */
    public function test_boot_guard_rejects_system_databases(): void
    {
        Config::set('database.connections.fake_system', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'information_schema',
            'username' => 'root',
            'password' => '',
        ]);

        $this->expectException(DatabaseSafetyException::class);
        DatabaseSafetyGuard::assertTargetDatabase('fake_system');
    }

    /**
     * AppServiceProvider::boot must fail closed: it must log a critical message and rethrow, NEVER silently swallow.
     */
    public function test_app_service_provider_fails_closed_and_logs_critical_on_unapproved_database(): void
    {
        $logSpy = Log::spy();

        Config::set('database.default', 'quarantine');

        $provider = new AppServiceProvider($this->app);

        $thrown = false;
        try {
            $provider->boot();
        } catch (DatabaseSafetyException $e) {
            $thrown = true;
            $this->assertStringContainsString('quarantined database', $e->getMessage());
        }

        $this->assertTrue($thrown, 'AppServiceProvider must rethrow DatabaseSafetyException and fail closed.');
        $logSpy->shouldHaveReceived('critical')->once();
    }

    /**
     * Mutating operation guard must prevent execution against unapproved databases.
     */
    public function test_mutating_operations_halt_on_unapproved_database(): void
    {
        $this->expectException(DatabaseSafetyException::class);
        DatabaseSafetyGuard::assertMutatingOperationAllowed('quarantine');
    }
}
