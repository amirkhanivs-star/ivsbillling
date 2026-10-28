<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FeeHistoryService
{
    /**
     * Get the active import batch ID for historical staged collections.
     */
    public function getActiveBatchId(): string
    {
        return (string) config('imports.active_batch_id', 'BATCH-20261022-PORTAL-V2-REVISED');
    }

    /**
     * Get unified, reconstructed fee history for a family, paginated and filtered.
     */
    public function getFamilyFeeHistory(int $familyId, array $filters = [], ?object $user = null): LengthAwarePaginator
    {
        $family = DB::table('families')->where('id', $familyId)->first();
        if (!$family) {
            return new LengthAwarePaginator([], 0, 20);
        }

        $students = DB::table('students')->where('family_id', $familyId)->get();
        $studentGrs = $students->pluck('gr_number')->filter()->all();

        // 1. Fetch portal payments
        $portalReceipts = $this->fetchPortalReceiptsForFamily($familyId, $user);

        // 2. Fetch historical collections from active batch and reconstruct into receipts
        $historicalReceipts = $this->fetchHistoricalReceipts($studentGrs, $family->family_code, $user);

        // 3. Merge both collections
        $merged = $portalReceipts->concat($historicalReceipts);

        // 4. Apply filters
        $filtered = $this->applyFilters($merged, $filters);

        // 5. Sort chronologically: newest payment date first, then ID descending
        $sorted = $filtered->sort(function ($a, $b) {
            $dateA = $a->received_on ?? '1970-01-01';
            $dateB = $b->received_on ?? '1970-01-01';
            $cmp = strcmp($dateB, $dateA);
            if ($cmp !== 0) {
                return $cmp;
            }
            return ($b->id ?? 0) <=> ($a->id ?? 0);
        })->values();

        // 6. Paginate
        return $this->paginateCollection($sorted, $filters);
    }

    /**
     * Get unified fee history for a single student profile (/manage-student/{id}).
     */
    public function getStudentFeeHistory(int $studentId, array $filters = [], ?object $user = null): LengthAwarePaginator
    {
        $student = DB::table('students')->where('id', $studentId)->first();
        if (!$student) {
            return new LengthAwarePaginator([], 0, 20);
        }

        // 1. Fetch portal allocations for this student
        $portalReceipts = $this->fetchPortalReceiptsForStudent($studentId, $user);

        // 2. Fetch historical collections for this student's GR number
        $historicalReceipts = $this->fetchHistoricalReceipts([$student->gr_number], null, $user);

        // 3. Merge
        $merged = $portalReceipts->concat($historicalReceipts);

        // 4. Apply filters
        $filtered = $this->applyFilters($merged, $filters);

        // 5. Sort chronologically
        $sorted = $filtered->sort(function ($a, $b) {
            $dateA = $a->received_on ?? '1970-01-01';
            $dateB = $b->received_on ?? '1970-01-01';
            $cmp = strcmp($dateB, $dateA);
            if ($cmp !== 0) {
                return $cmp;
            }
            return ($b->id ?? 0) <=> ($a->id ?? 0);
        })->values();

        // 6. Paginate
        return $this->paginateCollection($sorted, $filters);
    }

    /**
     * Fetch and normalize live portal payments for a family.
     */
    protected function fetchPortalReceiptsForFamily(int $familyId, ?object $user = null): Collection
    {
        $query = DB::table('payments as p')
            ->leftJoin('users as u', 'u.id', '=', 'p.received_by')
            ->leftJoin('users as rev', 'rev.id', '=', 'p.reversed_by')
            ->where('p.family_id', $familyId);

        if ($user && !in_array($user->role, ['super_admin', 'finance_admin'], true)) {
            $query->where('p.campus_id', $user->campus_id)
                ->when($user->department_id, fn($q) => $q->where('p.department_id', $user->department_id));
        }

        $payments = $query->select('p.*', 'u.name as received_by_name', 'rev.name as reversed_by_name')
            ->orderByDesc('p.id')
            ->get();

        if ($payments->isEmpty()) {
            return collect();
        }

        $paymentIds = $payments->pluck('id')->all();
        $allocations = DB::table('payment_allocations as pa')
            ->join('invoice_items as ii', 'ii.id', '=', 'pa.invoice_item_id')
            ->leftJoin('enrollments as en', 'en.id', '=', 'ii.enrollment_id')
            ->leftJoin('fee_types as ft', 'ft.id', '=', 'ii.fee_type_id')
            ->leftJoin('students as s', 's.id', '=', 'ii.student_id')
            ->whereIn('pa.payment_id', $paymentIds)
            ->select(
                'pa.id as allocation_id',
                'pa.payment_id',
                'pa.amount_minor',
                'ii.description',
                'ii.discount_minor',
                'ii.period_start',
                'en.grade_label',
                DB::raw("COALESCE(ft.name, ii.description, 'Monthly Fee') as fee_type_name"),
                's.id as student_id',
                's.name as student_name',
                's.gr_number'
            )
            ->get()
            ->groupBy('payment_id');

        return $payments->map(function ($p) use ($allocations, $user) {
            $allocs = $allocations->get($p->id, collect());
            return $this->formatPortalReceipt($p, $allocs, $user);
        });
    }

    /**
     * Fetch and normalize live portal payments for a single student.
     */
    protected function fetchPortalReceiptsForStudent(int $studentId, ?object $user = null): Collection
    {
        // Find payment IDs allocated to this student
        $allocQuery = DB::table('payment_allocations as pa')
            ->join('invoice_items as ii', 'ii.id', '=', 'pa.invoice_item_id')
            ->leftJoin('enrollments as en', 'en.id', '=', 'ii.enrollment_id')
            ->leftJoin('fee_types as ft', 'ft.id', '=', 'ii.fee_type_id')
            ->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->where('ii.student_id', $studentId);

        if ($user && !in_array($user->role, ['super_admin', 'finance_admin'], true)) {
            $allocQuery->where('p.campus_id', $user->campus_id)
                ->when($user->department_id, fn($q) => $q->where('p.department_id', $user->department_id));
        }

        $studentAllocs = $allocQuery->select(
            'pa.id as allocation_id',
            'pa.payment_id',
            'pa.amount_minor',
            'ii.description',
            'ii.discount_minor',
            'ii.period_start',
            'en.grade_label',
            DB::raw("COALESCE(ft.name, ii.description, 'Monthly Fee') as fee_type_name")
        )->get()->groupBy('payment_id');

        if ($studentAllocs->isEmpty()) {
            return collect();
        }

        $paymentIds = $studentAllocs->keys()->all();
        $payments = DB::table('payments as p')
            ->leftJoin('users as u', 'u.id', '=', 'p.received_by')
            ->leftJoin('users as rev', 'rev.id', '=', 'p.reversed_by')
            ->whereIn('p.id', $paymentIds)
            ->select('p.*', 'u.name as received_by_name', 'rev.name as reversed_by_name')
            ->orderByDesc('p.id')
            ->get();

        $student = DB::table('students')->where('id', $studentId)->first();

        return $payments->map(function ($p) use ($studentAllocs, $student, $user) {
            $allocs = $studentAllocs->get($p->id, collect());
            // Attach student details to each allocation
            $allocsWithStudent = $allocs->map(function ($a) use ($student) {
                $a->student_id = $student->id;
                $a->student_name = $student->name;
                $a->gr_number = $student->gr_number;
                return $a;
            });
            return $this->formatPortalReceipt($p, $allocsWithStudent, $user, true);
        });
    }

    /**
     * Format a portal payment into the unified receipt object.
     */
    protected function formatPortalReceipt(object $p, Collection $allocs, ?object $user = null, bool $forStudentOnly = false): object
    {
        $isReversed = ($p->status === 'reversed');
        $feeTypes = $allocs->pluck('fee_type_name')->filter()->unique()->values()->all();
        if (empty($feeTypes)) {
            $feeTypes = ['Monthly Fee'];
        }

        $classes = $allocs->pluck('grade_label')->filter()->unique()->values()->all();
        $months = $allocs->map(function ($a) {
            return !empty($a->period_start) ? Carbon::parse($a->period_start)->format('M Y') : null;
        })->filter()->unique()->values()->all();

        $students = $allocs->map(function ($a) {
            return [
                'id' => $a->student_id ?? null,
                'name' => $a->student_name ?? '',
                'gr_number' => $a->gr_number ?? '',
            ];
        })->unique('gr_number')->values()->all();

        $studentNames = array_map(function ($s) {
            return !empty($s['name']) ? ($s['name'] . (!empty($s['gr_number']) ? ' (' . $s['gr_number'] . ')' : '')) : '';
        }, $students);
        $studentNames = array_filter($studentNames);

        // If for a single student, show student's allocated amount, else full receipt amount
        $amountMinor = $forStudentOnly ? (int) $allocs->sum('amount_minor') : (int) $p->amount_minor;
        $discountMinor = (int) $allocs->sum('discount_minor');

        $canReverse = !$isReversed && $user && in_array($user->role, ['super_admin', 'finance_admin', 'cashier'], true);

        // Build item breakdown for modal
        $items = $allocs->map(function ($a) use ($p) {
            return [
                'student_name' => $a->student_name ?? '',
                'gr_number' => $a->gr_number ?? '',
                'fee_particular' => $a->fee_type_name ?? ($a->description ?? 'Monthly Fee'),
                'period' => !empty($a->period_start) ? Carbon::parse($a->period_start)->format('M Y') : 'General',
                'original_amount' => number_format(((int) $a->amount_minor + (int) ($a->discount_minor ?? 0)) / 100, 2),
                'discount' => (int) ($a->discount_minor ?? 0) > 0 ? number_format((int) $a->discount_minor / 100, 2) : '—',
                'amount' => number_format((int) $a->amount_minor / 100, 2),
                'currency' => $p->currency,
            ];
        })->all();

        $receipt = new \stdClass();
        $receipt->id = (int) $p->id;
        $receipt->primary_id = (int) $p->id;
        $receipt->slip_number = (string) $p->receipt_number;
        $receipt->source = 'portal';
        $receipt->source_label = 'Portal';
        $receipt->is_historical = false;
        $receipt->is_reversed = $isReversed;
        $receipt->status = $p->status ?? 'received';
        $receipt->received_on = $p->received_on ? Carbon::parse($p->received_on)->format('Y-m-d') : Carbon::now()->format('Y-m-d');
        $receipt->received_on_formatted = $p->received_on ? Carbon::parse($p->received_on)->format('d M Y') : '—';
        $receipt->currency = strtoupper((string) $p->currency);
        $receipt->amount_minor = $amountMinor;
        $receipt->amount = $amountMinor / 100;
        $receipt->discount_minor = $discountMinor;
        $receipt->discount = $discountMinor / 100;
        $receipt->fee_types = $feeTypes;
        $receipt->fee_types_str = implode(', ', $feeTypes);
        $receipt->classes = $classes;
        $receipt->classes_str = implode(', ', $classes) ?: '—';
        $receipt->students = $students;
        $receipt->student_grs = array_column($students, 'gr_number');
        $receipt->students_str = implode(', ', $studentNames) ?: '—';
        $receipt->months = $months;
        $receipt->months_str = implode(', ', $months) ?: ($p->selected_period ? Carbon::parse($p->selected_period . '-01')->format('M Y') : 'General');
        $receipt->received_by_name = $p->received_by_name ?? 'Staff';
        $receipt->payment_method = $p->method ?? 'Cash';
        $receipt->print_url = '/receipts/' . $p->id . '/pdf';
        $receipt->can_reverse = $canReverse;
        $receipt->can_correct_myskool = false;
        $receipt->correction_status = null;
        $receipt->correction_badge = null;
        $receipt->items = $items;

        $receipt->details_payload = [
            'slip_number' => $receipt->slip_number,
            'source' => 'portal',
            'source_label' => 'Live Portal Receipt',
            'received_on' => $receipt->received_on_formatted,
            'currency' => $receipt->currency,
            'total_amount' => $receipt->currency . ' ' . number_format($receipt->amount, 2),
            'discount' => $receipt->discount_minor > 0 ? $receipt->currency . ' ' . number_format($receipt->discount, 2) : '0',
            'received_by' => $receipt->received_by_name,
            'payment_method' => $receipt->payment_method,
            'status' => $isReversed ? 'REVERSED' : 'Active Paid',
            'is_reversed' => $isReversed,
            'print_url' => $receipt->print_url,
            'items' => $items,
        ];

        return $receipt;
    }

    /**
     * Resolve a map of GR number => ['family_id' => int, 'family_code' => string]
     * from the students and families tables without guessing.
     */
    protected function resolveGrFamilyMap(array $grNumbers): array
    {
        $cleanGrs = array_values(array_unique(array_filter(
            array_map(fn($g) => trim((string) $g), $grNumbers),
            fn($g) => $g !== ''
        )));

        if (empty($cleanGrs) || !Schema::hasTable('students') || !Schema::hasTable('families')) {
            return [];
        }

        $rows = DB::table('students as s')
            ->join('families as f', 'f.id', '=', 's.family_id')
            ->whereIn('s.gr_number', $cleanGrs)
            ->select('s.gr_number', 's.family_id', 'f.family_code', 'f.is_provisional')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $gr = trim((string) $row->gr_number);
            $famCode = trim((string) ($row->family_code ?? ''));
            if ($gr !== '' && $famCode !== '' && !(bool) ($row->is_provisional ?? false) && !str_starts_with($famCode, 'PROV-FAM-')) {
                $map[$gr] = [
                    'family_id' => (int) $row->family_id,
                    'family_code' => $famCode,
                ];
            }
        }

        return $map;
    }

    /**
     * Fetch historical staged collections for given GR numbers and/or family code,
     * and reconstruct them into verified receipt objects without double counting.
     */
    public function fetchHistoricalReceipts(array $grNumbers, ?string $familyCode = null, ?object $user = null): Collection
    {
        if (!Schema::hasTable('qa_staged_collections')) {
            return collect();
        }

        $activeBatch = $this->getActiveBatchId();

        $query = DB::table('qa_staged_collections')
            ->where('batch_id', $activeBatch);

        if (!empty($grNumbers) && !empty($familyCode)) {
            $query->where(function ($q) use ($grNumbers, $familyCode) {
                $q->where(function ($sub) use ($grNumbers, $familyCode) {
                    $sub->whereIn('gr_number', $grNumbers)
                        ->where(function ($famCheck) use ($familyCode) {
                            $famCheck->whereNull('family_code')
                                     ->orWhere('family_code', '')
                                     ->orWhere('family_code', $familyCode);
                        });
                })->orWhere('family_code', $familyCode);
            });
        } elseif (!empty($grNumbers)) {
            $query->whereIn('gr_number', $grNumbers);
        } elseif (!empty($familyCode)) {
            $query->where('family_code', $familyCode);
        } else {
            return collect();
        }

        $rows = $query->orderBy('payment_date_parsed')
            ->orderBy('source_row')
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        $grFamilyMap = $this->resolveGrFamilyMap($rows->pluck('gr_number')->all());
        $fallbackFamilyCode = trim((string) ($familyCode ?? ''));
        $rowIds = $rows->pluck('id')->map(fn($id) => (int) $id)->all();

        // Batch-load settlement allocations and correction statuses without N+1 queries
        $settlementByColId = [];
        if (Schema::hasTable('qa_historical_settlement_collections')) {
            $allocRows = DB::table('qa_historical_settlement_collections')
                ->whereIn('qa_staged_collection_id', $rowIds)
                ->orderBy('id')
                ->get();
            foreach ($allocRows as $ar) {
                $settlementByColId[(int) $ar->qa_staged_collection_id] = [
                    'status' => (string) $ar->status,
                    'reconciliation_id' => (string) $ar->reconciliation_id,
                    'invoice_item_id' => (int) $ar->invoice_item_id,
                ];
            }
        } elseif (Schema::hasTable('qa_historical_settlements')) {
            $setRows = DB::table('qa_historical_settlements')
                ->whereIn('active_primary_collection_id', $rowIds)
                ->orderBy('id')
                ->get();
            foreach ($setRows as $sr) {
                $settlementByColId[(int) $sr->active_primary_collection_id] = [
                    'status' => (string) $sr->status,
                    'reconciliation_id' => (string) $sr->reconciliation_id,
                    'invoice_item_id' => (int) $sr->invoice_item_id,
                ];
            }
        }

        $correctionByColId = [];
        if (Schema::hasTable('qa_myskool_payment_corrections')) {
            $corrRows = DB::table('qa_myskool_payment_corrections')
                ->whereIn('qa_staged_collection_id', $rowIds)
                ->orderBy('sequence_no')
                ->orderBy('id')
                ->get();
            foreach ($corrRows as $cr) {
                $correctionByColId[(int) $cr->qa_staged_collection_id] = [
                    'correction_id' => (string) $cr->correction_id,
                    'action_type' => (string) $cr->action_type,
                    'resulting_status' => (string) $cr->resulting_status,
                ];
            }
        }

        $canCorrectMySkool = (bool) ($user && in_array($user->role, ['super_admin', 'finance_admin', 'admin'], true));

        // Reconstruct receipts using verified identity:
        // slip_number + family_code + date + currency
        // If slip number is missing or family identity is unmatched, never merge across families/students.
        $grouped = [];

        foreach ($rows as $r) {
            $slip = trim((string) $r->slip_number);
            $gr = trim((string) ($r->gr_number ?? ''));
            $date = $r->payment_date_parsed ?: $r->payment_date_raw;
            $curr = strtoupper(trim((string) ($r->currency ?: 'SAR')));
            $stagedFam = trim((string) ($r->family_code ?? ''));
            $mappedFam = ($gr !== '' && isset($grFamilyMap[$gr])) ? $grFamilyMap[$gr]['family_code'] : '';
            $fam = $stagedFam !== '' ? $stagedFam : ($mappedFam !== '' ? $mappedFam : $fallbackFamilyCode);

            if ($slip === '' || $slip === '-' || $slip === '—' || $slip === '0') {
                $key = 'UNSPECIFIED_SLIP_' . $r->id;
            } elseif ($fam !== '') {
                $key = $slip . '|FAM:' . $fam . '|' . $date . '|' . $curr;
            } elseif ($gr !== '') {
                $key = $slip . '|UNMATCHED_GR:' . $gr . '|' . $date . '|' . $curr;
            } else {
                $key = 'UNMATCHED_ROW_' . $r->id;
            }

            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'primary_id' => $r->id,
                    'slip_number' => $slip !== '' && $slip !== '-' && $slip !== '—' && $slip !== '0' ? $slip : ('MY-LEGACY-' . $r->id),
                    'family_code' => $fam,
                    'date' => $date,
                    'currency' => $curr,
                    'payment_method' => $r->payment_method ?: 'Cash',
                    'total_amount' => 0.0,
                    'students' => [],
                    'fee_types' => [],
                    'months' => [],
                    'items' => [],
                ];
            }

            // Sum amount strictly without double counting
            $amt = (float) $r->amount;
            $grouped[$key]['total_amount'] += $amt;

            // Student details
            $grouped[$key]['students'][$r->gr_number] = [
                'name' => $r->student_name ?: ('GR ' . $r->gr_number),
                'gr_number' => (string) $r->gr_number,
            ];

            // Parse fee particular for type and month
            $part = trim((string) $r->fee_particular);
            $feeType = 'Monthly Fee';
            $month = null;

            if ($part !== '') {
                if (preg_match('/^(.*?)\s*-\s*([A-Za-z]{3}-\d{4})/i', $part, $matches)) {
                    $feeType = trim($matches[1]);
                    $month = trim($matches[2]);
                    $grouped[$key]['fee_types'][$feeType] = $feeType;
                    $grouped[$key]['months'][$month] = $month;
                } elseif (preg_match('/([A-Za-z]{3}-\d{4})/i', $part, $matches)) {
                    $month = trim($matches[1]);
                    $feeType = trim(str_replace($month, '', $part)) ?: 'Monthly Fee';
                    $feeType = trim($feeType, " \t\n\r\0\x0B-");
                    $grouped[$key]['fee_types'][$feeType] = $feeType;
                    $grouped[$key]['months'][$month] = $month;
                } else {
                    $feeType = $part;
                    $grouped[$key]['fee_types'][$feeType] = $feeType;
                }
            }

            $colId = (int) $r->id;
            $setInfo = $settlementByColId[$colId] ?? null;
            $corrInfo = $correctionByColId[$colId] ?? null;

            $itemStatus = 'historical';
            if ($corrInfo) {
                $resStatus = $corrInfo['resulting_status'];
                if ($resStatus === 'reapplied' || ($setInfo && $setInfo['status'] === 'applied')) {
                    $itemStatus = 'reconciled';
                } elseif (in_array($resStatus, ['reversed', 'disputed', 'under_review', 'corrected'], true)) {
                    $itemStatus = $resStatus;
                }
            } elseif ($setInfo) {
                $itemStatus = ($setInfo['status'] === 'applied') ? 'reconciled' : 'reversed';
            } elseif (!empty($r->has_currency_conflict) || !empty($r->is_currency_unresolved)) {
                $itemStatus = 'under_review';
            }

            $grouped[$key]['items'][] = [
                'id' => $colId,
                'staged_collection_id' => $colId,
                'source_row' => $r->source_row,
                'student_name' => $r->student_name ?: ('GR ' . $r->gr_number),
                'gr_number' => (string) $r->gr_number,
                'fee_particular' => $part ?: 'Monthly Fee',
                'period' => $month ?: 'Historical',
                'original_amount' => number_format($amt, 2),
                'discount' => '0',
                'amount' => number_format($amt, 2),
                'currency' => $curr,
                'correction_status' => $itemStatus,
                'reconciliation_id' => $setInfo['reconciliation_id'] ?? null,
            ];
        }

        // Convert grouped receipts into normalized receipt objects
        return collect($grouped)->map(function ($grp) use ($canCorrectMySkool) {
            $studentsArr = array_values($grp['students']);
            $studentNames = array_map(function ($s) {
                return $s['name'] . (!empty($s['gr_number']) ? ' (' . $s['gr_number'] . ')' : '');
            }, $studentsArr);

            $feeTypes = !empty($grp['fee_types']) ? array_values($grp['fee_types']) : ['Monthly Fee'];
            $months = !empty($grp['months']) ? array_values($grp['months']) : [];

            $totalAmount = (float) $grp['total_amount'];
            $amountMinor = (int) round($totalAmount * 100);

            $dateFormatted = '—';
            $dateStandard = '1970-01-01';
            if (!empty($grp['date'])) {
                try {
                    $carbon = Carbon::parse($grp['date']);
                    $dateFormatted = $carbon->format('d M Y');
                    $dateStandard = $carbon->format('Y-m-d');
                } catch (\Throwable $e) {
                    $dateFormatted = (string) $grp['date'];
                    $dateStandard = (string) $grp['date'];
                }
            }

            // Compute aggregate correction status & badge across all items on the slip
            $itemStatuses = array_column($grp['items'], 'correction_status');
            $totalItemsCount = count($itemStatuses);
            $reversedItemsCount = count(array_filter($itemStatuses, fn($s) => $s === 'reversed'));
            $reconciledItemsCount = count(array_filter($itemStatuses, fn($s) => $s === 'reconciled'));
            $disputedItemsCount = count(array_filter($itemStatuses, fn($s) => $s === 'disputed'));
            $correctedItemsCount = count(array_filter($itemStatuses, fn($s) => $s === 'corrected'));
            $underReviewItemsCount = count(array_filter($itemStatuses, fn($s) => $s === 'under_review'));

            $correctionStatus = 'historical';
            $correctionBadge = null;
            $isReversed = false;

            if ($totalItemsCount > 0 && $reversedItemsCount === $totalItemsCount) {
                $correctionStatus = 'reversed';
                $correctionBadge = 'Reversed';
                $isReversed = true;
            } elseif ($reversedItemsCount > 0 && $reversedItemsCount < $totalItemsCount) {
                $correctionStatus = 'partially_reversed';
                $correctionBadge = 'Partially Reversed';
            } elseif ($disputedItemsCount > 0) {
                $correctionStatus = 'disputed';
                $correctionBadge = 'Disputed';
            } elseif ($correctedItemsCount > 0) {
                $correctionStatus = 'corrected';
                $correctionBadge = 'Corrected';
            } elseif ($underReviewItemsCount > 0) {
                $correctionStatus = 'under_review';
                $correctionBadge = 'Under Review';
            } elseif ($reconciledItemsCount > 0) {
                $correctionStatus = 'reconciled';
                $correctionBadge = 'Reconciled';
            }

            $receipt = new \stdClass();
            $receipt->id = (int) $grp['primary_id'];
            $receipt->primary_id = (int) $grp['primary_id'];
            $receipt->slip_number = (string) $grp['slip_number'];
            $receipt->source = 'myskool';
            $receipt->source_label = 'MySkool';
            $receipt->is_historical = true;
            $receipt->is_reversed = $isReversed;
            $receipt->status = $isReversed ? 'reversed' : 'received';
            $receipt->received_on = $dateStandard;
            $receipt->received_on_formatted = $dateFormatted;
            $receipt->currency = $grp['currency'];
            $receipt->amount_minor = $amountMinor;
            $receipt->amount = $totalAmount;
            $receipt->discount_minor = 0;
            $receipt->discount = 0.0;
            $receipt->fee_types = $feeTypes;
            $receipt->fee_types_str = implode(', ', $feeTypes);
            $receipt->classes = [];
            $receipt->classes_str = '—';
            $receipt->students = $studentsArr;
            $receipt->student_grs = array_column($studentsArr, 'gr_number');
            $receipt->students_str = implode(', ', $studentNames) ?: '—';
            $receipt->months = $months;
            $receipt->months_str = !empty($months) ? implode(', ', $months) : 'Legacy Billing';
            $receipt->received_by_name = '—'; // Do not invent receiving staff
            $receipt->payment_method = $grp['payment_method'];
            $receipt->print_url = '/receipts/historical/' . $grp['primary_id'] . '/pdf';
            $receipt->can_reverse = false; // Strictly protected against Portal cashier reversal
            $receipt->can_correct_myskool = $canCorrectMySkool;
            $receipt->correction_status = $correctionStatus;
            $receipt->correction_badge = $correctionBadge;
            $receipt->items = $grp['items'];

            $receipt->details_payload = [
                'slip_number' => $receipt->slip_number,
                'source' => 'myskool',
                'source_label' => 'Historical MySkool Payment Record',
                'received_on' => $receipt->received_on_formatted,
                'currency' => $receipt->currency,
                'total_amount' => $receipt->currency . ' ' . number_format($receipt->amount, 2),
                'discount' => '0',
                'received_by' => '— (Historical Legacy Record)',
                'payment_method' => $receipt->payment_method,
                'status' => $correctionBadge ? "Historical Record ({$correctionBadge})" : 'Historical Staged Collection (Read-Only)',
                'is_reversed' => $isReversed,
                'correction_status' => $correctionStatus,
                'correction_badge' => $correctionBadge,
                'print_url' => $receipt->print_url,
                'is_historical' => true,
                'notice' => 'Historical MySkool Payment Record — Reconstructed from Imported Data',
                'items' => $grp['items'],
            ];

            return $receipt;
        })->values();
    }

    /**
     * Retrieve complete reconstructed historical receipt information for printing or modal by ID.
     */
    public function getHistoricalReceiptDetails(int $stagedCollectionId): ?object
    {
        if (!Schema::hasTable('qa_staged_collections')) {
            return null;
        }

        $activeBatch = $this->getActiveBatchId();
        $primary = DB::table('qa_staged_collections')
            ->where('batch_id', $activeBatch)
            ->where('id', $stagedCollectionId)
            ->first();

        if (!$primary) {
            return null;
        }

        $slip = trim((string) $primary->slip_number);
        $primaryGr = trim((string) ($primary->gr_number ?? ''));
        $date = $primary->payment_date_parsed ?: $primary->payment_date_raw;
        $curr = strtoupper(trim((string) ($primary->currency ?: 'SAR')));
        $fam = trim((string) ($primary->family_code ?? ''));

        if ($fam === '' && $primaryGr !== '') {
            $primaryGrMap = $this->resolveGrFamilyMap([$primaryGr]);
            if (isset($primaryGrMap[$primaryGr])) {
                $fam = $primaryGrMap[$primaryGr]['family_code'];
            }
        }

        if ($slip !== '' && $slip !== '-' && $slip !== '—' && $slip !== '0') {
            $query = DB::table('qa_staged_collections')
                ->where('batch_id', $activeBatch)
                ->where('slip_number', $slip)
                ->where('currency', $curr);

            if ($date) {
                $query->where(function ($q) use ($date) {
                    $q->where('payment_date_parsed', $date)
                      ->orWhere('payment_date_raw', $date);
                });
            }

            $candidateRows = $query->orderBy('source_row')->get();
            $candidateGrMap = $this->resolveGrFamilyMap($candidateRows->pluck('gr_number')->all());

            $siblingRows = $candidateRows->filter(function ($row) use ($primary, $fam, $primaryGr, $candidateGrMap) {
                if ((int) $row->id === (int) $primary->id) {
                    return true;
                }

                $rowGr = trim((string) ($row->gr_number ?? ''));
                $rowStagedFam = trim((string) ($row->family_code ?? ''));
                $rowMappedFam = ($rowGr !== '' && isset($candidateGrMap[$rowGr])) ? $candidateGrMap[$rowGr]['family_code'] : '';

                // Never merge if staged family_code and student-table family_code both exist and disagree
                if ($rowStagedFam !== '' && $rowMappedFam !== '' && $rowStagedFam !== $rowMappedFam) {
                    return false;
                }

                $rowResolvedFam = $rowStagedFam !== '' ? $rowStagedFam : $rowMappedFam;

                if ($fam !== '') {
                    // Only include rows on this slip that resolve to the exact same family code
                    return $rowResolvedFam === $fam;
                }

                // Unmatched family identity: never guess or combine other students on a shared slip
                if ($primaryGr !== '') {
                    return $rowResolvedFam === '' && $rowGr === $primaryGr;
                }

                return false;
            })->values();
        } else {
            $siblingRows = collect([$primary]);
        }

        $totalAmount = 0.0;
        $items = [];
        $students = [];
        $feeTypes = [];
        $months = [];

        foreach ($siblingRows as $r) {
            $amt = (float) $r->amount;
            $totalAmount += $amt;
            $students[$r->gr_number] = [
                'name' => $r->student_name ?: ('GR ' . $r->gr_number),
                'gr_number' => (string) $r->gr_number,
            ];

            $part = trim((string) $r->fee_particular);
            $feeType = 'Monthly Fee';
            $month = null;

            if ($part !== '') {
                if (preg_match('/^(.*?)\s*-\s*([A-Za-z]{3}-\d{4})/i', $part, $matches)) {
                    $feeType = trim($matches[1]);
                    $month = trim($matches[2]);
                    $feeTypes[$feeType] = $feeType;
                    $months[$month] = $month;
                } elseif (preg_match('/([A-Za-z]{3}-\d{4})/i', $part, $matches)) {
                    $month = trim($matches[1]);
                    $feeType = trim(str_replace($month, '', $part)) ?: 'Monthly Fee';
                    $feeType = trim($feeType, " \t\n\r\0\x0B-");
                    $feeTypes[$feeType] = $feeType;
                    $months[$month] = $month;
                } else {
                    $feeType = $part;
                    $feeTypes[$feeType] = $feeType;
                }
            }

            $items[] = (object) [
                'source_row' => $r->source_row,
                'name' => $r->student_name ?: ('GR ' . $r->gr_number),
                'gr_number' => (string) $r->gr_number,
                'fee_type_name' => $feeType,
                'description' => $part,
                'period_start' => $month,
                'amount_minor' => (int) round($amt * 100),
                'discount_minor' => 0,
                'amount' => $amt,
            ];
        }

        // Lookup verified family only when family code is known (never guess for unmatched family identities)
        $family = null;
        if ($fam !== '') {
            $family = DB::table('families')->where('family_code', $fam)->first();
        }

        // Campus / department for branding
        $departmentId = 1;
        $campusId = 1;
        if ($family) {
            $enrollment = DB::table('enrollments as e')
                ->join('students as s', 's.id', '=', 'e.student_id')
                ->where('s.family_id', $family->id)
                ->select('e.department_id', 'e.campus_id')
                ->first();
            if ($enrollment) {
                $departmentId = $enrollment->department_id;
                $campusId = $enrollment->campus_id;
            }
        }

        $receipt = new \stdClass();
        $receipt->id = (int) $primary->id;
        $receipt->slip_number = $slip !== '' && $slip !== '-' && $slip !== '—' && $slip !== '0' ? $slip : ('MY-LEGACY-' . $primary->id);
        $receipt->payment_date = $primary->payment_date_parsed ?: $primary->payment_date_raw;
        $receipt->currency = $curr;
        $receipt->amount_minor = (int) round($totalAmount * 100);
        $receipt->amount = $totalAmount;
        $receipt->payment_method = $primary->payment_method ?: 'Cash';
        $receipt->batch_id = $activeBatch;
        $receipt->family = $family;
        $receipt->family_code = $fam !== '' ? $fam : ($family->family_code ?? '—');
        $receipt->department_id = $departmentId;
        $receipt->campus_id = $campusId;
        $receipt->students = array_values($students);
        $receipt->items = $items;
        $receipt->fee_types = array_values($feeTypes);
        $receipt->months = array_values($months);

        return $receipt;
    }

    /**
     * Apply search, source, student, and date filters to a collection of unified receipts.
     */
    protected function applyFilters(Collection $receipts, array $filters): Collection
    {
        return $receipts->filter(function ($r) use ($filters) {
            // Source filter: 'all', 'portal', 'myskool'
            if (!empty($filters['source']) && $filters['source'] !== 'all') {
                if ($r->source !== $filters['source']) {
                    return false;
                }
            }

            // Student filter by GR number or student ID
            if (!empty($filters['student_gr'])) {
                $gr = (string) $filters['student_gr'];
                if (!in_array($gr, $r->student_grs, true)) {
                    return false;
                }
            }

            // Search filter: matches slip number, student name, or student GR
            if (!empty($filters['search'])) {
                $s = strtolower(trim((string) $filters['search']));
                $slipMatch = str_contains(strtolower($r->slip_number), $s);
                $studentMatch = str_contains(strtolower($r->students_str), $s);
                $feeMatch = str_contains(strtolower($r->fee_types_str), $s);
                $monthMatch = str_contains(strtolower($r->months_str), $s);
                if (!$slipMatch && !$studentMatch && !$feeMatch && !$monthMatch) {
                    return false;
                }
            }

            // Date from filter
            if (!empty($filters['date_from'])) {
                if (strcmp($r->received_on, $filters['date_from']) < 0) {
                    return false;
                }
            }

            // Date to filter
            if (!empty($filters['date_to'])) {
                if (strcmp($r->received_on, $filters['date_to']) > 0) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Paginate a collection into a LengthAwarePaginator.
     */
    protected function paginateCollection(Collection $collection, array $filters): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 20);
        if ($perPage < 10 || $perPage > 100) {
            $perPage = 20;
        }

        $page = (int) ($filters['page'] ?? request()->input('page', 1));
        if ($page < 1) {
            $page = 1;
        }

        $total = $collection->count();
        $slice = $collection->slice(($page - 1) * $perPage, $perPage)->values();

        $paginator = new LengthAwarePaginator($slice, $total, $perPage, $page, [
            'path' => request()->url(),
            'query' => request()->query(),
        ]);

        return $paginator;
    }
}
