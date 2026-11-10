<?php

namespace App\Services;

use App\Services\Money;
use App\Services\GuardianNameResolver;
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
            ->leftJoin('notification_batches as nb', 'nb.batch_id', '=', 'n.batch_id')
            ->leftJoin('invoices as rep_i', 'rep_i.id', '=', 'n.superseded_by_invoice_id');

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

        // Sent reporting follows the campaign, including older paid invoices and merge history.
        // Other queue views retain their existing invoice-period filtering.
        if (!empty($filters['period'])) {
            $period = $filters['period'] . '%';
            $periodBasis = $filters['period_basis'] ?? (($filters['submission_status'] ?? null) === 'submitted' ? 'campaign' : 'invoice');
            if ($periodBasis === 'campaign') {
                $q->where(function ($campaign) use ($period) {
                    $campaign->where('nb.billing_period', 'like', $period)
                        ->orWhere(function ($legacy) use ($period) {
                            $legacy->where(function ($missing) {
                                $missing->whereNull('nb.billing_period')->orWhere('nb.billing_period', '');
                            })->where('b.period_start', 'like', $period);
                        });
                });
            } else {
                $q->where('b.period_start', 'like', $period);
            }
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
            $grade = $filters['grade'];
            $q->whereExists(function ($sub) use ($grade) {
                $sub->select(DB::raw(1))
                    ->from('invoice_items as sub_ii')
                    ->leftJoin('enrollments as sub_en', 'sub_en.id', '=', 'sub_ii.enrollment_id')
                    ->whereColumn('sub_ii.invoice_id', 'n.invoice_id')
                    ->where(function ($gq) use ($grade) {
                        $gq->where('sub_en.grade_label', $grade)
                            ->orWhereExists(function ($eq) use ($grade) {
                                $eq->select(DB::raw(1))
                                    ->from('enrollments as active_en')
                                    ->whereColumn('active_en.student_id', 'sub_ii.student_id')
                                    ->where('active_en.status', 'active')
                                    ->where('active_en.grade_label', $grade);
                            });
                    });
            });
        }

        // Filter: Webhook Submission Status
        if (!empty($filters['submission_status']) && $filters['submission_status'] !== 'all') {
            switch ($filters['submission_status']) {
                case 'queued':
                    $q->where('n.status', 'queued')->where(function ($sq) {
                        $sq->where('n.is_superseded', false)->orWhereNull('n.is_superseded');
                    });
                    break;
                case 'submitted':
                    $q->whereIn('n.status', ['submitted', 'delivered', 'read'])->where(function ($sq) {
                        $sq->where('n.is_superseded', false)->orWhereNull('n.is_superseded');
                    });
                    break;
                case 'failed':
                    $q->where('n.status', 'failed')->where(function ($sq) {
                        $sq->where('n.is_superseded', false)->orWhereNull('n.is_superseded');
                    });
                    break;
                case 'blocked':
                    $q->where('n.status', 'blocked')->where(function ($sq) {
                        $sq->where('n.is_superseded', false)->orWhereNull('n.is_superseded');
                    });
                    break;
                case 'superseded':
                    $q->where(function ($sq) {
                        $sq->where('n.status', 'superseded')->orWhere('n.is_superseded', true);
                    });
                    break;
            }
        }

        // Filter: Actual WhatsApp Delivery Status
        if (!empty($filters['delivery_status']) && $filters['delivery_status'] !== 'all') {
            switch ($filters['delivery_status']) {
                case 'delivered':
                    $q->where('n.status', 'delivered')->where(function ($sq) {
                        $sq->where('n.is_superseded', false)->orWhereNull('n.is_superseded');
                    });
                    break;
                case 'read':
                    $q->where('n.status', 'read')->where(function ($sq) {
                        $sq->where('n.is_superseded', false)->orWhereNull('n.is_superseded');
                    });
                    break;
                case 'pending':
                    $q->whereIn('n.status', ['queued', 'processing', 'submitted'])->where(function ($sq) {
                        $sq->where('n.is_superseded', false)->orWhereNull('n.is_superseded');
                    });
                    break;
                case 'failed':
                    $q->where('n.status', 'failed')->where(function ($sq) {
                        $sq->where('n.is_superseded', false)->orWhereNull('n.is_superseded');
                    });
                    break;
                case 'superseded':
                    $q->where(function ($sq) {
                        $sq->where('n.status', 'superseded')->orWhere('n.is_superseded', true);
                    });
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
            'rep_i.invoice_number as superseded_by_invoice_number',
            'n.attempts',
            'n.last_attempt_at',
            'n.response_code',
            'n.submitted_at',
            'n.delivered_at',
            'n.payload',
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

        $queuePeriod = !empty($filters['period'])
            ? (string) $filters['period']
            : ($items->first()?->billing_period ? substr((string) $items->first()->billing_period, 0, 7) : date('Y-m'));
        $consolidatedSummaries = !empty($familyIds)
            ? app(ConsolidatedInvoiceBuilder::class)->summarizeFamiliesOutstanding($familyIds, $queuePeriod, $user)
            : [];

        // 4. Enrich each row
        $enriched = $items->map(function ($row) use ($studentsByInvoice, $siblingsByFamily, $previousDuesMap, $consolidatedSummaries, $user) {
            $stList = $studentsByInvoice->get($row->invoice_id, collect());
            $sibList = $siblingsByFamily->get($row->family_id, collect());

            $key = "{$row->family_id}_{$row->currency}";
            $prevDuesMinor = (int)($previousDuesMap->get($key, 0));

            $remainingBalanceMinor = (int)max(0, $row->total_minor - $row->paid_minor);
            $laterPeriodMinor = 0;
            $totalPayableMinor = $remainingBalanceMinor + $prevDuesMinor;

            $famSummary = $row->family_id ? ($consolidatedSummaries[(int) $row->family_id] ?? null) : null;
            $isMonthly = (($row->kind ?? 'monthly') !== 'paid');
            $hasDataIntegrityIssue = false;
            $isPayableVerified = true;
            $dataIntegrityMessage = null;

            if ($isMonthly && $famSummary !== null) {
                $hasDataIntegrityIssue = (bool) ($famSummary['has_data_integrity_issue'] ?? false);
                $isPayableVerified = (bool) ($famSummary['is_payable_verified'] ?? true);
                $dataIntegrityMessage = $famSummary['data_integrity_message'] ?? null;

                if ($hasDataIntegrityIssue || !$isPayableVerified) {
                    $prevDuesMinor = 0;
                    $remainingBalanceMinor = 0;
                    $laterPeriodMinor = 0;
                    $totalPayableMinor = 0;
                } elseif (isset($famSummary['currencies_map'][$row->currency])) {
                    $cSum = $famSummary['currencies_map'][$row->currency];
                    $prevDuesMinor = (int) $cSum['previous_dues_minor'];
                    $remainingBalanceMinor = (int) $cSum['selected_month_minor'];
                    $laterPeriodMinor = (int) $cSum['later_period_minor'];
                    $totalPayableMinor = (int) $cSum['total_payable_minor'];
                }
            }

            $dispatchedPayload = !empty($row->payload) && is_string($row->payload)
                ? json_decode($row->payload, true)
                : null;
            $dispatchedEntry = $dispatchedPayload;
            if (is_array($dispatchedPayload) && !empty($dispatchedPayload['invoices']) && is_array($dispatchedPayload['invoices'])) {
                foreach ($dispatchedPayload['invoices'] as $candInv) {
                    if ((int) ($candInv['invoice_id'] ?? 0) === (int) $row->invoice_id) {
                        $dispatchedEntry = $candInv;
                        break;
                    }
                }
            }
            $dispatchedCalculatedAt = $dispatchedEntry['statement_metadata']['calculated_at']
                ?? ($dispatchedEntry['timestamp'] ?? ($dispatchedPayload['timestamp'] ?? null));
            $dispatchedTotalsByCurrency = $dispatchedEntry['statement_metadata']['dispatched_totals_by_currency']
                ?? ($dispatchedEntry['invoice']['currencies_breakdown'] ?? null);

            // Generate scope-aware PDF URLs
            $pdfService = app(\App\Services\PdfAccessService::class);
            $invoicePdfUrl = $pdfService->getInvoicePdfUrl((int)$row->invoice_id, $user);
            $consolidatedChallanUrl = $row->family_id ? $pdfService->getFamilyChallanPdfUrl((int)$row->family_id, null, $user) : null;
            $isRowSuperseded = (bool)($row->is_superseded || $row->notification_status === 'superseded');
            $supersededByInvoiceUrl = !empty($row->superseded_by_invoice_id)
                ? $pdfService->getInvoicePdfUrl((int)$row->superseded_by_invoice_id, $user)
                : null;

            // Computed Webhook Submission Status
            $webhookStatus = $isRowSuperseded
                ? 'superseded'
                : match ($row->notification_status) {
                    'queued' => 'queued',
                    'processing' => 'processing',
                    'blocked' => 'blocked',
                    'failed' => ($row->submitted_at ? 'submitted' : 'failed'),
                    'submitted', 'delivered', 'read' => 'submitted',
                    default => $row->notification_status,
                };

            // Computed WhatsApp Delivery Status
            $deliveryStatus = $isRowSuperseded
                ? 'superseded'
                : match ($row->notification_status) {
                    'queued' => 'pending',
                    'processing' => 'pending',
                    'submitted' => 'pending',
                    'delivered' => 'delivered',
                    'read' => 'read',
                    'failed' => ($row->submitted_at ? 'failed' : 'n/a'),
                    'blocked' => 'blocked',
                    default => 'pending',
                };

            $row->guardian_name = GuardianNameResolver::isValid($row->guardian_name ?? null)
                ? $row->guardian_name
                : GuardianNameResolver::forFamily($row->family_id, $stList->pluck('student_id')->all());
            $row->students_list = $stList;
            $row->siblings_list = $sibList;
            $row->previous_dues_minor = $prevDuesMinor;
            $row->remaining_balance_minor = $remainingBalanceMinor;
            $row->later_period_minor = $laterPeriodMinor;
            $row->total_payable_minor = $totalPayableMinor;
            $row->formatted_previous_dues = Money::format($prevDuesMinor);
            $row->formatted_balance = Money::format($remainingBalanceMinor);
            $row->formatted_later_period = Money::format($laterPeriodMinor);
            $row->formatted_total_payable = Money::format($totalPayableMinor);
            $row->is_multi_currency = (bool) ($famSummary['is_multi_currency'] ?? false);
            $row->currencies_map = $famSummary['currencies_map'] ?? [];
            $row->has_data_integrity_issue = $hasDataIntegrityIssue;
            $row->is_payable_verified = $isPayableVerified;
            $row->data_integrity_message = $dataIntegrityMessage;
            $row->dispatched_calculated_at = $dispatchedCalculatedAt;
            $row->dispatched_totals_by_currency = $dispatchedTotalsByCurrency;
            $row->invoice_pdf_url = $invoicePdfUrl;
            $row->consolidated_challan_url = $consolidatedChallanUrl;
            $row->superseded_by_invoice_url = $supersededByInvoiceUrl;
            $row->is_superseded = $isRowSuperseded;
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
            SUM(CASE WHEN n.status = 'queued' AND (n.is_superseded = 0 OR n.is_superseded IS NULL) THEN 1 ELSE 0 END) as queued,
            SUM(CASE WHEN n.status IN ('submitted', 'delivered', 'read') AND (n.is_superseded = 0 OR n.is_superseded IS NULL) THEN 1 ELSE 0 END) as submitted,
            SUM(CASE WHEN n.status = 'failed' AND (n.is_superseded = 0 OR n.is_superseded IS NULL) THEN 1 ELSE 0 END) as failed,
            SUM(CASE WHEN n.status = 'delivered' AND (n.is_superseded = 0 OR n.is_superseded IS NULL) THEN 1 ELSE 0 END) as delivered,
            SUM(CASE WHEN n.status = 'read' AND (n.is_superseded = 0 OR n.is_superseded IS NULL) THEN 1 ELSE 0 END) as read_count,
            SUM(CASE WHEN n.status = 'blocked' AND (n.is_superseded = 0 OR n.is_superseded IS NULL) THEN 1 ELSE 0 END) as blocked,
            SUM(CASE WHEN n.status = 'superseded' OR n.is_superseded = 1 THEN 1 ELSE 0 END) as superseded
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

        $notifs = DB::table('notifications')->where('batch_id', $batchId)->get(['status', 'is_superseded']);
        $allSuperseded = $notifs->isNotEmpty() && $notifs->every(fn($n) => $n->status === 'superseded' || (bool)$n->is_superseded);
        $batch->is_all_superseded = $allSuperseded;
        $batch->display_status = $allSuperseded ? 'superseded' : $batch->status;

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
                'processing' => 'processing',
                        'blocked' => 'blocked',
                        'failed' => ($row->submitted_at ? 'submitted' : 'failed'),
                        'submitted', 'delivered', 'read' => 'submitted',
                        default => $row->notification_status,
                    };

                    $deliveryStatus = match ($row->notification_status) {
                        'queued' => 'pending',
                'processing' => 'pending',
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
                        GuardianNameResolver::isValid($row->guardian_name) ? $row->guardian_name : GuardianNameResolver::forFamily($row->family_id),
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
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Default to the current or most recent past generated month; future months remain selectable.
     */
    public function getLatestBillingMonth(): string
    {
        $currentMonth = now()->format('Y-m');
        foreach ($this->getAvailableBillingMonths() as $month) {
            if ($month <= $currentMonth) {
                return $month;
            }
        }
        return $currentMonth;
    }

    /**
     * Read-only submission reporting using the same campaign/status/filter rules as the Sent table.
     * Keep renamed notification kinds: webhook_type survives family merges.
     */
    public function getCampaignSubmissionStats(string $period, object $user, array $filters = []): array
    {
        $filters = array_merge($filters, ['period' => $period, 'submission_status' => 'submitted', 'period_basis' => 'campaign']);
        $query = $this->buildBaseQueueQuery($filters, $user);
        $counts = (clone $query)->selectRaw("
            COUNT(n.id) as submitted,
            SUM(CASE WHEN n.webhook_type = 'paid' OR n.kind = 'paid' THEN 1 ELSE 0 END) as paid_notifications,
            SUM(CASE WHEN n.status IN ('delivered', 'read') THEN 1 ELSE 0 END) as verified_deliveries
        ")->first();
        $invoicePeriodCount = $this->buildBaseQueueQuery(array_merge($filters, ['period_basis' => 'invoice']), $user)->count();

        return [
            'submitted' => (int) ($counts->submitted ?? 0),
            'paid_notifications' => (int) ($counts->paid_notifications ?? 0),
            'monthly_submissions' => (int) ($counts->submitted ?? 0) - (int) ($counts->paid_notifications ?? 0),
            'verified_deliveries' => (int) ($counts->verified_deliveries ?? 0),
            'invoice_period_submitted' => $invoicePeriodCount,
        ];
    }

    /**
     * Compute clean dashboard KPI statistics for the selected billing month (Section 8).
     */
    public function getDashboardKpiStats(string $period, object $user, array $filters = []): array
    {
        $dt = \Carbon\Carbon::createFromFormat('!Y-m', $period);
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
                    ->whereIn('n.status', ['submitted', 'delivered', 'read', 'queued', 'processing']);
            });

        // Ineligible query (missing verification / opt-in)
        $ineligibleQuery = (clone $readyQuery)
            ->where(function ($sub) {
                $sub->where('f.contact_verified', 0)
                    ->orWhere('f.messaging_opt_in', 0)
                    ->orWhereNull('f.whatsapp')
                    ->orWhere('f.whatsapp', '');
            });

        $initialReadyQuery = $this->buildInitialReadyQuery(array_merge($filters, ['period' => $period]), $user);
        $actionableReadyCount = (clone $initialReadyQuery)->distinct()->count('i.family_id');
        $actionableInvoicesCount = (clone $initialReadyQuery)->count('i.id');
        $ineligibleCount = (clone $ineligibleQuery)->distinct()->count('i.family_id');

        // Paid Invoices (fully settled invoices for the selected period; includes prior unqueued for 2026-10, or all periods when in unified all-period mode)
        $paidKpiPeriod = (!empty($filters['all_period']) || ($filters['period'] ?? '') === 'all') ? 'all' : $period;
        $paidInvoicesCount = $this->paidWorkflowBaseQuery(array_merge($filters, ['period' => $paidKpiPeriod]), $user)->count('i.id');

        // Processing (currently queued in active batches)
        $procQuery = DB::table('notifications as n')
            ->join('invoices as i', 'i.id', '=', 'n.invoice_id')
            ->join('billing_runs as b', 'b.id', '=', 'i.billing_run_id')
            ->where('b.period_start', 'like', $period . '%')
            ->whereIn('n.kind', ['monthly', 'paid'])
            ->whereIn('n.status', ['queued', 'processing'])
            ->where(function ($sq) {
                $sq->where('n.is_superseded', false)->orWhereNull('n.is_superseded');
            });

        if (!in_array($user->role, ['super_admin', 'finance_admin'], true)) {
            $procQuery->where('i.campus_id', $user->campus_id);
            if (!empty($user->department_id)) {
                $procQuery->where('i.department_id', $user->department_id);
            }
        }
        $processingCount = $procQuery->count();

        // Campaign submissions are webhook acceptance, not verified WhatsApp delivery.
        $submissionStats = $this->getCampaignSubmissionStats($period, $user, $filters);
        $submittedCount = $submissionStats['submitted'];

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

        $coverageScope = (clone $baseInv)->distinct()->pluck('i.family_id')->all();
        $coverageStats = app(InvoiceSubmissionCoverageService::class)->summarize($period, $user, $coverageScope);
        $coverageStats['no_recorded_submission_families'] = count($coverageScope) - $coverageStats['covered_families'];

        return [
            'coverage' => $coverageStats,
            'selected_period' => $period,
            'selected_month' => $monthName,
            'awaiting_review' => $actionableReadyCount,
            'awaiting_review_invoices' => $actionableInvoicesCount,
            'ready_families' => $actionableReadyCount,
            'ready_invoices' => $actionableInvoicesCount,
            'paid_invoices' => $paidInvoicesCount,
            'processing' => $processingCount,
            'submitted' => $submittedCount,
            'monthly_submissions' => $submissionStats['monthly_submissions'],
            'paid_notifications' => $submissionStats['paid_notifications'],
            'verified_deliveries' => $submissionStats['verified_deliveries'],
            'invoice_period_submitted' => $submissionStats['invoice_period_submitted'],
            'attention' => $attentionCount,
            'contact_ineligible' => $ineligibleCount,
            'has_active_search' => $hasActiveSearch,
            'scoped_eligible' => $scopedEligible,
            'scoped_invoices' => $scopedInvoices,
        ];
    }

    /**
     * Use the exact initial batch selection for both Ready reporting and its visible table.
     */
    public function buildInitialReadyQuery(array $filters, object $user): Builder
    {
        $filters = array_merge($filters, ['paid_only' => false, 'webhook_type' => 'monthly']);
        $batchService = app(BatchDeliveryService::class);
        $snapshot = $batchService->resolveFilteredSelection($filters, $user, ['whatsapp']);
        $familyIds = array_column($snapshot['eligible_recipients'], 'family_id');
        return $batchService->buildFilterQuery($filters, $user)
            ->leftJoin('campuses as c', 'c.id', '=', 'i.campus_id')
            ->whereIn('i.family_id', $familyIds);
    }

    public function getReadyToSendChallans(array $filters, object $user, int $perPage = 25): LengthAwarePaginator
    {
        $period = !empty($filters['period']) ? $filters['period'] : $this->getLatestBillingMonth();

        $q = $this->buildInitialReadyQuery(array_merge($filters, ['period' => $period]), $user);

        // Base query for invoices matching eligibility and authorization
        $baseInvoices = (clone $q)->select('i.id as auth_invoice_id', 'i.family_id', 'i.created_at as auth_created_at');

        // Select the actual newest authoritative SCH per family strictly by (created_at DESC, id DESC)
        // Window function ensures auth_created_at and auth_invoice_id belong to the same genuine SCH
        $rankedQuery = DB::query()->fromSub($baseInvoices, 'cand_inv')
            ->select('cand_inv.family_id', 'cand_inv.auth_invoice_id', 'cand_inv.auth_created_at')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY cand_inv.family_id ORDER BY cand_inv.auth_created_at DESC, cand_inv.auth_invoice_id DESC) as rn');

        // Exactly one consolidated row per family, ordered by that authoritative SCH before pagination
        $authoritativeQuery = DB::query()->fromSub($rankedQuery, 'auth_inv')
            ->where('auth_inv.rn', 1)
            ->orderByDesc('auth_inv.auth_created_at')
            ->orderByDesc('auth_inv.auth_invoice_id');

        $totalFamilies = (clone $authoritativeQuery)->count();
        $currentPage = \Illuminate\Pagination\Paginator::resolveCurrentPage() ?: 1;

        $pagedFamilyIds = (clone $authoritativeQuery)
            ->forPage($currentPage, $perPage)
            ->pluck('family_id')
            ->all();

        if (empty($pagedFamilyIds)) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], $totalFamilies, $perPage, $currentPage, [
                'path' => \Illuminate\Pagination\Paginator::resolveCurrentPath(),
                'query' => request()->query(),
            ]);
        }

        // Fetch all invoices for these paged families in this period ordered by authoritative created_at DESC, id DESC
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
                'i.created_at as invoice_created_at',
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
            ->orderByDesc('i.created_at')
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

        // All active siblings for these families across other grades (scoped to user's authorized campus/department)
        $siblingsByFamily = DB::table('students as s')
            ->join('enrollments as e', 'e.student_id', '=', 's.id')
            ->whereIn('s.family_id', $pagedFamilyIds)
            ->where('s.status', 'active')
            ->where('e.status', 'active')
            ->when(!in_array($user->role, ['super_admin', 'finance_admin'], true), function ($sq) use ($user) {
                $sq->where('e.campus_id', $user->campus_id)
                    ->when(!empty($user->department_id), fn($dq) => $dq->where('e.department_id', $user->department_id));
            })
            ->select('s.family_id', 's.id as student_id', 's.name', 's.gr_number', 'e.grade_label')
            ->distinct()
            ->get()
            ->groupBy('family_id');

        // Canonical batched outstanding calculation across all applicable unpaid obligations (previous, selected-month, later-period)
        $consolidatedBuilder = app(\App\Services\ConsolidatedInvoiceBuilder::class);
        $outstandingSummaries = $consolidatedBuilder->summarizeFamiliesOutstanding($pagedFamilyIds, $period, $user);

        $familyRows = collect();
        foreach ($pagedFamilyIds as $famId) {
            $famInvoices = $invoicesByFamily->get($famId, collect());
            if ($famInvoices->isEmpty()) continue;

            $primaryInv = $famInvoices->first();
            $invIds = $famInvoices->pluck('invoice_id')->all();
            $invNumbers = $famInvoices->pluck('invoice_number')->all();
            $famSummary = $outstandingSummaries[$famId] ?? null;

            // Students across selected-month invoices and all applicable unpaid obligations in the family
            $famStudents = collect();
            foreach ($invIds as $iId) {
                $stList = $studentsByInvoice->get($iId, collect());
                foreach ($stList as $st) {
                    $famStudents->push($st);
                }
            }
            if (!empty($famSummary['students'])) {
                foreach ($famSummary['students'] as $st) {
                    $famStudents->push($st);
                }
            }
            $uniqueStudents = $famStudents->unique(fn($s) => $s->student_id ?? $s->gr_number)->values();
            $familySiblings = $siblingsByFamily->get($famId, collect());

            $currenciesMap = $famSummary['currencies_map'] ?? [];
            $currencyBreakdowns = $famSummary['currencies'] ?? [];
            $duesByMonth = $famSummary['dues_by_month'] ?? [];
            $laterDuesByMonth = $famSummary['later_dues_by_month'] ?? [];

            $hasDataIntegrityIssue = (bool)($famSummary['has_data_integrity_issue'] ?? empty($currenciesMap));
            $isPayableVerified = !$hasDataIntegrityIssue && !empty($currenciesMap);
            $dataIntegrityStatus = $famSummary['data_integrity_status'] ?? ($hasDataIntegrityIssue ? 'data_integrity_issue' : 'verified');
            $dataIntegrityIssues = $famSummary['data_integrity_issues'] ?? [];
            $dataIntegrityMessage = $famSummary['data_integrity_message'] ?? ($hasDataIntegrityIssue
                ? sprintf(
                    'Data Integrity Issue: Invoice %s header indicates an unpaid balance, but no matching valid unpaid invoice items were found. Flagged for staff review.',
                    $primaryInv->invoice_number
                )
                : null);

            $primaryCurrency = $primaryInv->currency;
            $primaryCurrBreakdown = $currenciesMap[$primaryCurrency] ?? ($currencyBreakdowns[0] ?? null);
            $effectiveCurrency = $primaryCurrBreakdown['currency'] ?? $primaryCurrency;

            $prevMinor = (int)($primaryCurrBreakdown['previous_dues_minor'] ?? 0);
            $selectedMonthMinor = (int)($primaryCurrBreakdown['selected_month_minor'] ?? 0);
            $laterMinor = (int)($primaryCurrBreakdown['later_period_minor'] ?? 0);
            $totalPayableMinor = (int)($primaryCurrBreakdown['total_payable_minor'] ?? ($prevMinor + $selectedMonthMinor + $laterMinor));

            $isUnverifiedWithoutItems = $hasDataIntegrityIssue && empty($currenciesMap);

            $pdfService = app(\App\Services\PdfAccessService::class);
            $signedChallanUrl = $pdfService->getFamilyChallanPdfUrl((int)$famId, $period, $user);
            $invoicePdfUrl = $pdfService->getInvoicePdfUrl((int)$primaryInv->invoice_id, $user);

            $rowObj = (object)[
                'family_id' => $famId,
                'family_code' => $primaryInv->family_code,
                'guardian_name' => GuardianNameResolver::forFamily($famId, $uniqueStudents),
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
                'selected_period' => $period,
                'due_date' => $primaryInv->due_date,
                'invoice_status' => $primaryInv->invoice_status,
                'campus_name' => $primaryInv->campus_name,
                'department_name' => $primaryInv->department_name,
                'currency' => $effectiveCurrency,
                'total_minor' => $isUnverifiedWithoutItems ? 0 : (int)$primaryInv->total_minor,
                'paid_minor' => (int)$primaryInv->paid_minor,
                'header_total_minor' => (int)$primaryInv->total_minor,
                'header_paid_minor' => (int)$primaryInv->paid_minor,
                'has_data_integrity_issue' => $hasDataIntegrityIssue,
                'is_payable_verified' => $isPayableVerified,
                'data_integrity_status' => $dataIntegrityStatus,
                'data_integrity_error' => $dataIntegrityMessage,
                'data_integrity_message' => $dataIntegrityMessage,
                'data_integrity_issues' => $dataIntegrityIssues,
                'is_multi_currency' => count($currencyBreakdowns) > 1,
                'currencies' => $currencyBreakdowns,
                'currencies_map' => $currenciesMap,
                'previous_outstanding_minor' => $prevMinor,
                'formatted_previous_outstanding' => $primaryCurrBreakdown['formatted_previous_outstanding'] ?? ($effectiveCurrency . ' ' . Money::format($prevMinor)),
                'prev_dues_minor' => $prevMinor,
                'formatted_prev_dues' => $primaryCurrBreakdown['formatted_previous_dues'] ?? ($effectiveCurrency . ' ' . Money::format($prevMinor)),
                'selected_month_minor' => $selectedMonthMinor,
                'formatted_selected_month' => $isUnverifiedWithoutItems
                    ? 'UNVERIFIED (Data Integrity Review Required)'
                    : ($primaryCurrBreakdown['formatted_selected_month'] ?? ($effectiveCurrency . ' ' . Money::format($selectedMonthMinor))),
                'current_fee_minor' => $selectedMonthMinor,
                'formatted_current_fee' => $isUnverifiedWithoutItems
                    ? 'UNVERIFIED (Data Integrity Review Required)'
                    : ($primaryCurrBreakdown['formatted_current_fee'] ?? ($effectiveCurrency . ' ' . Money::format($selectedMonthMinor))),
                'remaining_balance_minor' => $selectedMonthMinor,
                'formatted_remaining_balance' => $isUnverifiedWithoutItems
                    ? 'UNVERIFIED (Data Integrity Review Required)'
                    : ($primaryCurrBreakdown['formatted_selected_month'] ?? ($effectiveCurrency . ' ' . Money::format($selectedMonthMinor))),
                'later_period_minor' => $laterMinor,
                'formatted_later_period' => $primaryCurrBreakdown['formatted_later_period'] ?? ($effectiveCurrency . ' ' . Money::format($laterMinor)),
                'total_payable_minor' => $totalPayableMinor,
                'total_outstanding_payable_minor' => $totalPayableMinor,
                'formatted_total_payable' => $isUnverifiedWithoutItems
                    ? 'UNVERIFIED — Staff Review Required'
                    : ($primaryCurrBreakdown['formatted_total_payable'] ?? ($effectiveCurrency . ' ' . Money::format($totalPayableMinor))),
                'formatted_total_outstanding_payable' => $isUnverifiedWithoutItems
                    ? 'UNVERIFIED — Staff Review Required'
                    : ($primaryCurrBreakdown['formatted_total_outstanding_payable'] ?? ($effectiveCurrency . ' ' . Money::format($totalPayableMinor))),
                'invoice_students' => $uniqueStudents,
                'family_siblings' => $familySiblings,
                'dues_by_month' => $duesByMonth,
                'later_dues_by_month' => $laterDuesByMonth,
                'consolidated_challan_url' => $signedChallanUrl,
                'signed_challan_url' => $signedChallanUrl,
                'invoice_pdf_url' => $invoicePdfUrl,
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

        $q = $this->paidWorkflowBaseQuery($filters, $user);
        [$stageSql, $stageBindings] = $this->paidWorkflowStageSql();
        $this->filterPaidWorkflow($q, $filters, $stageSql, $stageBindings);

        $paginator = $q->select(
            'i.id',
            'paid_n.id as authoritative_notification_id',
            'paid_n.status as notification_status',
            'paid_n.error as notification_error',
            'paid_b.status as paid_batch_status',
            'paid_b.error as paid_batch_error',
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
        ->selectRaw("({$stageSql}) as paid_workflow_stage", $stageBindings)
        ->orderByDesc('i.created_at')
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
                'p.amount_minor as payment_amount_minor',
                'pa.amount_minor as allocation_amount_minor'
            )
            ->orderByDesc('p.created_at')
            ->orderByDesc('p.id')
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
                    'amount_minor' => (int)$r->payment_amount_minor,
                    'formatted_amount' => Money::format((int)$r->payment_amount_minor),
                    'allocated_amount_minor' => 0,
                    'formatted_allocated_amount' => '',
                    'receipt_pdf_url' => app(\App\Services\PdfAccessService::class)->getReceiptPdfUrl((int)$pId),
                ];
            }
            $receiptsByInvoice[$invId][$pId]['allocated_amount_minor'] += (int)$r->allocation_amount_minor;
        }

        foreach ($receiptsByInvoice as $invId => &$pMap) {
            foreach ($pMap as &$pItem) {
                $pItem['formatted_allocated_amount'] = Money::format($pItem['allocated_amount_minor']);
            }
        }
        unset($pMap, $pItem);

        $notifs = DB::table('notifications')
            ->whereIn('id', $paginator->pluck('authoritative_notification_id')->filter()->all())
            ->get()->keyBy('id');

        $enriched = $paginator->getCollection()->map(function ($row) use ($studentsRaw, $receiptsByInvoice, $notifs, $user) {
            $rowStudents = $studentsRaw->get($row->id, collect());
            $rowReceipts = array_values($receiptsByInvoice[$row->id] ?? []);
            $rowNotif = $notifs->get($row->authoritative_notification_id);
            $isContactEligible = (bool)$row->contact_verified && (bool)$row->messaging_opt_in && !empty($row->whatsapp);
            $isAlreadySentOrQueued = $row->paid_workflow_stage !== 'unqueued';
            $isDeliveryEligible = PaidInvoiceEligibility::eligible((int)$row->id) && $isContactEligible && !$isAlreadySentOrQueued && in_array($row->invoice_status, ['paid'], true);
            $row->is_delivery_eligible = $isDeliveryEligible;

            // Authoritative paid amount specifically allocated to this SCH invoice.
            // If payment allocations exist, sum their amounts for this invoice.
            // Fall back to recorded paid_minor for direct settlements without allocation rows.
            $allocatedPaidMinor = !empty($rowReceipts)
                ? (int)array_sum(array_column($rowReceipts, 'allocated_amount_minor'))
                : (int)$row->paid_minor;

            // Total amount of linked RCP payment receipts (deduplicated by payment_id in $rowReceipts).
            // Fall back to recorded paid_minor for direct settlements without allocation rows.
            $receiptPaidMinor = !empty($rowReceipts)
                ? (int)array_sum(array_column($rowReceipts, 'amount_minor'))
                : (int)$row->paid_minor;

            $row->allocated_paid_minor = $allocatedPaidMinor;
            $row->formatted_allocated_paid = Money::format($allocatedPaidMinor);
            $row->receipt_paid_minor = $receiptPaidMinor;
            // Display the total amount of linked RCP payment receipt(s) in Amount Paid column.
            // Do not overwrite i.paid_minor or change existing financial calculations ($row->paid_minor preserved).
            $row->formatted_paid_amount = Money::format($receiptPaidMinor);

            $row->guardian_name = ($rowStudents->count() === 1 && !empty($rowStudents->first()->student_id))
                ? GuardianNameResolver::forStudent($rowStudents->first()->student_id, $row)
                : GuardianNameResolver::forFamily($row->family_id, $rowStudents);
            $row->students = $rowStudents;
            $row->receipts = $rowReceipts;
            $row->notification = $rowNotif;
            $row->is_contact_eligible = $isContactEligible;
            $row->is_already_queued_or_sent = $isAlreadySentOrQueued;
            [$row->delivery_status, $row->delivery_error] = $this->paidDisplayState($row);
            $row->delivery_batch_id = $rowNotif?->batch_id;
            $row->delivery_submitted_at = $rowNotif?->submitted_at;
            $row->invoice_pdf_url = app(\App\Services\PdfAccessService::class)->getInvoicePdfUrl((int)$row->id, $user);

            return $row;
        });

        $paginator->setCollection($enriched);

        return $paginator;
    }


    private function authoritativePaidNotification(): Builder
    {
        // A previous acceptance wins over an accidental later queued record.
        return DB::table('notifications as paid_choice')->select('paid_choice.id')
            ->whereColumn('paid_choice.invoice_id', 'i.id')->where('paid_choice.kind', 'paid')
            ->where(fn ($q) => $q->whereIn('paid_choice.status', ['submitted', 'delivered', 'read'])
                ->orWhere(fn ($active) => $active->where('paid_choice.is_superseded', false)->orWhereNull('paid_choice.is_superseded')))
            ->whereNotIn('paid_choice.status', ['superseded', 'cancelled'])
            ->orderByRaw("CASE paid_choice.status WHEN 'read' THEN 0 WHEN 'delivered' THEN 1 WHEN 'submitted' THEN 2 ELSE 3 END")
            ->orderByDesc('paid_choice.id')->limit(1);
    }

    private function paidWorkflowBaseQuery(array $filters, object $user): Builder
    {
        $period = !empty($filters['period']) ? $filters['period'] : $this->getLatestBillingMonth();
        $q = DB::table('invoices as i')
            ->join('billing_runs as b', 'b.id', '=', 'i.billing_run_id')
            ->join('families as f', 'f.id', '=', 'i.family_id')
            ->leftJoin('departments as d', 'd.id', '=', 'i.department_id')
            ->leftJoin('campuses as c', 'c.id', '=', 'i.campus_id')
            ->leftJoin('notifications as paid_n', function ($join) {
                $join->on('paid_n.invoice_id', '=', 'i.id')
                    ->where('paid_n.id', '=', $this->authoritativePaidNotification());
            })
            ->leftJoin('notification_batches as paid_b', 'paid_b.batch_id', '=', 'paid_n.batch_id');

        $searchTerm = !empty($filters['search']) ? trim((string)$filters['search']) : '';

        if ($searchTerm === '') {
            if ($period === 'all') {
                // All-period listing: do not restrict by billing period start
            } elseif ($period === '2026-10') {
                $q->where(function ($periodQ) {
                    $periodQ->where('b.period_start', 'like', '2026-10%')
                        ->orWhere(function ($older) {
                            $older->where('b.period_start', '<', '2026-10-01')
                                ->where(fn ($delivery) => $delivery->whereNull('paid_n.id')
                                    ->orWhere('paid_b.billing_period', '2026-10'));
                        });
                });
            } else {
                $q->where('b.period_start', 'like', $period . '%');
            }

            $q->where(function ($sub) {
                $sub->where('i.status', 'paid')
                    ->orWhere(function ($sq) {
                        $sq->where('i.total_minor', '>', 0)
                           ->whereColumn('i.paid_minor', '>=', 'i.total_minor');
                    });
            })
            ->whereNotIn('i.status', ['void', 'voided']);

            // Paid delivery begins with actual receipts dated October 9, 2026 or later.
            // Use payment allocations, not invoice issue/billing dates, to avoid sending older paid records.
            PaidInvoiceEligibility::constrain($q);
        } else {
            // Search mode: lookup reference across all invoice periods without selected-month restriction
            $q->whereNotIn('i.status', ['void', 'voided']);

            // Resolve receipt numbers from payments table
            $matchedReceiptPaymentIds = DB::table('payments')
                ->where('status', 'received')
                ->whereNull('reversed_at')
                ->where(function ($pq) use ($searchTerm) {
                    $pq->where('receipt_number', $searchTerm)
                       ->orWhere('receipt_number', 'like', "%{$searchTerm}%");
                })
                ->pluck('id')
                ->all();

            $receiptInvoiceIds = [];
            if (!empty($matchedReceiptPaymentIds)) {
                $receiptInvoiceIds = DB::table('payment_allocations as pa')
                    ->join('invoice_items as ii', 'ii.id', '=', 'pa.invoice_item_id')
                    ->whereIn('pa.payment_id', $matchedReceiptPaymentIds)
                    ->pluck('ii.invoice_id')
                    ->unique()
                    ->filter()
                    ->all();
            }

            $isExactReceipt = DB::table('payments')
                ->where('receipt_number', $searchTerm)
                ->where('status', 'received')
                ->whereNull('reversed_at')
                ->exists();

            $isExactInvoice = DB::table('invoices')->where('invoice_number', $searchTerm)->exists();
            $isExactFamily = DB::table('families')->where('family_code', $searchTerm)->exists();
            $isExactGr = DB::table('students')->where('gr_number', $searchTerm)->exists();

            if ($isExactReceipt) {
                if (!empty($receiptInvoiceIds)) {
                    $q->whereIn('i.id', $receiptInvoiceIds);
                } else {
                    $q->whereRaw('0 = 1');
                }
            } elseif ($isExactInvoice) {
                $q->where('i.invoice_number', $searchTerm);
            } elseif ($isExactFamily) {
                $q->where('f.family_code', $searchTerm);
                $q->where(function ($paidQ) {
                    $paidQ->where('i.status', 'paid')
                          ->orWhere('i.paid_minor', '>', 0);
                });
            } elseif ($isExactGr) {
                $q->whereExists(function ($stSub) use ($searchTerm) {
                    $stSub->selectRaw('1')
                        ->from('invoice_items as st_ii')
                        ->join('students as st_s', 'st_s.id', '=', 'st_ii.student_id')
                        ->whereColumn('st_ii.invoice_id', 'i.id')
                        ->where('st_s.gr_number', $searchTerm);
                });
                $q->where(function ($paidQ) {
                    $paidQ->where('i.status', 'paid')
                          ->orWhere('i.paid_minor', '>', 0);
                });
            } else {
                $like = "%{$searchTerm}%";
                $q->where(function ($sq) use ($like, $receiptInvoiceIds) {
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
                    if (!empty($receiptInvoiceIds)) {
                        $sq->orWhereIn('i.id', $receiptInvoiceIds);
                    }
                });
                $q->where(function ($paidQ) {
                    $paidQ->where('i.status', 'paid')
                          ->orWhere('i.paid_minor', '>', 0);
                });
            }
        }

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

        return $q;
    }

    private function activePaidNotificationIds(): array
    {
        $candidates = [];
        $lease = max(420, (int) config('queue.connections.database.retry_after', 420));
        foreach (DB::table('jobs')->whereNotNull('reserved_at')->where('reserved_at', '>', now()->timestamp - $lease)->get(['payload']) as $job) {
            $payload = json_decode($job->payload, true);
            if (($payload['displayName'] ?? null) !== \App\Jobs\DispatchBatchToN8n::class) {
                continue;
            }
            $command = @unserialize($payload['data']['command'] ?? '', ['allowed_classes' => false]);
            if (!is_object($command)) {
                continue;
            }
            $values = (array) $command;
            if (!is_array($values['selectedNotificationIds'] ?? null) || !is_string($values['batchId'] ?? null)) {
                continue;
            }
            foreach ($values['selectedNotificationIds'] as $id) {
                $candidates[(int) $id][$values['batchId']] = true;
            }
        }
        if (!$candidates) {
            return [];
        }
        return DB::table('notifications')->where('kind', 'paid')->whereIn('id', array_keys($candidates))
            ->get(['id', 'batch_id'])->filter(fn ($n) => isset($candidates[$n->id][$n->batch_id]))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function paidWorkflowStageSql(): array
    {
        $ids = $this->activePaidNotificationIds();
        $active = $ids ? 'paid_n.id IN ('.implode(',', $ids).')' : '0 = 1';
        $staleBefore = now()->subSeconds(max(420, (int) config('queue.connections.database.retry_after', 420)))->toDateTimeString();
        return ["
            CASE
                WHEN paid_n.id IS NULL THEN 'unqueued'
                WHEN paid_n.status IN ('submitted','delivered','read') THEN 'submitted'
                WHEN paid_n.status IN ('failed','blocked','qa_suppressed') THEN 'attention'
                WHEN {$active} AND paid_n.status IN ('queued','processing') THEN 'processing'
                WHEN paid_n.status = 'processing' AND COALESCE(paid_n.last_attempt_at,paid_n.updated_at,paid_n.created_at) <= ? THEN 'attention'
                WHEN paid_n.status = 'processing' THEN 'processing'
                WHEN paid_n.status = 'queued' AND paid_n.attempts > 0 THEN 'attention'
                WHEN paid_n.status = 'queued' AND paid_b.status = 'processing'
                    AND COALESCE(paid_b.updated_at,paid_b.created_at) <= ? THEN 'attention'
                WHEN paid_n.status = 'queued' AND paid_b.status IN ('submitted','completed','blocked','qa_suppressed') THEN 'attention'
                WHEN paid_n.status = 'queued' THEN 'queued'
                ELSE 'attention'
            END", [$staleBefore, $staleBefore]];
    }

    private function filterPaidWorkflow(Builder $query, array $filters, string $sql, array $bindings): void
    {
        $stage = $filters['paid_status'] ?? 'all';
        if (in_array($stage, ['unqueued', 'queued', 'processing', 'submitted', 'attention'], true)) {
            $query->whereRaw("({$sql}) = ?", [...$bindings, $stage]);
        }
    }

    private function paidDisplayState(object $row): array
    {
        $stage = $row->paid_workflow_stage;
        $status = match ($stage) {
            'submitted' => in_array($row->notification_status, ['delivered', 'read'], true) ? $row->notification_status : 'submitted',
            'processing' => 'processing',
            'queued' => 'queued',
            'attention' => ($row->notification_status === 'processing'
                || ($row->notification_status === 'queued' && $row->paid_batch_status === 'processing'))
                    ? 'stalled' : (in_array($row->notification_status, ['failed', 'blocked', 'qa_suppressed'], true) ? $row->notification_status : 'attention'),
            default => 'unqueued',
        };
        $error = $row->notification_error;
        if ($stage === 'attention') {
            $error = $status === 'stalled'
                ? 'Dispatch exceeded its processing window. Review queue and webhook evidence before retrying.'
                : ($error ?: $row->paid_batch_error ?: 'Notification and batch state need review before retrying.');
        }
        return [$status, $error];
    }

    public function paidInvoiceStages(array $invoiceIds, array $filters, object $user): array
    {
        if (!$invoiceIds) {
            return [];
        }
        [$sql, $bindings] = $this->paidWorkflowStageSql();
        return $this->paidWorkflowBaseQuery($filters, $user)->whereIn('i.id', $invoiceIds)
            ->select('i.id')->selectRaw("({$sql}) as paid_workflow_stage", $bindings)
            ->get()->pluck('paid_workflow_stage', 'id')->all();
    }

    public function preferredPaidStage(array $stages): string
    {
        foreach (['processing', 'queued', 'attention', 'submitted', 'unqueued'] as $stage) {
            if (in_array($stage, $stages, true)) {
                return $stage;
            }
        }
        return 'queued';
    }

    public function paidWorkflowSignature(array $stats, $rows): string
    {
        $states = [];
        foreach ($rows as $row) {
            $states[] = [$row->id, $row->paid_workflow_stage, $row->delivery_status, $row->delivery_error, $row->delivery_submitted_at];
        }
        return hash('sha256', json_encode([$stats, $states], JSON_THROW_ON_ERROR));
    }

    public function getPaidWorkflowRefresh(array $filters, object $user, int $perPage, array $trackingIds = []): array
    {
        $stats = $this->getPaidWorkflowStats($filters['period'], $user, $filters);
        [$sql, $bindings] = $this->paidWorkflowStageSql();
        $query = $this->paidWorkflowBaseQuery($filters, $user);
        $this->filterPaidWorkflow($query, $filters, $sql, $bindings);
        // State-only polling: no receipt PDFs, recipient data or payload building.
        $rows = $query->select('i.id', 'paid_n.status as notification_status', 'paid_n.error as notification_error',
                'paid_b.status as paid_batch_status', 'paid_b.error as paid_batch_error', 'paid_n.submitted_at as delivery_submitted_at')
            ->selectRaw("({$sql}) as paid_workflow_stage", $bindings)
            ->orderByDesc('i.created_at')
            ->orderByDesc('i.id')
            ->paginate($perPage);
        foreach ($rows as $row) {
            [$row->delivery_status, $row->delivery_error] = $this->paidDisplayState($row);
        }
        $tracking = $this->paidInvoiceStages($trackingIds, $filters, $user);
        return [
            'signature' => $this->paidWorkflowSignature($stats, $rows),
            'counts' => $stats,
            'tracking_stage' => count($tracking) === count($trackingIds) && $trackingIds
                ? $this->preferredPaidStage(array_values($tracking)) : null,
        ];
    }

    /** Same invoice scope and authoritative state as the paid table. */
    public function getPaidWorkflowStats(string $period, object $user, array $filters = []): array
    {
        [$sql, $bindings] = $this->paidWorkflowStageSql();
        $counts = $this->paidWorkflowBaseQuery(array_merge($filters, ['period' => $period]), $user)
            ->selectRaw("({$sql}) as paid_workflow_stage, COUNT(*) as stage_count", $bindings)
            ->groupBy('paid_workflow_stage')->get()->pluck('stage_count', 'paid_workflow_stage')->all();
        $stats = array_fill_keys(['unqueued', 'queued', 'processing', 'submitted', 'attention'], 0);
        foreach ($counts as $stage => $count) {
            $stats[$stage] = (int) $count;
        }
        $stats['total'] = array_sum($stats);
        return $stats;
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
                    ->whereIn('n.status', ['submitted', 'delivered', 'read', 'queued', 'processing']);
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

            $cleanGuardianName = GuardianNameResolver::forFamily($fam, $uniqueStudents);
            $rawFatherName = $regDetails->get($famId)->father_name ?? null;
            $fatherName = GuardianNameResolver::isValid($rawFatherName)
                ? trim((string) $rawFatherName)
                : ($cleanGuardianName !== GuardianNameResolver::NOT_PROVIDED ? $cleanGuardianName : null);
            $isFormula = !GuardianNameResolver::isValid($fam->guardian_name ?? null);

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
        if (!empty($filters['period']) && $filters['period'] !== 'all') {
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

        $this->applyCreationDateFilters($q, 'i.created_at', $filters);

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
        )->orderByDesc('i.created_at')->orderByDesc('i.id');

        $paginator = $q->paginate($perPage)->withQueryString();

        $pdfAccess = app(\App\Services\PdfAccessService::class);
        $paginator->getCollection()->transform(function ($row) use ($pdfAccess, $user) {
            $row->invoice_pdf_url = $pdfAccess->getInvoicePdfUrl((int)$row->id, $user);
            $row->formatted_total = \App\Services\Money::format($row->total_minor);
            $row->formatted_paid = \App\Services\Money::format($row->paid_minor);
            $row->formatted_balance = \App\Services\Money::format(max(0, (int)$row->total_minor - (int)$row->paid_minor));
            return $row;
        });

        return $paginator;
    }

    /**
     * Get paginated complete financial receipt history across all time (Phase C.1).
     * One row per genuine RCP receipt number, sorted by payments.created_at DESC, payments.id DESC.
     * Includes historical and newly created records, and preserves full allocation details without duplication.
     */
    public function getFinancialReceiptHistory(array $filters, object $user, int $perPage = 25): LengthAwarePaginator
    {
        $q = DB::table('payments as p')
            ->leftJoin('families as f', 'f.id', '=', 'p.family_id')
            ->leftJoin('departments as d', 'd.id', '=', 'p.department_id')
            ->leftJoin('campuses as c', 'c.id', '=', 'p.campus_id');

        if (!in_array($user->role, ['super_admin', 'finance_admin'], true)) {
            $q->where('p.campus_id', $user->campus_id);
            if (!empty($user->department_id)) {
                $q->where('p.department_id', $user->department_id);
            }
        }

        if (!empty($filters['campus_id'])) {
            $q->where('p.campus_id', (int)$filters['campus_id']);
        }
        if (!empty($filters['department_id'])) {
            $q->where('p.department_id', (int)$filters['department_id']);
        }
        if (!empty($filters['receipt_status']) && $filters['receipt_status'] !== 'all') {
            if ($filters['receipt_status'] === 'reversed') {
                $q->where(function ($rev) {
                    $rev->whereNotNull('p.reversed_at')
                        ->orWhere('p.status', 'reversed');
                });
            } elseif ($filters['receipt_status'] === 'received') {
                $q->whereNull('p.reversed_at')
                  ->where('p.status', '!=', 'reversed');
            }
        }

        $this->applyCreationDateFilters($q, 'p.created_at', $filters);

        if (!empty($filters['search'])) {
            $s = trim($filters['search']);
            $q->where(function ($sq) use ($s) {
                $sq->where('p.receipt_number', 'like', "%{$s}%")
                    ->orWhere('f.family_code', 'like', "%{$s}%")
                    ->orWhere('f.guardian_name', 'like', "%{$s}%")
                    ->orWhere('f.whatsapp', 'like', "%{$s}%")
                    ->orWhereExists(function ($paSub) use ($s) {
                        $paSub->select(DB::raw(1))
                            ->from('payment_allocations as sub_pa')
                            ->join('invoice_items as sub_ii', 'sub_ii.id', '=', 'sub_pa.invoice_item_id')
                            ->join('invoices as sub_inv', 'sub_inv.id', '=', 'sub_ii.invoice_id')
                            ->leftJoin('students as sub_s', 'sub_s.id', '=', 'sub_ii.student_id')
                            ->whereColumn('sub_pa.payment_id', 'p.id')
                            ->where(function ($matchQ) use ($s) {
                                $matchQ->where('sub_inv.invoice_number', 'like', "%{$s}%")
                                    ->orWhere('sub_s.name', 'like', "%{$s}%")
                                    ->orWhere('sub_s.gr_number', 'like', "%{$s}%");
                            });
                    });
            });
        }

        $q->select(
            'p.*',
            'f.family_code',
            'f.guardian_name',
            'f.whatsapp',
            'd.name as department_name',
            'c.name as campus_name'
        )->orderByDesc('p.created_at')->orderByDesc('p.id');

        $paginator = $q->paginate($perPage)->withQueryString();

        $paymentIds = $paginator->pluck('id')->all();
        if (!empty($paymentIds)) {
            $allocationsRaw = DB::table('payment_allocations as pa')
                ->join('invoice_items as ii', 'ii.id', '=', 'pa.invoice_item_id')
                ->join('invoices as inv', 'inv.id', '=', 'ii.invoice_id')
                ->leftJoin('students as s', 's.id', '=', 'ii.student_id')
                ->leftJoin('billing_runs as br', 'br.id', '=', 'inv.billing_run_id')
                ->whereIn('pa.payment_id', $paymentIds)
                ->select(
                    'pa.payment_id',
                    'pa.amount_minor',
                    'inv.id as invoice_id',
                    'inv.invoice_number',
                    'br.period_start as billing_period',
                    'ii.description as item_description',
                    's.name as student_name',
                    's.gr_number'
                )
                ->orderBy('pa.id')
                ->get()
                ->groupBy('payment_id');

            $pdfAccess = app(\App\Services\PdfAccessService::class);

            $paginator->getCollection()->transform(function ($row) use ($allocationsRaw, $pdfAccess, $user) {
                $rowAllocs = $allocationsRaw->get($row->id, collect());

                $allocationsByInvoice = [];
                foreach ($rowAllocs as $al) {
                    $invNum = $al->invoice_number;
                    if (!isset($allocationsByInvoice[$invNum])) {
                        $allocationsByInvoice[$invNum] = [
                            'invoice_id' => $al->invoice_id,
                            'invoice_number' => $invNum,
                            'invoice_pdf_url' => $pdfAccess->getInvoicePdfUrl((int)$al->invoice_id, $user),
                            'billing_period' => $al->billing_period,
                            'amount_minor' => 0,
                            'items' => [],
                        ];
                    }
                    $allocationsByInvoice[$invNum]['amount_minor'] += (int)$al->amount_minor;
                    $allocationsByInvoice[$invNum]['items'][] = [
                        'amount_minor' => (int)$al->amount_minor,
                        'description' => $al->item_description,
                        'student_name' => $al->student_name,
                        'gr_number' => $al->gr_number,
                    ];
                }

                $row->allocations_by_invoice = array_values($allocationsByInvoice);
                $row->allocations_count = $rowAllocs->count();
                $row->invoices_paid_count = count($allocationsByInvoice);
                $row->invoices_paid_numbers = array_keys($allocationsByInvoice);
                $row->receipt_pdf_url = $pdfAccess->getReceiptPdfUrl((int)$row->id);
                $row->formatted_amount = \App\Services\Money::format($row->amount_minor);

                return $row;
            });
        }

        return $paginator;
    }

    /**
     * Get document counts for SCH Invoices and RCP Payment Receipts.
     */
    public function getFinancialHistoryCounts(array $filters, object $user): array
    {
        $schQuery = DB::table('invoices as i');
        if (!in_array($user->role, ['super_admin', 'finance_admin'], true)) {
            $schQuery->where('i.campus_id', $user->campus_id);
            if (!empty($user->department_id)) {
                $schQuery->where('i.department_id', $user->department_id);
            }
        }
        if (!empty($filters['campus_id'])) {
            $schQuery->where('i.campus_id', (int)$filters['campus_id']);
        }
        if (!empty($filters['department_id'])) {
            $schQuery->where('i.department_id', (int)$filters['department_id']);
        }
        $this->applyCreationDateFilters($schQuery, 'i.created_at', $filters);
        $schCount = $schQuery->count();

        $rcpQuery = DB::table('payments as p');
        if (!in_array($user->role, ['super_admin', 'finance_admin'], true)) {
            $rcpQuery->where('p.campus_id', $user->campus_id);
            if (!empty($user->department_id)) {
                $rcpQuery->where('p.department_id', $user->department_id);
            }
        }
        if (!empty($filters['campus_id'])) {
            $rcpQuery->where('p.campus_id', (int)$filters['campus_id']);
        }
        if (!empty($filters['department_id'])) {
            $rcpQuery->where('p.department_id', (int)$filters['department_id']);
        }
        $this->applyCreationDateFilters($rcpQuery, 'p.created_at', $filters);
        $rcpCount = $rcpQuery->count();

        return [
            'sch' => $schCount,
            'rcp' => $rcpCount,
        ];
    }

    /**
     * Apply creation date filtering with application timezone and full-day boundaries (Phase C.2).
     */
    protected function applyCreationDateFilters($query, string $column, array $filters): void
    {
        $tz = config('app.timezone') ?: (env('APP_TIMEZONE') ?: 'UTC');

        if (!empty($filters['from_date'])) {
            $fromVal = $filters['from_date'];
            if ($fromVal instanceof \DateTimeInterface) {
                $fromStr = \Carbon\Carbon::instance($fromVal)->setTimezone($tz)->startOfDay()->format('Y-m-d H:i:s');
                $query->where($column, '>=', $fromStr);
            } elseif (is_string($fromVal) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($fromVal))) {
                $fromStr = \Carbon\Carbon::createFromFormat('Y-m-d', trim($fromVal), $tz)->startOfDay()->format('Y-m-d H:i:s');
                $query->where($column, '>=', $fromStr);
            }
        }

        if (!empty($filters['to_date'])) {
            $toVal = $filters['to_date'];
            if ($toVal instanceof \DateTimeInterface) {
                $toStr = \Carbon\Carbon::instance($toVal)->setTimezone($tz)->endOfDay()->format('Y-m-d H:i:s');
                $query->where($column, '<=', $toStr);
            } elseif (is_string($toVal) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($toVal))) {
                $toStr = \Carbon\Carbon::createFromFormat('Y-m-d', trim($toVal), $tz)->endOfDay()->format('Y-m-d H:i:s');
                $query->where($column, '<=', $toStr);
            }
        }
    }

    /**
     * Reliably detect background queue worker status.
     * Returns whether a worker is running and number of pending/reserved jobs.
     */
    public function getQueueWorkerStatus(): array
    {
        $pendingJobs = DB::table('jobs')->whereIn('queue', ['notifications', 'paid-future', 'default'])->count();
        $reservedJobs = DB::table('jobs')->whereIn('queue', ['notifications', 'paid-future', 'default'])->whereNotNull('reserved_at')->count();

        $isRunning = false;

        // 1. If a job is currently reserved in the jobs table, an active worker is executing it
        if ($reservedJobs > 0) {
            $isRunning = true;
        } else {
            // 2. Check OS process list for queue:work scoped strictly to this application
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $output = @shell_exec('wmic process where "name=\'php.exe\'" get commandline 2>nul');
                if ($output && stripos($output, 'queue:work') !== false) {
                    $isRunning = true;
                }
            } else {
                $base = base_path();
                $output = @shell_exec('pgrep -f "' . preg_quote($base, '/') . '.*queue:work" 2>/dev/null');
                if ($output && !empty(trim((string)$output))) {
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
                    DB::raw("COUNT(CASE WHEN status = 'queued' AND (is_superseded = 0 OR is_superseded IS NULL) THEN 1 END) as queued_count"),
                    DB::raw("COUNT(CASE WHEN status = 'processing' AND (is_superseded = 0 OR is_superseded IS NULL) THEN 1 END) as processing_count"),
                    DB::raw("COUNT(CASE WHEN status IN ('submitted', 'delivered', 'read') AND (is_superseded = 0 OR is_superseded IS NULL) THEN 1 END) as submitted_count"),
                    DB::raw("COUNT(CASE WHEN status = 'failed' AND (is_superseded = 0 OR is_superseded IS NULL) THEN 1 END) as failed_count"),
                    DB::raw("COUNT(DISTINCT CASE WHEN status = 'failed' AND (is_superseded = 0 OR is_superseded IS NULL) THEN chunk_index END) as failed_chunks_count"),
                    DB::raw("COUNT(CASE WHEN status = 'superseded' OR is_superseded = 1 THEN 1 END) as superseded_count"),
                    DB::raw("COUNT(*) as total_notifications"),
                    DB::raw("MAX(last_attempt_at) as last_attempt_at"),
                    DB::raw("MAX(error) as sample_error")
                )
                ->first();

            $totalNotifs = (int)($counts->total_notifications ?? 0);
            $supersededCount = (int)($counts->superseded_count ?? 0);
            $allSuperseded = ($totalNotifs > 0 && $supersededCount === $totalNotifs);

            $b->is_all_superseded = $allSuperseded;
            $b->display_status = $allSuperseded ? 'superseded' : $b->status;
            $b->queued_recipients = (int)($counts->queued_count ?? 0);
            $b->processing_recipients = (int)($counts->processing_count ?? 0);
            $b->submitted_recipients = (int)($counts->submitted_count ?? 0);
            $b->failed_recipients = (int)($counts->failed_count ?? 0);
            $b->superseded_recipients = $supersededCount;
            $b->chunks_failed = (int)($counts->failed_chunks_count ?? 0);
            $b->chunks_sent = (int)$b->chunks_sent;
            $b->chunks_total = (int)$b->chunks_total;
            $b->chunks_pending = $allSuperseded ? 0 : max(0, $b->chunks_total - $b->chunks_sent - $b->chunks_failed);
            $b->last_attempt = $counts->last_attempt_at ?: $b->submitted_at;
            $b->display_error = $b->error ?: $counts->sample_error;
        }

        return $batches;
    }
}

