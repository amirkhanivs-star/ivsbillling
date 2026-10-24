<?php

namespace App\Services\Staging;

use App\Exceptions\DatabaseSafetyException;
use Illuminate\Support\Facades\DB;

class DatabaseSafetyGuard
{
    /**
     * Single approved canonical target database for IQRA Billing Portal.
     */
    public const APPROVED_TARGET_DATABASE = 'iqra_billing';

    /**
     * System schemas that must NEVER be touched under any circumstances.
     */
    public const FORBIDDEN_SYSTEM_DATABASES = [
        'information_schema',
        'mysql',
        'performance_schema',
        'phpmyadmin',
        'sys',
    ];

    /**
     * Retired legacy databases that must no longer be accessed at runtime.
     */
    public const RETIRED_DATABASES = [
        'iqra_billing_clean',
        'iqra_billing_qa',
    ];

    /**
     * Legacy constant aliases for backwards compatibility.
     */
    public const REQUIRED_STAGING_DATABASE = 'iqra_billing';
    public const FORBIDDEN_QUARANTINE_DATABASE = 'mysql';
    public const ALLOWED_QA_DATABASE = 'iqra_billing';

    /**
     * Resolve the explicitly approved target database name (defaults strictly to canonical 'iqra_billing').
     */
    public static function getApprovedTargetDatabase(): string
    {
        $configured = env('APPROVED_DATABASE_NAME') ?: config('database.approved_target_database');
        if (!empty($configured)) {
            return strtolower(trim((string) $configured));
        }
        return self::APPROVED_TARGET_DATABASE;
    }

    /**
     * Assert that the connection is strictly verified against the approved target database.
     *
     * @param string|null $connection
     * @throws DatabaseSafetyException
     */
    public static function assertTargetDatabase(?string $connection = null): void
    {
        $connName = $connection ?: config('database.default', 'mysql');

        // Explicitly reject quarantine connection name
        if ($connName === 'quarantine') {
            throw new DatabaseSafetyException(
                "CRITICAL DATABASE ISOLATION BREACH: Connection '{$connName}' is connected to quarantined database. Operations are STRICTLY FORBIDDEN."
            );
        }

        // Try getting connection object safely
        try {
            $conn = DB::connection($connName);
        } catch (\Throwable $e) {
            throw new DatabaseSafetyException(
                "DATABASE CONNECTION ERROR: Unable to resolve connection '{$connName}': " . $e->getMessage(),
                0,
                $e
            );
        }

        if ($conn->getDriverName() === 'sqlite') {
            // Permit in-memory SQLite only in the isolated automated testing environment
            if (app()->environment('testing') || app()->runningUnitTests()) {
                return;
            }

            throw new DatabaseSafetyException(
                "CRITICAL DATABASE SAFETY VIOLATION: SQLite database driver is only permitted in the isolated automated testing environment."
            );
        }

        try {
            $row = $conn->selectOne('SELECT DATABASE() as current_db');
            $activeDb = $row?->current_db ?? $conn->getDatabaseName();
        } catch (\Throwable $e) {
            throw new DatabaseSafetyException(
                "DATABASE IDENTITY CHECK FAILED: Unable to query physical database on connection '{$connName}': " . $e->getMessage(),
                0,
                $e
            );
        }

        $activeDbLower = strtolower(trim((string) $activeDb));
        $approvedTarget = self::getApprovedTargetDatabase();

        // 1. Guard against system schemas
        if (in_array($activeDbLower, self::FORBIDDEN_SYSTEM_DATABASES, true)) {
            throw new DatabaseSafetyException(
                "CRITICAL SYSTEM PROTECTION BREACH: Connection '{$connName}' is connected to system database '{$activeDb}'. Operations are strictly FORBIDDEN."
            );
        }

        // 2. Guard against retired databases
        if (in_array($activeDbLower, self::RETIRED_DATABASES, true)) {
            throw new DatabaseSafetyException(
                "RETIRED DATABASE ACCESSED: Connection '{$connName}' attempted to use retired database '{$activeDb}'. The portal must use '{$approvedTarget}'."
            );
        }

        // 3. Ensure exact match with approved target database
        $expectedDb = strtolower(config("database.connections.{$connName}.database", $approvedTarget));
        if ($activeDbLower !== $approvedTarget || $activeDbLower !== $expectedDb) {
            throw new DatabaseSafetyException(
                "DATABASE IDENTITY CHECK FAILED: Expected approved database '{$approvedTarget}', but connected to '{$activeDb}'. Execution aborted."
            );
        }
    }

    /**
     * Guard migrations from executing against system or unauthorized databases.
     *
     * @param string|null $connection
     * @throws DatabaseSafetyException
     */
    public static function assertAllowedMigration(?string $connection = null): void
    {
        self::assertTargetDatabase($connection);
    }

    /**
     * Guard mutating operations (reset, imports, schema modifications).
     */
    public static function assertMutatingOperationAllowed(?string $connection = null): void
    {
        self::assertTargetDatabase($connection);
    }

    /**
     * Backwards-compatible aliases routing safely with quarantine & staging isolation guards.
     */
    public static function assertQaDatabase(?string $connection = 'qa'): void
    {
        if ($connection === 'quarantine') {
            throw new DatabaseSafetyException(
                "CRITICAL DATABASE ISOLATION BREACH: Connection '{$connection}' is connected to quarantined database. QA operations are STRICTLY FORBIDDEN on this database."
            );
        }
        if ($connection === 'staging') {
            throw new DatabaseSafetyException(
                "DATABASE IDENTITY CHECK FAILED: Target is staging database, but operation strictly requires QA/canonical database."
            );
        }
        self::assertTargetDatabase($connection);
    }

    public static function assertStagingDatabase(?string $connection = null): void
    {
        if ($connection === 'quarantine') {
            throw new DatabaseSafetyException(
                "CRITICAL DATABASE ISOLATION BREACH: Connection '{$connection}' is connected to quarantined database."
            );
        }
        self::assertTargetDatabase($connection);
    }

    public static function assertNotQuarantineDatabase(?string $connection = null): void
    {
        if ($connection === 'quarantine') {
            throw new DatabaseSafetyException(
                "CRITICAL DATABASE ISOLATION BREACH: Application or testing attempted to access quarantined database 'quarantine'. This database is strictly protected and quarantined."
            );
        }
        self::assertTargetDatabase($connection);
    }

    public static function assertNotStagingDatabase(?string $connection = null): void
    {
        if ($connection === 'staging') {
            throw new DatabaseSafetyException(
                "DATABASE SAFETY BREACH: Attempted to run a QA-specific operation against staging database 'staging'."
            );
        }
        self::assertTargetDatabase($connection);
    }

    /**
     * Ensure that the connection is NOT targeting any system schema.
     *
     * @param string|null $connection
     * @throws DatabaseSafetyException
     */
    public static function assertNotSystemDatabase(?string $connection = null): void
    {
        $conn = DB::connection($connection);
        if ($conn->getDriverName() === 'sqlite') {
            return;
        }

        try {
            $row = $conn->selectOne('SELECT DATABASE() as current_db');
            $activeDb = $row?->current_db ?? $conn->getDatabaseName();
        } catch (\Throwable $e) {
            $activeDb = $conn->getDatabaseName();
        }

        $activeDbLower = strtolower((string) $activeDb);
        if (in_array($activeDbLower, self::FORBIDDEN_SYSTEM_DATABASES, true)) {
            throw new DatabaseSafetyException(
                "CRITICAL SYSTEM PROTECTION BREACH: Connection is connected to system database '{$activeDb}'."
            );
        }
    }

    /**
     * Helper to test if connection matches approved database.
     */
    public static function isQaDatabase(?string $connection = null): bool
    {
        return self::isTargetDatabase($connection);
    }

    public static function isTargetDatabase(?string $connection = null): bool
    {
        $conn = DB::connection($connection);
        if ($conn->getDriverName() === 'sqlite') {
            return true;
        }
        try {
            $row = $conn->selectOne('SELECT DATABASE() as current_db');
            $activeDb = strtolower((string) ($row?->current_db ?? $conn->getDatabaseName()));
            return $activeDb === strtolower(self::getApprovedTargetDatabase());
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get the active database name on the connection.
     */
    public static function getActiveDatabaseName(?string $connection = null): string
    {
        $conn = DB::connection($connection);
        if ($conn->getDriverName() === 'sqlite') {
            return ':memory:';
        }
        $row = $conn->selectOne('SELECT DATABASE() as current_db');
        return (string) ($row?->current_db ?? $conn->getDatabaseName());
    }
}
