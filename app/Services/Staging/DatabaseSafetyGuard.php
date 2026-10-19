<?php

namespace App\Services\Staging;

use App\Exceptions\DatabaseSafetyException;
use Illuminate\Support\Facades\DB;

class DatabaseSafetyGuard
{
    public const REQUIRED_STAGING_DATABASE = 'iqra_billing_clean';
    public const FORBIDDEN_QUARANTINE_DATABASE = 'iqra_billing';

    /**
     * Assert that the connection is strictly isolated and connected to iqra_billing_clean.
     *
     * @param string|null $connection
     * @throws DatabaseSafetyException
     */
    public static function assertStagingDatabase(?string $connection = 'staging'): void
    {
        $conn = DB::connection($connection);
        $driver = $conn->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite in-memory or file database for unit/feature tests is permitted
            return;
        }

        // Explicitly query MySQL for the physical selected database name
        try {
            $row = $conn->selectOne('SELECT DATABASE() as current_db');
            $activeDb = $row?->current_db ?? $conn->getDatabaseName();
        } catch (\Throwable $e) {
            throw new DatabaseSafetyException(
                "DATABASE IDENTITY CHECK FAILED: Unable to query physical database on connection '{$connection}': " . $e->getMessage(),
                0,
                $e
            );
        }

        if (strtolower((string) $activeDb) === strtolower(self::FORBIDDEN_QUARANTINE_DATABASE)) {
            throw new DatabaseSafetyException(
                "CRITICAL DATABASE ISOLATION BREACH: Connection '{$connection}' is connected to the quarantined database '" . self::FORBIDDEN_QUARANTINE_DATABASE . "'. Staging, migration, and cleanup operations are STRICTLY FORBIDDEN on this database."
            );
        }

        if (strtolower((string) $activeDb) !== strtolower(self::REQUIRED_STAGING_DATABASE)) {
            throw new DatabaseSafetyException(
                "DATABASE IDENTITY CHECK FAILED: Expected staging database '" . self::REQUIRED_STAGING_DATABASE . "', but connected to '{$activeDb}'. Execution aborted."
            );
        }
    }

    /**
     * Guard staging migrations from executing against iqra_billing.
     *
     * @param string|null $connection
     * @throws DatabaseSafetyException
     */
    public static function assertAllowedMigration(?string $connection = null): void
    {
        $conn = DB::connection($connection);
        $driver = $conn->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        try {
            $row = $conn->selectOne('SELECT DATABASE() as current_db');
            $activeDb = $row?->current_db ?? $conn->getDatabaseName();
        } catch (\Throwable $e) {
            throw new DatabaseSafetyException(
                "MIGRATION SAFETY CHECK FAILED: Unable to verify physical database name: " . $e->getMessage(),
                0,
                $e
            );
        }

        if (strtolower((string) $activeDb) === strtolower(self::FORBIDDEN_QUARANTINE_DATABASE)) {
            throw new DatabaseSafetyException(
                "MIGRATION SAFETY BREACH: Staging migrations cannot be executed against quarantined database '" . self::FORBIDDEN_QUARANTINE_DATABASE . "'."
            );
        }

        if (strtolower((string) $activeDb) !== strtolower(self::REQUIRED_STAGING_DATABASE)) {
            throw new DatabaseSafetyException(
                "MIGRATION SAFETY CHECK FAILED: Staging migrations must run against '" . self::REQUIRED_STAGING_DATABASE . "', but target is '{$activeDb}'."
            );
        }
    }

    /**
     * Get the active database name on the connection.
     */
    public static function getActiveDatabaseName(?string $connection = 'staging'): string
    {
        $conn = DB::connection($connection);
        if ($conn->getDriverName() === 'sqlite') {
            return ':memory:';
        }
        $row = $conn->selectOne('SELECT DATABASE() as current_db');
        return (string) ($row?->current_db ?? $conn->getDatabaseName());
    }
}
