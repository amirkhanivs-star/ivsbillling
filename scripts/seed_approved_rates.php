<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$feeTypes = DB::table('fee_types')->get()->keyBy('code');

// Define classes and their standard approved rates per fee type
$classConfigs = [
    // School Department (ID: 1)
    [
        'class_label' => '11th (FED) - A',
        'department_id' => 1,
        'currency' => 'SAR',
        'rates' => [
            'monthly' => ['minor' => 35000, 'freq' => 'monthly'],
            'registration' => ['minor' => 10000, 'freq' => 'one_time'],
            'admission' => ['minor' => 50000, 'freq' => 'one_time'],
            'books' => ['minor' => 20000, 'freq' => 'one_time'],
            'slc_charges' => ['minor' => 5000, 'freq' => 'one_time'],
            'exam' => ['minor' => 15000, 'freq' => 'one_time'],
            're_exam' => ['minor' => 10000, 'freq' => 'one_time'],
            'custom' => ['minor' => 7500, 'freq' => 'one_time'],
        ]
    ],
    [
        'class_label' => '11th (FED) - A',
        'department_id' => 1,
        'currency' => 'AED',
        'rates' => [
            'monthly' => ['minor' => 35000, 'freq' => 'monthly'],
            'registration' => ['minor' => 10000, 'freq' => 'one_time'],
            'admission' => ['minor' => 50000, 'freq' => 'one_time'],
            'books' => ['minor' => 20000, 'freq' => 'one_time'],
            'slc_charges' => ['minor' => 5000, 'freq' => 'one_time'],
            'exam' => ['minor' => 15000, 'freq' => 'one_time'],
            're_exam' => ['minor' => 10000, 'freq' => 'one_time'],
            'custom' => ['minor' => 7500, 'freq' => 'one_time'],
        ]
    ],
    [
        'class_label' => '1 - A',
        'department_id' => 1,
        'currency' => 'SAR',
        'rates' => [
            'monthly' => ['minor' => 25000, 'freq' => 'monthly'],
            'registration' => ['minor' => 10000, 'freq' => 'one_time'],
            'admission' => ['minor' => 40000, 'freq' => 'one_time'],
            'books' => ['minor' => 15000, 'freq' => 'one_time'],
            'slc_charges' => ['minor' => 5000, 'freq' => 'one_time'],
            'exam' => ['minor' => 10000, 'freq' => 'one_time'],
            're_exam' => ['minor' => 8000, 'freq' => 'one_time'],
            'custom' => ['minor' => 5000, 'freq' => 'one_time'],
        ]
    ],
    // Tuition Department (ID: 2)
    [
        'class_label' => 'Tuition - A',
        'department_id' => 2,
        'currency' => 'SAR',
        'rates' => [
            'monthly' => ['minor' => 40000, 'freq' => 'monthly'],
            'registration' => ['minor' => 10000, 'freq' => 'one_time'],
            'admission' => ['minor' => 30000, 'freq' => 'one_time'],
            'books' => ['minor' => 15000, 'freq' => 'one_time'],
            'slc_charges' => ['minor' => 5000, 'freq' => 'one_time'],
            'exam' => ['minor' => 12000, 'freq' => 'one_time'],
            're_exam' => ['minor' => 10000, 'freq' => 'one_time'],
            'custom' => ['minor' => 5000, 'freq' => 'one_time'],
        ]
    ]
];

$inserted = 0;
foreach ($classConfigs as $cfg) {
    foreach ($cfg['rates'] as $code => $rateInfo) {
        $ft = $feeTypes->get($code);
        if (!$ft) {
            echo "Warning: fee type code '{$code}' not found.\n";
            continue;
        }

        $exists = DB::table('fee_catalog_rates')
            ->where('fee_type_id', $ft->id)
            ->where('department_id', $cfg['department_id'])
            ->where('class_label', $cfg['class_label'])
            ->where('currency', $cfg['currency'])
            ->exists();

        if (!$exists) {
            DB::table('fee_catalog_rates')->insert([
                'fee_type_id' => $ft->id,
                'department_id' => $cfg['department_id'],
                'class_label' => $cfg['class_label'],
                'currency' => $cfg['currency'],
                'amount_minor' => $rateInfo['minor'],
                'frequency' => $rateInfo['freq'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $inserted++;
        }
    }
}

echo "Successfully seeded {$inserted} approved catalog rates across 8 fee types.\n";
