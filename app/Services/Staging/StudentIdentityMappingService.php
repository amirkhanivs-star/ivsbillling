<?php

namespace App\Services\Staging;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class StudentIdentityMappingService
{
    /**
     * Process student identities and family groupings.
     */
    public function processStudentIdentities(string $connection = 'staging', int $chunkSize = 500): array
    {
        DatabaseSafetyGuard::assertStagingDatabase($connection);

        $now = now();
        $students = DB::connection($connection)->table('staging_students')->get();
        $currReviews = DB::connection($connection)->table('staging_currency_reviews')
            ->pluck('currency_approved_manual', 'gr_number')
            ->toArray();

        // 1. Group siblings by original family_code
        $familyGroups = [];
        foreach ($students as $stu) {
            $fam = trim((string) $stu->family_code);
            if ($fam !== '') {
                $familyGroups[$fam][] = $stu->gr_number;
            }
        }

        // 2. Detect multi-currency sibling groups
        $multiCurrencyFamilies = [];
        foreach ($familyGroups as $fam => $grs) {
            if (count($grs) > 1) {
                $currencies = [];
                foreach ($grs as $gr) {
                    $c = $currReviews[$gr] ?? null;
                    if (!empty($c)) {
                        $currencies[$c] = true;
                    }
                }
                if (count($currencies) > 1) {
                    $multiCurrencyFamilies[$fam] = true;
                }
            }
        }

        // 3. Process records
        $records = [];
        $verifiedCount = 0;
        $provisionalCount = 0;
        $siblingStudentCount = 0;

        foreach ($students as $stu) {
            $fam = trim((string) $stu->family_code);
            $hasFamily = ($fam !== '');
            $siblings = $hasFamily ? ($familyGroups[$fam] ?? []) : [];
            $isSibling = count($siblings) > 1;

            if ($isSibling) {
                $siblingStudentCount++;
            }

            $provFamilyCode = null;
            $mappingStatus = 'VERIFIED';

            if (!$hasFamily) {
                $provisionalCount++;
                $mappingStatus = 'PROVISIONAL_FAMILY';
                $provFamilyCode = 'PROV-FAM-' . $stu->gr_number;
            } else {
                $verifiedCount++;
            }

            $records[] = [
                'source_key' => $stu->source_key,
                'raw_id' => $stu->raw_id,
                'gr_number' => $stu->gr_number,
                'family_code' => $hasFamily ? $fam : null,
                'provisional_family_code' => $provFamilyCode,
                'student_name' => $stu->student_name,
                'father_name' => $stu->father_name,
                'guardian_name' => $stu->guardian_name,
                'status' => $stu->status_raw,
                'date_of_admission' => $stu->date_of_admission,
                'date_of_leaving' => $stu->date_of_leaving,
                'leaving_reason' => $stu->leaving_reason,
                'class_name' => $stu->class_name,
                'class_admitted' => $stu->class_admitted,
                'is_sibling' => $isSibling,
                'sibling_count' => count($siblings),
                'sibling_gr_numbers' => $isSibling ? implode(', ', $siblings) : null,
                'has_multi_currency_siblings' => $hasFamily && isset($multiCurrencyFamilies[$fam]),
                'mapping_status' => $mappingStatus,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // 4. Chunked Upsert
        foreach (array_chunk($records, $chunkSize) as $chunk) {
            DB::connection($connection)->table('staging_student_identities')->upsert(
                $chunk,
                ['source_key'],
                [
                    'raw_id', 'gr_number', 'family_code', 'provisional_family_code',
                    'student_name', 'father_name', 'guardian_name', 'status',
                    'date_of_admission', 'date_of_leaving', 'leaving_reason',
                    'class_name', 'class_admitted', 'is_sibling', 'sibling_count',
                    'sibling_gr_numbers', 'has_multi_currency_siblings', 'mapping_status',
                    'updated_at'
                ]
            );
        }

        return [
            'total_students' => count($students),
            'verified_family_mappings' => $verifiedCount,
            'provisional_family_mappings' => $provisionalCount,
            'unique_family_codes' => count($familyGroups),
            'sibling_families_count' => count(array_filter($familyGroups, fn($g) => count($g) > 1)),
            'sibling_students_count' => $siblingStudentCount,
            'multi_currency_families_count' => count($multiCurrencyFamilies),
        ];
    }

    /**
     * Process initial campus assignment proposals.
     */
    public function processCampusProposals(string $connection = 'staging', int $chunkSize = 500): array
    {
        DatabaseSafetyGuard::assertStagingDatabase($connection);

        $now = now();
        $students = DB::connection($connection)->table('staging_students')->get();
        $records = [];

        foreach ($students as $stu) {
            $records[] = [
                'source_key' => $stu->source_key,
                'gr_number' => $stu->gr_number,
                'class_name' => $stu->class_name,
                'proposed_campus' => 'main',
                'approved_campus' => null, // Never auto-approved
                'status' => 'PENDING',
                'notes' => 'Proposed Main Campus based on institutional default; awaiting administrative approval',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($records, $chunkSize) as $chunk) {
            DB::connection($connection)->table('staging_campus_assignments')->upsert(
                $chunk,
                ['source_key'],
                ['gr_number', 'class_name', 'proposed_campus', 'updated_at']
            );
        }

        return [
            'total_proposals' => count($students),
            'pending_approval' => count($students),
            'approved' => 0,
        ];
    }

    /**
     * Process initial department classification proposals.
     */
    public function processDepartmentProposals(string $connection = 'staging', int $chunkSize = 500): array
    {
        DatabaseSafetyGuard::assertStagingDatabase($connection);

        $now = now();
        $students = DB::connection($connection)->table('staging_students')->get();
        $records = [];
        $schoolCount = 0;
        $tuitionCount = 0;

        foreach ($students as $stu) {
            $hint = strtolower(trim((string) $stu->department_hint));
            $className = strtolower((string) $stu->class_name);

            if ($hint === 'tuition' || str_contains($className, 'tuition')) {
                $dept = 'tuition';
                $evidence = 'source_department_hint';
                $tuitionCount++;
            } else {
                $dept = 'school';
                $evidence = 'source_department_hint';
                $schoolCount++;
            }

            $records[] = [
                'source_key' => $stu->source_key,
                'gr_number' => $stu->gr_number,
                'class_name' => $stu->class_name,
                'department_code' => $dept,
                'evidence_source' => $evidence,
                'status' => 'PROPOSED', // Never auto-approved
                'is_primary' => true,
                'notes' => "Proposed {$dept} classification based on {$evidence}",
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($records, $chunkSize) as $chunk) {
            DB::connection($connection)->table('staging_department_assignments')->upsert(
                $chunk,
                ['source_key', 'department_code'],
                ['gr_number', 'class_name', 'evidence_source', 'is_primary', 'updated_at']
            );
        }

        return [
            'total_proposals' => count($students),
            'school_proposed' => $schoolCount,
            'tuition_proposed' => $tuitionCount,
            'quran_proposed' => 0,
            'pending_approval' => count($students),
            'approved' => 0,
        ];
    }

    /**
     * Process historical unknown and unlinked GR reconciliations.
     */
    public function processHistoricalGrReconciliations(string $connection = 'staging', int $chunkSize = 500): array
    {
        DatabaseSafetyGuard::assertStagingDatabase($connection);

        $now = now();

        // 1. Build lookup maps from active staging students
        $knownStudents = DB::connection($connection)->table('staging_students')
            ->select('gr_number', 'student_name')
            ->get();

        $grMap = [];
        $normalizedNameMap = [];

        foreach ($knownStudents as $ks) {
            $grMap[$ks->gr_number] = $ks->student_name;
            $norm = $this->normalizeName($ks->student_name);
            if ($norm !== '') {
                $normalizedNameMap[$norm] = $ks->gr_number;
            }
        }

        // 2. Process Unknown Dues
        $dues = DB::connection($connection)->table('staging_dues')->get();
        $duesRecon = [];
        $unknownDuesCount = 0;
        $matchedDuesCount = 0;

        foreach ($dues as $d) {
            if (!isset($grMap[$d->gr_number])) {
                $unknownDuesCount++;
                $norm = $this->normalizeName($d->student_name);
                $suggestedGr = $normalizedNameMap[$norm] ?? null;
                $confidence = 'NO_MATCH';

                if ($suggestedGr) {
                    $matchedDuesCount++;
                    $confidence = 'EXACT_NAME';
                }

                $duesRecon[] = [
                    'source_type' => 'due',
                    'source_key' => $d->source_key,
                    'source_row' => $d->source_row,
                    'raw_gr_number' => $d->gr_number,
                    'raw_student_name' => $d->student_name,
                    'amount' => $d->outstanding_amount,
                    'currency_iso' => $d->currency_iso,
                    'slip_number' => null,
                    'particular_raw' => $d->particular_raw,
                    'transaction_date' => $d->period_key,
                    'suggested_match_gr' => $suggestedGr,
                    'suggested_match_name' => $suggestedGr ? ($grMap[$suggestedGr] ?? null) : null,
                    'match_confidence' => $confidence,
                    'reconciliation_status' => 'UNRESOLVED',
                    'resolved_gr_number' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // 3. Process Unknown & Unlinked Payments
        $payments = DB::connection($connection)->table('staging_payments')->get();
        $payRecon = [];
        $unknownPayCount = 0;
        $unlinkedPayCount = 0;
        $matchedPayCount = 0;

        foreach ($payments as $p) {
            $isUnlinked = empty(trim((string) $p->gr_number));
            $isUnknown = (!$isUnlinked && !isset($grMap[$p->gr_number]));

            if ($isUnlinked || $isUnknown) {
                if ($isUnlinked) {
                    $unlinkedPayCount++;
                    $confidence = 'UNLINKED';
                    $suggestedGr = null;
                } else {
                    $unknownPayCount++;
                    $norm = $this->normalizeName($p->student_name);
                    $suggestedGr = $normalizedNameMap[$norm] ?? null;
                    if ($suggestedGr) {
                        $matchedPayCount++;
                        $confidence = 'EXACT_NAME';
                    } else {
                        $confidence = 'NO_MATCH';
                    }
                }

                $payRecon[] = [
                    'source_type' => 'payment',
                    'source_key' => $p->source_key,
                    'source_row' => $p->source_row,
                    'raw_gr_number' => $isUnlinked ? null : $p->gr_number,
                    'raw_student_name' => $p->student_name,
                    'amount' => $p->paid_amount,
                    'currency_iso' => $p->currency_iso,
                    'slip_number' => $p->slip_number,
                    'particular_raw' => $p->particular_raw,
                    'transaction_date' => $p->payment_date_raw,
                    'suggested_match_gr' => $suggestedGr,
                    'suggested_match_name' => $suggestedGr ? ($grMap[$suggestedGr] ?? null) : null,
                    'match_confidence' => $confidence,
                    'reconciliation_status' => 'UNRESOLVED',
                    'resolved_gr_number' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // 4. Chunked Upsert
        $allRecon = array_merge($duesRecon, $payRecon);
        foreach (array_chunk($allRecon, $chunkSize) as $chunk) {
            DB::connection($connection)->table('staging_gr_reconciliations')->upsert(
                $chunk,
                ['source_key'],
                [
                    'source_type', 'source_row', 'raw_gr_number', 'raw_student_name',
                    'amount', 'currency_iso', 'slip_number', 'particular_raw',
                    'transaction_date', 'suggested_match_gr', 'suggested_match_name',
                    'match_confidence', 'updated_at'
                ]
            );
        }

        return [
            'unknown_dues_entries' => $unknownDuesCount,
            'dues_suggested_matches' => $matchedDuesCount,
            'unknown_payment_entries' => $unknownPayCount,
            'unlinked_payment_entries' => $unlinkedPayCount,
            'payment_suggested_matches' => $matchedPayCount,
            'total_reconciliation_records' => count($allRecon),
        ];
    }

    /**
     * Bulk approve campus mapping.
     */
    public function approveCampusBulk(
        string $connection,
        array $criteria,
        string $targetCampus,
        string $approverName,
        ?string $notes = null
    ): int {
        DatabaseSafetyGuard::assertStagingDatabase($connection);

        // Verify campus exists in master if table exists
        if (Schema::connection($connection)->hasTable('campuses')) {
            $campusExists = DB::connection($connection)->table('campuses')
                ->where('code', $targetCampus)
                ->orWhere('name', $targetCampus)
                ->exists();
            if (!$campusExists) {
                throw new \InvalidArgumentException("Target campus '{$targetCampus}' does not exist in campuses master.");
            }
        }

        $query = DB::connection($connection)->table('staging_campus_assignments')
            ->where('status', 'PENDING');

        if (!empty($criteria['class_name'])) {
            $query->where('class_name', $criteria['class_name']);
        }
        if (!empty($criteria['proposed_campus'])) {
            $query->where('proposed_campus', $criteria['proposed_campus']);
        }
        if (!empty($criteria['gr_numbers'])) {
            $query->whereIn('gr_number', (array) $criteria['gr_numbers']);
        }

        $count = $query->count();
        if ($count === 0) {
            return 0;
        }

        $now = now();
        $query->update([
            'approved_campus' => $targetCampus,
            'status' => 'APPROVED',
            'approved_by' => $approverName,
            'approved_at' => $now,
            'notes' => $notes ?: "Bulk approved campus '{$targetCampus}' for {$count} students",
            'updated_at' => $now,
        ]);

        // Synchronize staging_students.campus_approved for affected students
        if (Schema::connection($connection)->hasTable('staging_students')) {
            $grQuery = DB::connection($connection)->table('staging_campus_assignments')
                ->where('approved_campus', $targetCampus)
                ->where('status', 'APPROVED');
            if (!empty($criteria['class_name'])) {
                $grQuery->where('class_name', $criteria['class_name']);
            }
            $affectedGrs = $grQuery->pluck('gr_number')->toArray();
            foreach (array_chunk($affectedGrs, 500) as $grChunk) {
                DB::connection($connection)->table('staging_students')
                    ->whereIn('gr_number', $grChunk)
                    ->update(['campus_approved' => $targetCampus]);
            }
        }

        // Audit log entry
        DB::connection($connection)->table('staging_approval_logs')->insert([
            'entity_type' => 'campus',
            'entity_id' => null,
            'gr_number' => null,
            'action' => 'CAMPUS_BULK_APPROVAL',
            'old_value' => json_encode(['status' => 'PENDING', 'approved_campus' => null]),
            'new_value' => json_encode(['status' => 'APPROVED', 'approved_campus' => $targetCampus, 'criteria' => $criteria, 'affected_count' => $count]),
            'performed_by' => $approverName,
            'reason' => $notes ?: 'Administrative bulk approval',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $count;
    }

    /**
     * Correct a specific student's campus assignment with full audit trail.
     */
    public function correctCampusAssignment(
        string $connection,
        string $grNumber,
        string $newCampus,
        string $approverName,
        ?string $reason = null
    ): bool {
        DatabaseSafetyGuard::assertStagingDatabase($connection);

        if (Schema::connection($connection)->hasTable('campuses')) {
            $campusExists = DB::connection($connection)->table('campuses')
                ->where('code', $newCampus)
                ->orWhere('name', $newCampus)
                ->exists();
            if (!$campusExists) {
                throw new \InvalidArgumentException("Target campus '{$newCampus}' does not exist in campuses master.");
            }
        }

        $existing = DB::connection($connection)->table('staging_campus_assignments')
            ->where('gr_number', $grNumber)
            ->first();

        if (!$existing) {
            return false;
        }

        $now = now();
        $oldValue = [
            'approved_campus' => $existing->approved_campus,
            'status' => $existing->status,
            'approved_by' => $existing->approved_by,
            'approved_at' => $existing->approved_at,
        ];

        DB::connection($connection)->table('staging_campus_assignments')
            ->where('gr_number', $grNumber)
            ->update([
                'approved_campus' => $newCampus,
                'status' => 'APPROVED',
                'approved_by' => $approverName,
                'approved_at' => $now,
                'notes' => $reason ?: "Campus corrected to '{$newCampus}'",
                'updated_at' => $now,
            ]);

        if (Schema::connection($connection)->hasTable('staging_students')) {
            DB::connection($connection)->table('staging_students')
                ->where('gr_number', $grNumber)
                ->update(['campus_approved' => $newCampus]);
        }

        DB::connection($connection)->table('staging_approval_logs')->insert([
            'entity_type' => 'campus',
            'entity_id' => $existing->id,
            'gr_number' => $grNumber,
            'action' => 'CAMPUS_ASSIGNMENT_CORRECTION',
            'old_value' => json_encode($oldValue),
            'new_value' => json_encode(['approved_campus' => $newCampus, 'status' => 'APPROVED', 'approved_by' => $approverName]),
            'performed_by' => $approverName,
            'reason' => $reason ?: 'Administrative campus correction',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return true;
    }

    /**
     * Bulk approve department classification.
     */
    public function approveDepartmentBulk(
        string $connection,
        array $criteria,
        string $targetDepartment,
        string $approverName,
        ?string $notes = null
    ): int {
        DatabaseSafetyGuard::assertStagingDatabase($connection);

        $query = DB::connection($connection)->table('staging_department_assignments')
            ->where('status', 'PROPOSED');

        if (!empty($criteria['class_name'])) {
            $query->where('class_name', $criteria['class_name']);
        }
        if (!empty($criteria['department_code'])) {
            $query->where('department_code', $criteria['department_code']);
        }
        if (!empty($criteria['gr_numbers'])) {
            $query->whereIn('gr_number', (array) $criteria['gr_numbers']);
        }

        $count = $query->count();
        if ($count === 0) {
            return 0;
        }

        $now = now();
        $query->update([
            'department_code' => $targetDepartment,
            'status' => 'APPROVED',
            'approved_by' => $approverName,
            'approved_at' => $now,
            'notes' => $notes ?: "Bulk approved department '{$targetDepartment}' for {$count} records",
            'updated_at' => $now,
        ]);

        DB::connection($connection)->table('staging_approval_logs')->insert([
            'entity_type' => 'department',
            'entity_id' => null,
            'gr_number' => null,
            'action' => 'DEPARTMENT_BULK_APPROVAL',
            'old_value' => json_encode(['status' => 'PROPOSED']),
            'new_value' => json_encode(['status' => 'APPROVED', 'department_code' => $targetDepartment, 'criteria' => $criteria, 'affected_count' => $count]),
            'performed_by' => $approverName,
            'reason' => $notes ?: 'Administrative bulk department approval',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $count;
    }

    /**
     * Reassign or add department enrollment for a specific student.
     */
    public function reassignStudentDepartment(
        string $connection,
        string $grNumber,
        string $newDepartment,
        string $approverName,
        ?string $reason = null,
        bool $asAdditionalEnrollment = false
    ): bool {
        DatabaseSafetyGuard::assertStagingDatabase($connection);

        if (Schema::connection($connection)->hasTable('departments')) {
            $deptExists = DB::connection($connection)->table('departments')
                ->where('code', $newDepartment)
                ->exists();
            if (!$deptExists) {
                throw new \InvalidArgumentException("Target department '{$newDepartment}' does not exist.");
            }
        }

        $now = now();
        $student = DB::connection($connection)->table('staging_students')
            ->where('gr_number', $grNumber)
            ->first();

        if (!$student) {
            return false;
        }

        if ($asAdditionalEnrollment) {
            $existing = DB::connection($connection)->table('staging_department_assignments')
                ->where('gr_number', $grNumber)
                ->where('department_code', $newDepartment)
                ->first();

            if ($existing) {
                return true;
            }

            DB::connection($connection)->table('staging_department_assignments')->insert([
                'source_key' => $student->source_key,
                'gr_number' => $grNumber,
                'class_name' => $student->class_name,
                'department_code' => $newDepartment,
                'evidence_source' => 'manual_multi_department_enrollment',
                'status' => 'APPROVED',
                'is_primary' => false,
                'approved_by' => $approverName,
                'approved_at' => $now,
                'notes' => $reason ?: "Added secondary enrollment in {$newDepartment}",
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::connection($connection)->table('staging_approval_logs')->insert([
                'entity_type' => 'department',
                'entity_id' => null,
                'gr_number' => $grNumber,
                'action' => 'DEPARTMENT_ADDITIONAL_ENROLLMENT',
                'old_value' => null,
                'new_value' => json_encode(['department_code' => $newDepartment, 'is_primary' => false, 'status' => 'APPROVED']),
                'performed_by' => $approverName,
                'reason' => $reason ?: 'Added multi-department enrollment',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return true;
        }

        $existing = DB::connection($connection)->table('staging_department_assignments')
            ->where('gr_number', $grNumber)
            ->where('is_primary', true)
            ->first();

        $oldDept = $existing ? $existing->department_code : 'unknown';

        if ($existing) {
            DB::connection($connection)->table('staging_department_assignments')
                ->where('id', $existing->id)
                ->update([
                    'department_code' => $newDepartment,
                    'status' => 'APPROVED',
                    'approved_by' => $approverName,
                    'approved_at' => $now,
                    'notes' => $reason ?: "Reassigned from {$oldDept} to {$newDepartment}",
                    'updated_at' => $now,
                ]);
        } else {
            DB::connection($connection)->table('staging_department_assignments')->insert([
                'source_key' => $student->source_key,
                'gr_number' => $grNumber,
                'class_name' => $student->class_name,
                'department_code' => $newDepartment,
                'evidence_source' => 'manual_reassignment',
                'status' => 'APPROVED',
                'is_primary' => true,
                'approved_by' => $approverName,
                'approved_at' => $now,
                'notes' => $reason ?: "Manually assigned department {$newDepartment}",
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::connection($connection)->table('staging_approval_logs')->insert([
            'entity_type' => 'department',
            'entity_id' => $existing ? $existing->id : null,
            'gr_number' => $grNumber,
            'action' => 'DEPARTMENT_REASSIGNMENT',
            'old_value' => json_encode(['department_code' => $oldDept]),
            'new_value' => json_encode(['department_code' => $newDepartment, 'status' => 'APPROVED']),
            'performed_by' => $approverName,
            'reason' => $reason ?: 'Manual department reassignment',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return true;
    }

    /**
     * Approve currency for an unresolved student.
     */
    public function approveCurrency(
        string $connection,
        string $grNumber,
        string $currencyIso,
        string $approverName,
        ?string $notes = null
    ): bool {
        DatabaseSafetyGuard::assertStagingDatabase($connection);

        $record = DB::connection($connection)->table('staging_currency_reviews')
            ->where('gr_number', $grNumber)
            ->first();

        if (!$record) {
            return false;
        }

        $now = now();
        DB::connection($connection)->table('staging_currency_reviews')
            ->where('gr_number', $grNumber)
            ->update([
                'currency_approved_manual' => strtoupper($currencyIso),
                'review_status' => 'APPROVED',
                'action_needed' => $notes ?: 'Manually reviewed and approved in Phase 3A',
                'updated_at' => $now,
            ]);

        DB::connection($connection)->table('staging_approval_logs')->insert([
            'entity_type' => 'currency',
            'entity_id' => $record->id,
            'gr_number' => $grNumber,
            'action' => 'CURRENCY_MANUAL_APPROVAL',
            'old_value' => json_encode(['review_status' => $record->review_status, 'currency_approved' => $record->currency_approved_manual]),
            'new_value' => json_encode(['review_status' => 'APPROVED', 'currency_approved' => strtoupper($currencyIso)]),
            'performed_by' => $approverName,
            'reason' => $notes ?: 'Manual currency review approval',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return true;
    }

    /**
     * Resolve historical GR reconciliation entry.
     */
    public function resolveGrReconciliation(
        string $connection,
        string $sourceKey,
        string $resolvedGr,
        string $reviewerName,
        ?string $notes = null
    ): bool {
        DatabaseSafetyGuard::assertStagingDatabase($connection);

        $now = now();
        $updated = DB::connection($connection)->table('staging_gr_reconciliations')
            ->where('source_key', $sourceKey)
            ->update([
                'resolved_gr_number' => $resolvedGr,
                'reconciliation_status' => 'RESOLVED_LINKED',
                'reviewed_by' => $reviewerName,
                'reviewed_at' => $now,
                'notes' => $notes,
                'updated_at' => $now,
            ]);

        if ($updated) {
            DB::connection($connection)->table('staging_approval_logs')->insert([
                'entity_type' => 'gr_reconciliation',
                'entity_id' => null,
                'gr_number' => $resolvedGr,
                'action' => 'GR_MANUAL_LINKING',
                'old_value' => json_encode(['source_key' => $sourceKey, 'status' => 'UNRESOLVED']),
                'new_value' => json_encode(['source_key' => $sourceKey, 'status' => 'RESOLVED_LINKED', 'resolved_gr' => $resolvedGr]),
                'performed_by' => $reviewerName,
                'reason' => $notes ?: 'Manual GR reconciliation linking',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return (bool) $updated;
    }

    /**
     * Aggregate all metrics for the Phase 3A Review Dashboard.
     */
    public function getPhase3aDashboardMetrics(string $connection = 'staging'): array
    {
        DatabaseSafetyGuard::assertStagingDatabase($connection);

        $db = DB::connection($connection);

        $totalStudents = $db->table('staging_students')->count();
        $totalIdentities = $db->table('staging_student_identities')->count();
        $siblingStudents = $db->table('staging_student_identities')->where('is_sibling', true)->count();
        $provisionalFamilies = $db->table('staging_student_identities')->where('mapping_status', 'PROVISIONAL_FAMILY')->count();
        $multiCurrencyFamilies = $db->table('staging_student_identities')->where('has_multi_currency_siblings', true)->distinct('family_code')->count('family_code');

        // Campuses
        $campusTotal = $db->table('staging_campus_assignments')->count();
        $campusApproved = $db->table('staging_campus_assignments')->where('status', 'APPROVED')->count();
        $campusPending = $db->table('staging_campus_assignments')->where('status', 'PENDING')->count();

        // Departments
        $deptTotal = $db->table('staging_department_assignments')->count();
        $deptApproved = $db->table('staging_department_assignments')->where('status', 'APPROVED')->count();
        $deptPending = $db->table('staging_department_assignments')->where('status', 'PROPOSED')->count();
        $deptSchool = $db->table('staging_department_assignments')->where('department_code', 'school')->count();
        $deptTuition = $db->table('staging_department_assignments')->where('department_code', 'tuition')->count();

        // Currencies
        $currTotal = $db->table('staging_currency_reviews')->count();
        $currApproved = $db->table('staging_currency_reviews')->where('review_status', 'APPROVED')->count();
        $currPending = $db->table('staging_currency_reviews')->where('review_status', 'PENDING_REVIEW')->count();

        // GR Reconciliations
        $reconTotal = $db->table('staging_gr_reconciliations')->count();
        $reconDues = $db->table('staging_gr_reconciliations')->where('source_type', 'due')->count();
        $reconPayments = $db->table('staging_gr_reconciliations')->where('source_type', 'payment')->count();
        $reconExactMatches = $db->table('staging_gr_reconciliations')->where('match_confidence', 'EXACT_NAME')->count();
        $reconUnlinked = $db->table('staging_gr_reconciliations')->where('match_confidence', 'UNLINKED')->count();
        $reconResolved = $db->table('staging_gr_reconciliations')->where('reconciliation_status', '!=', 'UNRESOLVED')->count();

        // Exceptions
        $totalExceptions = $db->table('staging_exceptions')->count();
        $approvalLogsCount = $db->table('staging_approval_logs')->count();

        return [
            'students' => [
                'total' => $totalStudents,
                'identities_staged' => $totalIdentities,
                'sibling_students' => $siblingStudents,
                'provisional_families' => $provisionalFamilies,
                'multi_currency_families' => $multiCurrencyFamilies,
            ],
            'campus' => [
                'total' => $campusTotal,
                'approved' => $campusApproved,
                'pending' => $campusPending,
            ],
            'department' => [
                'total' => $deptTotal,
                'approved' => $deptApproved,
                'pending' => $deptPending,
                'school' => $deptSchool,
                'tuition' => $deptTuition,
            ],
            'currency' => [
                'total' => $currTotal,
                'approved' => $currApproved,
                'pending' => $currPending,
            ],
            'reconciliation' => [
                'total' => $reconTotal,
                'dues_unknown' => $reconDues,
                'payments_unknown' => $reconPayments,
                'exact_name_matches' => $reconExactMatches,
                'unlinked_payments' => $reconUnlinked,
                'resolved' => $reconResolved,
            ],
            'exceptions' => [
                'total' => $totalExceptions,
            ],
            'audit_logs' => [
                'total' => $approvalLogsCount,
            ],
        ];
    }

    /**
     * Helper to normalize a student name for deterministic matching.
     */
    private function normalizeName(?string $name): string
    {
        if (empty($name)) {
            return '';
        }
        $n = strtolower($name);
        $n = preg_replace('/[^a-z0-9]/', '', $n);
        return trim($n);
    }
}
