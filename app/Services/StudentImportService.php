<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StudentImportService
{
    private array $departments;
    private int $defaultCampusId;
    private ?int $monthlyFeeTypeId;

    public function __construct()
    {
        $this->departments = DB::table('departments')->pluck('id', 'code')->toArray();
        $this->defaultCampusId = DB::table('campuses')->value('id') ?? 1;
        $this->monthlyFeeTypeId = DB::table('fee_types')->where('code', 'monthly')->value('id') ?? 1;
    }

    private function parseDate(?string $val): ?string
    {
        if (!$val) return null;
        $val = trim($val);
        if ($val === '') return null;
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $val, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
            return $val;
        }
        try {
            return Carbon::parse($val)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    public function importFromFile(string $filePath): array
    {
        throw new \RuntimeException('Direct-to-production student bulk imports are disabled during Phase 1 Recovery Protection.');

        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("CSV file not found at {$filePath}");
        }

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new \RuntimeException("Unable to open CSV file.");
        }

        $header = null;
        $lineNum = 0;
        $importedCount = 0;
        $updatedCount = 0;
        $familiesMap = [];
        $programsMap = [];

        // Cache existing families by family_code
        $existingFamilies = DB::table('families')->whereNotNull('family_code')->pluck('id', 'family_code')->toArray();
        foreach ($existingFamilies as $code => $id) {
            $familiesMap[$code] = $id;
        }

        // Cache existing programs
        $existingPrograms = DB::table('programs')->select('id', 'department_id', 'name')->get();
        foreach ($existingPrograms as $p) {
            $programsMap[$p->department_id . ':' . strtolower(trim($p->name))] = $p->id;
        }

        // Cache existing students by GR
        $existingStudents = DB::table('students')->pluck('id', 'gr_number')->toArray();

        $batch = [];
        $now = now()->toDateTimeString();

        while (($row = fgetcsv($handle)) !== false) {
            $lineNum++;
            // Check for metadata preamble
            if ($lineNum <= 2 && (empty($row[1]) || stripos($row[0], 'Students') !== false || stripos($row[0], 'Exported') !== false)) {
                continue;
            }

            if ($header === null) {
                $header = array_map(fn($h) => trim($h), $row);
                continue;
            }

            if (count($row) < 4) {
                continue;
            }

            $rowData = [];
            foreach ($header as $idx => $colName) {
                $rowData[$colName] = isset($row[$idx]) ? trim($row[$idx]) : '';
            }

            $gr = $rowData['GR No'] ?? '';
            if ($gr === '') {
                continue;
            }

            $batch[] = $rowData;

            if (count($batch) >= 200) {
                $res = $this->processBatch($batch, $familiesMap, $programsMap, $existingStudents, $now);
                $importedCount += $res['imported'];
                $updatedCount += $res['updated'];
                $batch = [];
            }
        }

        if (!empty($batch)) {
            $res = $this->processBatch($batch, $familiesMap, $programsMap, $existingStudents, $now);
            $importedCount += $res['imported'];
            $updatedCount += $res['updated'];
        }

        fclose($handle);

        return [
            'total' => $importedCount + $updatedCount,
            'imported' => $importedCount,
            'updated' => $updatedCount,
            'families_count' => count($familiesMap),
        ];
    }

    private function processBatch(array $batch, array &$familiesMap, array &$programsMap, array &$existingStudents, string $now): array
    {
        $imported = 0;
        $updated = 0;

        DB::transaction(function () use ($batch, &$familiesMap, &$programsMap, &$existingStudents, $now, &$imported, &$updated) {
            foreach ($batch as $row) {
                $gr = $row['GR No'];
                $name = $row['Student Name'] ?? '';
                if ($name === '') $name = 'Student ' . $gr;
                $familyCode = trim($row['Family Code'] ?? '');
                if ($familyCode === '') {
                    $familyCode = $gr;
                }

                $fatherContact = trim(
                    $row['Contact (Father)']
                    ?? $row['Father Contact']
                    ?? $row['Contact #']
                    ?? $row['Contact']
                    ?? $row['Phone']
                    ?? $row['Phone Number']
                    ?? $row['Mobile']
                    ?? $row['Mobile #']
                    ?? $row['Cell']
                    ?? $row['WhatsApp']
                    ?? ''
                );
                $motherContact = trim($row['Contact (Mother)'] ?? $row['Mother Contact'] ?? '');
                $phone = $fatherContact ?: ($motherContact ?: null);
                $guardianName = trim(
                    $row['Father Name']
                    ?? $row['Father\'s Name']
                    ?? $row['Father']
                    ?? $row['Guardian Name']
                    ?? $row['Guardian']
                    ?? $row['Parent Name']
                    ?? ''
                );
                $address = trim($row['Address'] ?? '');
                $religion = trim($row['Religion'] ?? '');
                $gender = trim($row['Gender'] ?? '');
                $classLabel = trim($row['Class'] ?? '') ?: 'General';
                $classAdmitted = trim($row['Class Admitted'] ?? '');
                $dob = $this->parseDate($row['Date of Birth'] ?? null);
                $admissionDate = $this->parseDate($row['Date of Admission'] ?? null);
                $leavingDate = $this->parseDate($row['Date of Leaving'] ?? null);
                $leavingReason = trim($row['Leaving Reason'] ?? '');
                $statusRaw = strtolower(trim($row['Status'] ?? 'present'));

                if (in_array($statusRaw, ['present', 'active', 'running', 'enrolled', 'current'])) {
                    $status = 'present';
                } elseif (in_array($statusRaw, ['left', 'inactive', 'withdrawn', 'struck off', 'leave', 'passed out', 'complete'])) {
                    $status = 'left';
                } elseif ($statusRaw === 'suspended') {
                    $status = 'suspended';
                } else {
                    $status = ($leavingDate !== null || str_contains(strtolower($leavingReason), 'left') || str_contains(strtolower($leavingReason), 'migration')) ? 'left' : 'present';
                }

                $feeFloat = (float)preg_replace('/[^0-9.]/', '', $row['Monthly Fee'] ?? '0');
                $monthlyFeeMinor = (int)round($feeFloat * 100);

                // Determine department
                if (stripos($classLabel, 'Tuition') !== false) {
                    $deptId = $this->departments['tuition'] ?? 3;
                } elseif (stripos($classLabel, 'Quran') !== false || stripos($classLabel, 'Hifz') !== false) {
                    $deptId = $this->departments['quran'] ?? 2;
                } else {
                    $deptId = $this->departments['school'] ?? 1;
                }

                // 1. Family Resolution
                if (!isset($familiesMap[$familyCode])) {
                    $famId = DB::table('families')->insertGetId([
                        'family_code' => $familyCode,
                        'guardian_name' => $guardianName ?: ('Family of ' . $name),
                        'whatsapp' => $phone,
                        'email' => null,
                        'contact_verified' => false,
                        'messaging_opt_in' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $familiesMap[$familyCode] = $famId;
                } else {
                    $famId = $familiesMap[$familyCode];
                    if ($phone) {
                        DB::table('families')->where('id', $famId)->whereNull('whatsapp')->update(['whatsapp' => $phone, 'updated_at' => $now]);
                    }
                    if ($guardianName) {
                        DB::table('families')->where('id', $famId)
                            ->where(function ($q) {
                                $q->whereNull('guardian_name')->orWhere('guardian_name', 'like', 'Family of %');
                            })
                            ->update(['guardian_name' => $guardianName, 'updated_at' => $now]);
                    }
                }

                // 2. Student Resolution
                $isNew = !isset($existingStudents[$gr]);
                if ($isNew) {
                    $studentId = DB::table('students')->insertGetId([
                        'gr_number' => $gr,
                        'name' => $name,
                        'family_id' => $famId,
                        'gender' => $gender ?: null,
                        'status' => $status,
                        'date_of_admission' => $admissionDate ?: ($now ? substr($now, 0, 10) : null),
                        'legacy_myskool_id' => $row['ID'] ?? null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $existingStudents[$gr] = $studentId;
                    $imported++;
                } else {
                    $studentId = $existingStudents[$gr];
                    $studentUpdate = [
                        'name' => $name,
                        'family_id' => $famId,
                        'gender' => $gender ?: null,
                        'status' => $status,
                        'legacy_myskool_id' => $row['ID'] ?? null,
                        'updated_at' => $now,
                    ];
                    if ($admissionDate) {
                        $studentUpdate['date_of_admission'] = $admissionDate;
                    }
                    DB::table('students')->where('id', $studentId)->update($studentUpdate);
                    $updated++;
                }

                // 3. Student Registration Details
                $detailsUpdate = [
                    'religion' => $religion ?: null,
                    'current_address' => $address ?: null,
                    'permanent_address' => $address ?: null,
                    'registration_number' => $gr,
                    'class_admitted' => $classAdmitted ?: null,
                    'dob' => $dob,
                    'updated_at' => $now,
                ];
                if ($guardianName) {
                    $detailsUpdate['father_name'] = $guardianName;
                }
                if ($phone) {
                    $detailsUpdate['phone_number'] = $phone;
                }
                if ($admissionDate) {
                    $detailsUpdate['registration_date'] = $admissionDate;
                }
                DB::table('student_registration_details')->updateOrInsert(
                    ['student_id' => $studentId],
                    $detailsUpdate
                );

                // 4. Program Resolution
                $progKey = $deptId . ':' . strtolower(trim($classLabel));
                if (!isset($programsMap[$progKey])) {
                    $progId = DB::table('programs')->insertGetId([
                        'department_id' => $deptId,
                        'name' => $classLabel,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $programsMap[$progKey] = $progId;
                } else {
                    $progId = $programsMap[$progKey];
                }

                // 5. Enrollment Resolution
                $enrollment = DB::table('enrollments')->where('student_id', $studentId)->first();
                $enrollmentStatus = ($status === 'left') ? 'inactive' : 'active';

                if (!$enrollment) {
                    $enrollmentId = DB::table('enrollments')->insertGetId([
                        'student_id' => $studentId,
                        'campus_id' => $this->defaultCampusId,
                        'department_id' => $deptId,
                        'program_id' => $progId,
                        'grade_label' => $classLabel,
                        'currency' => 'SAR',
                        'monthly_fee_minor' => $monthlyFeeMinor,
                        'started_on' => $admissionDate ?: substr($now, 0, 10),
                        'ended_on' => $leavingDate,
                        'status' => $enrollmentStatus,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } else {
                    $enrollmentId = $enrollment->id;
                    DB::table('enrollments')->where('id', $enrollmentId)->update([
                        'program_id' => $progId,
                        'grade_label' => $classLabel,
                        'department_id' => $deptId,
                        'monthly_fee_minor' => $monthlyFeeMinor,
                        'ended_on' => $leavingDate,
                        'status' => $enrollmentStatus,
                        'updated_at' => $now,
                    ]);
                }

                // 6. Fee Plan Item (Monthly Fee)
                if ($monthlyFeeMinor > 0 && $this->monthlyFeeTypeId) {
                    DB::table('fee_plan_items')->updateOrInsert(
                        [
                            'enrollment_id' => $enrollmentId,
                            'fee_type_id' => $this->monthlyFeeTypeId,
                        ],
                        [
                            'description' => 'Monthly Fee',
                            'frequency' => 'monthly',
                            'currency' => 'SAR',
                            'amount_minor' => $monthlyFeeMinor,
                            'is_active' => ($status !== 'left'),
                            'updated_at' => $now,
                        ]
                    );
                }
            }
        });

        return ['imported' => $imported, 'updated' => $updated];
    }
}
