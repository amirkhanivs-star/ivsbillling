<?php

/**
 * MySkool Replica Data Conversion & Preprocessing Script
 * 
 * Usage:
 *   php scripts/convert_myskool_data.php
 *   php scripts/convert_myskool_data.php --contacts=path/to/contacts.csv
 *   php scripts/convert_myskool_data.php --import
 */

namespace App\Scripts;

function normalizeCurrency(?string $val): string {
    $c = strtoupper(trim(str_replace(['`', "'", '"', ' '], '', (string)$val)));
    if (in_array($c, ['SAR', 'SAFR', 'SAT', 'SR', 'SAR`'])) return 'SAR';
    if (in_array($c, ['AED', 'SAE', 'DHS', 'DIRHAM', 'AED`'])) return 'AED';
    if (in_array($c, ['RS', 'PKR', 'RUPEES', 'RS.'])) return 'PKR';
    if (in_array($c, ['QAR', 'QR'])) return 'QAR';
    if (in_array($c, ['USD', '$'])) return 'USD';
    if (in_array($c, ['OMR', 'RO'])) return 'OMR';
    if (in_array($c, ['BHD', 'BD'])) return 'BHD';
    return $c ?: 'SAR';
}

function parseParticularPeriod(string $part): array {
    $desc = trim($part);
    $periodStart = '2026-08-01';
    
    // Pattern 1: e.g. "Monthly Fee - Jul-2025" or "Grade 6 - (English) - Sep-2026"
    if (preg_match('/^(.*?)\s*-\s*([A-Za-z]{3})-(\d{4})$/', $part, $m)) {
        $desc = trim($m[1]);
        $month = date('m', strtotime("1 {$m[2]} {$m[3]}"));
        $periodStart = "{$m[3]}-{$month}-01";
    } elseif (preg_match('/^(.*?)\s*-\s*([A-Za-z]{3})\s+(\d{4})$/', $part, $m)) {
        $desc = trim($m[1]);
        $month = date('m', strtotime("1 {$m[2]} {$m[3]}"));
        $periodStart = "{$m[3]}-{$month}-01";
    } elseif (preg_match('/^(.*?)\s*-\s*([A-Za-z]{3})-(\d{2})$/', $part, $m)) {
        $desc = trim($m[1]);
        $year = 2000 + (int)$m[3];
        $month = date('m', strtotime("1 {$m[2]} {$year}"));
        $periodStart = "{$year}-{$month}-01";
    } elseif (preg_match('/^(.*?)\s*-\s*([A-Za-z]{3})$/', $part, $m)) {
        $desc = trim($m[1]);
        $month = date('m', strtotime("1 {$m[2]} 2026"));
        $periodStart = "2026-{$month}-01";
    }

    $periodEnd = date('Y-m-t', strtotime($periodStart));
    $periodKey = substr($periodStart, 0, 7);

    return [
        'description' => $desc,
        'period_key' => $periodKey,
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
    ];
}

function parsePaidDate(string $val): string {
    $val = trim($val);
    if ($val === '') return '2026-08-01';
    
    // 1-Mar-25 or 15-Oct-2026
    if (preg_match('/^(\d{1,2})\s*-\s*([A-Za-z]{3})\s*-\s*(\d{2,4})$/', $val, $m)) {
        $day = (int)$m[1];
        $monthName = $m[2];
        $year = (int)$m[3];
        if ($year < 100) $year += 2000;
        $mNum = date('m', strtotime("1 $monthName $year"));
        return sprintf('%04d-%02d-%02d', $year, $mNum, $day);
    }
    // 15 - Oct (default year 2026)
    if (preg_match('/^(\d{1,2})\s*-\s*([A-Za-z]{3})$/', $val, $m)) {
        $day = (int)$m[1];
        $mNum = date('m', strtotime("1 {$m[2]} 2026"));
        return sprintf('2026-%02d-%02d', $mNum, $day);
    }
    try {
        $ts = strtotime($val);
        if ($ts && $ts > 0) return date('Y-m-d', $ts);
    } catch (\Throwable) {}

    return '2026-08-01';
}

$baseDir = dirname(__DIR__);
$docsDir = $baseDir . DIRECTORY_SEPARATOR . 'docs';
$storageImportsDir = $baseDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'imports';

// SAFETY GUARD: Legacy CSV preprocessing is deactivated.
fwrite(STDERR, "========================================================================\n");
fwrite(STDERR, "LEGACY CONVERTER DEACTIVATED (Phase 3C.1 Cleanup):\n");
fwrite(STDERR, "Legacy CSVs have been moved to storage/app/private/archive_20261022_legacy_csvs/\n");
fwrite(STDERR, "The active authoritative QA source is: storage/app/imports/UPdated school record for portal.xlsx\n");
fwrite(STDERR, "Run 'php artisan qa:import-reviewed-workbook' to ingest reviewed portal data.\n");
fwrite(STDERR, "========================================================================\n");
exit(1);

// Parse CLI options
$options = getopt('', ['contacts:', 'import']);
$contactsFile = $options['contacts'] ?? null;
$contactsMap = [];

if ($contactsFile && file_exists($contactsFile)) {
    echo "Loading external contacts from: $contactsFile\n";
    $cfp = fopen($contactsFile, 'r');
    $cheader = fgetcsv($cfp);
    $grCol = -1; $phoneCol = -1;
    foreach ($cheader as $i => $ch) {
        $chLow = strtolower(trim($ch));
        if (in_array($chLow, ['gr', 'gr no', 'grn', 'registration number', 'id'])) $grCol = $i;
        if (in_array($chLow, ['contact', 'contact #', 'phone', 'mobile', 'whatsapp', 'cell'])) $phoneCol = $i;
    }
    if ($grCol !== -1 && $phoneCol !== -1) {
        while ($crow = fgetcsv($cfp)) {
            $cGr = trim($crow[$grCol] ?? '');
            $cPh = trim($crow[$phoneCol] ?? '');
            if ($cGr !== '' && $cPh !== '') {
                $contactsMap[$cGr] = $cPh;
            }
        }
    }
    fclose($cfp);
    echo "  Loaded " . count($contactsMap) . " contacts.\n";
}

// ----------------------------------------------------
// 1. CONVERT STUDENT INFORMATION
// ----------------------------------------------------
$studentIn = $docsDir . DIRECTORY_SEPARATOR . 'Myskool replica - Student information to import.csv';
$studentOut = $docsDir . DIRECTORY_SEPARATOR . 'Compatible_Students.csv';
$studentStorageOut = $storageImportsDir . DIRECTORY_SEPARATOR . 'students.csv';

echo "\n======================================================\n";
echo "1. Processing Student Information...\n";
echo "======================================================\n";

if (file_exists($studentIn)) {
    $sIn = fopen($studentIn, 'r');
    $sOut = fopen($studentOut, 'w');
    $sStoreOut = fopen($studentStorageOut, 'w');

    $header = fgetcsv($sIn);
    while (!in_array('GR No', (array)$header)) { $header = fgetcsv($sIn); }

    // Normalize header
    $outHeader = [
        'ID', 'GR No', 'Family Code', 'Student Name', 'Father Name', 'Gender',
        'Date of Birth', 'Religion', 'Contact (Father)', 'Contact (Mother)',
        'Address', 'Date of Admission', 'Class', 'Class Admitted', 'Monthly Fee',
        'Status', 'Guardian Name', 'RFID', 'Date of Leaving', 'Leaving Reason',
        'Siblings', 'Siblings Class'
    ];
    fputcsv($sOut, $outHeader);
    fputcsv($sStoreOut, $outHeader);

    $idIdx = array_search('ID', $header);
    $grIdx = array_search('GR No', $header);
    $famIdx = array_search('Family Code', $header);
    if ($famIdx === false) $famIdx = array_search('family', $header);
    $nameIdx = array_search('Student Name', $header);
    $fatherIdx = array_search('Father Name', $header);
    $genderIdx = array_search('Gender', $header);
    $dobIdx = array_search('Date of Birth', $header);
    $relIdx = array_search('Religion', $header);
    $contactFIdx = array_search('Contact (Father)', $header);
    $contactMIdx = array_search('Contact (Mother)', $header);
    $addressIdx = array_search('Address', $header);
    $doaIdx = array_search('Date of Admission', $header);
    $classIdx = array_search('Class', $header);
    $classAdmIdx = array_search('Class Admitted', $header);
    $feeIdx = array_search('Monthly Fee', $header);
    if ($feeIdx === false) $feeIdx = array_search('monthly', $header);
    $statusIdx = array_search('Status', $header);
    if ($statusIdx === false) $statusIdx = array_search('status', $header);
    $guardianIdx = array_search('Guardian Name', $header);
    if ($guardianIdx === false) $guardianIdx = array_search('guardian', $header);
    $rfidIdx = array_search('RFID', $header);
    $dolIdx = array_search('Date of Leaving', $header);
    $lrIdx = array_search('Leaving Reason', $header);
    $sibIdx = array_search('Siblings', $header);
    $sibClsIdx = array_search('Siblings Class', $header);

    $studentCount = 0;
    while ($row = fgetcsv($sIn)) {
        $gr = trim($row[$grIdx] ?? '');
        if ($gr === '') continue;

        $father = $fatherIdx !== false ? trim($row[$fatherIdx] ?? '') : '';
        $contactF = $contactFIdx !== false ? trim($row[$contactFIdx] ?? '') : '';
        if ($contactF === '' && isset($contactsMap[$gr])) {
            $contactF = $contactsMap[$gr];
        }

        $statusRaw = $statusIdx !== false ? strtolower(trim($row[$statusIdx] ?? '')) : '';
        $status = in_array($statusRaw, ['left', 'inactive', 'withdrawn', 'struck off']) ? 'left' : 'present';

        $cleanRow = [
            $idIdx !== false ? trim($row[$idIdx] ?? '') : '',
            $gr,
            $famIdx !== false ? trim($row[$famIdx] ?? '') : $gr,
            $nameIdx !== false ? trim($row[$nameIdx] ?? '') : '',
            $father,
            $genderIdx !== false ? trim($row[$genderIdx] ?? '') : '',
            $dobIdx !== false ? trim($row[$dobIdx] ?? '') : '',
            $relIdx !== false ? trim($row[$relIdx] ?? '') : 'ISLAM',
            $contactF,
            $contactMIdx !== false ? trim($row[$contactMIdx] ?? '') : '',
            $addressIdx !== false ? trim($row[$addressIdx] ?? '') : '',
            $doaIdx !== false ? trim($row[$doaIdx] ?? '') : '',
            $classIdx !== false ? trim($row[$classIdx] ?? '') : 'General',
            $classAdmIdx !== false ? trim($row[$classAdmIdx] ?? '') : '',
            $feeIdx !== false ? trim($row[$feeIdx] ?? '') : '0',
            $status,
            $father ?: ($guardianIdx !== false ? trim($row[$guardianIdx] ?? '') : ''),
            $rfidIdx !== false ? trim($row[$rfidIdx] ?? '') : '',
            $dolIdx !== false ? trim($row[$dolIdx] ?? '') : '',
            $lrIdx !== false ? trim($row[$lrIdx] ?? '') : '',
            $sibIdx !== false ? trim($row[$sibIdx] ?? '') : '',
            $sibClsIdx !== false ? trim($row[$sibClsIdx] ?? '') : '',
        ];

        fputcsv($sOut, $cleanRow);
        fputcsv($sStoreOut, $cleanRow);
        $studentCount++;
    }

    fclose($sIn);
    fclose($sOut);
    fclose($sStoreOut);

    echo "  Processed $studentCount students.\n";
    echo "  Generated: $studentOut\n";
    echo "  Synchronized to: $studentStorageOut\n";
} else {
    echo "  Notice: $studentIn not found.\n";
}

// ----------------------------------------------------
// 2. CONVERT DUES DETAILS (FILTER OUT 3,136 SUMMATIONS)
// ----------------------------------------------------
$duesIn = $docsDir . DIRECTORY_SEPARATOR . 'Myskool replica - Dues details.csv';
$duesOut = $docsDir . DIRECTORY_SEPARATOR . 'Compatible_Dues_Details.csv';
$duesStorageOut = $storageImportsDir . DIRECTORY_SEPARATOR . 'outstanding_report.csv';

echo "\n======================================================\n";
echo "2. Processing Dues Details (Removing Summation Rows)...\n";
echo "======================================================\n";

if (file_exists($duesIn)) {
    $dIn = fopen($duesIn, 'r');
    $dOut = fopen($duesOut, 'w');
    $dStoreOut = fopen($duesStorageOut, 'w');

    // Header
    fputcsv($dOut, ['Class', 'GRN', 'Student Name', 'Contact #', 'Particular', 'Currency', 'Balance']);
    
    // Standard MySkool style format for storage app import
    fputcsv($dStoreOut, ['IQRA VIRTUAL SCHOOL', 'Agha Meer Jani Road Wazir Bagh Peshawar', 'Outstanding Report', '', '', '', '']);
    fputcsv($dStoreOut, ['', '', '', '', '', '', '']);
    fputcsv($dStoreOut, ['Class', 'GRN', 'Student Name', 'Contact #', 'Particular', 'Currency', 'Balance']);

    $headerFound = false;
    $currentClass = 'General';
    $currentGr = '';
    $currentName = '';
    $currentContact = '';
    $duesCount = 0;
    $skippedSummations = 0;

    while (($row = fgetcsv($dIn)) !== false) {
        if (!$headerFound) {
            $joined = strtolower(implode(' ', array_map('trim', $row)));
            if (str_contains($joined, 'grn') && str_contains($joined, 'particular')) {
                $headerFound = true;
            }
            continue;
        }

        $col0 = trim($row[0] ?? '');
        $col1 = trim($row[1] ?? ''); // GRN or Summation
        $col2 = trim($row[2] ?? ''); // Student Name
        $col3 = trim($row[3] ?? ''); // Contact #
        $col4 = trim($row[4] ?? ''); // Particular
        $col5 = trim($row[5] ?? ''); // Currency
        $col6 = trim($row[6] ?? ''); // Balance

        // Class section header e.g. "FS-1 - A" in col 0
        if ($col0 !== '' && $col1 === '' && $col4 === '') {
            $currentClass = $col0;
            continue;
        }

        // Summation or subtotal row (has amount in col 1, but no student name and no particular)
        if ($col1 !== '' && $col2 === '' && $col4 === '') {
            $skippedSummations++;
            $currentGr = '';
            $currentName = '';
            continue;
        }

        // Class total summary line e.g. "FS-1 - A 171 students - Rs 293,086"
        if (stripos($col1, 'students') !== false || stripos($col0, 'students') !== false) {
            $skippedSummations++;
            continue;
        }

        // Student main line
        if ($col1 !== '' && is_numeric(str_replace(',', '', $col1)) && $col2 !== '') {
            $currentGr = trim(str_replace(',', '', $col1));
            $currentName = $col2;
            $currentContact = $col3;
        }

        // Valid Fee Item
        if ($currentGr !== '' && $col4 !== '' && is_numeric(str_replace(',', '', $col6))) {
            $balance = (float)str_replace(',', '', $col6);
            if ($balance <= 0) continue;

            $currency = normalizeCurrency($col5);

            $cleanDuesRow = [
                $currentClass,
                $currentGr,
                $currentName,
                $currentContact,
                $col4,
                $currency,
                number_format($balance, 2, '.', '')
            ];

            fputcsv($dOut, $cleanDuesRow);
            fputcsv($dStoreOut, $cleanDuesRow);
            $duesCount++;
        }
    }

    fclose($dIn);
    fclose($dOut);
    fclose($dStoreOut);

    echo "  Processed $duesCount dues items.\n";
    echo "  Filtered out $skippedSummations subtotal/summation rows successfully.\n";
    echo "  Generated: $duesOut\n";
    echo "  Synchronized to: $duesStorageOut\n";
} else {
    echo "  Notice: $duesIn not found.\n";
}

// ----------------------------------------------------
// 3. CONVERT PAID DETAILS (COLLECTION REPORT)
// ----------------------------------------------------
$paidIn = $docsDir . DIRECTORY_SEPARATOR . 'Myskool replica - Paid Details.csv';
$paidOut = $docsDir . DIRECTORY_SEPARATOR . 'Compatible_Paid_Details.csv';
$paidStorageOut = $storageImportsDir . DIRECTORY_SEPARATOR . 'collection_report.csv';

echo "\n======================================================\n";
echo "3. Processing Paid Details (Collection Report)...\n";
echo "======================================================\n";

if (file_exists($paidIn)) {
    $pIn = fopen($paidIn, 'r');
    $pOut = fopen($paidOut, 'w');
    $pStoreOut = fopen($paidStorageOut, 'w');

    // Headers
    fputcsv($pOut, ['Slip #', 'GRN', 'Student Name', 'Particular', 'Payment Date', 'Currency', 'Amount']);
    
    // MySkool format for storage
    fputcsv($pStoreOut, ['IQRA VIRTUAL SCHOOL', 'Agha Meer Jani Road Wazir Bagh Peshawar', 'Collection Report', '', '', '', '', '']);
    fputcsv($pStoreOut, ['', '', '', '', '', '', '', '']);
    fputcsv($pStoreOut, ['Cash', 'Slip #', 'GRN', 'Student Name', 'Particular', 'Date', 'Currency', 'Amount']);

    $headerFound = false;
    $currentSlip = '';
    $currentGr = '';
    $currentName = '';
    $paidCount = 0;

    while (($row = fgetcsv($pIn)) !== false) {
        if (!$headerFound) {
            $joined = strtolower(implode(' ', array_map('trim', $row)));
            if (str_contains($joined, 'slip') && str_contains($joined, 'particular')) {
                $headerFound = true;
            }
            continue;
        }

        $col1 = trim($row[1] ?? ''); // Slip #
        $col2 = trim($row[2] ?? ''); // GRN
        $col3 = trim($row[3] ?? ''); // Student Name
        $col4 = trim($row[4] ?? ''); // Particular
        $col5 = trim($row[5] ?? ''); // Date
        $col6 = trim($row[6] ?? ''); // Currency
        $col7 = trim($row[7] ?? ''); // Amount

        if ($col1 !== '') $currentSlip = $col1;
        if ($col2 !== '') {
            $currentGr = trim(str_replace(',', '', $col2));
            $currentName = $col3;
        }

        // Paid fee line
        if ($currentSlip !== '' && $currentGr !== '' && $col4 !== '' && is_numeric(str_replace(',', '', $col7))) {
            $amt = (float)str_replace(',', '', $col7);
            if ($amt <= 0) continue;

            $currency = normalizeCurrency($col6);
            $parsedDate = parsePaidDate($col5);

            $cleanPaidRow = [
                $currentSlip,
                $currentGr,
                $currentName,
                $col4,
                $parsedDate,
                $currency,
                number_format($amt, 2, '.', '')
            ];

            fputcsv($pOut, $cleanPaidRow);
            fputcsv($pStoreOut, ['', $currentSlip, $currentGr, $currentName, $col4, $parsedDate, $currency, number_format($amt, 2, '.', '')]);
            $paidCount++;
        }
    }

    fclose($pIn);
    fclose($pOut);
    fclose($pStoreOut);

    echo "  Processed $paidCount payment items.\n";
    echo "  Generated: $paidOut\n";
    echo "  Synchronized to: $paidStorageOut\n";
} else {
    echo "  Notice: $paidIn not found.\n";
}

echo "\n======================================================\n";
echo "CONVERSION COMPLETED SUCCESSFULLY!\n";
echo "Compatible files generated in docs/ and synchronized to storage/app/imports/\n";
echo "======================================================\n";
