<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Active Import Version Setting
    |--------------------------------------------------------------------------
    | Explicitly identifies the currently authorized import source version.
    | Future imports never silently fall back or select an old CSV or ZIP.
    */
    'active_version' => env('ACTIVE_IMPORT_VERSION', 'v2_reviewed_20261022'),

    /*
    |--------------------------------------------------------------------------
    | Version Catalog
    |--------------------------------------------------------------------------
    */
    'versions' => [
        'v1_historical_staging' => [
            'label' => 'October 19 Staging Export (GAS Baseline)',
            'date' => '2026-10-19',
            'type' => 'zip',
            'expected_sha256' => 'a292f36f5503c0e44b602920c31a7c8caef41dea5990bf4e6ecc3ecb1f8b0122',
            'target_database' => 'clean',
            'is_historical' => true,
            'is_active' => false,
        ],
        'v2_reviewed_20261022' => [
            'label' => 'October 22 Reviewed Workbook (QA Portal)',
            'date' => '2026-10-22',
            'type' => 'xlsx',
            'filename' => 'UPdated school record for portal.xlsx',
            'relative_path' => 'storage/app/imports/UPdated school record for portal.xlsx',
            'expected_sha256' => 'd2276cf0c75a0c17b143d1815fda568c9b5cc7e0154f546b8075d1a98e225401',
            'target_database' => 'qa',
            'is_historical' => false,
            'is_active' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Disallowed Legacy Sources
    |--------------------------------------------------------------------------
    | Direct imports from these legacy CSVs or replicas are strictly blocked.
    */
    'disallowed_legacy_sources' => [
        'storage/app/imports/students.csv',
        'storage/app/imports/collection_report.csv',
        'storage/app/imports/outstanding_report.csv',
        'docs/Compatible_Students.csv',
        'docs/Compatible_Dues_Details.csv',
        'docs/Compatible_Paid_Details.csv',
        'docs/Myskool replica - Student information to import.csv',
        'docs/Myskool replica - Dues details.csv',
        'docs/Myskool replica - Paid Details.csv',
    ],
];
