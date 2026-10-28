<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Allow Historical Reconciliation Apply Flag
    |--------------------------------------------------------------------------
    |
    | When false (the default), reconciliation mutations are strictly blocked.
    | Must only be enabled in isolated test environments or with explicit
    | owner authorization and confirmation parameters.
    |
    */
    'allow_apply' => env('ALLOW_HISTORICAL_RECONCILIATION_APPLY', false),

    /*
    |--------------------------------------------------------------------------
    | Default Snapshot Cutoff Date
    |--------------------------------------------------------------------------
    |
    | The default cutoff date corresponding to the October 22, 2026 snapshot.
    | Any historical payment with payment_date > cutoff is considered
    | post-cutoff for settlement reconciliation.
    |
    */
    'default_cutoff' => '2026-10-22',

    /*
    |--------------------------------------------------------------------------
    | Default Batch ID
    |--------------------------------------------------------------------------
    */
    'default_batch_id' => 'BATCH-20261025-FINAL-MYSKOOL',
];
