<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Active Import Version Setting
    |--------------------------------------------------------------------------
    | Explicitly identifies the currently authorized import source version.
    | Future imports never silently fall back or select an old CSV or ZIP.
    */
    'active_version' => env('ACTIVE_IMPORT_VERSION', 'v4_final_myskool_20261025'),
    'active_batch_id' => env('ACTIVE_IMPORT_BATCH_ID', 'BATCH-20261025-FINAL-MYSKOOL'),
    'allow_opening_balance_posting' => env('ALLOW_OPENING_BALANCE_POSTING', false),
    'hold_tuition_opening_balances' => env('HOLD_TUITION_OPENING_BALANCES', true),
    'hold_urdu_subject_opening_balances' => env('HOLD_URDU_SUBJECT_OPENING_BALANCES', true),

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
            'label' => 'October 22 Reviewed Workbook (Original Snapshot)',
            'date' => '2026-10-22',
            'type' => 'xlsx',
            'filename' => 'UPdated school record for portal.xlsx',
            'relative_path' => 'storage/app/imports/UPdated school record for portal.xlsx',
            'expected_sha256' => 'd2276cf0c75a0c17b143d1815fda568c9b5cc7e0154f546b8075d1a98e225401',
            'target_database' => 'qa',
            'is_historical' => true,
            'is_active' => false,
        ],
        'v2_reviewed_20261022_revised' => [
            'label' => 'October 22 Reviewed Workbook (Revised QA Snapshot)',
            'date' => '2026-10-22',
            'type' => 'xlsx',
            'filename' => 'UPdated school record for portal.xlsx',
            'relative_path' => 'storage/app/imports/UPdated school record for portal.xlsx',
            'expected_sha256' => 'f48f1ee57eb826776c2f1eb7cab0150640605d4b30f1c761044f7d0d12d0468e',
            'target_database' => 'qa',
            'is_historical' => true,
            'is_active' => false,
        ],
        'v3_final_myskool_20261023' => [
            'label' => 'Final Approved MySkool Workbook (October 23)',
            'date' => '2026-10-23',
            'type' => 'xlsx',
            'filename' => 'My skool fully updated (1).xlsx',
            'relative_path' => 'storage/app/imports/My skool fully updated (1).xlsx',
            'expected_sha256' => '77d8e10f84726cc7b9708bfdb749948a7dbe717d60dab15b91361d5f2e4c4f4b',
            'batch_id' => 'BATCH-20261023-FINAL-MYSKOOL',
            'sheets' => [
                'students' => 'Students',
                'paid_details' => 'Paid details',
                'outstanding' => 'Outstanding (Compiled Valid)',
            ],
            'target_database' => 'iqra_billing',
            'is_historical' => true,
            'is_active' => false,
        ],
        'v4_final_myskool_20261025' => [
            'label' => 'Final Authoritative MySkool Workbook (October 25)',
            'date' => '2026-10-25',
            'type' => 'xlsx',
            'filename' => 'My skool paid (1).xlsx',
            'relative_path' => 'storage/app/imports/My skool paid (1).xlsx',
            'expected_sha256' => 'ac83e8d71b89c992eb3a35ed2cd154929dd522432bb5c5c05742bdd0255622ae',
            'batch_id' => 'BATCH-20261025-FINAL-MYSKOOL',
            'sheets' => [
                'students' => 'Students',
                'paid_details' => 'Paid details',
                'outstanding' => 'Outstanding (Compiled Valid)',
            ],
            'target_database' => 'iqra_billing',
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
