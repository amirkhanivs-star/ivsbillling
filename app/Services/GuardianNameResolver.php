<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class GuardianNameResolver
{
    /**
     * Fallback text when no valid guardian or father name can be resolved.
     */
    public const NOT_PROVIDED = 'Not provided';

    /**
     * Validate whether a guardian/father name string is clean and legitimate.
     * Treats null, empty/whitespace strings, Excel formulas (e.g. =len(N3)),
     * Excel error literals, and generic placeholders as invalid.
     */
    public static function isValid(?string $name): bool
    {
        if ($name === null) {
            return false;
        }

        $trimmed = trim($name);
        if ($trimmed === '') {
            return false;
        }

        // Excel formula values (starts with '=' or contains formula calls like 'len(')
        if (str_starts_with($trimmed, '=')) {
            return false;
        }
        if (stripos($trimmed, 'len(') !== false) {
            return false;
        }

        // Excel error values
        if (preg_match('/^#(?:REF!?|VALUE!?|N\/A|NAME\?|DIV\/0!?|NULL!?|NUM!?)$/i', $trimmed)) {
            return false;
        }

        // Placeholder strings
        $lower = strtolower($trimmed);
        if (in_array($lower, [
            '-', '--', '---', '----', '—', '–',
            'n/a', 'na', 'null', 'none', 'nil',
            'not provided', 'not assigned', 'undefined', 'unknown'
        ], true)) {
            return false;
        }

        return true;
    }

    /**
     * Return trimmed name if valid, or null if invalid.
     */
    public static function clean(?string $name): ?string
    {
        return self::isValid($name) ? trim((string)$name) : null;
    }

    /**
     * Resolve individual Guardian Name for a specific student using priority:
     * 1. student_registration_details.guardian_name_override (when populated and valid)
     * 2. families.guardian_name (when valid and not a formula)
     * 3. student_registration_details.father_name (when available and valid)
     * 4. 'Not provided'
     *
     * @param int|object|array $student Student ID or student model/stdClass
     * @param object|array|null $family Optional family record if already loaded
     * @param object|array|null $registration Optional registration details if already loaded
     * @return string
     */
    public static function forStudent($student, $family = null, $registration = null): string
    {
        $resolved = self::resolveStudent($student, $family, $registration);
        return $resolved['name'];
    }

    /**
     * Detailed resolution for a student with source attribution.
     *
     * @param int|object|array $student
     * @param object|array|null $family
     * @param object|array|null $registration
     * @return array{name: string, source: string, is_override: bool, raw_override: ?string, raw_family_guardian: ?string, raw_father_name: ?string}
     */
    public static function resolveStudent($student, $family = null, $registration = null): array
    {
        $studentObj = is_array($student) ? (object)$student : $student;
        $studentId = is_numeric($student) ? (int)$student : (int)($studentObj->id ?? $studentObj->student_id ?? 0);

        $override = null;
        $familyGuardian = null;
        $fatherName = null;

        $hasOverrideProp = false;
        $hasFatherProp = false;
        $hasFamilyProp = false;

        // Extract from passed registration details or student object
        if ($registration) {
            $regObj = is_array($registration) ? (object)$registration : $registration;
            if (is_object($regObj) && property_exists($regObj, 'guardian_name_override')) {
                $hasOverrideProp = true;
                $override = $regObj->guardian_name_override;
            }
            if (is_object($regObj) && property_exists($regObj, 'father_name')) {
                $hasFatherProp = true;
                $fatherName = $regObj->father_name;
            }
        }

        if (!$hasOverrideProp && is_object($studentObj) && property_exists($studentObj, 'guardian_name_override')) {
            $hasOverrideProp = true;
            $override = $studentObj->guardian_name_override;
        }

        if (!$hasFatherProp && is_object($studentObj) && property_exists($studentObj, 'father_name')) {
            $hasFatherProp = true;
            $fatherName = $studentObj->father_name;
        }

        // Extract from passed family or student object
        if ($family) {
            $hasFamilyProp = true;
            $famObj = is_array($family) ? (object)$family : $family;
            $familyGuardian = $famObj->guardian_name ?? null;
        } elseif (is_object($studentObj) && property_exists($studentObj, 'guardian_name')) {
            $hasFamilyProp = true;
            $familyGuardian = $studentObj->guardian_name;
        }

        // If any property was not provided on the inputs and we have a student ID, query DB
        if ((!$hasOverrideProp || !$hasFatherProp || !$hasFamilyProp) && $studentId > 0) {
            $record = DB::table('students as s')
                ->leftJoin('families as f', 'f.id', '=', 's.family_id')
                ->leftJoin('student_registration_details as sd', 'sd.student_id', '=', 's.id')
                ->where('s.id', $studentId)
                ->select(
                    'sd.guardian_name_override',
                    'sd.father_name',
                    'f.guardian_name as family_guardian_name'
                )
                ->first();

            if ($record) {
                if (!$hasOverrideProp) $override = $record->guardian_name_override;
                if (!$hasFatherProp) $fatherName = $record->father_name;
                if (!$hasFamilyProp) $familyGuardian = $record->family_guardian_name;
            }
        }

        // Apply Priority:
        // Priority 1: guardian_name_override
        if (self::isValid($override)) {
            return [
                'name' => trim((string)$override),
                'source' => 'override',
                'is_override' => true,
                'raw_override' => $override,
                'raw_family_guardian' => $familyGuardian,
                'raw_father_name' => $fatherName,
            ];
        }

        // Priority 2: families.guardian_name
        if (self::isValid($familyGuardian)) {
            return [
                'name' => trim((string)$familyGuardian),
                'source' => 'family',
                'is_override' => false,
                'raw_override' => $override,
                'raw_family_guardian' => $familyGuardian,
                'raw_father_name' => $fatherName,
            ];
        }

        // Priority 3: student_registration_details.father_name
        if (self::isValid($fatherName)) {
            return [
                'name' => trim((string)$fatherName),
                'source' => 'father',
                'is_override' => false,
                'raw_override' => $override,
                'raw_family_guardian' => $familyGuardian,
                'raw_father_name' => $fatherName,
            ];
        }

        // Priority 4: 'Not provided'
        return [
            'name' => self::NOT_PROVIDED,
            'source' => 'none',
            'is_override' => false,
            'raw_override' => $override,
            'raw_family_guardian' => $familyGuardian,
            'raw_father_name' => $fatherName,
        ];
    }

    /**
     * Resolve family-level presentation for a family.
     * Never returns raw formula values.
     * When students in the family have conflicting guardians (e.g. 90 families),
     * returns a safe combined presentation (e.g. "Guardian 1 / Guardian 2")
     * rather than arbitrarily picking the first student's Father Name.
     *
     * @param int|object|array $family Family ID or family object/array
     * @param iterable|null $students Optional collection/array of students for this family
     * @return string
     */
    public static function forFamily($family, ?iterable $students = null): string
    {
        $familyObj = is_array($family) ? (object)$family : $family;
        $familyId = is_numeric($family) ? (int)$family : (int)($familyObj->id ?? $familyObj->family_id ?? 0);

        $famGuardian = null;
        if (is_object($familyObj) && property_exists($familyObj, 'guardian_name')) {
            $famGuardian = $familyObj->guardian_name;
        }

        // Fetch students if not supplied
        if ($students === null && $familyId > 0) {
            $students = DB::table('students as s')
                ->leftJoin('student_registration_details as sd', 'sd.student_id', '=', 's.id')
                ->where('s.family_id', $familyId)
                ->select('s.id', 's.name', 'sd.father_name', 'sd.guardian_name_override')
                ->get();
        }

        // Collect individual guardian names for each student in the family
        $distinctStudentGuardians = [];
        if ($students) {
            foreach ($students as $stu) {
                $stuName = self::forStudent($stu, $familyObj);
                if ($stuName !== self::NOT_PROVIDED) {
                    $distinctStudentGuardians[$stuName] = true;
                }
            }
        }
        $distinctNames = array_keys($distinctStudentGuardians);

        // Case 1: Conflicting guardians across students in this family
        if (count($distinctNames) > 1) {
            return implode(' / ', $distinctNames);
        }

        // Case 2: Exactly 1 distinct student guardian
        if (count($distinctNames) === 1) {
            // If the family record has a valid (non-formula) guardian name that matches or stands on its own
            if (self::isValid($famGuardian)) {
                return trim((string)$famGuardian);
            }
            return $distinctNames[0];
        }

        // Case 3: No valid student guardians found, fall back to valid family guardian
        if (self::isValid($famGuardian)) {
            return trim((string)$famGuardian);
        }

        // If family guardian wasn't on object, try fetching from DB
        if ($familyId > 0 && $famGuardian === null && (!is_object($familyObj) || !property_exists($familyObj, 'guardian_name'))) {
            $dbGuardian = DB::table('families')->where('id', $familyId)->value('guardian_name');
            if (self::isValid($dbGuardian)) {
                return trim((string)$dbGuardian);
            }
        }

        return self::NOT_PROVIDED;
    }

    /**
     * Check if a family has conflicting student guardians.
     *
     * @param int|object|array $family
     * @param iterable|null $students
     * @return bool
     */
    public static function hasConflictingGuardians($family, ?iterable $students = null): bool
    {
        $familyObj = is_array($family) ? (object)$family : $family;
        $familyId = is_numeric($family) ? (int)$family : (int)($familyObj->id ?? $familyObj->family_id ?? 0);

        if ($students === null && $familyId > 0) {
            $students = DB::table('students as s')
                ->leftJoin('student_registration_details as sd', 'sd.student_id', '=', 's.id')
                ->where('s.family_id', $familyId)
                ->select('s.id', 's.name', 'sd.father_name', 'sd.guardian_name_override')
                ->get();
        }

        if (!$students) {
            return false;
        }

        $distinct = [];
        foreach ($students as $stu) {
            $name = self::forStudent($stu, $familyObj);
            if ($name !== self::NOT_PROVIDED) {
                $distinct[$name] = true;
            }
        }

        return count($distinct) > 1;
    }

    /**
     * Return document-ready parent display for a student record.
     *
     * @param int|object|array $student
     * @param object|array|null $family
     * @param object|array|null $registration
     * @return array{label: string, name: string, is_override: bool}
     */
    public static function forStudentDisplay($student, $family = null, $registration = null): array
    {
        $res = self::resolveStudent($student, $family, $registration);
        $label = match ($res['source']) {
            'father' => "Father's Name",
            'override' => "Guardian Name",
            'family' => "Guardian Name",
            default => "Father / Guardian Name",
        };

        return [
            'label' => $label,
            'name' => $res['name'],
            'is_override' => $res['is_override'],
        ];
    }

    /**
     * Return document-ready parent display for a family record.
     *
     * @param int|object|array $family
     * @param iterable|null $students
     * @return array{label: string, name: string, is_conflicting: bool}
     */
    public static function forFamilyDisplay($family, ?iterable $students = null): array
    {
        $isConflicting = self::hasConflictingGuardians($family, $students);
        $name = self::forFamily($family, $students);

        $label = $isConflicting ? "Guardians / Fathers" : "Guardian Name";

        return [
            'label' => $label,
            'name' => $name,
            'is_conflicting' => $isConflicting,
        ];
    }
}
