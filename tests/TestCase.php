<?php
namespace Tests;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // FAIL-SAFE: Prevent tests from ever executing RefreshDatabase or migrations against the live local database
        if (config('database.default') === 'mysql' && config('database.connections.mysql.database') === 'iqra_billing') {
            throw new RuntimeException(
                "CRITICAL SAFETY ABORT: Tests attempted to run against active MySQL database 'iqra_billing'! " .
                "This occurs when 'php artisan config:cache' is active. " .
                "Run 'php artisan config:clear' to safely run tests using the in-memory SQLite database."
            );
        }
    }
}
