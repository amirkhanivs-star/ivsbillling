<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Canonical read-only view-model builder for the approved Consolidated Fee Invoice PDF
 * (resources/views/pdf/consolidated-challan.blade.php).
 *
 * Ensures every parent-facing and internal fee-invoice PDF route uses the single approved
 * consolidated template and includes all applicable unpaid fee obligations recorded in the ledger.
 */
class ConsolidatedInvoiceBuilder
{
    /**
     * Canonical query builder for all applicable unpaid fee obligations recorded in the ledger
     * within the user's authorized campus and department scope.
     */
    public function baseUnpaidItemsQuery(?object $user = null): \Illuminate\Database\Query\Builder
    {
        return DB::table('invoice_items as ii')
            ->join('invoices as i', 'i.id', '=', 'ii.invoice_id')
            ->join('billing_runs as b', 'b.id', '=', 'i.billing_run_id')
            ->whereRaw('ii.net_minor > ii.paid_minor')
            ->whereNotIn('ii.status', ['void', 'voided', 'exempted', 'paid'])
            ->whereNotIn('i.status', ['void', 'voided', 'paid'])
            ->when($user && !in_array($user->role ?? null, ['super_admin', 'finance_admin'], true), function ($q) use ($user) {
                $q->where('i.campus_id', (int) ($user->campus_id ?? 0))
                    ->when(!empty($user->department_id), fn($v) => $v->where('i.department_id', (int) $user->department_id));
            });
    }

    /**
     * Detect financial data-integrity discrepancies where invoice headers indicate unpaid
     * balances within the authorized scope, but matching valid unpaid invoice_items are missing
     * or do not reconcile with the header balance.
     *
     * Performs strictly read-only detection without mutating any ledger records.
     */
    public function detectFamilyHeaderDiscrepancies(
        array $familyIds,
        Collection $validUnpaidItems,
        ?object $user = null
    ): array {
        $cleanFamilyIds = array_values(array_unique(array_filter(array_map('intval', $familyIds), fn($id) => $id > 0)));
        if (empty($cleanFamilyIds)) {
            return [];
        }

        $itemBalancesByInvoice = [];
        foreach ($validUnpaidItems as $it) {
            $invId = (int) ($it->invoice_id ?? 0);
            if ($invId <= 0) {
                continue;
            }
            $bal = max(0, (int) $it->net_minor - (int) $it->paid_minor);
            $itemBalancesByInvoice[$invId] = ($itemBalancesByInvoice[$invId] ?? 0) + $bal;
        }

        $unpaidHeaders = DB::table('invoices as i')
            ->leftJoin('billing_runs as b', 'b.id', '=', 'i.billing_run_id')
            ->whereIn('i.family_id', $cleanFamilyIds)
            ->whereNotIn('i.status', ['void', 'voided', 'paid'])
            ->whereRaw('i.total_minor > i.paid_minor')
            ->when($user && !in_array($user->role ?? null, ['super_admin', 'finance_admin'], true), function ($q) use ($user) {
                $q->where('i.campus_id', (int) ($user->campus_id ?? 0))
                    ->when(!empty($user->department_id), fn($v) => $v->where('i.department_id', (int) $user->department_id));
            })
            ->select(
                'i.id as invoice_id',
                'i.invoice_number',
                'i.family_id',
                'i.currency',
                'i.total_minor',
                'i.paid_minor',
                'i.status',
                'b.period_start as billing_period'
            )
            ->orderBy('i.id')
            ->get()
            ->groupBy('family_id');

        $results = [];
        foreach ($cleanFamilyIds as $famId) {
            $famHeaders = $unpaidHeaders->get($famId, collect());
            $issues = [];

            foreach ($famHeaders as $hdr) {
                $invId = (int) $hdr->invoice_id;
                $headerUnpaidMinor = max(0, (int) $hdr->total_minor - (int) $hdr->paid_minor);
                $canonicalItemsMinor = (int) ($itemBalancesByInvoice[$invId] ?? 0);

                if ($headerUnpaidMinor > 0 && $canonicalItemsMinor === 0) {
                    $issues[] = [
                        'type' => 'missing_valid_unpaid_items',
                        'invoice_id' => $invId,
                        'invoice_number' => (string) $hdr->invoice_number,
                        'currency' => (string) ($hdr->currency ?? 'SAR'),
                        'header_unpaid_minor' => $headerUnpaidMinor,
                        'canonical_items_unpaid_minor' => 0,
                        'message' => sprintf(
                            'Data Integrity Issue: Invoice %s header indicates an unpaid balance of %s %s, but no matching valid unpaid invoice items were found. Flagged for staff review.',
                            $hdr->invoice_number,
                            $hdr->currency ?? 'SAR',
                            Money::format($headerUnpaidMinor)
                        ),
                    ];
                } elseif ($headerUnpaidMinor > 0 && $canonicalItemsMinor !== $headerUnpaidMinor) {
                    $issues[] = [
                        'type' => 'header_item_balance_mismatch',
                        'invoice_id' => $invId,
                        'invoice_number' => (string) $hdr->invoice_number,
                        'currency' => (string) ($hdr->currency ?? 'SAR'),
                        'header_unpaid_minor' => $headerUnpaidMinor,
                        'canonical_items_unpaid_minor' => $canonicalItemsMinor,
                        'message' => sprintf(
                            'Data Integrity Issue: Invoice %s header unpaid balance (%s %s) does not match valid unpaid invoice items (%s %s). Flagged for staff review.',
                            $hdr->invoice_number,
                            $hdr->currency ?? 'SAR',
                            Money::format($headerUnpaidMinor),
                            $hdr->currency ?? 'SAR',
                            Money::format($canonicalItemsMinor)
                        ),
                    ];
                }
            }

            $hasIssue = !empty($issues);
            $results[$famId] = [
                'has_data_integrity_issue' => $hasIssue,
                'is_payable_verified' => !$hasIssue,
                'data_integrity_status' => $hasIssue ? 'data_integrity_issue' : 'verified',
                'data_integrity_issues' => $issues,
                'data_integrity_message' => $hasIssue ? implode(' ', array_column($issues, 'message')) : null,
                'item_balances_by_invoice' => $itemBalancesByInvoice,
            ];
        }

        return $results;
    }

    /**
     * Compute batched read-only consolidated outstanding summaries for multiple families
     * using the exact same ledger inclusion, exclusion, authorization, and remaining-balance
     * rules as buildFamilyViewData().
     */
    public function summarizeFamiliesOutstanding(
        array $familyIds,
        string $selectedPeriod,
        ?object $user = null
    ): array {
        $cleanFamilyIds = array_values(array_unique(array_filter(array_map('intval', $familyIds), fn($id) => $id > 0)));
        if (empty($cleanFamilyIds)) {
            return [];
        }

        $selectedMonthPrefix = substr(trim($selectedPeriod), 0, 7);
        $todayStr = Carbon::now()->toDateString();

        $items = $this->baseUnpaidItemsQuery($user)
            ->join('students as s', 's.id', '=', 'ii.student_id')
            ->leftJoin('enrollments as e', 'e.id', '=', 'ii.enrollment_id')
            ->whereIn('i.family_id', $cleanFamilyIds)
            ->select(
                'ii.id',
                'ii.invoice_id',
                'ii.student_id',
                'ii.enrollment_id',
                'ii.currency',
                'ii.description',
                'ii.period_key',
                'ii.period_start',
                'ii.period_end',
                'ii.amount_minor',
                'ii.discount_minor',
                'ii.exemption_minor',
                'ii.net_minor',
                'ii.paid_minor',
                'ii.status as item_status',
                'i.family_id',
                'i.invoice_number',
                'i.issued_on',
                'i.due_date',
                'i.department_id',
                'i.campus_id',
                'b.period_start as billing_period',
                'b.frequency as billing_frequency',
                's.name as student_name',
                's.gr_number',
                DB::raw("COALESCE(e.grade_label, (SELECT e2.grade_label FROM enrollments as e2 WHERE e2.student_id = s.id ORDER BY CASE WHEN e2.status = 'active' THEN 0 ELSE 1 END, e2.id DESC LIMIT 1)) as grade_label")
            )
            ->orderBy('ii.currency')
            ->orderBy('s.name')
            ->orderBy('ii.period_start')
            ->orderBy('ii.id')
            ->get();

        $itemsByFamily = $items->groupBy('family_id');
        $discrepanciesByFamily = $this->detectFamilyHeaderDiscrepancies($cleanFamilyIds, $items, $user);
        $summaries = [];

        foreach ($cleanFamilyIds as $famId) {
            $famItems = $itemsByFamily->get($famId, collect());
            $famDiscrepancy = $discrepanciesByFamily[$famId] ?? [
                'has_data_integrity_issue' => false,
                'is_payable_verified' => true,
                'data_integrity_status' => 'verified',
                'data_integrity_issues' => [],
                'data_integrity_message' => null,
            ];

            $students = $famItems->map(fn($it) => (object) [
                'student_id' => (int) $it->student_id,
                'student_name' => $it->student_name,
                'gr_number' => $it->gr_number,
                'grade_label' => $it->grade_label,
            ])->unique('student_id')->values();

            $currenciesMap = [];
            $prevMonthsMap = [];
            $laterMonthsMap = [];

            foreach ($famItems->groupBy('currency') as $curr => $cItems) {
                $previousMinor = 0;
                $selectedMonthMinor = 0;
                $laterPeriodMinor = 0;

                foreach ($cItems as $it) {
                    $balance = max(0, (int) $it->net_minor - (int) $it->paid_minor);
                    if ($balance <= 0) {
                        continue;
                    }

                    $isOb = (($it->billing_frequency ?? 'monthly') === 'opening_balance')
                        || str_contains((string) ($it->invoice_number ?? ''), '-OB-');
                    $rawPeriod = !empty($it->period_start) ? $it->period_start : ($it->billing_period ?? null);
                    $itemMonth = $rawPeriod ? Carbon::parse($rawPeriod)->format('Y-m') : null;
                    $itemDue = !empty($it->due_date) ? Carbon::parse($it->due_date)->toDateString() : null;

                    if (!$isOb && $itemMonth !== null && $itemMonth === $selectedMonthPrefix) {
                        $selectedMonthMinor += $balance;
                    } elseif (!$isOb && $itemMonth !== null && $itemMonth > $selectedMonthPrefix) {
                        $laterPeriodMinor += $balance;
                        $lKey = "{$curr}|{$itemMonth}";
                        $isLaterOverdue = ($itemDue !== null && $itemDue < $todayStr);
                        if (!isset($laterMonthsMap[$lKey])) {
                            $dt = Carbon::createFromFormat('Y-m', $itemMonth);
                            $laterMonthsMap[$lKey] = [
                                'month' => $itemMonth,
                                'month_label' => $dt ? $dt->format('M-y') : $itemMonth,
                                'period_label' => $dt ? $dt->format('M-Y') : $itemMonth,
                                'currency' => $curr,
                                'dues_minor' => 0,
                                'due_date' => $itemDue,
                                'due_date_formatted' => $itemDue ? Carbon::parse($itemDue)->format('d-M-Y') : 'Scheduled',
                                'is_overdue' => $isLaterOverdue,
                                'status_label' => $isLaterOverdue ? 'Later Period Overdue' : 'Generated Later Period',
                            ];
                        } elseif ($isLaterOverdue && !$laterMonthsMap[$lKey]['is_overdue']) {
                            $laterMonthsMap[$lKey]['is_overdue'] = true;
                            $laterMonthsMap[$lKey]['status_label'] = 'Later Period Overdue';
                        }
                        $laterMonthsMap[$lKey]['dues_minor'] += $balance;
                    } else {
                        $previousMinor += $balance;
                        $mKey = $itemMonth ?: 'OB';
                        $pKey = "{$curr}|{$mKey}";
                        if (!isset($prevMonthsMap[$pKey])) {
                            $dt = $itemMonth ? Carbon::createFromFormat('Y-m', $itemMonth) : null;
                            $prevMonthsMap[$pKey] = [
                                'month' => $mKey,
                                'month_label' => $dt ? $dt->format('M-y') : 'Opening',
                                'period_label' => $dt ? $dt->format('M-Y') : 'Opening Balance',
                                'currency' => $curr,
                                'dues_minor' => 0,
                                'is_opening_balance' => $isOb,
                            ];
                        }
                        $prevMonthsMap[$pKey]['dues_minor'] += $balance;
                    }
                }

                $totalPayableMinor = $previousMinor + $selectedMonthMinor + $laterPeriodMinor;

                $currenciesMap[$curr] = [
                    'currency' => $curr,
                    'previous_dues_minor' => $previousMinor,
                    'previous_outstanding_minor' => $previousMinor,
                    'formatted_previous_dues' => $curr . ' ' . Money::format($previousMinor),
                    'formatted_previous_outstanding' => $curr . ' ' . Money::format($previousMinor),
                    'selected_month_minor' => $selectedMonthMinor,
                    'current_fee_minor' => $selectedMonthMinor,
                    'formatted_selected_month' => $curr . ' ' . Money::format($selectedMonthMinor),
                    'formatted_current_fee' => $curr . ' ' . Money::format($selectedMonthMinor),
                    'later_period_minor' => $laterPeriodMinor,
                    'formatted_later_period' => $curr . ' ' . Money::format($laterPeriodMinor),
                    'total_payable_minor' => $totalPayableMinor,
                    'total_outstanding_payable_minor' => $totalPayableMinor,
                    'formatted_total_payable' => $curr . ' ' . Money::format($totalPayableMinor),
                    'formatted_total_outstanding_payable' => $curr . ' ' . Money::format($totalPayableMinor),
                    'formatted' => $curr . ' ' . Money::format($totalPayableMinor),
                ];
            }

            foreach ($prevMonthsMap as &$pm) {
                $pm['formatted'] = $pm['currency'] . ' ' . Money::format($pm['dues_minor']);
            }
            unset($pm);

            foreach ($laterMonthsMap as &$lm) {
                $lm['formatted'] = $lm['currency'] . ' ' . Money::format($lm['dues_minor']);
            }
            unset($lm);

            $summaries[$famId] = [
                'family_id' => $famId,
                'is_multi_currency' => count($currenciesMap) > 1,
                'currencies' => array_values($currenciesMap),
                'currencies_map' => $currenciesMap,
                'dues_by_month' => array_values($prevMonthsMap),
                'later_dues_by_month' => array_values($laterMonthsMap),
                'students' => $students,
                'has_data_integrity_issue' => (bool) $famDiscrepancy['has_data_integrity_issue'],
                'is_payable_verified' => (bool) $famDiscrepancy['is_payable_verified'],
                'data_integrity_status' => $famDiscrepancy['data_integrity_status'],
                'data_integrity_issues' => $famDiscrepancy['data_integrity_issues'],
                'data_integrity_message' => $famDiscrepancy['data_integrity_message'],
            ];
        }

        return $summaries;
    }

    /**
     * Determine whether the given user is authorized to dispatch a full-family consolidated
     * monthly fee statement. Restricted staff (e.g. department/campus cashiers) may only view
     * scoped internal previews and cannot dispatch unrestricted full-family statements.
     */
    public function canDispatchFullFamilyStatement(?object $user): bool
    {
        if ($user === null) {
            return true;
        }

        return in_array($user->role ?? null, ['super_admin', 'finance_admin'], true);
    }

    /**
     * Evaluate server-side dispatch eligibility (full-family authorization + financial data integrity)
     * for one or more families before any monthly fee notification is queued, retried, or sent.
     */
    public function evaluateFamiliesDispatchEligibility(
        array $familyIds,
        ?string $selectedPeriod = null,
        ?object $user = null
    ): array {
        $cleanFamilyIds = array_values(array_unique(array_filter(array_map('intval', $familyIds), fn($id) => $id > 0)));
        if (empty($cleanFamilyIds)) {
            return [];
        }

        $period = ($selectedPeriod !== null && trim($selectedPeriod) !== '') ? trim($selectedPeriod) : Carbon::now()->format('Y-m');
        $summaries = $this->summarizeFamiliesOutstanding($cleanFamilyIds, $period, null);

        $isAuthorized = $this->canDispatchFullFamilyStatement($user);
        $authError = $isAuthorized
            ? null
            : 'Unauthorized: Restricted staff cannot dispatch unrestricted full-family consolidated fee statements.';

        $results = [];
        foreach ($cleanFamilyIds as $famId) {
            $summary = $summaries[$famId] ?? null;
            $hasIssue = (bool) ($summary['has_data_integrity_issue'] ?? true);
            $hasVerifiedItems = !empty($summary['currencies_map'] ?? []);
            $isVerified = !$hasIssue && (bool) ($summary['is_payable_verified'] ?? false) && $hasVerifiedItems;

            $integrityMessage = $summary['data_integrity_message'] ?? null;
            if (($hasIssue || !$isVerified) && empty($integrityMessage)) {
                $integrityMessage = 'Data Integrity Issue: Unverified consolidated payable balance — staff review required before dispatch.';
            }

            $canDispatch = $isAuthorized && !$hasIssue && $isVerified;
            $blockReason = !$isAuthorized
                ? $authError
                : (!$canDispatch ? $integrityMessage : null);

            $results[$famId] = [
                'family_id' => $famId,
                'is_authorized' => $isAuthorized,
                'authorization_error' => $authError,
                'has_data_integrity_issue' => $hasIssue,
                'is_payable_verified' => $isVerified,
                'data_integrity_status' => $hasIssue ? 'data_integrity_issue' : ($isVerified ? 'verified' : 'unverified'),
                'data_integrity_issues' => $summary['data_integrity_issues'] ?? [],
                'data_integrity_message' => $integrityMessage,
                'can_dispatch' => $canDispatch,
                'block_reason' => $blockReason,
                'summary' => $summary,
            ];
        }

        return $results;
    }

    /**
     * Evaluate server-side dispatch eligibility for a single family.
     */
    public function evaluateFamilyDispatchEligibility(
        int $familyId,
        ?string $selectedPeriod = null,
        ?object $user = null
    ): array {
        $res = $this->evaluateFamiliesDispatchEligibility([$familyId], $selectedPeriod, $user);
        return $res[$familyId] ?? [
            'family_id' => $familyId,
            'is_authorized' => $this->canDispatchFullFamilyStatement($user),
            'authorization_error' => $this->canDispatchFullFamilyStatement($user) ? null : 'Unauthorized: Restricted staff cannot dispatch unrestricted full-family consolidated fee statements.',
            'has_data_integrity_issue' => true,
            'is_payable_verified' => false,
            'data_integrity_status' => 'data_integrity_issue',
            'data_integrity_issues' => [],
            'data_integrity_message' => 'Data Integrity Issue: Family record could not be verified for dispatch.',
            'can_dispatch' => false,
            'block_reason' => 'Data Integrity Issue: Family record could not be verified for dispatch.',
            'summary' => null,
        ];
    }

    /**
     * Build view data for a family-scope consolidated fee invoice PDF.
     */
    public function buildFamilyViewData(
        object $family,
        ?object $user = null,
        bool $isPublic = false,
        ?int $targetInvoiceId = null,
        ?string $targetPeriod = null,
        ?object $referenceInvoice = null
    ): array {
        $familyId = (int) $family->id;

        if ($referenceInvoice !== null && $targetInvoiceId === null && isset($referenceInvoice->id)) {
            $targetInvoiceId = (int) $referenceInvoice->id;
        }

        if ($targetPeriod === null && $referenceInvoice !== null && !empty($referenceInvoice->billing_run_id)) {
            $refPeriodStart = DB::table('billing_runs')
                ->where('id', $referenceInvoice->billing_run_id)
                ->value('period_start');
            if ($refPeriodStart) {
                $targetPeriod = Carbon::parse($refPeriodStart)->toDateString();
            }
        } elseif ($targetPeriod === null && $targetInvoiceId !== null) {
            $refPeriodStart = DB::table('invoices as i')
                ->leftJoin('billing_runs as b', 'b.id', '=', 'i.billing_run_id')
                ->where('i.id', $targetInvoiceId)
                ->when($user && !in_array($user->role ?? null, ['super_admin', 'finance_admin'], true), function ($q) use ($user) {
                    $q->where('i.campus_id', (int) ($user->campus_id ?? 0))
                        ->when(!empty($user->department_id), fn($v) => $v->where('i.department_id', (int) $user->department_id));
                })
                ->value('b.period_start');
            if ($refPeriodStart) {
                $targetPeriod = Carbon::parse($refPeriodStart)->toDateString();
            }
        }

        $items = $this->baseUnpaidItemsQuery($user)
            ->join('students as s', 's.id', '=', 'ii.student_id')
            ->leftJoin('enrollments as e', 'e.id', '=', 'ii.enrollment_id')
            ->leftJoin('departments as d', 'd.id', '=', 'i.department_id')
            ->where('i.family_id', $familyId)
            ->select(
                'ii.*',
                's.name as student_name',
                's.gr_number',
                'i.id as invoice_id',
                'i.invoice_number',
                'i.issued_on',
                'i.due_date',
                'i.department_id',
                'i.campus_id',
                'd.name as department_name',
                DB::raw("COALESCE(e.grade_label, (SELECT e2.grade_label FROM enrollments as e2 WHERE e2.student_id = ii.student_id ORDER BY CASE WHEN e2.status = 'active' THEN 0 ELSE 1 END, e2.id DESC LIMIT 1)) as grade_label"),
                'b.period_start as billing_period',
                'b.frequency as billing_frequency'
            )
            ->orderBy('ii.currency')
            ->orderBy('s.name')
            ->orderBy('ii.period_start')
            ->orderBy('ii.id')
            ->get();

        $discrepancyReport = $this->detectFamilyHeaderDiscrepancies([$familyId], $items, $user)[$familyId] ?? [
            'has_data_integrity_issue' => false,
            'is_payable_verified' => true,
            'data_integrity_status' => 'verified',
            'data_integrity_issues' => [],
            'data_integrity_message' => null,
            'item_balances_by_invoice' => [],
        ];

        $currencyGroups = $items->groupBy('currency')->map(function ($cItems, $currency) use ($targetInvoiceId, $targetPeriod) {
            $studentsGroup = $cItems->groupBy('student_id')->map(function ($sItems) {
                $first = $sItems->first();
                return [
                    'student_id' => $first->student_id,
                    'student_name' => $first->student_name,
                    'gr_number' => $first->gr_number,
                    'grade_label' => $first->grade_label,
                    'items' => $sItems,
                    'student_subtotal_net_minor' => $sItems->sum(fn($it) => (int) $it->net_minor),
                    'student_subtotal_paid_minor' => $sItems->sum(fn($it) => (int) $it->paid_minor),
                    'student_subtotal_balance_minor' => $sItems->sum(fn($it) => max(0, (int) $it->net_minor - (int) $it->paid_minor)),
                ];
            });

            $resolvedDates = $this->resolveAuthoritativeChallanDates($cItems, $targetInvoiceId, $targetPeriod);

            return [
                'currency' => $currency,
                'students' => $studentsGroup,
                'items' => $cItems,
                'due_date' => $resolvedDates['due_date'],
                'issued_on' => $resolvedDates['issued_on'],
                'current_invoice_id' => $resolvedDates['current_invoice_id'],
                'current_period_start' => $resolvedDates['current_period_start'],
                'historical_due_dates' => $resolvedDates['historical_due_dates'],
                'upcoming_due_dates' => $resolvedDates['upcoming_due_dates'],
                'total_amount_minor' => $cItems->sum(fn($it) => (int) ($it->amount_minor ?? $it->net_minor)),
                'total_discount_minor' => $cItems->sum(fn($it) => (int) ($it->discount_minor ?? 0) + (int) ($it->exemption_minor ?? 0)),
                'total_net_minor' => $cItems->sum('net_minor'),
                'total_paid_minor' => $cItems->sum('paid_minor'),
                'total_balance_minor' => $cItems->sum(fn($it) => max(0, (int) $it->net_minor - (int) $it->paid_minor)),
            ];
        });

        $overallDates = $this->resolveAuthoritativeChallanDates($items, $targetInvoiceId, $targetPeriod);

        $familyStudents = DB::table('students as s')
            ->leftJoin('student_registration_details as sd', 'sd.student_id', '=', 's.id')
            ->where('s.family_id', $familyId)
            ->when($user && !in_array($user->role ?? null, ['super_admin', 'finance_admin'], true), function ($q) use ($user) {
                $q->where(function ($sub) use ($user) {
                    $sub->whereExists(function ($eq) use ($user) {
                        $eq->select(DB::raw(1))
                            ->from('enrollments as e')
                            ->whereColumn('e.student_id', 's.id')
                            ->where('e.campus_id', (int) ($user->campus_id ?? 0))
                            ->when(!empty($user->department_id), fn($dq) => $dq->where('e.department_id', (int) $user->department_id));
                    })->orWhereExists(function ($iq) use ($user) {
                        $iq->select(DB::raw(1))
                            ->from('invoice_items as ii_s')
                            ->join('invoices as i_s', 'i_s.id', '=', 'ii_s.invoice_id')
                            ->whereColumn('ii_s.student_id', 's.id')
                            ->where('i_s.campus_id', (int) ($user->campus_id ?? 0))
                            ->when(!empty($user->department_id), fn($dq) => $dq->where('i_s.department_id', (int) $user->department_id));
                    });
                });
            })
            ->select('s.*', 'sd.father_name', 'sd.guardian_name_override')
            ->selectSub(
                DB::table('enrollments as e')
                    ->whereColumn('e.student_id', 's.id')
                    ->orderByRaw("CASE WHEN e.status = 'active' THEN 0 ELSE 1 END")
                    ->orderByDesc('e.id')
                    ->select('grade_label')
                    ->limit(1),
                'grade_label'
            )
            ->get();

        $parentDisplay = GuardianNameResolver::forFamilyDisplay($family, $familyStudents);

        $primaryDeptId = $items->first()?->department_id
            ?? ($referenceInvoice->department_id ?? null)
            ?? ($user && !in_array($user->role ?? null, ['super_admin', 'finance_admin'], true) && !empty($user->department_id) ? (int) $user->department_id : null)
            ?? 1;
        $dept = DB::table('departments')->where('id', $primaryDeptId)->first();
        $branding = DB::table('department_branding')->where('department_id', $primaryDeptId)->first();
        $banner = $this->bannerDataUri($branding);

        $refVerifiedItemsMinor = $referenceInvoice !== null
            ? (int) ($discrepancyReport['item_balances_by_invoice'][(int) $referenceInvoice->id] ?? 0)
            : null;
        $referenceInvoiceMeta = $referenceInvoice
            ? $this->buildReferenceInvoiceMeta($referenceInvoice, $refVerifiedItemsMinor)
            : null;

        return [
            'mode' => 'family',
            'family' => $family,
            'familyStudents' => $familyStudents,
            'parentDisplay' => $parentDisplay,
            'currencyGroups' => $currencyGroups,
            'totalItemsCount' => $items->count(),
            'dept' => $dept,
            'branding' => $branding,
            'banner' => $banner,
            'statementNumber' => 'CHL-FAM-' . ($family->family_code ?: $family->id) . '-' . now()->format('Ymd'),
            'generatedAt' => now(),
            'issuedOn' => $overallDates['issued_on'] ?? ($referenceInvoiceMeta['issued_on'] ?? null),
            'dueDate' => $overallDates['due_date'] ?? ($referenceInvoiceMeta['due_date'] ?? null),
            'referenceInvoice' => $referenceInvoiceMeta,
            'hasDataIntegrityIssue' => (bool) $discrepancyReport['has_data_integrity_issue'],
            'isPayableVerified' => (bool) $discrepancyReport['is_payable_verified'],
            'dataIntegrityStatus' => $discrepancyReport['data_integrity_status'],
            'dataIntegrityIssues' => $discrepancyReport['data_integrity_issues'],
            'dataIntegrityMessage' => $discrepancyReport['data_integrity_message'],
        ];
    }

    /**
     * Build view data for a single-student consolidated fee invoice PDF.
     */
    public function buildStudentViewData(
        object $student,
        ?object $user = null,
        ?int $targetInvoiceId = null,
        ?string $targetPeriod = null,
        ?object $referenceInvoice = null
    ): array {
        $studentId = (int) $student->id;

        if ($targetPeriod === null && $targetInvoiceId !== null) {
            $refPeriodStart = DB::table('invoices as i')
                ->leftJoin('billing_runs as b', 'b.id', '=', 'i.billing_run_id')
                ->where('i.id', $targetInvoiceId)
                ->when($user && !in_array($user->role ?? null, ['super_admin', 'finance_admin'], true), function ($q) use ($user) {
                    $q->where('i.campus_id', (int) ($user->campus_id ?? 0))
                        ->when(!empty($user->department_id), fn($v) => $v->where('i.department_id', (int) $user->department_id));
                })
                ->value('b.period_start');
            if ($refPeriodStart) {
                $targetPeriod = Carbon::parse($refPeriodStart)->toDateString();
            }
        }

        $items = $this->baseUnpaidItemsQuery($user)
            ->leftJoin('enrollments as e', 'e.id', '=', 'ii.enrollment_id')
            ->leftJoin('departments as d', 'd.id', '=', 'i.department_id')
            ->where('ii.student_id', $studentId)
            ->select(
                'ii.*',
                'i.id as invoice_id',
                'i.invoice_number',
                'i.issued_on',
                'i.due_date',
                'i.department_id',
                'i.campus_id',
                'd.name as department_name',
                DB::raw("COALESCE(e.grade_label, (SELECT e2.grade_label FROM enrollments as e2 WHERE e2.student_id = ii.student_id ORDER BY CASE WHEN e2.status = 'active' THEN 0 ELSE 1 END, e2.id DESC LIMIT 1)) as grade_label"),
                'b.period_start as billing_period',
                'b.frequency as billing_frequency'
            )
            ->orderBy('ii.currency')
            ->orderBy('ii.period_start')
            ->orderBy('ii.id')
            ->get();

        $currencyGroups = $items->groupBy('currency')->map(function ($cItems, $currency) use ($targetInvoiceId, $targetPeriod) {
            $resolvedDates = $this->resolveAuthoritativeChallanDates($cItems, $targetInvoiceId, $targetPeriod);

            return [
                'currency' => $currency,
                'items' => $cItems,
                'due_date' => $resolvedDates['due_date'],
                'issued_on' => $resolvedDates['issued_on'],
                'current_invoice_id' => $resolvedDates['current_invoice_id'],
                'current_period_start' => $resolvedDates['current_period_start'],
                'historical_due_dates' => $resolvedDates['historical_due_dates'],
                'upcoming_due_dates' => $resolvedDates['upcoming_due_dates'],
                'total_amount_minor' => $cItems->sum(fn($it) => (int) ($it->amount_minor ?? $it->net_minor)),
                'total_discount_minor' => $cItems->sum(fn($it) => (int) ($it->discount_minor ?? 0) + (int) ($it->exemption_minor ?? 0)),
                'total_net_minor' => $cItems->sum('net_minor'),
                'total_paid_minor' => $cItems->sum('paid_minor'),
                'total_balance_minor' => $cItems->sum(fn($it) => max(0, (int) $it->net_minor - (int) $it->paid_minor)),
            ];
        });

        $overallDates = $this->resolveAuthoritativeChallanDates($items, $targetInvoiceId, $targetPeriod);

        $enrollment = DB::table('enrollments')
            ->where('student_id', $studentId)
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->first();
        $primaryDeptId = $items->first()?->department_id ?? $enrollment?->department_id ?? 1;
        $dept = DB::table('departments')->where('id', $primaryDeptId)->first();
        $branding = DB::table('department_branding')->where('department_id', $primaryDeptId)->first();
        $banner = $this->bannerDataUri($branding);
        $parentDisplay = GuardianNameResolver::forStudentDisplay($student);

        $referenceInvoiceMeta = $referenceInvoice ? $this->buildReferenceInvoiceMeta($referenceInvoice) : null;

        return [
            'mode' => 'student',
            'student' => $student,
            'enrollment' => $enrollment,
            'parentDisplay' => $parentDisplay,
            'currencyGroups' => $currencyGroups,
            'totalItemsCount' => $items->count(),
            'dept' => $dept,
            'branding' => $branding,
            'banner' => $banner,
            'statementNumber' => 'CHL-STU-' . $student->gr_number . '-' . now()->format('Ymd'),
            'generatedAt' => now(),
            'issuedOn' => $overallDates['issued_on'] ?? ($referenceInvoiceMeta['issued_on'] ?? null),
            'dueDate' => $overallDates['due_date'] ?? ($referenceInvoiceMeta['due_date'] ?? null),
            'referenceInvoice' => $referenceInvoiceMeta,
        ];
    }

    /**
     * Build view data when opened via an individual invoice route (/invoices/{invoice}/pdf or /public/invoices/...).
     * Uses the canonical consolidated design and includes all applicable unpaid obligations for the family,
     * while preserving original invoice references and specific accounting invoice details.
     */
    public function buildForInvoice(
        object $invoice,
        ?object $user = null,
        bool $isPublic = false,
        ?string $targetPeriod = null
    ): array {
        $family = DB::table('families')->where('id', $invoice->family_id)->first();
        if (!$family) {
            $family = (object) [
                'id' => $invoice->family_id,
                'family_code' => 'FAM-' . $invoice->family_id,
                'guardian_name' => null,
                'whatsapp' => null,
            ];
        }

        return $this->buildFamilyViewData(
            $family,
            $user,
            $isPublic,
            (int) $invoice->id,
            $targetPeriod,
            $invoice
        );
    }

    /**
     * Build metadata for a specific referenced accounting invoice so that the consolidated statement
     * preserves original invoice references and distinguishes the specific invoice from the consolidated total.
     */
    public function buildReferenceInvoiceMeta(object $invoice, ?int $verifiedItemsBalanceMinor = null): array
    {
        $periodStart = !empty($invoice->billing_run_id)
            ? DB::table('billing_runs')->where('id', $invoice->billing_run_id)->value('period_start')
            : null;

        $totalMinor = (int) ($invoice->total_minor ?? 0);
        $paidMinor = (int) ($invoice->paid_minor ?? 0);
        $remainingMinor = max(0, $totalMinor - $paidMinor);
        $rawStatus = (string) ($invoice->status ?? 'issued');

        $isUnpaidHeader = !in_array($rawStatus, ['void', 'voided', 'paid'], true) && $remainingMinor > 0;
        $hasDataIntegrityIssue = $isUnpaidHeader
            && $verifiedItemsBalanceMinor !== null
            && $verifiedItemsBalanceMinor !== $remainingMinor;

        return [
            'id' => (int) $invoice->id,
            'invoice_number' => (string) ($invoice->invoice_number ?? ''),
            'currency' => (string) ($invoice->currency ?? 'SAR'),
            'status' => $hasDataIntegrityIssue ? 'UNVERIFIED — DATA INTEGRITY REVIEW REQUIRED' : $rawStatus,
            'raw_status' => $rawStatus,
            'issued_on' => !empty($invoice->issued_on) ? Carbon::parse($invoice->issued_on)->toDateString() : null,
            'issued_on_formatted' => !empty($invoice->issued_on) ? Carbon::parse($invoice->issued_on)->format('d-F-Y') : 'N/A',
            'due_date' => !empty($invoice->due_date) ? Carbon::parse($invoice->due_date)->toDateString() : null,
            'due_date_formatted' => !empty($invoice->due_date) ? Carbon::parse($invoice->due_date)->format('d-F-Y') : 'N/A',
            'period_start' => $periodStart ? Carbon::parse($periodStart)->toDateString() : null,
            'period_label' => $periodStart ? Carbon::parse($periodStart)->format('M-Y') : 'N/A',
            'total_minor' => $hasDataIntegrityIssue ? (int) $verifiedItemsBalanceMinor : $totalMinor,
            'paid_minor' => $paidMinor,
            'remaining_minor' => $hasDataIntegrityIssue ? (int) $verifiedItemsBalanceMinor : $remainingMinor,
            'header_total_minor' => $totalMinor,
            'header_remaining_minor' => $remainingMinor,
            'has_data_integrity_issue' => $hasDataIntegrityIssue,
            'is_payable_verified' => !$hasDataIntegrityIssue,
        ];
    }

    /**
     * Resolve authoritative statement issue date, current billing due date, prior overdue due dates,
     * and any already-generated later-period due dates without overwriting saved ledger dates.
     */
    public function resolveAuthoritativeChallanDates(
        Collection $items,
        ?int $targetInvoiceId = null,
        ?string $targetPeriod = null
    ): array {
        if ($items->isEmpty()) {
            return [
                'due_date' => null,
                'issued_on' => null,
                'current_invoice_id' => null,
                'current_period_start' => null,
                'historical_due_dates' => [],
                'upcoming_due_dates' => [],
            ];
        }

        $currentItem = null;
        if ($targetInvoiceId !== null) {
            $currentItem = $items->firstWhere('invoice_id', $targetInvoiceId);
        }

        if (!$currentItem && $targetPeriod !== null && trim($targetPeriod) !== '') {
            $periodPrefix = substr(trim($targetPeriod), 0, 7);
            $periodMatches = $items->filter(function ($it) use ($periodPrefix) {
                $isOb = (($it->billing_frequency ?? 'monthly') === 'opening_balance')
                    || str_contains((string) ($it->invoice_number ?? ''), '-OB-');
                $p = !empty($it->period_start) ? Carbon::parse($it->period_start)->format('Y-m') : '';
                return !$isOb && $p === $periodPrefix;
            });
            if ($periodMatches->isNotEmpty()) {
                $currentItem = $periodMatches->sortByDesc(function ($it) {
                    $due = !empty($it->due_date) ? Carbon::parse($it->due_date)->toDateString() : '0000-00-00';
                    $invId = sprintf('%010d', (int) ($it->invoice_id ?? 0));
                    return "{$invId}|{$due}";
                })->first();
            }
        }

        if (!$currentItem) {
            $regularItems = $items->filter(fn($it) => (($it->billing_frequency ?? 'monthly') !== 'opening_balance')
                && !str_contains((string) ($it->invoice_number ?? ''), '-OB-'));
            $pool = $regularItems->isNotEmpty() ? $regularItems : $items;
            $currentItem = $pool->sortByDesc(function ($it) {
                $period = !empty($it->period_start) ? Carbon::parse($it->period_start)->toDateString() : '0000-00-00';
                $due = !empty($it->due_date) ? Carbon::parse($it->due_date)->toDateString() : '0000-00-00';
                $invId = sprintf('%010d', (int) ($it->invoice_id ?? 0));
                return "{$period}|{$invId}|{$due}";
            })->first();
        }

        $dueDate = !empty($currentItem?->due_date)
            ? Carbon::parse($currentItem->due_date)->toDateString()
            : $items->pluck('due_date')->filter()->map(fn($d) => Carbon::parse($d)->toDateString())->sortDesc()->first();

        $issuedOn = !empty($currentItem?->issued_on)
            ? Carbon::parse($currentItem->issued_on)->toDateString()
            : $items->pluck('issued_on')->filter()->map(fn($d) => Carbon::parse($d)->toDateString())->sortDesc()->first();

        $currentInvoiceId = isset($currentItem?->invoice_id) ? (int) $currentItem->invoice_id : null;
        $currentPeriodStart = !empty($currentItem?->period_start)
            ? Carbon::parse($currentItem->period_start)->toDateString()
            : null;

        $todayStr = Carbon::now()->toDateString();
        $historicalMap = [];
        $upcomingMap = [];

        foreach ($items as $it) {
            $invId = isset($it->invoice_id) ? (int) $it->invoice_id : null;
            $itPeriod = !empty($it->period_start) ? Carbon::parse($it->period_start)->toDateString() : null;
            $itDue = !empty($it->due_date) ? Carbon::parse($it->due_date)->toDateString() : null;
            $isHistoricalOb = (($it->billing_frequency ?? 'monthly') === 'opening_balance')
                || str_contains((string) ($it->invoice_number ?? ''), '-OB-');

            $isCurrent = !$isHistoricalOb && (
                ($currentInvoiceId !== null && $invId !== null && $invId === $currentInvoiceId)
                || ($currentPeriodStart !== null && $itPeriod === $currentPeriodStart)
            );

            if ($isCurrent) {
                continue;
            }

            $isLaterPeriod = !$isHistoricalOb
                && $currentPeriodStart !== null
                && $itPeriod !== null
                && $itPeriod > $currentPeriodStart;

            $key = ($invId ?: ($it->invoice_number ?? '')) . '|' . ($itPeriod ?? '') . '|' . ($itDue ?? '');

            if ($isLaterPeriod) {
                if (!isset($upcomingMap[$key])) {
                    $upcomingMap[$key] = [
                        'invoice_id' => $invId,
                        'invoice_number' => $it->invoice_number ?? null,
                        'period_start' => $itPeriod,
                        'period_label' => $itPeriod ? Carbon::parse($itPeriod)->format('M-Y') : 'Later',
                        'issued_on' => !empty($it->issued_on) ? Carbon::parse($it->issued_on)->toDateString() : null,
                        'due_date' => $itDue,
                        'due_date_formatted' => $itDue ? Carbon::parse($itDue)->format('d-F-Y') : 'Scheduled',
                        'is_overdue' => $itDue !== null && $itDue < $todayStr,
                    ];
                }
            } else {
                if (!isset($historicalMap[$key])) {
                    $historicalMap[$key] = [
                        'invoice_id' => $invId,
                        'invoice_number' => $it->invoice_number ?? null,
                        'period_start' => $itPeriod,
                        'period_label' => $itPeriod ? Carbon::parse($itPeriod)->format('M-Y') : 'Prior',
                        'issued_on' => !empty($it->issued_on) ? Carbon::parse($it->issued_on)->toDateString() : null,
                        'due_date' => $itDue,
                        'due_date_formatted' => $itDue ? Carbon::parse($itDue)->format('d-F-Y') : 'Historical',
                        'is_opening_balance' => $isHistoricalOb,
                    ];
                }
            }
        }

        return [
            'due_date' => $dueDate,
            'issued_on' => $issuedOn,
            'current_invoice_id' => $currentInvoiceId,
            'current_period_start' => $currentPeriodStart,
            'historical_due_dates' => array_values($historicalMap),
            'upcoming_due_dates' => array_values($upcomingMap),
        ];
    }

    /**
     * Resolve banner image as base64 data URI.
     */
    public function bannerDataUri(?object $branding): string
    {
        $path = isset($branding->logo_path) ? $branding->logo_path : null;
        if ($path && \Illuminate\Support\Facades\Storage::disk('local')->exists($path)) {
            $blob = \Illuminate\Support\Facades\Storage::disk('local')->get($path);
            $mime = str_ends_with(strtolower($path), '.png') ? 'image/png' : 'image/jpeg';
            return 'data:' . $mime . ';base64,' . base64_encode($blob);
        }
        $defaultBanner = public_path('assets/ivs-banner.png');
        if (is_file($defaultBanner)) {
            return 'data:image/png;base64,' . base64_encode(file_get_contents($defaultBanner));
        }
        return '';
    }
}
