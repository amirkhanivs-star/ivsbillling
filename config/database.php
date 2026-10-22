<?php

use Illuminate\Support\Str;

return [

    'default' => env('DB_CONNECTION', 'qa'),

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'iqra_billing_qa'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
        ],

        'qa' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_QA_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('DB_QA_PORT', env('DB_PORT', '3306')),
            'database' => env('DB_QA_DATABASE', env('DB_DATABASE', 'iqra_billing_qa')),
            'username' => env('DB_QA_USERNAME', env('DB_USERNAME', 'root')),
            'password' => env('DB_QA_PASSWORD', env('DB_PASSWORD', '')),
            'unix_socket' => env('DB_QA_SOCKET', env('DB_SOCKET', '')),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
        ],

        'staging' => [
            'driver' => 'mysql',
            'host' => env('DB_STAGING_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('DB_STAGING_PORT', env('DB_PORT', '3306')),
            'database' => env('DB_STAGING_DATABASE', 'iqra_billing_clean'),
            'username' => env('DB_STAGING_USERNAME', env('DB_USERNAME', 'root')),
            'password' => env('DB_STAGING_PASSWORD', env('DB_PASSWORD', '')),
            'unix_socket' => env('DB_STAGING_SOCKET', env('DB_SOCKET', '')),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
        ],

        'quarantine' => [
            'driver' => 'mysql',
            'host' => env('DB_QUARANTINE_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('DB_QUARANTINE_PORT', env('DB_PORT', '3306')),
            'database' => env('DB_QUARANTINE_DATABASE', 'iqra_billing'),
            'username' => env('DB_QUARANTINE_USERNAME', env('DB_USERNAME', 'root')),
            'password' => env('DB_QUARANTINE_PASSWORD', env('DB_PASSWORD', '')),
            'unix_socket' => env('DB_QUARANTINE_SOCKET', env('DB_SOCKET', '')),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
        ],

    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

];
