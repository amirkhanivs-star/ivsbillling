<?php

namespace App\Services;

use App\Services\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceDeliveryQueueService
{
    /**
     * Build the base query for notifications queue with authorization and filters.
     * Contains joins and where clauses only; no select columns or order by.
     */
    public function buildBaseQueueQuery(array $filters, object $user): Builder
    {
        $q = DB::table('notifications as n')
            ->join('invoices as i', 'i.id', '=', 'n.invoice_id')
            ->leftJoin('billing_runs as b', 'b.id', '=', 'i.billing_run_id')
            ->leftJoin('families as f', 'f.id', '=', 'i.family_id')
            ->leftJoin('departments as d', 'd.id', '=', 'i.department_id')
            ->leftJoin('campuses as c', 'c.id', '=', 'i.campus_id')
            ->leftJoin('notification_batches as nb', 'nb.batch_id', '=', 'n.batch_id');

        // Role-based Campus / Department access control
        if (!in_array($user->role, ['super_admin', 'finance_admin'], true)) {
            $q->where('i.campus_id', $user->campus_id);
            if (!empty($user->department_id)) {
                $q->where('i.department_id', $user->department_id);
            }
        }

        // Filter: Specific Batch ID
        if (!empty($filters['batch_id'])) {
            $q->where('n.batch_id', $filters['batch_id']);
        }

        // Filter: Billing Period
        if (!empty($filters['period'])) {
            $q->where('b.period_start', 'like', $filters['period'] . '%');
        }

        // Filter: Department
        if (!empty($filters['department_id'])) {
            $q->where('i.department_id', (int)$filters['department_id']);
        }

        // Filter: Campus
        if (!empty($filters['campus_id'])) {
            $q->where('i.campus_id', (int)$filters['campus_id']);
        }

        // Filter: Currency
        if (!empty($filters['currency'])) {
            $q->where('i.currency', strtoupper($filters['currency']));
        }

        // Filter: Grade / Class
        if (!empty($filters['grade'])) {
            $q->whereExists(function ($sub) use ($filters) {
                $sub->select(DB::raw(1))
                    ->from('invoice_items as sub_ii')
                    ->join('enrollments as sub_en', 'sub_en.id', '=', 'sub_ii.enrollment_id')
                    ->whereColumn('sub_ii.invoice_id', 'n.invoice_id')
                    ->where('sub_en.grade_label', $filters['grade']);
            });
        }

        // Filter: Webhook Submission Status
        if (!empty($filters['submission_status']) && $filters['submission_status'] !== 'all') {
            switch ($filters['submission_status']) {
                case 'queued':
                    $q->where('n.status', 'queued');
                    break;
                case 'submitted':
                    $q->whereIn('n.status', ['submitted', 'delivered', 'read']);
                    break;
                case 'failed':
                    $q->where('n.status', 'failed');
                    break;
                case 'blocked':
                    $q->where('n.status', 'blocked');
                    break;
            }
        }

        // Filter: Actual WhatsApp Delivery Status
        if (!empty($filters['delivery_status']) && $filters['delivery_status'] !== 'all') {
            switch ($filters['delivery_status']) {
                case 'delivered':
                    $q->where('n.status', 'delivered');
                    break;
                case 'read':
                    $q->where('n.status', 'read');
                    break;
                case 'pending':
                    $q->whereIn('n.status', ['queued', 'submitted']);
                    break;
                case 'failed':
                    $q->where('n.status', 'failed');
                    break;
            }
        }

        // Filter: Superseded Status
        if (isset($filters['is_superseded']) && $filters['is_superseded'] !== 'all') {
            if ($filters['is_superseded'] === 'superseded') {
                $q->where('n.is_superseded', true);
            } elseif ($filters['is_superseded'] === 'active') {
                $q->where('n.is_superseded', false);
            }
        }

        // Universal Search
        if (!empty($filters['search'])) {
            $term = '%' . trim($filters['search']) . '%';
            $rawTerm = trim($filters['search']);
            $q->where(function ($sub) use ($term, $rawTerm) {
                $sub->where('i.invoice_number', 'like', $term)
                    ->orWhere('f.family_code', 'like', $term)
                    ->orWhere('f.guardian_name', 'like', $term)
                    ->orWhere('f.whatsapp', 'like', $term)
                    ->orWhere('f.email', 'like', $term)
                    ->orWhere('n.batch_id', 'like', $term)
                    ->orWhere('n.event_id', 'like', $term)
                    ->orWhere('n.idempotency_key', 'like', $term)
                    ->orWhere('n.provider_message_id', 'like', $term);

                // Support searching local/international phone variations (e.g. 0300... matching +92300...)
                $digitsOnly = preg_replace('/\D+/', '', $rawTerm);
                if (!empty($digitsOnly)) {
                    $normalizedDigits = ltrim($digitsOnly, '0');
                    if (strlen($normalizedDigits) >= 5) {
                        $sub->orWhere('f.whatsapp', 'like', '%' . $normalizedDigits . '%');
                    }
                }

                if (is_numeric($rawTerm)) {
                    $sub->orWhere('n.id', (int)$rawTerm)
                        ->orWhere('i.id', (int)$rawTerm);
                }

                // Match student name or GR number
                $sub->orWhereExists(function ($studentSub) use ($term) {
                    $studentSub->select(DB::raw(1))
                        ->from('invoice_items as st_ii')
                        ->join('students as st_s', 'st_s.id', '=', 'st_ii.student_id')
                        ->whereColumn('st_ii.invoice_id', 'n.invoice_id')
                        ->where(function ($matchSub) use ($term) {
                            $matchSub->where('st_s.name', 'like', $term)
                                ->orWhere('st_s.gr_number', 'like', $term);
                        });
                });
            });
        }

        return $q;
    }

    /**
     * Build the query for paginated queue details and exports with specific columns and sorting.
     */
    public function buildQueueQuery(array $filters, object $user): Builder
    {
        $q = $this->buildBaseQueueQuery($filters, $user);

        $q->select(
            'n.id as notification_id',
            'n.invoice_id',
            'n.channel',
            'n.channels',
            'n.kind',
            'n.webhook_type',
            'n.event_type',
            'n.status as notification_status',
            'n.idempotency_key',
            'n.event_id',
            'n.batch_id',
            'n.chunk_index',
            'n.provider_message_id',
            'n.error as notification_error',
            'n.is_superseded',
            'n.superseded_by_invoice_id',
            'n.attempts',
            'n.last_attempt_at',
            'n.response_code',
            'n.submitted_at',
            'n.delivered_at',
            'n.created_at as notification_created_at',
            'n.created_at',
            'n.updated_at as notification_updated_at',
            'n.updated_at',
            'i.invoice_number',
            'b.period_start as billing_period',
            'i.issued_on as issue_date',
            'i.due_date',
            'i.currency',
            'i.total_minor',
            'i.paid_minor',
            'i.status as invoice_status',
            'i.family_id',
            'i.department_id',
            'i.campus_id',
            'f.family_code',
            'f.guardian_name',
            'f.whatsapp',
            'f.email as family_email',
            'f.contact_verified',
            'f.whatsapp_verified',
            'f.email_verified',
            'f.messaging_opt_in',
            'd.name as department_name',
            'c.name as campus_name',
            'nb.chunks_total',
            'nb.chunks_sent',
            'nb.status as batch_status'
        );

        // Sorting
        $sortBy = $filters['sort_by'] ?? 'id';
        $sortDir = strtolower($filters['sort_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        switch ($sortBy) {
            case 'invoice':
                $q->orderBy('i.invoice_number', $sortDir);
                break;
            case 'submitted_at':
                $q->orderBy('n.submitted_at', $sortDir);
                break;
            case 'status':
                $q->orderBy('n.status', $sortDir);
                break;
            case 'batch':
                $q->orderBy('n.batch_id', $sortDir);
                break;
            case 'chunk':
                $q->orderBy('n.chunk_index', $sortDir);
                break;
            default:
                $q->orderBy('n.id', $sortDir);
                break;
        }

        if ($sortBy !== 'id') {
            $q->orderByDesc('n.id');
        }

        return $q;
    }

    /**
     * Get paginated queue results with sibling info, previous dues, and signed PDF links.
     */
    public function getPaginatedQueue(array $filters, object $user, int $perPage = 25): LengthAwarePaginator
    {
        $allowedPerPage = [25, 50, 100];
        if (!in_array($perPage, $allowedPerPage, true)) {
            $perPage = 25;
        }

        $query = $this->buildQueueQuery($filters, $user);
        $paginator = $query->paginate($perPage)->withQueryString();

        $items = $paginator->getCollection();
        if ($items->isEmpty()) {
            return $paginator;
        }

        $invoiceIds = $items->pluck('invoice_id')->unique()->all();
        $familyIds = $items->pluck('family_id')->filter()->unique()->all();

        // 1. Fetch Students & Grades for these invoices
        $studentsByInvoice = DB::table('invoice_items as ii')
            ->join('students as s', 's.id', '=', 'ii.student_id')
            ->leftJoin('enrollments as en', 'en.id', '=', 'ii.enrollment_id')
            ->whereIn('ii.invoice_id', $invoiceIds)
            ->select('ii.invoice_id', 's.id as student_id', 's.name', 's.gr_number', 'en.grade_label')
            ->distinct()
            ->get()
            ->groupBy('invoice_id');

        // 2. Fetch all active Siblings in these families
        $siblingsByFamily = collect();
        if (!empty($familyIds)) {
            $siblingsByFamily = DB::table('students as s')
                ->leftJoin('enrollments as en', function ($j) {
                    $j->on('en.student_id', '=', 's.id')->where('en.status', '=', 'active');
                })
                ->whereIn('s.family_id', $familyIds)
                ->where('s.status', 'active')
                ->select('s.id as student_id', 's.family_id', 's.name', 's.gr_number', 'en.grade_label')
                ->distinct()
                ->get()
                ->groupBy('family_id');
        }

        // 3. Compute Previous Unpaid Dues for each family and currency
        $previousDuesMap = collect();
        if (!empty($familyIds)) {
            $previousDuesRows = DB::table('invoices as old_inv')
                ->join('invoice_items as old_item', 'old_item.invoice_id', '=', 'old_inv.id')
                ->whereIn('old_inv.family_id', $familyIds)
                ->whereNotIn('old_inv.id', $invoiceIds)
                ->whereRaw('old_item.net_minor > old_item.paid_minor')
                ->whereNotIn('old_item.status', ['voided', 'exempted', 'paid'])
                ->whereNotIn('old_inv.status', ['void', 'voided', 'paid'])
                ->select(
                    'old_inv.family_id',
                    'old_inv.currency',
                    DB::raw('SUM(old_item.net_minor - old_item.paid_minor) as dues_minor')
                )
                ->groupBy('old_inv.family_id', 'old_inv.currency')
                ->get();

            foreach ($previousDuesRows as $r) {
                $previousDuesMap->put("{$r->family_id}_{$r->currency}", (int)$r->dues_minor);
            }
        }

        // 4. Enrich each row
        $enriched = $items->map(function ($row) use ($studentsByInvoice, $siblingsByFamily, $previousDuesMap) {
            $stList = $studentsByInvoice->get($row->invoice_id, collect());
            $sibList = $siblingsByFamily->get($row->family_id, collect());

            $key = "{$row->family_id}_{$row->currency}";
            $prevDuesMinor = (int)($previousDuesMap->get($key, 0));

            $remainingBalanceMinor = (int)max(0, $row->total_minor - $row->paid_minor);
            $totalPayableMinor = $remainingBalanceMinor + $prevDuesMinor;

            // Generate signed PDF URLs (valid for 7 days)
            $invoicePdfUrl = URL::temporarySignedRoute(
                'invoices.signed_pdf',
                now()->addDays(7),
                ['invoice' => $row->invoice_id]
            );

            $consolidatedChallanUrl = $row->family_id ? URL::temporarySignedRoute(
                'families.signed_challan_pdf',
                now()->addDays(7),
                ['family' => $row->family_id]
            ) : null;

            // Computed Webhook Submission Status
            $webhookStatus = match ($row->notification_status) {
                'queued' => 'queued',
                'blocked' => 'blocked',
                'failed' => ($row->submitted_at ? 'submitted' : 'failed'),
                'submitted', 'delivered', 'read' => 'submitted',
                default => $row->notification_status,
            };

            // Computed WhatsApp Delivery Status
            $deliveryStatus = match ($row->notification_status) {
                'queued' => 'pending',
                'submitted' => 'pending',
                'delivered' => 'delivered',
                'read' => 'read',
                'failed' => ($row->submitted_at ? 'failed' : 'n/a'),
                'blocked' => 'blocked',
                default => 'pending',
            };

            $row->students_list = $stList;
            $row->siblings_list = $sibList;
            $row->previous_dues_minor = $prevDuesMinor;
            $row->remaining_balance_minor = $remainingBalanceMinor;
            $row->total_payable_minor = $totalPayableMinor;
            $row->formatted_previous_dues = Money::format($prevDuesMinor);
            $row->formatted_balance = Money::format($remainingBalanceMinor);
            $row->formatted_total_payable = Money::format($totalPayableMinor);
            $row->invoice_pdf_url = $invoicePdfUrl;
            $row->consolidated_challan_url = $consolidatedChallanUrl;
            $row->webhook_submission_status = $webhookStatus;
            $row->whatsapp_delivery_status = $deliveryStatus;
            $row->created_at = $row->notification_created_at ?? ($row->created_at ?? null);
            $row->updated_at = $row->notification_updated_at ?? ($row->updated_at ?? null);

            return $row;
        });

        $paginator->setCollection($enriched);
        return $paginator;
    }

    /**
     * Compute summary statistics for current filter scope.
     */
    public function getQueueStats(array $filters, object $user): array
    {
        $base = $this->buildBaseQueueQuery($filters, $user);

        $res = $base->selectRaw("
            COUNT(n.id) as total,
            SUM(CASE WHEN n.status = 'queued' THEN 1 ELSE 0 END) as queued,
            SUM(CASE WHEN n.status IN ('submitted', 'delivered', 'read') THEN 1 ELSE 0 END) as submitted,
            SUM(CASE WHEN n.status = 'failed' THEN 1 ELSE 0 END) as failed,
            SUM(CASE WHEN n.status = 'delivered' THEN 1 ELSE 0 END) as delivered,
            SUM(CASE WHEN n.status = 'read' THEN 1 ELSE 0 END) as read_count,
            SUM(CASE WHEN n.status = 'blocked' THEN 1 ELSE 0 END) as blocked,
            SUM(CASE WHEN n.is_superseded = 1 THEN 1 ELSE 0 END) as superseded
        ")->first();

        return [
            'total' => (int)($res->total ?? 0),
            'queued' => (int)($res->queued ?? 0),
            'submitted' => (int)($res->submitted ?? 0),
            'failed' => (int)($res->failed ?? 0),
            'delivered' => (int)($res->delivered ?? 0),
            'read' => (int)($res->read_count ?? 0),
            'blocked' => (int)($res->blocked ?? 0),
            'superseded' => (int)($res->superseded ?? 0),
        ];
    }

    /**
     * Retrieve batch details, statistics, and approver details.
     */
    public function getBatchDetails(string $batchId, object $user): ?array
    {
        $batch = DB::table('notification_batches as b')
            ->leftJoin('users as u', 'u.id', '=', 'b.approved_by')
            ->where('b.batch_id', $batchId)
            ->select('b.*', 'u.name as approver_name', 'u.email as approver_email')
            ->first();

        if (!$batch) {
            return null;
        }

        // Scope check for campus/dept staff
        if (!in_array($user->role, ['super_admin', 'finance_admin'], true)) {
            // Verify if any invoice in this batch belongs to this user's campus
            $hasInvoice = DB::table('notifications as n')
                ->join('invoices as i', 'i.id', '=', 'n.invoice_id')
                ->where('n.batch_id', $batchId)
                ->where('i.campus_id', $user->campus_id)
                ->exists();

            if (!$hasInvoice) {
                return null;
            }
        }

        $stats = $this->getQueueStats(['batch_id' => $batchId], $user);

        return [
            'batch' => $batch,
            'stats' => $stats,
        ];
    }

    /**
     * Stream CSV export of currently filtered delivery queue.
     */
    public function streamCsvExport(array $filters, object $user): StreamedResponse
    {
        $query = $this->buildQueueQuery($filters, $user);

        $filename = 'iqra-delivery-queue-' . ($filters['batch_id'] ? $filters['batch_id'] . '-' : '') . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM for Excel compatibility
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            // CSV Headers
            fputcsv($handle, [
                'Notification ID',
                'Batch ID',
                'Chunk Index',
                'Invoice Number',
                'Billing Period',
                'Department',
                'Campus',
                'Family Code',
                'Guardian Name',
                'Relationship',
                'WhatsApp Number',
                'WhatsApp Verified',
                'Students',
                'GR Numbers',
                'Grades',
                'Currency',
                'Invoice Remaining Balance',
                'Previous Dues',
                'Total Payable',
                'Webhook Submission Status',
                'WhatsApp Delivery Status',
                'Superseded For Delivery',
                'Provider Message ID',
                'Attempts',
                'Submitted At',
                'Delivered At',
                'Diagnostic Error',
            ]);

            // Stream through cursor in batches of 200
            $query->chunk(200, function ($rows) use ($handle) {
                $invoiceIds = $rows->pluck('invoice_id')->unique()->all();
                $familyIds = $rows->pluck('family_id')->filter()->unique()->all();

                $studentsByInvoice = DB::table('invoice_items as ii')
                    ->join('students as s', 's.id', '=', 'ii.student_id')
                    ->leftJoin('enrollments as en', 'en.id', '=', 'ii.enrollment_id')
                    ->whereIn('ii.invoice_id', $invoiceIds)
                    ->select('ii.invoice_id', 's.name', 's.gr_number', 'en.grade_label')
                    ->distinct()
                    ->get()
                    ->groupBy('invoice_id');

                $previousDuesMap = collect();
                if (!empty($familyIds)) {
                    $duesRows = DB::table('invoices as old_inv')
                        ->join('invoice_items as old_item', 'old_item.invoice_id', '=', 'old_inv.id')
                        ->whereIn('old_inv.family_id', $familyIds)
                        ->whereNotIn('old_inv.id', $invoiceIds)
                        ->whereRaw('old_item.net_minor > old_item.paid_minor')
                        ->whereNotIn('old_item.status', ['voided', 'exempted', 'paid'])
                        ->whereNotIn('old_inv.status', ['void', 'voided', 'paid'])
                        ->select(
                            'old_inv.family_id',
                            'old_inv.currency',
                            DB::raw('SUM(old_item.net_minor - old_item.paid_minor) as dues_minor')
                        )
                        ->groupBy('old_inv.family_id', 'old_inv.currency')
                        ->get();

                    foreach ($duesRows as $dr) {
                        $previousDuesMap->put("{$dr->family_id}_{$dr->currency}", (int)$dr->dues_minor);
                    }
                }

                foreach ($rows as $row) {
                    $stItems = $studentsByInvoice->get($row->invoice_id, collect());
                    $stNames = $stItems->pluck('name')->unique()->implode('; ');
                    $stGrs = $stItems->pluck('gr_number')->filter()->unique()->implode('; ');
                    $stGrades = $stItems->pluck('grade_label')->filter()->unique()->implode('; ');

                    $key = "{$row->family_id}_{$row->currency}";
                    $prevDuesMinor = (int)($previousDuesMap->get($key, 0));
                    $remainingBalanceMinor = (int)max(0, $row->total_minor - $row->paid_minor);
                    $totalPayableMinor = $remainingBalanceMinor + $prevDuesMinor;

                    $webhookStatus = match ($row->notification_status) {
                        'queued' => 'queued',
                        'blocked' => 'blocked',
                        'failed' => ($row->submitted_at ? 'submitted' : 'failed'),
                        'submitted', 'delivered', 'read' => 'submitted',
                        default => $row->notification_status,
                    };

                    $deliveryStatus = match ($row->notification_status) {
                        'queued' => 'pending',
                        'submitted' => 'pending',
                        'delivered' => 'delivered',
                        'read' => 'read',
                        'failed' => ($row->submitted_at ? 'failed' : 'n/a'),
                        'blocked' => 'blocked',
                        default => 'pending',
                    };

                    fputcsv($handle, [
                        $row->notification_id,
                        $row->batch_id ?: 'N/A',
                        $row->chunk_index ?: 1,
                        $row->invoice_number,
                        $row->billing_period,
                        $row->department_name ?: 'General',
                        $row->campus_name ?: 'Main Campus',
                        $row->family_code ?: 'N/A',
                        $row->guardian_name ?: 'Guardian',
                        'Guardian',
                        $row->whatsapp ?: 'N/A',
                        $row->whatsapp_verified ? 'Yes' : 'No',
                        $stNames,
                        $stGrs,
                        $stGrades,
                        $row->currency,
                        Money::format($remainingBalanceMinor),
                        Money::format($prevDuesMinor),
                        Money::format($totalPayableMinor),
                        ucfirst($webhookStatus),
                        ucfirst($deliveryStatus),
                        $row->is_superseded ? 'Yes' : 'No',
                        $row->provider_message_id ?: 'None',
                        $row->attempts,
                        $row->submitted_at ? substr($row->submitted_at, 0, 19) : '',
                        $row->delivered_at ? substr($row->delivered_at, 0, 19) : '',
                        $row->notification_error ?: '',
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Get list of all available monthly billing periods from generated invoices.
     */
    public function getAvailableBillingMonths(): array
    {
        return DB::table('billing_runs as b')
            ->join('invoices as i', 'i.billing_run_id', '=', 'b.id')
            ->where('b.frequency', 'monthly')
            ->selectRaw("DISTINCT SUBSTRING(b.period_start, 1, 7) as period_month")
            ->orderByDesc('period_month')
            ->pluck('period_month')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Get the latest successfully generated monthly billing period.
     */
    public function getLatestBillingMonth(): string
    {
        $months = $this->getAvailableBillingMonths();
        return $months[0] ?? date('Y-m');
    }

    /**
     * Compute clean dashboard KPI statistics for the selected billing month (Section 8).
     */
    public function getDashboardKpiStats(string $period, object $user, array $filters = []): array
    {
        $dt = \Carbon\Carbon::createFromFormat('Y-m', $period);
        $monthName = $dt ? $dt->format('F Y') : $period;

        $baseInv = DB::table('invoices as i')
            ->join('billing_runs as b', 'b.id', '=', 'i.billing_run_id')
            ->join('families as f', 'f.id', '=', 'i.family_id')
            ->where('b.period_start', 'like', $period . '%')
            ->whereNotIn('i.status', ['void', 'voided']);

        if (!in_array($user->role, ['super_admin', 'finance_admin'], true)) {
            $baseInv->where('i.campus_id', $user->campus_id);
            if (!empty($user->department_id)) {
                $baseInv->where('i.department_id', $user->department_id);
            }
        }

        // Ready / Awaiting Review (unpaid, not superseded by newer run, not already queued/sent on ANY family invoice)
        $readyQuery = (clone $baseInv)
            ->where('i.status', '!=', 'paid')
            ->whereRaw('i.total_minor > i.paid_minor')
            ->whereNotExists(function ($sub) use ($period) {
                $sub->select(DB::raw(1))
                    ->from('notifications as n')
                    ->join('invoices as ni', 'ni.id', '=', 'n.invoice_id')
                    ->join('billing_runs as nb', 'nb.id', '=', 'ni.billing_run_id')
                    ->whereColumn('ni.family_id', 'i.family_id')
                    ->where('nb.period_start', 'like', $period . '%')
                    ->where('n.kind', 'monthly')
                    ->whereIn('n.status', ['submitted', 'delivered', 'read', 'queued']);
            });

        // Actionable eligible ready query (verified contact + opt-in)
        $actionableReadyQuery = (clone $readyQuery)
            ->where('f.contact_verified', 1)
            ->where('f.messaging_opt_in', 1)
            ->whereNotNull('f.whatsapp')
            ->where('f.whatsapp', '!=', '');

        // Ineligible query (missing verification / opt-in)
        $ineligibleQuery = (clone $readyQuery)
            ->where(function ($sub) {
                $sub->where('f.contact_verified', 0)
                    ->orWhere('f.messaging_opt_in', 0)
                    ->orWhereNull('f.whatsapp')
                    ->orWhere('f.whatsapp', '');
            });

        $actionableReadyCount = (clone $actionableReadyQuery)->distinct()->count('i.family_id');
        $actionableInvoicesCount = (clone $actionableReadyQuery)->count('i.id');
        $ineligibleCount = (clone $ineligibleQuery)->distinct()->count('i.family_id');

        // Paid Invoices (fully settled invoices for the selected period)
        $paidInvQuery = (clone $baseInv)
            ->where(function ($sub) {
                $sub->where('i.status', 'paid')
                    ->orWhere(function ($sq) {
                        $sq->where('i.total_minor', '>', 0)
                           ->whereColumn('i.paid_minor', '>=', 'i.total_minor');
                    });
            });
        $paidInvoicesCount = $paidInvQuery->count('i.id');

        // Processing (currently queued in active batches)
        $procQuery = DB::table('notifications as n')
            ->join('invoices as i', 'i.id', '=', 'n.invoice_id')
            ->join('billing_runs as b', 'b.id', '=', 'i.billing_run_id')
            ->where('b.period_start', 'like', $period . '%')
            ->whereIn('n.kind', ['monthly', 'paid'])
            ->where('n.status', 'queued');

        if (!in_array($user->role, ['super_admin', 'finance_admin'], true)) {
            $procQuery->where('i.campus_id', $user->campus_id);
            if (!empty($user->department_id)) {
                $procQuery->where('i.department_id', $user->department_id);
            }
        }
        $processingCount = $procQuery->count();

        // Submitted / Sent (HTTP 200/202 accepted by n8n or delivered/read)
        $sentQuery = DB::table('notifications as n')
            ->join('invoices as i', 'i.id', '=', 'n.invoice_id')
            ->join('billing_runs as b', 'b.id', '=', 'i.billing_run_id')
            ->where('b.period_start', 'like', $period . '%')
            ->whereIn('n.kind', ['monthly', 'paid'])
            ->whereIn('n.status', ['submitted', 'delivered', 'read']);

        if (!in_array($user->role, ['super_admin', 'finance_admin'], true)) {
            $sentQuery->where('i.campus_id', $user->campus_id);
            if (!empty($user->department_id)) {
                $sentQuery->where('i.department_id', $user->department_id);
            }
        }
        $submittedCount = $sentQuery->count();

        // Needs Attention (failed or blocked + contact ineligible unnotified)
        $attNotifQuery = DB::table('notifications as n')
            ->join('invoices as i', 'i.id', '=', 'n.invoice_id')
            ->join('billing_runs as b', 'b.id', '=', 'i.billing_run_id')
            ->where('b.period_start', 'like', $period . '%')
            ->whereIn('n.kind', ['monthly', 'paid'])
            ->whereIn('n.status', ['failed', 'blocked']);

        if (!in_array($user->role, ['super_admin', 'finance_admin'], true)) {
            $attNotifQuery->where('i.campus_id', $user->campus_id);
            if (!empty($user->department_id)) {
                $attNotifQuery->where('i.department_id', $user->department_id);
            }
        }
        $notifFamilyIds = (clone $attNotifQuery)->distinct()->pluck('i.family_id')->all();
        $ineligibleFamilyIds = (clone $ineligibleQuery)->distinct()->pluck('i.family_id')->all();
        $attentionCount = count(array_unique(array_merge($notifFamilyIds, $ineligibleFamilyIds)));

        // Check if there is an active search or sub-filter
        $hasActiveSearch = !empty($filters['search']) || !empty($filters['grade']) || !empty($filters['department_id']) || !empty($filters['campus_id']);
        $scopedEligible = null;
        $scopedInvoices = null;
        if ($hasActiveSearch) {
            $batchSvc = app(BatchDeliveryService::class);
            $scopedRes = $batchSvc->resolveFilteredSelection($filters, $user, ['whatsapp']);
            $scopedEligible = $scopedRes['total_eligible_recipients'] ?? 0;
            $scopedInvoices = $scopedRes['total_matched_invoices'] ?? 0;
        }

        return [
            'selected_period' => $period,
            'selected_month' => $monthName,
            'awaiting_review' => $actionableReadyCount,
            'awaiting_review_invoices' => $actionableInvoicesCount,
            'ready_families' => $actionableReadyCount,
            'ready_invoices' => $actionableInvoicesCount,
            'paid_invoices' => $paidInvoicesCount,
            'processing' => $processingCount,
            'submitted' => $submittedCount,
            'attention' => $attentionCount,
            'contact_ineligible' => $ineligibleCount,
            'has_active_search' => $hasActiveSearch,
            'scoped_eligible' => $scopedEligible,
            'scoped_invoices' => $scopedInvoices,
        ];
    }

    /**
     * Get paginated active Ready to Send monthly family challans for selected billing month.
     * Consolidates multi-invoice families into single monthly family delivery targets,
     * properly accounting for all siblings, multi-currency balances, and previous unpaid dues.
     */
    public function getReadyToSendChallans(array $filters, object $user, int $perPage = 25): LengthAwarePaginator
    {
        $period = !empty($filters['period']) ? $filters['period'] : $this->getLatestBillingMonth();

        $q = DB::table('invoices as i')
            ->join('billing_runs as b', 'b.id', '=', 'i.billing_run_id')
            ->join('families as f', 'f.id', '=', 'i.family_id')
            ->leftJoin('departments as d', 'd.id', '=', 'i.department_id')
            ->leftJoin('campuses as c', 'c.id', '=', 'i.campus_id')
            ->where('b.period_start', 'like', $period . '%')
            ->where('i.status', '!=', 'paid')
            ->whereRaw('i.total_minor > i.paid_minor')
            ->whereNotIn('i.status', ['void', 'voided']);

        // Scope check for campus/dept staff
        if (!in_array($user->role, ['super_admin', 'finance_admin'], true)) {
            $q->where('i.campus_id', $user->campus_id);
            if (!empty($user->department_id)) {
                $q->where('i.department_id', $user->department_id);
            }
        }

        // Filters
        if (!empty($filters['campus_id'])) {
            $q->where('i.campus_id', (int)$filters['campus_id']);
        }
        if (!empty($filters['department_id'])) {
            $q->where('i.department_id', (int)$filters['department_id']);
        }

        // Grade filter
        if (!empty($filters['grade'])) {
            $grade = $filters['grade'];
            $q->whereExists(function ($sub) use ($grade) {
                $sub->select(DB::raw(1))
                    ->from('invoice_items as sub_ii')
                    ->join('enrollments as sub_e', 'sub_e.id', '=', 'sub_ii.enrollment_id')
                    ->whereColumn('sub_ii.invoice_id', 'i.id')
                    ->where('sub_e.grade_label', $grade);
            });
        }

        // Search filter (handles exact family code prioritization + universal search)
        if (!empty($filters['search'])) {
            $term = trim($filters['search']);
            $isExactFamily = DB::table('families')->where('family_code', $term)->exists();
            if ($isExactFamily) {
                $q->where('f.family_code', $term);
            } else {
                $q->where(function ($sq) use ($term) {
                    $sq->where('i.invoice_number', 'like', "%{$term}%")
                       ->orWhere('f.family_code', 'like', "%{$term}%")
                       ->orWhere('f.guardian_name', 'like', "%{$term}%")
                       ->orWhere('f.whatsapp', 'like', "%{$term}%")
                       ->orWhere('f.email', 'like', "%{$term}%")
                       ->orWhereExists(function ($stSub) use ($term) {
                           $stSub->selectRaw('1')
                               ->from('invoice_items as st_ii')
                               ->join('students as st_s', 'st_s.id', '=', 'st_ii.student_id')
                               ->whereColumn('st_ii.invoice_id', 'i.id')
                               ->where(function ($stQuery) use ($term) {
                                   $stQuery->where('st_s.name', 'like', "%{$term}%")
                                           ->orWhere('st_s.gr_number', 'like', "%{$term}%");
                               });
                       });
                });
            }
        }

        // Contact eligibility filter
        if (!empty($filters['contact_status']) && $filters['contact_status'] !== 'all') {
            if ($filters['contact_status'] === 'eligible_only') {
                $q->where('f.contact_verified', 1)->where('f.messaging_opt_in', 1)->whereNotNull('f.whatsapp')->where('f.whatsapp', '!=', '');
            } elseif ($filters['contact_status'] === 'ineligible_only') {
                $q->where(function ($sub) {
                    $sub->where('f.contact_verified', 0)
                        ->orWhere('f.messaging_opt_in', 0)
                        ->orWhereNull('f.whatsapp')
                        ->orWhere('f.whatsapp', '');
                });
            }
        } else {
            // Default in Ready to Send: only actionable eligible families
            $q->where('f.contact_verified', 1)->where('f.messaging_opt_in', 1)->whereNotNull('f.whatsapp')->where('f.whatsapp', '!=', '');
        }

        // Exclude if ANY invoice of this family for this cycle already has an active notification
        $q->whereNotExists(function ($sub) use ($period) {
            $sub->select(DB::raw(1))
                ->from('notifications as n')
                ->join('invoices as ni', 'ni.id', '=', 'n.invoice_id')
                ->join('billing_runs as nb', 'nb.id', '=', 'ni.billing_run_id')
                ->whereColumn('ni.family_id', 'i.family_id')
                ->where('nb.period_start', 'like', $period . '%')
                ->where('n.kind', 'monthly')
                ->whereIn('n.status', ['submitted', 'delivered', 'read', 'queued']);
        });

        // Paginate by distinct families
        $familyIdsQuery = (clone $q)->select('i.family_id')->distinct();
        $totalFamilies = (clone $q)->distinct()->count('i.family_id');

        $currentPage = \Illuminate\Pagination\Paginator::resolveCurrentPage() ?: 1;
        $pagedFamilyIds = (clone $familyIdsQuery)
            ->orderByDesc('i.family_id')
            ->forPage($currentPage, $perPage)
            ->pluck('family_id')
            ->all();

        if (empty($pagedFamilyIds)) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], $totalFamilies, $perPage, $currentPage, [
                'path' => \Illuminate\Pagination\Paginator::resolveCurrentPath(),
                'query' => request()->query(),
            ]);
        }

        // Fetch all invoices for these paged families in this period
        $invoices = (clone $q)
            ->whereIn('i.family_id', $pagedFamilyIds)
            ->select(
                'i.id as invoice_id',
                'i.invoice_number',
                'i.family_id',
                'i.campus_id',
                'i.department_id',
                'i.currency',
                'i.total_minor',
                'i.paid_minor',
                'i.issued_on',
                'i.due_date',
                'i.status as invoice_status',
                'b.period_start as billing_period',
                'f.family_code',
                'f.guardian_name',
                'f.whatsapp',
                'f.email',
                'f.contact_verified',
                'f.whatsapp_verified',
                'f.email_verified',
                'f.messaging_opt_in',
                'd.name as department_name',
                'c.name as campus_name'
            )
            ->orderByDesc('i.id')
            ->get();

        $invoicesByFamily = $invoices->groupBy('family_id');
        $allInvoiceIds = $invoices->pluck('invoice_id')->all();

        // Students and grades for these invoices
        $studentsByInvoice = DB::table('invoice_items as ii')
            ->join('students as s', 's.id', '=', 'ii.student_id')
            ->leftJoin('enrollments as e', 'e.id', '=', 'ii.enrollment_id')
            ->whereIn('ii.invoice_id', $allInvoiceIds)
            ->select(
                'ii.invoice_id',
                's.id as student_id',
                's.name as student_name',
                's.gr_number',
                'e.grade_label'
            )
            ->distinct()
            ->get()
            ->groupBy('invoice_id');

        // All active siblings for these families across other grades
        $siblingsByFamily = DB::table('students as s')
            ->join('enrollments as e', 'e.student_id', '=', 's.id')
            ->whereIn('s.family_id', $pagedFamilyIds)
            ->where('s.status', 'active')
            ->where('e.status', 'active')
            ->select('s.family_id', 's.id as student_id', 's.name', 's.gr_number', 'e.grade_label')
            ->distinct()
            ->get()
            ->groupBy('family_id');

        // Previous unpaid dues across prior months for each family and currency
        $earliestPeriod = $invoices->min('billing_period') ?: ($period . '-01');
        $duesRows = DB::table('invoices as old_inv')
            ->join('invoice_items as old_item', 'old_item.invoice_id', '=', 'old_inv.id')
            ->join('billing_runs as ob', 'ob.id', '=', 'old_inv.billing_run_id')
            ->whereIn('old_inv.family_id', $pagedFamilyIds)
            ->where('ob.period_start', '<', $earliestPeriod)
            ->whereRaw('old_item.net_minor > old_item.paid_minor')
            ->whereNotIn('old_item.status', ['void', 'voided', 'paid', 'exempted'])
            ->whereNotIn('old_inv.status', ['void', 'voided', 'paid'])
            ->select(
                'old_inv.family_id',
                'old_inv.currency',
                DB::raw("SUBSTRING(ob.period_start, 1, 7) as period_month"),
                DB::raw('SUM(old_item.net_minor - old_item.paid_minor) as dues_minor')
            )
            ->groupBy('old_inv.family_id', 'old_inv.currency', 'period_month')
            ->orderBy('period_month')
            ->get();

        $duesByFamily = $duesRows->groupBy('family_id');

        $familyRows = collect();
        foreach ($pagedFamilyIds as $famId) {
            $famInvoices = $invoicesByFamily->get($famId, collect());
            if ($famInvoices->isEmpty()) continue;

            $primaryInv = $famInvoices->first();
            $invIds = $famInvoices->pluck('invoice_id')->all();
            $invNumbers = $famInvoices->pluck('invoice_number')->all();

            // Students
            $famStudents = collect();
            foreach ($invIds as $iId) {
                $stList = $studentsByInvoice->get($iId, collect());
                foreach ($stList as $st) {
                    $famStudents->push($st);
                }
            }
            $uniqueStudents = $famStudents->unique('gr_number')->values();
            $familySiblings = $siblingsByFamily->get($famId, collect());

            // Previous dues for this family
            $famDuesRows = $duesByFamily->get($famId, collect());
            $duesByMonth = $famDuesRows->map(function ($pRow) {
                return [
                    'month' => $pRow->period_month,
                    'currency' => $pRow->currency,
                    'dues_minor' => (int)$pRow->dues_minor,
                    'formatted' => $pRow->currency . ' ' . Money::format($pRow->dues_minor),
                ];
            })->all();

            $prevDuesByCurr = [];
            foreach ($famDuesRows as $dr) {
                $prevDuesByCurr[$dr->currency] = ($prevDuesByCurr[$dr->currency] ?? 0) + (int)$dr->dues_minor;
            }

            // Currency breakdown
            $byCurr = $famInvoices->groupBy('currency');
            $currencyBreakdowns = [];
            $totalCurrentMinor = 0;
            $totalPrevMinor = 0;
            $currenciesMap = [];

            foreach ($byCurr as $curr => $currInvs) {
                $currFee = $currInvs->sum(fn($ci) => max(0, (int)$ci->total_minor - (int)$ci->paid_minor));
                $prevDues = (int)($prevDuesByCurr[$curr] ?? 0);
                $totalPayable = $currFee + $prevDues;

                $breakdownItem = [
                    'currency' => $curr,
                    'current_fee_minor' => $currFee,
                    'formatted_current_fee' => $curr . ' ' . Money::format($currFee),
                    'previous_dues_minor' => $prevDues,
                    'formatted_previous_dues' => $curr . ' ' . Money::format($prevDues),
                    'total_payable_minor' => $totalPayable,
                    'formatted_total_payable' => $curr . ' ' . Money::format($totalPayable),
                ];

                $currencyBreakdowns[] = $breakdownItem;
                $currenciesMap[$curr] = $breakdownItem;
                $totalCurrentMinor += $currFee;
                $totalPrevMinor += $prevDues;
            }

            // Include any currencies that only have previous dues
            foreach ($prevDuesByCurr as $pCurr => $pAmount) {
                if (!isset($currenciesMap[$pCurr])) {
                    $breakdownItem = [
                        'currency' => $pCurr,
                        'current_fee_minor' => 0,
                        'formatted_current_fee' => $pCurr . ' ' . Money::format(0),
                        'previous_dues_minor' => $pAmount,
                        'formatted_previous_dues' => $pCurr . ' ' . Money::format($pAmount),
                        'total_payable_minor' => $pAmount,
                        'formatted_total_payable' => $pCurr . ' ' . Money::format($pAmount),
                    ];
                    $currencyBreakdowns[] = $breakdownItem;
                    $currenciesMap[$pCurr] = $breakdownItem;
                    $totalPrevMinor += $pAmount;
                }
            }

            $primaryCurrency = $primaryInv->currency;
            $primaryCurrBreakdown = $currenciesMap[$primaryCurrency] ?? ($currencyBreakdowns[0] ?? null);

            $signedChallanUrl = URL::temporarySignedRoute(
                'families.signed_challan_pdf',
                now()->addDays(7),
                ['family' => $famId]
            );

            $rowObj = (object)[
                'family_id' => $famId,
                'family_code' => $primaryInv->family_code,
                'guardian_name' => $primaryInv->guardian_name,
                'whatsapp' => $primaryInv->whatsapp,
                'email' => $primaryInv->email,
                'contact_verified' => (bool)$primaryInv->contact_verified,
                'whatsapp_verified' => (bool)$primaryInv->whatsapp_verified,
                'email_verified' => (bool)$primaryInv->email_verified,
                'messaging_opt_in' => (bool)$primaryInv->messaging_opt_in,
                'primary_invoice_id' => $primaryInv->invoice_id,
                'invoice_id' => $primaryInv->invoice_id,
                'invoice_ids' => $invIds,
                'invoice_numbers' => $invNumbers,
                'invoice_number' => $primaryInv->invoice_number,
                'billing_period' => $primaryInv->billing_period,
                'due_date' => $primaryInv->due_date,
                'invoice_status' => $primaryInv->invoice_status,
                'campus_name' => $primaryInv->campus_name,
                'department_name' => $primaryInv->department_name,
                'currency' => $primaryCurrency,
                'total_minor' => (int)$primaryInv->total_minor,
                'paid_minor' => (int)$primaryInv->paid_minor,
                'is_multi_currency' => count($currencyBreakdowns) > 1,
                'currencies' => $currencyBreakdowns,
                'currencies_map' => $currenciesMap,
                'current_fee_minor' => $primaryCurrBreakdown['current_fee_minor'] ?? $totalCurrentMinor,
                'formatted_current_fee' => $primaryCurrBreakdown['formatted_current_fee'] ?? ($primaryCurrency . ' ' . Money::format($totalCurrentMinor)),
                'prev_dues_minor' => $primaryCurrBreakdown['previous_dues_minor'] ?? $totalPrevMinor,
                'formatted_prev_dues' => $primaryCurrBreakdown['formatted_previous_dues'] ?? ($primaryCurrency . ' ' . Money::format($totalPrevMinor)),
                'remaining_balance_minor' => $primaryCurrBreakdown['current_fee_minor'] ?? $totalCurrentMinor,
                'formatted_remaining_balance' => $primaryCurrBreakdown['formatted_current_fee'] ?? ($primaryCurrency . ' ' . Money::format($totalCurrentMinor)),
                'total_payable_minor' => $primaryCurrBreakdown['total_payable_minor'] ?? ($totalCurrentMinor + $totalPrevMinor),
                'formatted_total_payable' => $primaryCurrBreakdown['formatted_total_payable'] ?? ($primaryCurrency . ' ' . Money::format($totalCurrentMinor + $totalPrevMinor)),
                'invoice_students' => $uniqueStudents,
                'family_siblings' => $familySiblings,
                'dues_by_month' => $duesByMonth,
                'consolidated_challan_url' => $signedChallanUrl,
                'signed_challan_url' => $signedChallanUrl,
            ];

            $familyRows->push($rowObj);
        }

        return new \Illuminate\Pagination\LengthAwarePaginator($familyRows, $totalFamilies, $perPage, $currentPage, [
            'path' => \Illuminate\Pagination\Paginator::resolveCurrentPath(),
            'query' => request()->query(),
        ]);
    }

    /**
     * Get paginated fully paid invoices and their corresponding payment receipts for the selected billing month.
     * Enriched with student/family details, payment receipts, delivery notifications, and receipt/invoice PDFs.
     */
    public function getPaidInvoices(array $filters, object $user, int $perPage = 25): LengthAwarePaginator
    {
        $allowedPerPage = [25, 50, 100];
        if (!in_array($perPage, $allowedPerPage, true)) {
            $perPage = 25;
        }

        $period = !empty($filters['period']) ? $filters['period'] : $this->getLatestBillingMonth();

        $q = DB::table('invoices as i')
            ->join('billing_runs as b', 'b.id', '=', 'i.billing_run_id')
            ->join('families as f', 'f.id', '=', 'i.family_id')
            ->leftJoin('departments as d', 'd.id', '=', 'i.department_id')
            ->leftJoin('campuses as c', 'c.id', '=', 'i.campus_id')
            ->where('b.period_start', 'like', $period . '%')
            ->where(function ($sub) {
                $sub->where('i.status', 'paid')
                    ->orWhere(function ($sq) {
                        $sq->where('i.total_minor', '>', 0)
                           ->whereColumn('i.paid_minor', '>=', 'i.total_minor');
                    });
            })
            ->whereNotIn('i.status', ['void', 'voided']);

        // Role-based scoping
        if (!in_array($user->role, ['super_admin', 'finance_admin'], true)) {
            $q->where('i.campus_id', $user->campus_id);
            if (!empty($user->department_id)) {
                $q->where('i.department_id', $user->department_id);
            }
        } elseif (!empty($filters['campus_id'])) {
            $q->where('i.campus_id', (int)$filters['campus_id']);
        }

        // Department
        if (!empty($filters['department_id'])) {
            $q->where('i.department_id', (int)$filters['department_id']);
        }

        // Grade
        if (!empty($filters['grade'])) {
            $grade = $filters['grade'];
            $q->whereExists(function ($sub) use ($grade) {
                $sub->select(DB::raw(1))
                    ->from('invoice_items as sub_ii')
                    ->join('enrollments as sub_en', 'sub_en.id', '=', 'sub_ii.enrollment_id')
                    ->whereColumn('sub_ii.invoice_id', 'i.id')
                    ->where('sub_en.grade_label', $grade);
            });
        }

        // Contact eligibility
        if (!empty($filters['contact_status']) && $filters['contact_status'] !== 'all') {
            if ($filters['contact_status'] === 'eligible_only') {
                $q->where('f.contact_verified', 1)
                  ->where('f.messaging_opt_in', 1)
                  ->whereNotNull('f.whatsapp')
                  ->where('f.whatsapp', '!=', '');
            } elseif ($filters['contact_status'] === 'ineligible_only') {
                $q->where(function ($sq) {
                    $sq->where('f.contact_verified', 0)
                       ->orWhere('f.messaging_opt_in', 0)
                       ->orWhereNull('f.whatsapp')
                       ->orWhere('f.whatsapp', '');
                });
            }
        }

        // Universal Search
        if (!empty($filters['search'])) {
            $term = trim($filters['search']);
            $isExactFamily = DB::table('families')->where('family_code', $term)->exists();
            if ($isExactFamily) {
                $q->where('f.family_code', $term);
            } else {
                $like = "%{$term}%";
                $q->where(function ($sq) use ($like) {
                    $sq->where('i.invoice_number', 'like', $like)
                       ->orWhere('f.family_code', 'like', $like)
                       ->orWhere('f.guardian_name', 'like', $like)
                       ->orWhere('f.whatsapp', 'like', $like)
                       ->orWhere('f.email', 'like', $like)
                       ->orWhereExists(function ($stSub) use ($like) {
                           $stSub->selectRaw('1')
                               ->from('invoice_items as st_ii')
                               ->join('students as st_s', 'st_s.id', '=', 'st_ii.student_id')
                               ->whereColumn('st_ii.invoice_id', 'i.id')
                               ->where(function ($stQ) use ($like) {
                                   $stQ->where('st_s.name', 'like', $like)
                                       ->orWhere('st_s.gr_number', 'like', $like);
                               });
                       });
                });
            }
        }

        $paginator = $q->select(
            'i.id',
            'i.invoice_number',
            'i.currency',
            'i.total_minor',
            'i.paid_minor',
            'i.status as invoice_status',
            'i.issued_on',
            'i.due_date',
            'i.created_at as invoice_created_at',
            'b.period_start as billing_period',
            'f.id as family_id',
            'f.family_code',
            'f.guardian_name',
            'f.whatsapp',
            'f.email as family_email',
            'f.contact_verified',
            'f.messaging_opt_in',
            'd.name as department_name',
            'c.name as campus_name'
        )
        ->orderByDesc('i.id')
        ->paginate($perPage);

        $invoiceIds = $paginator->pluck('id')->all();

        if (empty($invoiceIds)) {
            return $paginator;
        }

        // Eager-load students and enrollments
        $studentsRaw = DB::table('invoice_items as ii')
            ->join('students as s', 's.id', '=', 'ii.student_id')
            ->leftJoin('enrollments as e', 'e.id', '=', 'ii.enrollment_id')
            ->whereIn('ii.invoice_id', $invoiceIds)
            ->select('ii.invoice_id', 's.id as student_id', 's.name', 's.gr_number', 'e.grade_label')
            ->distinct()
            ->get()
            ->groupBy('invoice_id');

        // Eager-load payment receipts
        $receiptsRaw = DB::table('payment_allocations as pa')
            ->join('invoice_items as ii', 'ii.id', '=', 'pa.invoice_item_id')
            ->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->whereIn('ii.invoice_id', $invoiceIds)
            ->select(
                'ii.invoice_id',
                'p.id as payment_id',
                'p.receipt_number',
                'p.received_on',
                'p.note',
                'p.currency',
                'pa.amount_minor'
            )
            ->orderByDesc('p.received_on')
            ->get();

        $receiptsByInvoice = [];
        foreach ($receiptsRaw as $r) {
            $invId = $r->invoice_id;
            $pId = $r->payment_id;
            if (!isset($receiptsByInvoice[$invId][$pId])) {
                $receiptsByInvoice[$invId][$pId] = [
                    'payment_id' => $pId,
                    'receipt_number' => $r->receipt_number,
                    'payment_date' => $r->received_on,
                    'note' => $r->note,
                    'currency' => $r->currency,
                    'amount_minor' => 0,
                    'formatted_amount' => '',
                    'receipt_pdf_url' => url("/receipts/{$pId}/pdf"),
                ];
            }
            $receiptsByInvoice[$invId][$pId]['amount_minor'] += (int)$r->amount_minor;
        }

        foreach ($receiptsByInvoice as $invId => &$pMap) {
            foreach ($pMap as &$pItem) {
                $pItem['formatted_amount'] = Money::format($pItem['amount_minor']);
            }
        }
        unset($pMap, $pItem);

        // Eager-load existing paid notifications
        $notifs = DB::table('notifications')
            ->whereIn('invoice_id', $invoiceIds)
            ->where('kind', 'paid')
            ->orderByDesc('id')
            ->get()
            ->groupBy('invoice_id');

        $enriched = $paginator->getCollection()->map(function ($row) use ($studentsRaw, $receiptsByInvoice, $notifs) {
            $rowStudents = $studentsRaw->get($row->id, collect());
            $rowReceipts = array_values($receiptsByInvoice[$row->id] ?? []);
            $rowNotif = $notifs->get($row->id, collect())->first();

            $isContactEligible = (bool)$row->contact_verified && (bool)$row->messaging_opt_in && !empty($row->whatsapp);
            $isAlreadySentOrQueued = $rowNotif && in_array($rowNotif->status, ['submitted', 'delivered', 'read', 'queued'], true);

            $row->students = $rowStudents;
            $row->receipts = $rowReceipts;
            $row->notification = $rowNotif;
            $row->is_contact_eligible = $isContactEligible;
            $row->is_already_queued_or_sent = $isAlreadySentOrQueued;
            $row->delivery_status = $rowNotif?->status ?? 'ready';
            $row->delivery_batch_id = $rowNotif?->batch_id;
            $row->delivery_error = $rowNotif?->error;
            $row->delivery_submitted_at = $rowNotif?->submitted_at;
            $row->invoice_pdf_url = url("/invoices/{$row->id}/pdf");

            return $row;
        });

        $paginator->setCollection($enriched);

        return $paginator;
    }

    /**
     * Get paginated Needs Attention cases for the selected billing month.
     * Includes:
     * 1. Families with missing/invalid WhatsApp contacts.
     * 2. Families without required verification or messaging permission.
     * 3. Actual failed or blocked webhook notifications.
     *
     * Deduplicated by family so the KPI and table share the exact same counting unit.
     */
    public function getNeedsAttentionCases(array $filters, object $user, int $perPage = 25): LengthAwarePaginator
    {
        $allowedPerPage = [25, 50, 100];
        if (!in_array($perPage, $allowedPerPage, true)) {
            $perPage = 25;
        }

        $period = !empty($filters['period']) ? $filters['period'] : $this->getLatestBillingMonth();

        // 1. Ineligible families query (unpaid invoices in period with contact/permission issues)
        $ineligibleBase = DB::table('invoices as i')
            ->join('billing_runs as b', 'b.id', '=', 'i.billing_run_id')
            ->join('families as f', 'f.id', '=', 'i.family_id')
            ->where('b.period_start', 'like', $period . '%')
            ->whereNotIn('i.status', ['void', 'voided'])
            ->where('i.status', '!=', 'paid')
            ->whereRaw('i.total_minor > i.paid_minor')
            ->whereNotExists(function ($sub) use ($period) {
                $sub->select(DB::raw(1))
                    ->from('notifications as n')
                    ->join('invoices as ni', 'ni.id', '=', 'n.invoice_id')
                    ->join('billing_runs as nb', 'nb.id', '=', 'ni.billing_run_id')
                    ->whereColumn('ni.family_id', 'i.family_id')
                    ->where('nb.period_start', 'like', $period . '%')
                    ->where('n.kind', 'monthly')
                    ->whereIn('n.status', ['submitted', 'delivered', 'read', 'queued']);
            })
            ->where(function ($sub) {
                $sub->where('f.contact_verified', 0)
                    ->orWhere('f.messaging_opt_in', 0)
                    ->orWhereNull('f.whatsapp')
                    ->orWhere('f.whatsapp', '');
            });

        // 2. Failed / blocked notifications query
        $notifBase = DB::table('notifications as n')
            ->join('invoices as i', 'i.id', '=', 'n.invoice_id')
            ->join('billing_runs as b', 'b.id', '=', 'i.billing_run_id')
            ->join('families as f', 'f.id', '=', 'i.family_id')
            ->where('b.period_start', 'like', $period . '%')
            ->whereIn('n.kind', ['monthly', 'paid'])
            ->whereIn('n.status', ['failed', 'blocked']);

        // Staff access control
        if (!in_array($user->role, ['super_admin', 'finance_admin'], true)) {
            $ineligibleBase->where('i.campus_id', $user->campus_id);
            $notifBase->where('i.campus_id', $user->campus_id);
            if (!empty($user->department_id)) {
                $ineligibleBase->where('i.department_id', $user->department_id);
                $notifBase->where('i.department_id', $user->department_id);
            }
        }

        // Campus filter
        if (!empty($filters['campus_id'])) {
            $ineligibleBase->where('i.campus_id', (int)$filters['campus_id']);
            $notifBase->where('i.campus_id', (int)$filters['campus_id']);
        }

        // Department filter
        if (!empty($filters['department_id'])) {
            $ineligibleBase->where('i.department_id', (int)$filters['department_id']);
            $notifBase->where('i.department_id', (int)$filters['department_id']);
        }

        // Grade filter
        if (!empty($filters['grade'])) {
            $grade = $filters['grade'];
            $gradeFilter = function ($sub) use ($grade) {
                $sub->select(DB::raw(1))
                    ->from('invoice_items as sub_ii')
                    ->join('enrollments as sub_e', 'sub_e.id', '=', 'sub_ii.enrollment_id')
                    ->whereColumn('sub_ii.invoice_id', 'i.id')
                    ->where('sub_e.grade_label', $grade);
            };
            $ineligibleBase->whereExists($gradeFilter);
            $notifBase->whereExists($gradeFilter);
        }

        // Search filter
        if (!empty($filters['search'])) {
            $term = trim($filters['search']);
            $isExactFamily = DB::table('families')->where('family_code', $term)->exists();
            if ($isExactFamily) {
                $ineligibleBase->where('f.family_code', $term);
                $notifBase->where('f.family_code', $term);
            } else {
                $searchFilter = function ($sq) use ($term) {
                    $sq->where('i.invoice_number', 'like', "%{$term}%")
                       ->orWhere('f.family_code', 'like', "%{$term}%")
                       ->orWhere('f.guardian_name', 'like', "%{$term}%")
                       ->orWhere('f.whatsapp', 'like', "%{$term}%")
                       ->orWhere('f.email', 'like', "%{$term}%")
                       ->orWhereExists(function ($stSub) use ($term) {
                           $stSub->selectRaw('1')
                               ->from('invoice_items as st_ii')
                               ->join('students as st_s', 'st_s.id', '=', 'st_ii.student_id')
                               ->whereColumn('st_ii.invoice_id', 'i.id')
                               ->where(function ($stQuery) use ($term) {
                                   $stQuery->where('st_s.name', 'like', "%{$term}%")
                                           ->orWhere('st_s.gr_number', 'like', "%{$term}%");
                               });
                       });
                };
                $ineligibleBase->where($searchFilter);
                $notifBase->where($searchFilter);
            }
        }

        // Deduplicate family IDs
        $ineligibleFamilyIds = (clone $ineligibleBase)->distinct()->pluck('i.family_id')->all();
        $notifFamilyIds = (clone $notifBase)->distinct()->pluck('i.family_id')->all();
        $allFamilyIds = array_values(array_unique(array_merge($notifFamilyIds, $ineligibleFamilyIds)));
        rsort($allFamilyIds);

        $totalFamilies = count($allFamilyIds);
        $currentPage = \Illuminate\Pagination\Paginator::resolveCurrentPage() ?: 1;
        $pagedFamilyIds = array_slice($allFamilyIds, ($currentPage - 1) * $perPage, $perPage);

        if (empty($pagedFamilyIds)) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], $totalFamilies, $perPage, $currentPage, [
                'path' => \Illuminate\Pagination\Paginator::resolveCurrentPath(),
                'query' => app()->bound('request') ? request()->query() : [],
            ]);
        }

        $families = DB::table('families')->whereIn('id', $pagedFamilyIds)->get()->keyBy('id');
        $invoices = DB::table('invoices as i')
            ->join('billing_runs as b', 'b.id', '=', 'i.billing_run_id')
            ->where('b.period_start', 'like', $period . '%')
            ->whereIn('i.family_id', $pagedFamilyIds)
            ->select('i.*', 'b.period_start as billing_period')
            ->get()
            ->groupBy('family_id');

        $notifs = DB::table('notifications as n')
            ->join('invoices as i', 'i.id', '=', 'n.invoice_id')
            ->join('billing_runs as b', 'b.id', '=', 'i.billing_run_id')
            ->where('b.period_start', 'like', $period . '%')
            ->whereIn('n.kind', ['monthly', 'paid'])
            ->whereIn('n.status', ['failed', 'blocked'])
            ->whereIn('i.family_id', $pagedFamilyIds)
            ->select('n.*', 'i.family_id')
            ->get()
            ->groupBy('family_id');

        $allInvoiceIds = $invoices->flatten(1)->pluck('id')->all();
        $studentsByInvoice = DB::table('invoice_items as ii')
            ->join('students as s', 's.id', '=', 'ii.student_id')
            ->leftJoin('enrollments as e', 'e.id', '=', 'ii.enrollment_id')
            ->whereIn('ii.invoice_id', $allInvoiceIds)
            ->select('ii.invoice_id', 's.id as student_id', 's.name', 's.gr_number', 'e.grade_label')
            ->distinct()
            ->get()
            ->groupBy('invoice_id');

        // Registration details for clean father name lookup
        $regDetails = DB::table('students as s')
            ->leftJoin('student_registration_details as sd', 'sd.student_id', '=', 's.id')
            ->whereIn('s.family_id', $pagedFamilyIds)
            ->whereNotNull('sd.father_name')
            ->select('s.family_id', 'sd.father_name')
            ->get()
            ->keyBy('family_id');

        $cases = collect();
        foreach ($pagedFamilyIds as $famId) {
            $fam = $families->get($famId);
            if (!$fam) continue;

            $famInvoices = $invoices->get($famId, collect());
            $primaryInv = $famInvoices->first();
            $famNotifs = $notifs->get($famId, collect());
            $latestNotif = $famNotifs->sortByDesc('id')->first();

            $stList = collect();
            foreach ($famInvoices as $inv) {
                foreach ($studentsByInvoice->get($inv->id, collect()) as $st) {
                    $stList->push($st);
                }
            }
            $uniqueStudents = $stList->unique('student_id')->values();
            $primaryStudent = $uniqueStudents->first();

            $fatherName = $regDetails->get($famId)?->father_name;
            $isFormula = !empty($fam->guardian_name) && str_starts_with(trim($fam->guardian_name), '=');
            $cleanGuardianName = $isFormula ? ($fatherName ?: 'Not provided') : ($fam->guardian_name ?: ($fatherName ?: 'Not provided'));

            $problemTags = [];
            $problemDescriptions = [];

            if ($latestNotif) {
                if ($latestNotif->status === 'failed') {
                    $problemTags[] = 'Delivery Failed';
                    $problemDescriptions[] = 'Webhook Delivery Failed: ' . ($latestNotif->error ?: 'Unknown error');
                } elseif ($latestNotif->status === 'blocked') {
                    $problemTags[] = 'Delivery Blocked';
                    $problemDescriptions[] = 'Webhook Blocked: ' . ($latestNotif->error ?: 'Unverified contact or missing consent');
                }
            }

            $hasPhone = !empty($fam->whatsapp) && trim($fam->whatsapp) !== '';
            $isPhoneValidFormat = $hasPhone && preg_match('/^\+[1-9][0-9]{6,14}$/', trim($fam->whatsapp));

            if (!$hasPhone) {
                $problemTags[] = 'No Phone';
                $problemDescriptions[] = 'Missing WhatsApp contact number';
            } elseif (!$isPhoneValidFormat) {
                $problemTags[] = 'Invalid Phone';
                $problemDescriptions[] = 'WhatsApp number not in E.164 format (e.g. +923001234567)';
            }

            if (!$fam->contact_verified) {
                $problemTags[] = 'Unverified';
                $problemDescriptions[] = 'Contact number has not been verified';
            }

            if (!$fam->messaging_opt_in) {
                $problemTags[] = 'No Consent';
                $problemDescriptions[] = 'Parent has not provided messaging permission (Opt-in required)';
            }

            $caseObj = (object)[
                'family_id' => $famId,
                'family_code' => $fam->family_code ?: ('Family #' . $famId),
                'guardian_name' => $cleanGuardianName,
                'raw_guardian_name' => $fam->guardian_name,
                'father_name' => $fatherName,
                'whatsapp' => $fam->whatsapp,
                'email' => $fam->email,
                'contact_verified' => (bool)$fam->contact_verified,
                'whatsapp_verified' => (bool)$fam->whatsapp_verified,
                'email_verified' => (bool)$fam->email_verified,
                'messaging_opt_in' => (bool)$fam->messaging_opt_in,
                'billing_period' => $period,
                'billing_month_formatted' => \Carbon\Carbon::parse($period . '-01')->format('F Y'),
                'invoice_id' => $primaryInv?->id,
                'invoice_number' => $primaryInv?->invoice_number,
                'invoice_numbers' => $famInvoices->pluck('invoice_number')->all(),
                'students' => $uniqueStudents,
                'primary_student_id' => $primaryStudent?->student_id,
                'notification_id' => $latestNotif?->id,
                'notification_status' => $latestNotif?->status,
                'notification_error' => $latestNotif?->error,
                'problem_tags' => $problemTags,
                'problem_descriptions' => $problemDescriptions,
                'exact_problem' => implode(' · ', $problemDescriptions),
                'contact_modal_data' => [
                    'family_id' => $fam->id,
                    'family_code' => $fam->family_code ?: ('Family #' . $fam->id),
                    'guardian_name' => ($isFormula ? ($fatherName ?: '') : ($fam->guardian_name ?: '')),
                    'whatsapp' => $fam->whatsapp ?: '',
                    'contact_verified' => (bool)$fam->contact_verified,
                    'messaging_opt_in' => (bool)$fam->messaging_opt_in,
                ],
            ];

            $cases->push($caseObj);
        }

        return new \Illuminate\Pagination\LengthAwarePaginator($cases, $totalFamilies, $perPage, $currentPage, [
            'path' => \Illuminate\Pagination\Paginator::resolveCurrentPath(),
            'query' => app()->bound('request') ? request()->query() : [],
        ]);
    }

    /**
     * Get paginated complete financial history across all time (Section 5.E).
     * Includes imported MySkool opening balances, previous invoices, superseded challans, and paid records.
     */
    public function getFinancialHistory(array $filters, object $user, int $perPage = 25): LengthAwarePaginator
    {
        $q = DB::table('invoices as i')
            ->leftJoin('billing_runs as b', 'b.id', '=', 'i.billing_run_id')
            ->leftJoin('families as f', 'f.id', '=', 'i.family_id')
            ->leftJoin('departments as d', 'd.id', '=', 'i.department_id')
            ->leftJoin('campuses as c', 'c.id', '=', 'i.campus_id');

        if (!in_array($user->role, ['super_admin', 'finance_admin'], true)) {
            $q->where('i.campus_id', $user->campus_id);
            if (!empty($user->department_id)) {
                $q->where('i.department_id', $user->department_id);
            }
        }

        if (!empty($filters['campus_id'])) {
            $q->where('i.campus_id', (int)$filters['campus_id']);
        }
        if (!empty($filters['department_id'])) {
            $q->where('i.department_id', (int)$filters['department_id']);
        }
        if (!empty($filters['period'])) {
            $q->where('b.period_start', 'like', $filters['period'] . '%');
        }
        if (!empty($filters['payment_status']) && $filters['payment_status'] !== 'all') {
            if ($filters['payment_status'] === 'paid') {
                $q->where('i.status', 'paid');
            } elseif ($filters['payment_status'] === 'unpaid') {
                $q->where('i.status', '!=', 'paid')->where('i.paid_minor', 0);
            } elseif ($filters['payment_status'] === 'partial') {
                $q->where('i.status', '!=', 'paid')->where('i.paid_minor', '>', 0);
            }
        }

        if (!empty($filters['search'])) {
            $s = trim($filters['search']);
            $q->where(function ($sq) use ($s) {
                $sq->where('i.invoice_number', 'like', "%{$s}%")
                    ->orWhere('f.family_code', 'like', "%{$s}%")
                    ->orWhere('f.guardian_name', 'like', "%{$s}%")
                    ->orWhere('f.whatsapp', 'like', "%{$s}%")
                    ->orWhereExists(function ($stSub) use ($s) {
                        $stSub->select(DB::raw(1))
                            ->from('invoice_items as st_ii')
                            ->join('students as st_s', 'st_s.id', '=', 'st_ii.student_id')
                            ->whereColumn('st_ii.invoice_id', 'i.id')
                            ->where(function ($stQuery) use ($s) {
                                $stQuery->where('st_s.name', 'like', "%{$s}%")
                                    ->orWhere('st_s.gr_number', 'like', "%{$s}%");
                            });
                    });
            });
        }

        $q->select(
            'i.*',
            'b.period_start as billing_period',
            'b.frequency',
            'f.family_code',
            'f.guardian_name',
            'f.whatsapp',
            'd.name as department_name',
            'c.name as campus_name'
        )->orderByDesc('i.id');

        return $q->paginate($perPage)->withQueryString();
    }

    /**
     * Reliably detect background queue worker status.
     * Returns whether a worker is running and number of pending/reserved jobs.
     */
    public function getQueueWorkerStatus(): array
    {
        $pendingJobs = DB::table('jobs')->where('queue', 'notifications')->count();
        $reservedJobs = DB::table('jobs')->where('queue', 'notifications')->whereNotNull('reserved_at')->count();

        $isRunning = false;

        // 1. If a job is currently reserved in the jobs table, an active worker is executing it
        if ($reservedJobs > 0) {
            $isRunning = true;
        } else {
            // 2. Check OS process list for queue:work
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $output = @shell_exec('wmic process where "name=\'php.exe\'" get commandline 2>nul');
                if ($output && stripos($output, 'queue:work') !== false) {
                    $isRunning = true;
                }
            } else {
                $output = @shell_exec('pgrep -f "artisan queue:work" 2>/dev/null');
                if (!empty(trim((string)$output))) {
                    $isRunning = true;
                }
            }
        }

        return [
            'is_running' => $isRunning,
            'pending_jobs' => $pendingJobs,
            'reserved_jobs' => $reservedJobs,
            'status' => $isRunning ? 'running' : ($pendingJobs > 0 ? 'stopped' : 'idle'),
            'message' => $isRunning
                ? 'Queue worker is active and processing notifications in background.'
                : ($pendingJobs > 0
                    ? "Queue worker is offline. {$pendingJobs} batch dispatch job(s) are buffered in queue."
                    : 'Queue worker is idle. No pending jobs in queue.'),
        ];
    }

    /**
     * Retrieve enriched notification batches with live chunk progress and error details.
     */
    public function getEnrichedBatches(?string $period = null, int $limit = 10): \Illuminate\Support\Collection
    {
        $query = DB::table('notification_batches as b')
            ->leftJoin('users as u', 'u.id', '=', 'b.approved_by')
            ->select('b.*', 'u.name as approver_name');

        if (!empty($period)) {
            $query->where('b.billing_period', 'like', "{$period}%");
        }

        $batches = $query->orderByDesc('b.id')->limit($limit)->get();

        if ($batches->isEmpty() && !empty($period)) {
            // Fallback to recent batches if none exist for period
            $batches = DB::table('notification_batches as b')
                ->leftJoin('users as u', 'u.id', '=', 'b.approved_by')
                ->select('b.*', 'u.name as approver_name')
                ->orderByDesc('b.id')
                ->limit($limit)
                ->get();
        }

        foreach ($batches as $b) {
            $counts = DB::table('notifications')
                ->where('batch_id', $b->batch_id)
                ->select(
                    DB::raw("COUNT(CASE WHEN status = 'queued' THEN 1 END) as queued_count"),
                    DB::raw("COUNT(CASE WHEN status IN ('submitted', 'delivered', 'read') THEN 1 END) as submitted_count"),
                    DB::raw("COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_count"),
                    DB::raw("COUNT(DISTINCT CASE WHEN status = 'failed' THEN chunk_index END) as failed_chunks_count"),
                    DB::raw("MAX(last_attempt_at) as last_attempt_at"),
                    DB::raw("MAX(error) as sample_error")
                )
                ->first();

            $b->queued_recipients = (int)($counts->queued_count ?? 0);
            $b->submitted_recipients = (int)($counts->submitted_count ?? 0);
            $b->failed_recipients = (int)($counts->failed_count ?? 0);
            $b->chunks_failed = (int)($counts->failed_chunks_count ?? 0);
            $b->chunks_sent = (int)$b->chunks_sent;
            $b->chunks_total = (int)$b->chunks_total;
            $b->chunks_pending = max(0, $b->chunks_total - $b->chunks_sent - $b->chunks_failed);
            $b->last_attempt = $counts->last_attempt_at ?: $b->submitted_at;
            $b->display_error = $b->error ?: $counts->sample_error;
        }

        return $batches;
    }
}

