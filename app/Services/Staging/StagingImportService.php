<?php

namespace App\Services\Staging;

use App\Exceptions\DatabaseSafetyException;
use Illuminate\Support\Facades\DB;
use ZipArchive;

class StagingImportService
{
    public const DEFAULT_ZIP_FILENAME = 'IQRA_STAGING_ONLY_20261019_075231-20261019T030357Z-1-001 (1).zip';

    public const REQUIRED_FILES = [
        'summary' => 'STAGING_summary.csv',
        'currency_review' => 'STAGING_currency_review.csv',
        'batches' => 'STAGING_batches.csv',
        'students' => 'STAGING_students.csv',
        'exceptions' => 'STAGING_exceptions.csv',
        'dues' => 'STAGING_dues.csv',
        'paid' => 'STAGING_paid.csv',
    ];

    public const EXPECTED_COUNTS = [
        'batches' => 79,
        'currency_review_total' => 4340,
        'currency_approved' => 4202,
        'currency_unresolved' => 138,
        'students' => 4340,
        'dues' => 16706,
        'paid' => 37595,
        'exceptions' => 16727,
    ];

    /**
     * Locate and open staging zip.
     */
    public function openZip(string $zipPath): ZipArchive
    {
        if (!file_exists($zipPath)) {
            throw new \InvalidArgumentException("Staging zip not found at: {$zipPath}");
        }

        $zip = new ZipArchive();
        $res = $zip->open($zipPath);
        if ($res !== true) {
            throw new \RuntimeException("Failed to open zip file [code {$res}]: {$zipPath}");
        }

        return $zip;
    }

    /**
     * Map archive entry names to standardized keys.
     */
    public function getZipFileMap(ZipArchive $zip): array
    {
        $map = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $fullName = $zip->getNameIndex($i);
            $baseName = basename($fullName);
            foreach (self::REQUIRED_FILES as $key => $expectedFile) {
                if ($baseName === $expectedFile) {
                    $map[$key] = $fullName;
                }
            }
        }

        foreach (self::REQUIRED_FILES as $key => $expectedFile) {
            if (!isset($map[$key])) {
                throw new \RuntimeException("Missing required file '{$expectedFile}' in zip archive.");
            }
        }

        return $map;
    }

    /**
     * Parse summary metrics from zip.
     */
    public function parseSummary(ZipArchive $zip, string $entryName): array
    {
        $fp = $zip->getStream($entryName);
        if (!$fp) {
            throw new \RuntimeException("Cannot stream summary entry: {$entryName}");
        }

        $summary = [];
        while (($row = fgetcsv($fp)) !== false) {
            if (isset($row[0])) {
                $summary[trim($row[0])] = trim($row[1] ?? '');
            }
        }
        fclose($fp);
        return $summary;
    }

    /**
     * Import batches metadata.
     */
    public function importBatches(ZipArchive $zip, string $entryName, string $connection, bool $dryRun = false, int $chunkSize = 200): array
    {
        DatabaseSafetyGuard::assertStagingDatabase($connection);

        $fp = $zip->getStream($entryName);
        $header = fgetcsv($fp);
        $count = 0;
        $inserted = 0;
        $chunk = [];
        $now = now();

        while (($row = fgetcsv($fp)) !== false) {
            if (empty($row) || !isset($row[0])) {
                continue;
            }
            $count++;

            $record = [
                'batch_category' => $row[0] ?? '',
                'source_sheet' => $row[1] ?? '',
                'start_row' => is_numeric($row[2] ?? null) ? (int)$row[2] : null,
                'end_row' => is_numeric($row[3] ?? null) ? (int)$row[3] : null,
                'source_batch_sha256' => $row[4] ?? null,
                'detail_rows_staged' => is_numeric($row[5] ?? null) ? (int)$row[5] : 0,
                'issue_findings' => is_numeric($row[6] ?? null) ? (int)$row[6] : 0,
                'non_detail_rows' => is_numeric($row[7] ?? null) ? (int)$row[7] : 0,
                'verification_status' => $row[8] ?? 'MATCH',
                'verified_at' => !empty($row[9]) ? date('Y-m-d H:i:s', strtotime($row[9])) : $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $chunk[] = $record;

            if (count($chunk) >= $chunkSize) {
                if (!$dryRun) {
                    DB::connection($connection)->table('staging_batches')->upsert(
                        $chunk,
                        ['batch_category', 'source_sheet', 'start_row', 'end_row'],
                        ['source_batch_sha256', 'detail_rows_staged', 'issue_findings', 'non_detail_rows', 'verification_status', 'verified_at', 'updated_at']
                    );
                }
                $inserted += count($chunk);
                $chunk = [];
            }
        }

        if (!empty($chunk)) {
            if (!$dryRun) {
                DB::connection($connection)->table('staging_batches')->upsert(
                    $chunk,
                    ['batch_category', 'source_sheet', 'start_row', 'end_row'],
                    ['source_batch_sha256', 'detail_rows_staged', 'issue_findings', 'non_detail_rows', 'verification_status', 'verified_at', 'updated_at']
                );
            }
            $inserted += count($chunk);
        }

        fclose($fp);

        return ['processed' => $count, 'staged' => $inserted];
    }

    /**
     * Import currency reviews.
     */
    public function importCurrencyReviews(ZipArchive $zip, string $entryName, string $connection, bool $dryRun = false, int $chunkSize = 500): array
    {
        DatabaseSafetyGuard::assertStagingDatabase($connection);

        $fp = $zip->getStream($entryName);
        $header = fgetcsv($fp);
        $count = 0;
        $inserted = 0;
        $approvedCount = 0;
        $unresolvedCount = 0;
        $currencyStats = [];
        $chunk = [];
        $now = now();

        while (($row = fgetcsv($fp)) !== false) {
            if (empty($row) || !isset($row[0])) {
                continue;
            }
            $count++;

            $grNo = trim($row[0] ?? '');
            $suggested = trim($row[3] ?? '');
            $approved = trim($row[4] ?? '');
            $matchStatus = trim($row[5] ?? '');

            if ($approved !== '') {
                $approvedCount++;
                $currencyStats[$approved] = ($currencyStats[$approved] ?? 0) + 1;
                $reviewStatus = 'APPROVED';
            } else {
                $unresolvedCount++;
                $reviewStatus = 'PENDING_REVIEW';
            }

            $record = [
                'gr_number' => $grNo,
                'student_name' => $row[1] ?? null,
                'family_code' => $row[2] ?? null,
                'currency_suggested' => $suggested !== '' ? $suggested : null,
                'currency_approved_manual' => $approved !== '' ? $approved : null,
                'match_status' => $matchStatus !== '' ? $matchStatus : null,
                'paid_detail_lines' => is_numeric($row[6] ?? null) ? (int)$row[6] : 0,
                'dues_detail_lines' => is_numeric($row[7] ?? null) ? (int)$row[7] : 0,
                'recognized_currency_counts' => $row[8] ?? null,
                'unrecognized_source_currency_labels' => $row[9] ?? null,
                'action_needed' => $row[10] ?? null,
                'review_status' => $reviewStatus,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $chunk[] = $record;

            if (count($chunk) >= $chunkSize) {
                if (!$dryRun) {
                    DB::connection($connection)->table('staging_currency_reviews')->upsert(
                        $chunk,
                        ['gr_number'],
                        ['student_name', 'family_code', 'currency_suggested', 'currency_approved_manual', 'match_status', 'paid_detail_lines', 'dues_detail_lines', 'recognized_currency_counts', 'unrecognized_source_currency_labels', 'action_needed', 'review_status', 'updated_at']
                    );
                }
                $inserted += count($chunk);
                $chunk = [];
            }
        }

        if (!empty($chunk)) {
            if (!$dryRun) {
                DB::connection($connection)->table('staging_currency_reviews')->upsert(
                    $chunk,
                    ['gr_number'],
                    ['student_name', 'family_code', 'currency_suggested', 'currency_approved_manual', 'match_status', 'paid_detail_lines', 'dues_detail_lines', 'recognized_currency_counts', 'unrecognized_source_currency_labels', 'action_needed', 'review_status', 'updated_at']
                );
            }
            $inserted += count($chunk);
        }

        fclose($fp);

        return [
            'processed' => $count,
            'staged' => $inserted,
            'approved' => $approvedCount,
            'unresolved' => $unresolvedCount,
            'currencies' => $currencyStats,
        ];
    }

    /**
     * Import student staging records.
     */
    public function importStudents(ZipArchive $zip, string $entryName, string $connection, bool $dryRun = false, int $chunkSize = 500): array
    {
        DatabaseSafetyGuard::assertStagingDatabase($connection);

        $fp = $zip->getStream($entryName);
        $header = fgetcsv($fp);
        $count = 0;
        $inserted = 0;
        $chunk = [];
        $now = now();

        while (($row = fgetcsv($fp)) !== false) {
            if (empty($row) || !isset($row[0])) {
                continue;
            }
            $count++;

            $sourceKey = $row[24] ?? ('STU:' . ($row[23] ?? $count));

            $record = [
                'raw_id' => $row[0] ?? null,
                'gr_number' => trim($row[1] ?? ''),
                'family_code' => !empty(trim($row[2] ?? '')) ? trim($row[2]) : null,
                'student_name' => $row[3] ?? null,
                'father_name' => $row[4] ?? null,
                'gender' => $row[5] ?? null,
                'date_of_birth' => $row[6] ?? null,
                'religion' => $row[7] ?? null,
                'contact_father' => $row[8] ?? null,
                'contact_mother' => $row[9] ?? null,
                'address' => $row[10] ?? null,
                'date_of_admission' => $row[11] ?? null,
                'class_name' => $row[12] ?? null,
                'class_admitted' => $row[13] ?? null,
                'monthly_fee_raw' => $row[14] ?? null,
                'currency_raw' => $row[15] ?? null,
                'status_raw' => $row[16] ?? null,
                'guardian_name' => $row[17] ?? null,
                'rfid' => $row[18] ?? null,
                'date_of_leaving' => $row[19] ?? null,
                'leaving_reason' => $row[20] ?? null,
                'siblings' => $row[21] ?? null,
                'siblings_class' => $row[22] ?? null,
                'source_row' => is_numeric($row[23] ?? null) ? (int)$row[23] : $count,
                'source_key' => $sourceKey,
                'department_hint' => $row[25] ?? null,
                'department_approved' => !empty($row[26]) ? $row[26] : null,
                'campus_approved' => !empty($row[27]) ? $row[27] : null,
                'currency_approved' => !empty($row[28]) ? $row[28] : null,
                'review_issues' => $row[29] ?? null,
                'staging_status' => $row[30] ?? 'REVIEW',
                'raw_row_json' => !empty($row[31]) ? $row[31] : null,
                'currency_suggested' => $row[32] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $chunk[] = $record;

            if (count($chunk) >= $chunkSize) {
                if (!$dryRun) {
                    DB::connection($connection)->table('staging_students')->upsert(
                        $chunk,
                        ['source_key'],
                        [
                            'raw_id', 'gr_number', 'family_code', 'student_name', 'father_name',
                            'gender', 'date_of_birth', 'religion', 'contact_father', 'contact_mother',
                            'address', 'date_of_admission', 'class_name', 'class_admitted', 'monthly_fee_raw',
                            'currency_raw', 'status_raw', 'guardian_name', 'rfid', 'date_of_leaving',
                            'leaving_reason', 'siblings', 'siblings_class', 'source_row', 'department_hint',
                            'department_approved', 'campus_approved', 'currency_approved', 'review_issues',
                            'staging_status', 'raw_row_json', 'currency_suggested', 'updated_at'
                        ]
                    );
                }
                $inserted += count($chunk);
                $chunk = [];
            }
        }

        if (!empty($chunk)) {
            if (!$dryRun) {
                DB::connection($connection)->table('staging_students')->upsert(
                    $chunk,
                    ['source_key'],
                    [
                        'raw_id', 'gr_number', 'family_code', 'student_name', 'father_name',
                        'gender', 'date_of_birth', 'religion', 'contact_father', 'contact_mother',
                        'address', 'date_of_admission', 'class_name', 'class_admitted', 'monthly_fee_raw',
                        'currency_raw', 'status_raw', 'guardian_name', 'rfid', 'date_of_leaving',
                        'leaving_reason', 'siblings', 'siblings_class', 'source_row', 'department_hint',
                        'department_approved', 'campus_approved', 'currency_approved', 'review_issues',
                        'staging_status', 'raw_row_json', 'currency_suggested', 'updated_at'
                    ]
                );
            }
            $inserted += count($chunk);
        }

        fclose($fp);

        return ['processed' => $count, 'staged' => $inserted];
    }

    /**
     * Import dues staging records.
     */
    public function importDues(ZipArchive $zip, string $entryName, string $connection, bool $dryRun = false, int $chunkSize = 500): array
    {
        DatabaseSafetyGuard::assertStagingDatabase($connection);

        $fp = $zip->getStream($entryName);
        $header = fgetcsv($fp);
        $count = 0;
        $inserted = 0;
        $chunk = [];
        $now = now();

        while (($row = fgetcsv($fp)) !== false) {
            if (empty($row) || !isset($row[0])) {
                continue;
            }
            $count++;

            $sourceKey = $row[1] ?? ('DUE:' . ($row[0] ?? $count));
            $amount = is_numeric(str_replace(',', '', $row[9] ?? '0')) ? (float)str_replace(',', '', $row[9]) : 0.00;

            $record = [
                'source_row' => is_numeric($row[0] ?? null) ? (int)$row[0] : $count,
                'source_key' => $sourceKey,
                'report_class' => $row[2] ?? null,
                'gr_number' => trim($row[3] ?? ''),
                'student_name' => $row[4] ?? null,
                'contact_raw' => $row[5] ?? null,
                'particular_raw' => $row[6] ?? null,
                'currency_raw' => $row[7] ?? null,
                'currency_iso' => !empty(trim($row[8] ?? '')) ? strtoupper(trim($row[8])) : null,
                'outstanding_amount' => $amount,
                'period_key' => $row[10] ?? null,
                'fee_description' => $row[11] ?? null,
                'department_hint' => $row[12] ?? null,
                'department_approved' => !empty($row[13]) ? $row[13] : null,
                'campus_approved' => !empty($row[14]) ? $row[14] : null,
                'family_code_approved' => !empty($row[15]) ? $row[15] : null,
                'review_issues' => $row[16] ?? null,
                'staging_status' => $row[17] ?? 'STAGED',
                'raw_row_json' => !empty($row[18]) ? $row[18] : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $chunk[] = $record;

            if (count($chunk) >= $chunkSize) {
                if (!$dryRun) {
                    DB::connection($connection)->table('staging_dues')->upsert(
                        $chunk,
                        ['source_key'],
                        [
                            'source_row', 'report_class', 'gr_number', 'student_name', 'contact_raw',
                            'particular_raw', 'currency_raw', 'currency_iso', 'outstanding_amount',
                            'period_key', 'fee_description', 'department_hint', 'department_approved',
                            'campus_approved', 'family_code_approved', 'review_issues', 'staging_status',
                            'raw_row_json', 'updated_at'
                        ]
                    );
                }
                $inserted += count($chunk);
                $chunk = [];
            }
        }

        if (!empty($chunk)) {
            if (!$dryRun) {
                DB::connection($connection)->table('staging_dues')->upsert(
                    $chunk,
                    ['source_key'],
                    [
                        'source_row', 'report_class', 'gr_number', 'student_name', 'contact_raw',
                        'particular_raw', 'currency_raw', 'currency_iso', 'outstanding_amount',
                        'period_key', 'fee_description', 'department_hint', 'department_approved',
                        'campus_approved', 'family_code_approved', 'review_issues', 'staging_status',
                        'raw_row_json', 'updated_at'
                    ]
                );
            }
            $inserted += count($chunk);
        }

        fclose($fp);

        return ['processed' => $count, 'staged' => $inserted];
    }

    /**
     * Import payments staging records.
     */
    public function importPayments(ZipArchive $zip, string $entryName, string $connection, bool $dryRun = false, int $chunkSize = 500): array
    {
        DatabaseSafetyGuard::assertStagingDatabase($connection);

        $fp = $zip->getStream($entryName);
        $header = fgetcsv($fp);
        $count = 0;
        $inserted = 0;
        $chunk = [];
        $now = now();

        while (($row = fgetcsv($fp)) !== false) {
            if (empty($row) || !isset($row[0])) {
                continue;
            }
            $count++;

            $sourceKey = $row[1] ?? ('PAY:' . ($row[0] ?? $count));
            $amount = is_numeric(str_replace(',', '', $row[11] ?? '0')) ? (float)str_replace(',', '', $row[11]) : 0.00;
            $dateIso = !empty($row[8]) ? date('Y-m-d', strtotime($row[8])) : null;

            $record = [
                'source_row' => is_numeric($row[0] ?? null) ? (int)$row[0] : $count,
                'source_key' => $sourceKey,
                'payment_method' => $row[2] ?? null,
                'slip_number' => $row[3] ?? null,
                'gr_number' => trim($row[4] ?? ''),
                'student_name' => $row[5] ?? null,
                'particular_raw' => $row[6] ?? null,
                'payment_date_raw' => $row[7] ?? null,
                'payment_date_iso' => $dateIso,
                'currency_raw' => $row[9] ?? null,
                'currency_iso' => !empty(trim($row[10] ?? '')) ? strtoupper(trim($row[10])) : null,
                'paid_amount' => $amount,
                'period_key' => $row[12] ?? null,
                'fee_description' => $row[13] ?? null,
                'department_hint' => $row[14] ?? null,
                'department_approved' => !empty($row[15]) ? $row[15] : null,
                'campus_approved' => !empty($row[16]) ? $row[16] : null,
                'family_code_approved' => !empty($row[17]) ? $row[17] : null,
                'review_issues' => $row[18] ?? null,
                'staging_status' => $row[19] ?? 'STAGED',
                'raw_row_json' => !empty($row[20]) ? $row[20] : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $chunk[] = $record;

            if (count($chunk) >= $chunkSize) {
                if (!$dryRun) {
                    DB::connection($connection)->table('staging_payments')->upsert(
                        $chunk,
                        ['source_key'],
                        [
                            'source_row', 'payment_method', 'slip_number', 'gr_number', 'student_name',
                            'particular_raw', 'payment_date_raw', 'payment_date_iso', 'currency_raw',
                            'currency_iso', 'paid_amount', 'period_key', 'fee_description',
                            'department_hint', 'department_approved', 'campus_approved',
                            'family_code_approved', 'review_issues', 'staging_status', 'raw_row_json',
                            'updated_at'
                        ]
                    );
                }
                $inserted += count($chunk);
                $chunk = [];
            }
        }

        if (!empty($chunk)) {
            if (!$dryRun) {
                DB::connection($connection)->table('staging_payments')->upsert(
                    $chunk,
                    ['source_key'],
                    [
                        'source_row', 'payment_method', 'slip_number', 'gr_number', 'student_name',
                        'particular_raw', 'payment_date_raw', 'payment_date_iso', 'currency_raw',
                        'currency_iso', 'paid_amount', 'period_key', 'fee_description',
                        'department_hint', 'department_approved', 'campus_approved',
                        'family_code_approved', 'review_issues', 'staging_status', 'raw_row_json',
                        'updated_at'
                    ]
                );
            }
            $inserted += count($chunk);
        }

        fclose($fp);

        return ['processed' => $count, 'staged' => $inserted];
    }

    /**
     * Import exceptions staging records.
     */
    public function importExceptions(ZipArchive $zip, string $entryName, string $connection, bool $dryRun = false, int $chunkSize = 500): array
    {
        DatabaseSafetyGuard::assertStagingDatabase($connection);

        $fp = $zip->getStream($entryName);
        $header = fgetcsv($fp);
        $count = 0;
        $inserted = 0;
        $chunk = [];
        $now = now();

        while (($row = fgetcsv($fp)) !== false) {
            if (empty($row) || !isset($row[0])) {
                continue;
            }
            $count++;

            $record = [
                'source_sheet' => $row[0] ?? '',
                'source_row' => is_numeric($row[1] ?? null) ? (int)$row[1] : $count,
                'source_key' => $row[2] ?? ('EXC:' . $count),
                'gr_number' => !empty(trim($row[3] ?? '')) ? trim($row[3]) : null,
                'issue_code' => $row[4] ?? 'UNKNOWN_ISSUE',
                'raw_row_json' => !empty($row[5]) ? $row[5] : null,
                'resolution_status' => 'OPEN',
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $chunk[] = $record;

            if (count($chunk) >= $chunkSize) {
                if (!$dryRun) {
                    DB::connection($connection)->table('staging_exceptions')->upsert(
                        $chunk,
                        ['source_key', 'issue_code'],
                        ['source_sheet', 'source_row', 'gr_number', 'raw_row_json', 'updated_at']
                    );
                }
                $inserted += count($chunk);
                $chunk = [];
            }
        }

        if (!empty($chunk)) {
            if (!$dryRun) {
                DB::connection($connection)->table('staging_exceptions')->upsert(
                    $chunk,
                    ['source_key', 'issue_code'],
                    ['source_sheet', 'source_row', 'gr_number', 'raw_row_json', 'updated_at']
                );
            }
            $inserted += count($chunk);
        }

        fclose($fp);

        return ['processed' => $count, 'staged' => $inserted];
    }

    /**
     * Get full reconciliation report comparing staging tables with source expectations.
     */
    public function getReconciliationReport(string $connection = 'staging'): array
    {
        DatabaseSafetyGuard::assertStagingDatabase($connection);

        $batches = DB::connection($connection)->table('staging_batches')->count();
        $currReviews = DB::connection($connection)->table('staging_currency_reviews')->count();
        $currApproved = DB::connection($connection)->table('staging_currency_reviews')->where('review_status', 'APPROVED')->count();
        $currUnresolved = DB::connection($connection)->table('staging_currency_reviews')->where('review_status', 'PENDING_REVIEW')->count();
        $students = DB::connection($connection)->table('staging_students')->count();
        $dues = DB::connection($connection)->table('staging_dues')->count();
        $payments = DB::connection($connection)->table('staging_payments')->count();
        $exceptions = DB::connection($connection)->table('staging_exceptions')->count();

        // Approved currencies breakdown
        $currBreakdown = DB::connection($connection)->table('staging_currency_reviews')
            ->whereNotNull('currency_approved_manual')
            ->where('currency_approved_manual', '!=', '')
            ->select('currency_approved_manual', DB::raw('count(*) as total'))
            ->groupBy('currency_approved_manual')
            ->pluck('total', 'currency_approved_manual')
            ->toArray();

        // Department status breakdown
        $deptPending = DB::connection($connection)->table('staging_students')
            ->whereNull('department_approved')
            ->count();

        // Campus status breakdown
        $campusPending = DB::connection($connection)->table('staging_students')
            ->whereNull('campus_approved')
            ->count();

        return [
            'database' => DatabaseSafetyGuard::getActiveDatabaseName($connection),
            'tables' => [
                'staging_batches' => [
                    'actual' => $batches,
                    'expected' => self::EXPECTED_COUNTS['batches'],
                    'matched' => $batches === self::EXPECTED_COUNTS['batches'],
                ],
                'staging_currency_reviews' => [
                    'actual' => $currReviews,
                    'expected' => self::EXPECTED_COUNTS['currency_review_total'],
                    'matched' => $currReviews === self::EXPECTED_COUNTS['currency_review_total'],
                    'approved' => $currApproved,
                    'unresolved' => $currUnresolved,
                    'breakdown' => $currBreakdown,
                ],
                'staging_students' => [
                    'actual' => $students,
                    'expected' => self::EXPECTED_COUNTS['students'],
                    'matched' => $students === self::EXPECTED_COUNTS['students'],
                    'department_pending_approval' => $deptPending,
                    'campus_pending_approval' => $campusPending,
                ],
                'staging_dues' => [
                    'actual' => $dues,
                    'expected' => self::EXPECTED_COUNTS['dues'],
                    'matched' => $dues === self::EXPECTED_COUNTS['dues'],
                ],
                'staging_payments' => [
                    'actual' => $payments,
                    'expected' => self::EXPECTED_COUNTS['paid'],
                    'matched' => $payments === self::EXPECTED_COUNTS['paid'],
                ],
                'staging_exceptions' => [
                    'actual' => $exceptions,
                    'expected' => self::EXPECTED_COUNTS['exceptions'],
                    'matched' => $exceptions === self::EXPECTED_COUNTS['exceptions'],
                ],
            ],
            'reconciled' => (
                $batches === self::EXPECTED_COUNTS['batches'] &&
                $currApproved === self::EXPECTED_COUNTS['currency_approved'] &&
                $currUnresolved === self::EXPECTED_COUNTS['currency_unresolved'] &&
                $students === self::EXPECTED_COUNTS['students'] &&
                $dues === self::EXPECTED_COUNTS['dues'] &&
                $payments === self::EXPECTED_COUNTS['paid'] &&
                $exceptions === self::EXPECTED_COUNTS['exceptions']
            ),
        ];
    }

    /**
     * Log an approval or staging event to staging_approval_logs.
     */
    public function logApprovalAction(
        string $connection,
        string $entityType,
        ?int $entityId,
        ?string $grNumber,
        string $action,
        ?array $oldValue,
        ?array $newValue,
        string $performedBy,
        ?string $reason = null
    ): void {
        DatabaseSafetyGuard::assertStagingDatabase($connection);

        DB::connection($connection)->table('staging_approval_logs')->insert([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'gr_number' => $grNumber,
            'action' => $action,
            'old_value' => $oldValue ? json_encode($oldValue) : null,
            'new_value' => $newValue ? json_encode($newValue) : null,
            'performed_by' => $performedBy,
            'reason' => $reason,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
