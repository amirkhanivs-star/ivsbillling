<?php

namespace App\Http\Controllers;

use App\Services\Staging\DatabaseSafetyGuard;
use App\Services\Staging\StudentIdentityMappingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MigrationReviewController
{
    private function connection(): string
    {
        return config('database.default') === 'sqlite' ? 'sqlite' : 'staging';
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless(
            in_array($request->user()->role, ['super_admin', 'finance_admin', 'department_manager'], true),
            403,
            'Unauthorized: Migration review and approvals require administrative privileges.'
        );
    }

    /**
     * Migration Review Dashboard overview.
     */
    public function index(Request $request, StudentIdentityMappingService $service)
    {
        $this->authorizeAdmin($request);
        $conn = $this->connection();
        $metrics = $service->getPhase3aDashboardMetrics($conn);

        // Recent audit logs
        $recentLogs = DB::connection($conn)->table('staging_approval_logs')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $activeKey = config('imports.active_version', 'v2_reviewed_20261022');
        $activeImport = config("imports.versions.{$activeKey}");
        $historicalImport = config("imports.versions.v1_historical_staging");

        return view('migration.dashboard', [
            'metrics' => $metrics,
            'recentLogs' => $recentLogs,
            'user' => $request->user(),
            'activeImportKey' => $activeKey,
            'activeImport' => $activeImport,
            'historicalImport' => $historicalImport,
        ]);
    }

    /**
     * Campus Review & Bulk Approval.
     */
    public function campusIndex(Request $request)
    {
        $this->authorizeAdmin($request);
        $conn = $this->connection();

        $className = $request->query('class_name');
        $status = $request->query('status', 'PENDING');

        $query = DB::connection($conn)->table('staging_campus_assignments');
        if (!empty($className)) {
            $query->where('class_name', $className);
        }
        if (!empty($status)) {
            $query->where('status', $status);
        }

        $records = $query->paginate(25)->withQueryString();

        // Class breakdown for bulk assignment preview
        $classSummary = DB::connection($conn)->table('staging_campus_assignments')
            ->select('class_name', 'status', DB::raw('count(*) as total'))
            ->groupBy('class_name', 'status')
            ->orderBy('class_name')
            ->get();

        $campuses = DB::connection($conn)->table('campuses')->orderBy('name')->get();

        return view('migration.campus', [
            'records' => $records,
            'classSummary' => $classSummary,
            'campuses' => $campuses,
            'currentClass' => $className,
            'currentStatus' => $status,
        ]);
    }

    /**
     * Bulk approve campus mapping.
     */
    public function campusApprove(Request $request, StudentIdentityMappingService $service)
    {
        $this->authorizeAdmin($request);
        $validated = $request->validate([
            'target_campus' => 'required|string|max:50',
            'class_name' => 'nullable|string|max:100',
            'confirm_approval' => 'required|accepted',
            'notes' => 'nullable|string|max:500',
        ]);

        $conn = $this->connection();
        $criteria = array_filter(['class_name' => $validated['class_name'] ?? null]);

        $approvedCount = $service->approveCampusBulk(
            $conn,
            $criteria,
            $validated['target_campus'],
            $request->user()->name,
            $validated['notes'] ?? null
        );

        return redirect('/migration/campus')->with('notice', "Successfully approved campus '{$validated['target_campus']}' for {$approvedCount} student(s).");
    }

    /**
     * Correct a specific student's campus assignment.
     */
    public function campusCorrect(Request $request, StudentIdentityMappingService $service)
    {
        $this->authorizeAdmin($request);
        $validated = $request->validate([
            'gr_number' => 'required|string|max:50',
            'target_campus' => 'required|string|max:50',
            'reason' => 'nullable|string|max:500',
        ]);

        $conn = $this->connection();
        $success = $service->correctCampusAssignment(
            $conn,
            $validated['gr_number'],
            $validated['target_campus'],
            $request->user()->name,
            $validated['reason'] ?? null
        );

        if (!$success) {
            return redirect('/migration/campus')->with('alert', "Student GR {$validated['gr_number']} not found for campus correction.");
        }

        return redirect('/migration/campus')->with('notice', "Successfully corrected campus for student GR {$validated['gr_number']} to '{$validated['target_campus']}'.");
    }

    /**
     * Department Review & Bulk Approval.
     */
    public function departmentIndex(Request $request)
    {
        $this->authorizeAdmin($request);
        $conn = $this->connection();

        $deptCode = $request->query('department_code');
        $status = $request->query('status');
        $className = $request->query('class_name');

        $query = DB::connection($conn)->table('staging_department_assignments')
            ->leftJoin('staging_students', 'staging_department_assignments.gr_number', '=', 'staging_students.gr_number')
            ->select(
                'staging_department_assignments.*',
                'staging_students.student_name',
                'staging_students.father_name'
            );

        if (!empty($deptCode)) {
            $query->where('staging_department_assignments.department_code', $deptCode);
        }
        if (!empty($status)) {
            $query->where('staging_department_assignments.status', $status);
        }
        if (!empty($className)) {
            $query->where('staging_department_assignments.class_name', $className);
        }

        $records = $query->paginate(25)->withQueryString();

        $classSummary = DB::connection($conn)->table('staging_department_assignments')
            ->select('class_name', 'department_code', 'status', DB::raw('count(*) as total'))
            ->groupBy('class_name', 'department_code', 'status')
            ->orderBy('class_name')
            ->get();

        $departments = DB::connection($conn)->table('departments')->orderBy('name')->get();

        $metrics = [
            'total' => DB::connection($conn)->table('staging_department_assignments')->count(),
            'school' => DB::connection($conn)->table('staging_department_assignments')->where('department_code', 'school')->count(),
            'tuition' => DB::connection($conn)->table('staging_department_assignments')->where('department_code', 'tuition')->count(),
            'quran' => DB::connection($conn)->table('staging_department_assignments')->where('department_code', 'quran')->count(),
            'approved' => DB::connection($conn)->table('staging_department_assignments')->where('status', 'APPROVED')->count(),
            'proposed' => DB::connection($conn)->table('staging_department_assignments')->where('status', 'PROPOSED')->count(),
        ];

        return view('migration.department', [
            'records' => $records,
            'classSummary' => $classSummary,
            'departments' => $departments,
            'metrics' => $metrics,
            'currentDept' => $deptCode,
            'currentClass' => $className,
            'currentStatus' => $status,
        ]);
    }

    /**
     * Bulk approve department classification.
     */
    public function departmentApprove(Request $request, StudentIdentityMappingService $service)
    {
        $this->authorizeAdmin($request);
        $validated = $request->validate([
            'target_department' => 'required|string|max:50',
            'class_name' => 'nullable|string|max:100',
            'department_code' => 'nullable|string|max:50',
            'confirm_approval' => 'required|accepted',
            'notes' => 'nullable|string|max:500',
        ]);

        $conn = $this->connection();
        $criteria = array_filter([
            'class_name' => $validated['class_name'] ?? null,
            'department_code' => $validated['department_code'] ?? null,
        ]);

        $approvedCount = $service->approveDepartmentBulk(
            $conn,
            $criteria,
            $validated['target_department'],
            $request->user()->name,
            $validated['notes'] ?? null
        );

        return redirect('/migration/department')->with('notice', "Successfully approved department '{$validated['target_department']}' for {$approvedCount} record(s).");
    }

    /**
     * Reassign or add department enrollment for an individual student.
     */
    public function departmentReassign(Request $request, StudentIdentityMappingService $service)
    {
        $this->authorizeAdmin($request);
        $validated = $request->validate([
            'gr_number' => 'required|string|max:50',
            'department_code' => 'required|string|max:50',
            'is_additional' => 'nullable|boolean',
            'reason' => 'nullable|string|max:500',
        ]);

        $conn = $this->connection();
        $success = $service->reassignStudentDepartment(
            $conn,
            $validated['gr_number'],
            $validated['department_code'],
            $request->user()->name,
            $validated['reason'] ?? null,
            (bool) ($validated['is_additional'] ?? false)
        );

        if (!$success) {
            return redirect('/migration/department')->with('alert', "Student GR {$validated['gr_number']} not found for department reassignment.");
        }

        $msg = ($validated['is_additional'] ?? false)
            ? "Added secondary enrollment in department '{$validated['department_code']}' for GR {$validated['gr_number']}."
            : "Reassigned department for GR {$validated['gr_number']} to '{$validated['department_code']}'.";

        return redirect('/migration/department')->with('notice', $msg);
    }

    /**
     * Currency Review (138 Unresolved Students).
     */
    public function currencyIndex(Request $request)
    {
        $this->authorizeAdmin($request);
        $conn = $this->connection();

        $status = $request->query('status', 'PENDING_REVIEW');
        $matchStatus = $request->query('match_status');

        $query = DB::connection($conn)->table('staging_currency_reviews');
        if (!empty($status)) {
            $query->where('review_status', $status);
        }
        if (!empty($matchStatus)) {
            $query->where('match_status', $matchStatus);
        }

        $records = $query->paginate(25)->withQueryString();

        $stats = [
            'total' => DB::connection($conn)->table('staging_currency_reviews')->count(),
            'approved' => DB::connection($conn)->table('staging_currency_reviews')->where('review_status', 'APPROVED')->count(),
            'pending' => DB::connection($conn)->table('staging_currency_reviews')->where('review_status', 'PENDING_REVIEW')->count(),
            'no_financial' => DB::connection($conn)->table('staging_currency_reviews')->where('match_status', 'NO_FINANCIAL_EVIDENCE')->count(),
            'unrecognized' => DB::connection($conn)->table('staging_currency_reviews')->where('match_status', 'UNRECOGNIZED_CURRENCY_ONLY')->count(),
        ];

        return view('migration.currency', [
            'records' => $records,
            'stats' => $stats,
            'currentStatus' => $status,
            'currentMatchStatus' => $matchStatus,
        ]);
    }

    /**
     * Approve currency for an unresolved student.
     */
    public function currencyApprove(Request $request, StudentIdentityMappingService $service)
    {
        $this->authorizeAdmin($request);
        $validated = $request->validate([
            'gr_number' => 'required|string|max:50',
            'currency_iso' => 'required|string|size:3',
            'notes' => 'nullable|string|max:500',
        ]);

        $conn = $this->connection();
        $ok = $service->approveCurrency(
            $conn,
            $validated['gr_number'],
            $validated['currency_iso'],
            $request->user()->name,
            $validated['notes'] ?? null
        );

        if (!$ok) {
            return back()->withErrors(['error' => 'Student GR number not found in currency review list.']);
        }

        return back()->with('notice', "Successfully approved currency '{$validated['currency_iso']}' for student GR {$validated['gr_number']}.");
    }

    /**
     * Historical GR Reconciliation Review.
     */
    public function grReconciliationIndex(Request $request)
    {
        $this->authorizeAdmin($request);
        $conn = $this->connection();

        $sourceType = $request->query('source_type');
        $confidence = $request->query('confidence');
        $search = $request->query('search');

        $query = DB::connection($conn)->table('staging_gr_reconciliations');
        if (!empty($sourceType)) {
            $query->where('source_type', $sourceType);
        }
        if (!empty($confidence)) {
            $query->where('match_confidence', $confidence);
        }
        if (!empty($search)) {
            $query->where(function($q) use ($search) {
                $q->where('raw_gr_number', 'like', "%{$search}%")
                  ->orWhere('raw_student_name', 'like', "%{$search}%")
                  ->orWhere('slip_number', 'like', "%{$search}%");
            });
        }

        $records = $query->paginate(25)->withQueryString();

        $stats = [
            'total' => DB::connection($conn)->table('staging_gr_reconciliations')->count(),
            'dues' => DB::connection($conn)->table('staging_gr_reconciliations')->where('source_type', 'due')->count(),
            'payments' => DB::connection($conn)->table('staging_gr_reconciliations')->where('source_type', 'payment')->count(),
            'exact_matches' => DB::connection($conn)->table('staging_gr_reconciliations')->where('match_confidence', 'EXACT_NAME')->count(),
            'unlinked' => DB::connection($conn)->table('staging_gr_reconciliations')->where('match_confidence', 'UNLINKED')->count(),
            'unresolved' => DB::connection($conn)->table('staging_gr_reconciliations')->where('reconciliation_status', 'UNRESOLVED')->count(),
            'resolved' => DB::connection($conn)->table('staging_gr_reconciliations')->where('reconciliation_status', '!=', 'UNRESOLVED')->count(),
        ];

        return view('migration.gr-reconciliation', [
            'records' => $records,
            'stats' => $stats,
            'currentSourceType' => $sourceType,
            'currentConfidence' => $confidence,
            'search' => $search,
        ]);
    }

    /**
     * Manually resolve or link a historical GR record.
     */
    public function grReconciliationResolve(Request $request, StudentIdentityMappingService $service)
    {
        $this->authorizeAdmin($request);
        $validated = $request->validate([
            'source_key' => 'required|string|max:50',
            'resolved_gr_number' => 'required|string|max:50',
            'notes' => 'nullable|string|max:500',
        ]);

        $conn = $this->connection();
        $ok = $service->resolveGrReconciliation(
            $conn,
            $validated['source_key'],
            $validated['resolved_gr_number'],
            $request->user()->name,
            $validated['notes'] ?? null
        );

        if (!$ok) {
            return back()->withErrors(['error' => 'Reconciliation source key not found.']);
        }

        return back()->with('notice', "Successfully linked {$validated['source_key']} to GR {$validated['resolved_gr_number']}.");
    }

    /**
     * Staging Exceptions list.
     */
    public function exceptionsIndex(Request $request)
    {
        $this->authorizeAdmin($request);
        $conn = $this->connection();

        $issueCode = $request->query('issue_code');
        $query = DB::connection($conn)->table('staging_exceptions');
        if (!empty($issueCode)) {
            $query->where('issue_code', $issueCode);
        }

        $records = $query->paginate(25)->withQueryString();

        $issueCodes = DB::connection($conn)->table('staging_exceptions')
            ->select('issue_code', DB::raw('count(*) as cnt'))
            ->groupBy('issue_code')
            ->orderByDesc('cnt')
            ->get();

        return view('migration.exceptions', [
            'records' => $records,
            'issueCodes' => $issueCodes,
            'currentIssueCode' => $issueCode,
        ]);
    }

    /**
     * Export exceptions as downloadable CSV.
     */
    public function exportExceptions(Request $request)
    {
        $this->authorizeAdmin($request);
        $conn = $this->connection();
        $issueCode = $request->query('issue_code');

        $query = DB::connection($conn)->table('staging_exceptions');
        if (!empty($issueCode)) {
            $query->where('issue_code', $issueCode);
        }

        $response = new StreamedResponse(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Source Sheet', 'Source Row', 'Source Key', 'GR Number', 'Issue Code', 'Status']);

            $query->chunk(500, function ($rows) use ($handle) {
                foreach ($rows as $row) {
                    fputcsv($handle, [
                        $row->id,
                        $row->source_sheet,
                        $row->source_row,
                        $row->source_key,
                        $row->gr_number,
                        $row->issue_code,
                        $row->resolution_status,
                    ]);
                }
            });

            fclose($handle);
        });

        $filename = 'staging_exceptions_' . ($issueCode ? strtolower($issueCode) . '_' : '') . date('Ymd_His') . '.csv';
        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    private function qaConnection(): string
    {
        return config('database.default') === 'sqlite' ? 'sqlite' : 'qa';
    }

    /**
     * Staged Collections Review (Sheet 03_COLLECTION_PRESENT_ONLY).
     */
    public function qaCollectionsIndex(Request $request)
    {
        $this->authorizeAdmin($request);
        $conn = $this->qaConnection();

        $query = DB::connection($conn)->table('qa_staged_collections');

        if ($term = trim((string) $request->query('q', ''))) {
            $query->where(function ($w) use ($term) {
                $w->where('gr_number', 'like', "%{$term}%")
                  ->orWhere('student_name', 'like', "%{$term}%")
                  ->orWhere('slip_number', 'like', "%{$term}%")
                  ->orWhere('family_code', 'like', "%{$term}%")
                  ->orWhere('fee_particular', 'like', "%{$term}%");
            });
        }

        if ($currency = $request->query('currency')) {
            $query->where('currency', $currency);
        }

        if ($request->has('unresolved') && $request->query('unresolved') !== '') {
            $query->where('is_currency_unresolved', (bool) $request->query('unresolved'));
        }

        $records = $query->orderBy('source_row')->paginate(25)->withQueryString();
        $totalCount = DB::connection($conn)->table('qa_staged_collections')->count();
        $unresolvedCount = DB::connection($conn)->table('qa_staged_collections')->where('is_currency_unresolved', true)->count();
        $currencies = DB::connection($conn)->table('qa_staged_collections')->whereNotNull('currency')->where('currency', '!=', '')->distinct()->pluck('currency');

        return view('migration.qa-collections', [
            'records' => $records,
            'totalCount' => $totalCount,
            'unresolvedCount' => $unresolvedCount,
            'currencies' => $currencies,
        ]);
    }

    /**
     * Staged Dues / Outstanding Review (Sheet 05_OUTSTANDING_PRESENT_ONLY).
     */
    public function qaDuesIndex(Request $request)
    {
        $this->authorizeAdmin($request);
        $conn = $this->qaConnection();

        $query = DB::connection($conn)->table('qa_staged_dues');

        if ($term = trim((string) $request->query('q', ''))) {
            $query->where(function ($w) use ($term) {
                $w->where('gr_number', 'like', "%{$term}%")
                  ->orWhere('student_name', 'like', "%{$term}%")
                  ->orWhere('family_code_final', 'like', "%{$term}%")
                  ->orWhere('fee_particular', 'like', "%{$term}%")
                  ->orWhere('class_in_dues_report', 'like', "%{$term}%");
            });
        }

        if ($currency = $request->query('currency')) {
            $query->where('currency', $currency);
        }

        if ($request->has('unresolved') && $request->query('unresolved') !== '') {
            $query->where('is_currency_unresolved', (bool) $request->query('unresolved'));
        }

        $records = $query->orderBy('source_row')->paginate(25)->withQueryString();
        $totalCount = DB::connection($conn)->table('qa_staged_dues')->count();
        $unresolvedCount = DB::connection($conn)->table('qa_staged_dues')->where('is_currency_unresolved', true)->count();
        $currencies = DB::connection($conn)->table('qa_staged_dues')->whereNotNull('currency')->where('currency', '!=', '')->distinct()->pluck('currency');

        return view('migration.qa-dues', [
            'records' => $records,
            'totalCount' => $totalCount,
            'unresolvedCount' => $unresolvedCount,
            'currencies' => $currencies,
        ]);
    }

    /**
     * Historical Opening Balances Preview (QA Isolated).
     */
    public function openingBalancesPreview(Request $request, \App\Services\Migration\HistoricalOpeningBalanceService $service)
    {
        $this->authorizeAdmin($request);
        $preview = $service->preview($this->qaConnection());

        return view('migration.opening-balances-preview', [
            'preview' => $preview,
            'user' => $request->user(),
        ]);
    }

    /**
     * Staff Review Screen for the 790 Held / Excluded Dues Lines.
     * Strictly review-only: does not automatically approve any record.
     */
    public function heldDuesIndex(Request $request, \App\Services\Migration\HistoricalOpeningBalanceService $service)
    {
        $this->authorizeAdmin($request);
        $allHeld = collect($service->getHeldDuesReport($this->qaConnection()));

        $counts = [
            'total' => $allHeld->count(),
            'review_hold_student' => $allHeld->where('category', 'review_hold_student')->count(),
            'unknown_period' => $allHeld->where('category', 'unknown_period')->count(),
            'unresolved_currency' => $allHeld->where('category', 'unresolved_currency')->count(),
        ];

        $filtered = $allHeld;

        $cat = (string) $request->query('category', 'all');
        if ($cat !== '' && $cat !== 'all') {
            $filtered = $filtered->where('category', $cat);
        }

        if ($term = trim((string) $request->query('q', ''))) {
            $filtered = $filtered->filter(function ($item) use ($term) {
                return str_contains(strtolower((string)$item['gr_number']), strtolower($term))
                    || str_contains(strtolower((string)$item['student_name']), strtolower($term))
                    || str_contains(strtolower((string)$item['fee_particular']), strtolower($term))
                    || (string)$item['source_row'] === $term;
            });
        }

        $page = (int) $request->query('page', 1);
        $perPage = 25;
        $items = $filtered->slice(($page - 1) * $perPage, $perPage)->values();
        $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
            $items,
            $filtered->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('migration.held-dues', [
            'records' => $paginator,
            'counts' => $counts,
            'currentCategory' => $cat,
            'searchTerm' => $request->query('q', ''),
        ]);
    }

    /**
     * Export all 790 Held Dues lines as CSV report for staff review.
     */
    public function exportHeldDues(Request $request, \App\Services\Migration\HistoricalOpeningBalanceService $service)
    {
        $this->authorizeAdmin($request);
        $held = $service->getHeldDuesReport($this->qaConnection());

        $cat = (string) $request->query('category', 'all');
        if ($cat !== '' && $cat !== 'all') {
            $held = array_values(array_filter($held, fn($h) => $h['category'] === $cat));
        }

        return response()->streamDownload(function () use ($held) {
            $f = fopen('php://output', 'w');
            fputcsv($f, [
                'Source Row',
                'GR Number',
                'Student Name',
                'Family Code',
                'Class',
                'Fee Particular',
                'Currency',
                'Outstanding Amount',
                'Hold Category',
                'Hold Reason',
                'Required Staff Correction',
                'Status',
            ]);

            foreach ($held as $row) {
                fputcsv($f, [
                    $row['source_row'],
                    $row['gr_number'],
                    $row['student_name'],
                    $row['family_code'],
                    $row['class_label'],
                    $row['fee_particular'],
                    $row['currency'],
                    $row['amount_decimal'],
                    $row['category_label'],
                    $row['reason'],
                    $row['required_correction'],
                    $row['status'],
                ]);
            }

            fclose($f);
        }, 'held_dues_exceptions_report_' . date('Ymd_His') . '.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }
}
