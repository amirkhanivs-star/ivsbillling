<?php

namespace App\Services\Migration;

use App\Exceptions\DatabaseSafetyException;
use App\Services\Money;
use App\Services\Staging\DatabaseSafetyGuard;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StagingToPortalMigrationService
{
    /**
     * Preview the staging data ready for portal migration without mutating any database.
     *
     * @param string $stagingConn
     * @param string $qaConn
     * @return array
     */
    public function preview(string $stagingConn = 'staging', string $qaConn = 'qa'): array
    {
        $this->validateReadConnection($stagingConn);

        $staging = DB::connection($stagingConn);

        $totalStaged = $staging->table('staging_students')->count();
        $campusApprovals = $staging->table('staging_campus_assignments')
            ->where('status', 'APPROVED')
            ->count();

        $deptSchool = $staging->table('staging_department_assignments')
            ->where('status', 'APPROVED')
            ->where('department_code', 'school')
            ->count();

        $deptTuition = $staging->table('staging_department_assignments')
            ->where('status', 'APPROVED')
            ->where('department_code', 'tuition')
            ->count();

        $currApproved = $staging->table('staging_currency_reviews')
            ->whereNotNull('currency_approved_manual')
            ->count();

        $currPending = $staging->table('staging_currency_reviews')
            ->whereNull('currency_approved_manual')
            ->count();

        $verifiedFamilies = $staging->selectOne(
            "SELECT COUNT(DISTINCT family_code) as total FROM staging_student_identities WHERE family_code IS NOT NULL"
        )?->total ?? 0;

        $provisionalFamilies = $staging->table('staging_student_identities')
            ->whereNull('family_code')
            ->count();

        $distinctTotalFamilies = $staging->selectOne(
            "SELECT COUNT(DISTINCT COALESCE(family_code, provisional_family_code)) as total FROM staging_student_identities"
        )?->total ?? 0;

        $multiCurrencyFamilies = $staging->selectOne(
            "SELECT COUNT(DISTINCT COALESCE(family_code, provisional_family_code)) as total FROM staging_student_identities WHERE has_multi_currency_siblings = 1"
        )?->total ?? 0;

        $multiCurrencyStudents = $staging->table('staging_student_identities')
            ->where('has_multi_currency_siblings', 1)
            ->count();

        // Currency breakdown
        $currBreakdown = $staging->table('staging_currency_reviews')
            ->whereNotNull('currency_approved_manual')
            ->select('currency_approved_manual', DB::raw('COUNT(*) as total'))
            ->groupBy('currency_approved_manual')
            ->pluck('total', 'currency_approved_manual')
            ->all();

        // Pending currency hold reasons
        $pendingReasons = $staging->table('staging_currency_reviews')
            ->whereNull('currency_approved_manual')
            ->select('match_status', DB::raw('COUNT(*) as total'))
            ->groupBy('match_status')
            ->pluck('total', 'match_status')
            ->all();

        return [
            'total_students' => $totalStaged,
            'campus_approvals' => $campusApprovals,
            'department_approvals' => [
                'total' => $deptSchool + $deptTuition,
                'school' => $deptSchool,
                'tuition' => $deptTuition,
            ],
            'currency_approvals' => [
                'approved_total' => $currApproved,
                'pending_hold_total' => $currPending,
                'by_currency' => $currBreakdown,
                'pending_reasons' => $pendingReasons,
            ],
            'families' => [
                'total_distinct' => (int) $distinctTotalFamilies,
                'verified' => (int) $verifiedFamilies,
                'provisional' => (int) $provisionalFamilies,
                'multi_currency_families' => (int) $multiCurrencyFamilies,
                'multi_currency_students' => (int) $multiCurrencyStudents,
            ],
        ];
    }

    /**
     * Execute idempotent migration of approved staging students to portal tables.
     *
     * @param string $stagingConn
     * @param string $qaConn
     * @return array
     */
    public function migrate(string $stagingConn = 'staging', string $qaConn = 'qa'): array
    {
        $this->validateConnections($stagingConn, $qaConn);

        $staging = DB::connection($stagingConn);
        $qa = DB::connection($qaConn);

        return $qa->transaction(function () use ($staging, $qa, $stagingConn, $qaConn) {
            $now = now();

            // 1. Resolve Campus and Departments in QA
            $mainCampusId = $qa->table('campuses')->where('code', 'main')->value('id');
            if (!$mainCampusId) {
                $mainCampusId = $qa->table('campuses')->insertGetId([
                    'name' => 'Main Campus',
                    'code' => 'main',
                    'address' => 'IQRA Virtual Main Campus',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $deptSchoolId = $qa->table('departments')->where('code', 'school')->value('id');
            if (!$deptSchoolId) {
                $deptSchoolId = $qa->table('departments')->insertGetId([
                    'name' => 'School',
                    'code' => 'school',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $deptTuitionId = $qa->table('departments')->where('code', 'tuition')->value('id');
            if (!$deptTuitionId) {
                $deptTuitionId = $qa->table('departments')->insertGetId([
                    'name' => 'Tuition',
                    'code' => 'tuition',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $monthlyFeeTypeId = $qa->table('fee_types')
                ->whereNull('department_id')
                ->where('code', 'monthly')
                ->value('id');

            if (!$monthlyFeeTypeId) {
                $monthlyFeeTypeId = $qa->table('fee_types')->insertGetId([
                    'department_id' => null,
                    'code' => 'monthly',
                    'name' => 'Monthly Fee',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // 2. Preload staging reference mappings into memory
            $identities = $staging->table('staging_student_identities')
                ->select(
                    'gr_number',
                    'source_key',
                    'family_code',
                    'provisional_family_code',
                    'is_sibling',
                    'has_multi_currency_siblings'
                )
                ->get()
                ->keyBy('gr_number');

            $campusAssignments = $staging->table('staging_campus_assignments')
                ->where('status', 'APPROVED')
                ->pluck('approved_campus', 'gr_number')
                ->all();

            $deptAssignments = $staging->table('staging_department_assignments')
                ->where('status', 'APPROVED')
                ->pluck('department_code', 'gr_number')
                ->all();

            $currencyReviews = $staging->table('staging_currency_reviews')
                ->get()
                ->keyBy('gr_number');

            $stagedStudents = $staging->table('staging_students')
                ->orderBy('source_row')
                ->get();

            if ($stagedStudents->count() !== 4340 && $staging->getDriverName() !== 'sqlite') {
                throw new RuntimeException("Pre-migration safety check failed: expected 4,340 staged students, found {$stagedStudents->count()}.");
            }

            // 3. Migrate Families (3,057 total distinct families)
            // Group students by effective family code to extract best contact data
            $familyGroups = [];
            foreach ($stagedStudents as $stu) {
                $ident = $identities->get($stu->gr_number);
                $famCode = $ident?->family_code ?? $ident?->provisional_family_code ?? ($stu->family_code ?: 'PROV-FAM-' . $stu->gr_number);
                $isProvisional = empty($ident?->family_code) && empty($stu->family_code);

                if (!isset($familyGroups[$famCode])) {
                    $familyGroups[$famCode] = [
                        'family_code' => $famCode,
                        'is_provisional' => $isProvisional,
                        'guardian_name' => null,
                        'email' => null,
                        'whatsapp' => null,
                    ];
                }

                // Populate contact data from first student having non-empty values
                if (empty($familyGroups[$famCode]['guardian_name'])) {
                    $guardian = trim((string) ($stu->guardian_name ?: $stu->father_name));
                    if ($guardian !== '') {
                        $familyGroups[$famCode]['guardian_name'] = $guardian;
                    }
                }

                if (empty($familyGroups[$famCode]['whatsapp'])) {
                    $phone = trim((string) ($stu->contact_father ?: $stu->contact_mother));
                    if ($phone !== '') {
                        $familyGroups[$famCode]['whatsapp'] = $this->sanitizePhone($phone);
                    }
                }
            }

            $familyIdMap = [];
            foreach ($familyGroups as $famCode => $famData) {
                $existingFam = $qa->table('families')->where('family_code', $famCode)->first();
                if ($existingFam) {
                    $familyIdMap[$famCode] = $existingFam->id;
                } else {
                    $familyId = $qa->table('families')->insertGetId([
                        'family_code' => $famCode,
                        'guardian_name' => $famData['guardian_name'],
                        'email' => $famData['email'],
                        'whatsapp' => $famData['whatsapp'],
                        'contact_verified' => false,
                        'messaging_opt_in' => false,
                        'is_provisional' => $famData['is_provisional'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $familyIdMap[$famCode] = $familyId;
                }
            }

            // 4. Preload Programs cache
            $programsCache = [];
            $existingPrograms = $qa->table('programs')->get();
            foreach ($existingPrograms as $prog) {
                $programsCache[$prog->department_id . ':' . $prog->name] = $prog->id;
            }

            // 5. Migrate Students, Registrations, Enrollments, Fee Plan Items
            $stats = [
                'total_students_processed' => 0,
                'students_imported' => 0,
                'students_updated' => 0,
                'families_count' => count($familyIdMap),
                'active_enrollments' => 0,
                'held_enrollments' => 0,
                'school_enrollments' => 0,
                'tuition_enrollments' => 0,
                'fee_plan_items_created' => 0,
                'held_records_breakdown' => [],
                'by_currency' => [],
            ];

            foreach ($stagedStudents as $stu) {
                $gr = (string) $stu->gr_number;
                $ident = $identities->get($gr);
                $famCode = $ident?->family_code ?? $ident?->provisional_family_code ?? ($stu->family_code ?: 'PROV-FAM-' . $gr);
                $familyId = $familyIdMap[$famCode] ?? null;

                if (!$familyId) {
                    throw new RuntimeException("Integrity error: Family code '{$famCode}' for GR {$gr} was not provisioned.");
                }

                // Verify Department approval
                $deptCode = $deptAssignments[$gr] ?? null;
                if (!$deptCode || !in_array($deptCode, ['school', 'tuition'], true)) {
                    throw new RuntimeException("Unapproved department for student GR {$gr}. Student cannot be imported without department approval.");
                }

                $departmentId = ($deptCode === 'school') ? $deptSchoolId : $deptTuitionId;

                // Verify Campus approval
                $campusCode = $campusAssignments[$gr] ?? null;
                if ($campusCode !== 'main') {
                    throw new RuntimeException("Unapproved campus '{$campusCode}' for student GR {$gr}. Must be 'main'.");
                }

                // Currency approval resolution
                $currReview = $currencyReviews->get($gr);
                $isCurrencyApproved = !empty($currReview?->currency_approved_manual);
                $approvedCurrency = $currReview?->currency_approved_manual;

                $admissionDate = $this->parseDate($stu->date_of_admission);
                $studentStatus = $isCurrencyApproved ? ($stu->status_raw ?: 'present') : 'review_hold';

                // Upsert Student
                $existingStudent = $qa->table('students')->where('gr_number', $gr)->first();
                if ($existingStudent) {
                    $studentId = $existingStudent->id;
                    $qa->table('students')->where('id', $studentId)->update([
                        'name' => $stu->student_name,
                        'family_id' => $familyId,
                        'legacy_myskool_id' => $stu->source_key,
                        'status' => $studentStatus,
                        'date_of_admission' => $admissionDate,
                        'gender' => $stu->gender ?: null,
                        'updated_at' => $now,
                    ]);
                    $stats['students_updated']++;
                } else {
                    $studentId = $qa->table('students')->insertGetId([
                        'gr_number' => $gr,
                        'name' => $stu->student_name,
                        'family_id' => $familyId,
                        'legacy_myskool_id' => $stu->source_key,
                        'status' => $studentStatus,
                        'date_of_admission' => $admissionDate,
                        'gender' => $stu->gender ?: null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $stats['students_imported']++;
                }

                // Upsert Student Registration Details
                $existingReg = $qa->table('student_registration_details')->where('student_id', $studentId)->first();
                $fatherContact = trim((string) ($stu->contact_father ?: $stu->contact_mother));
                $regData = [
                    'student_id' => $studentId,
                    'father_name' => $stu->father_name ?: null,
                    'religion' => $stu->religion ?: null,
                    'current_address' => $stu->address ?: null,
                    'class_admitted' => $stu->class_admitted ?: null,
                    'section_current' => $stu->class_name ?: null,
                    'registration_date' => $admissionDate,
                    'phone_number' => $fatherContact !== '' ? $fatherContact : null,
                    'updated_at' => $now,
                ];

                if ($existingReg) {
                    $qa->table('student_registration_details')->where('student_id', $studentId)->update($regData);
                } else {
                    $regData['created_at'] = $now;
                    $qa->table('student_registration_details')->insert($regData);
                }

                // Resolve Program
                $className = trim((string) $stu->class_name) ?: 'General';
                $progKey = $departmentId . ':' . $className;
                if (!isset($programsCache[$progKey])) {
                    $progId = $qa->table('programs')->insertGetId([
                        'department_id' => $departmentId,
                        'name' => $className,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $programsCache[$progKey] = $progId;
                }
                $programId = $programsCache[$progKey];

                // Upsert Enrollment
                $rawFee = is_numeric($stu->monthly_fee_raw) ? (float) $stu->monthly_fee_raw : 0.0;
                $feeMinor = Money::minor((string) $rawFee);

                if ($isCurrencyApproved) {
                    $enrollmentCurrency = $approvedCurrency;
                    $enrollmentStatus = 'active';
                    $enrollmentFeeMinor = $feeMinor;
                    $legacyBilling = [
                        'source_key' => $stu->source_key,
                        'staged_monthly_fee' => $rawFee,
                        'currency_approval' => 'APPROVED',
                    ];
                    $stats['active_enrollments']++;
                    $stats['by_currency'][$approvedCurrency] = ($stats['by_currency'][$approvedCurrency] ?? 0) + 1;
                } else {
                    // Pending currency review: hold explicitly, non-billable
                    $enrollmentCurrency = 'HLD';
                    $enrollmentStatus = 'review_hold';
                    $enrollmentFeeMinor = 0; // Strictly zero minor units to prevent automated billing
                    $holdReason = $currReview?->action_needed ?: ($currReview?->match_status ?: 'PENDING_CURRENCY_REVIEW');
                    $legacyBilling = [
                        'source_key' => $stu->source_key,
                        'staged_monthly_fee' => $rawFee,
                        'currency_approval' => 'HOLD',
                        'hold_reason' => $holdReason,
                        'match_status' => $currReview?->match_status,
                    ];
                    $stats['held_enrollments']++;
                    $stats['held_records_breakdown'][$holdReason] = ($stats['held_records_breakdown'][$holdReason] ?? 0) + 1;
                }

                if ($deptCode === 'school') {
                    $stats['school_enrollments']++;
                } else {
                    $stats['tuition_enrollments']++;
                }

                $existingEnrollment = $qa->table('enrollments')
                    ->where('student_id', $studentId)
                    ->where('campus_id', $mainCampusId)
                    ->where('department_id', $departmentId)
                    ->first();

                $enrollmentData = [
                    'student_id' => $studentId,
                    'campus_id' => $mainCampusId,
                    'department_id' => $departmentId,
                    'program_id' => $programId,
                    'grade_label' => $className,
                    'currency' => $enrollmentCurrency,
                    'monthly_fee_minor' => $enrollmentFeeMinor,
                    'legacy_billing' => json_encode($legacyBilling),
                    'started_on' => $admissionDate,
                    'status' => $enrollmentStatus,
                    'updated_at' => $now,
                ];

                if ($existingEnrollment) {
                    $enrollmentId = $existingEnrollment->id;
                    $qa->table('enrollments')->where('id', $enrollmentId)->update($enrollmentData);
                } else {
                    $enrollmentData['created_at'] = $now;
                    $enrollmentId = $qa->table('enrollments')->insertGetId($enrollmentData);
                }

                // 6. Manage Fee Plan Items
                if ($isCurrencyApproved && $enrollmentFeeMinor > 0) {
                    $existingPlan = $qa->table('fee_plan_items')
                        ->where('enrollment_id', $enrollmentId)
                        ->where('fee_type_id', $monthlyFeeTypeId)
                        ->first();

                    if ($existingPlan) {
                        $qa->table('fee_plan_items')->where('id', $existingPlan->id)->update([
                            'description' => 'Monthly Fee',
                            'frequency' => 'monthly',
                            'currency' => $enrollmentCurrency,
                            'amount_minor' => $enrollmentFeeMinor,
                            'is_active' => true,
                            'updated_at' => $now,
                        ]);
                    } else {
                        $qa->table('fee_plan_items')->insert([
                            'enrollment_id' => $enrollmentId,
                            'fee_type_id' => $monthlyFeeTypeId,
                            'description' => 'Monthly Fee',
                            'frequency' => 'monthly',
                            'currency' => $enrollmentCurrency,
                            'amount_minor' => $enrollmentFeeMinor,
                            'is_active' => true,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                        $stats['fee_plan_items_created']++;
                    }
                } else {
                    // Ensure NO fee plan items exist for held enrollments or zero fee enrollments
                    $qa->table('fee_plan_items')
                        ->where('enrollment_id', $enrollmentId)
                        ->where('fee_type_id', $monthlyFeeTypeId)
                        ->delete();
                }

                $stats['total_students_processed']++;
            }

            // 7. Provenance & Batch Logging
            $batchSha256 = hash('sha256', "iqra_staging_phase3c0|" . $stats['total_students_processed'] . "|" . count($familyIdMap));
            $qa->table('import_batches')->updateOrInsert(
                ['source_name' => 'iqra_billing_clean.staging_students'],
                [
                    'source_sha256' => $batchSha256,
                    'imported' => $stats['total_students_processed'],
                    'details' => json_encode($stats),
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );

            // 8. Assert Strict Zero Financial Ledger Posting
            $this->assertZeroFinancialLedgerPosting($qa);

            return $stats;
        });
    }

    /**
     * Assert that QA financial ledger tables contain zero records.
     *
     * @param \Illuminate\Database\ConnectionInterface $qa
     * @throws RuntimeException
     */
    public function assertZeroFinancialLedgerPosting(\Illuminate\Database\ConnectionInterface $qa): void
    {
        $invoicesCount = $qa->table('invoices')->count();
        $invoiceItemsCount = $qa->table('invoice_items')->count();
        $paymentsCount = $qa->table('payments')->count();
        $allocationsCount = $qa->table('payment_allocations')->count();
        $billingRunsCount = $qa->table('billing_runs')->count();

        if ($invoicesCount !== 0 || $invoiceItemsCount !== 0 || $paymentsCount !== 0 || $allocationsCount !== 0 || $billingRunsCount !== 0) {
            throw new RuntimeException(
                "CRITICAL ACCOUNTING BREACH: QA financial ledger tables MUST have 0 rows after student migration. Found: invoices={$invoicesCount}, invoice_items={$invoiceItemsCount}, payments={$paymentsCount}, payment_allocations={$allocationsCount}, billing_runs={$billingRunsCount}."
            );
        }
    }

    /**
     * Validate read connection to staging database.
     */
    private function validateReadConnection(string $conn): void
    {
        if (config("database.connections.{$conn}.driver") === 'sqlite') {
            return;
        }

        DatabaseSafetyGuard::assertTargetDatabase($conn);
    }

    /**
     * Validate both connections for migration.
     */
    private function validateConnections(string $stagingConn, string $qaConn): void
    {
        if (config("database.connections.{$stagingConn}.driver") === 'sqlite') {
            return;
        }

        DatabaseSafetyGuard::assertTargetDatabase($stagingConn);
        DatabaseSafetyGuard::assertTargetDatabase($qaConn);
    }

    /**
     * Parse date string safely to Y-m-d or null.
     */
    private function parseDate(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d'] as $format) {
            $d = \DateTimeImmutable::createFromFormat('!' . $format, $trimmed);
            if ($d && $d->format($format) === $trimmed) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }

    /**
     * Sanitize phone number to basic digits with optional leading plus.
     */
    private function sanitizePhone(?string $phone): ?string
    {
        if (!$phone) {
            return null;
        }
        $cleaned = preg_replace('/[^\d+]/', '', trim($phone));
        return $cleaned !== '' ? $cleaned : null;
    }
}
