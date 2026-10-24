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
            'n.updated_at as notification_updated_at',
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
}
