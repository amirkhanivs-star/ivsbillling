<?php

namespace App\Services;

use App\Jobs\DispatchBatchToN8n;
use App\Services\Staging\DatabaseSafetyGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;

class BatchDeliveryService
{
    /**
     * Build the query for invoices matching filter criteria with access control.
     */
    public function buildFilterQuery(array $filters, object $user)
    {
        $q = DB::table('invoices as i')
            ->join('families as f', 'f.id', '=', 'i.family_id')
            ->join('departments as d', 'd.id', '=', 'i.department_id')
            ->join('billing_runs as b', 'b.id', '=', 'i.billing_run_id');

        // Access control: campus and department
        if (!in_array($user->role, ['super_admin', 'finance_admin'], true)) {
            $q->where('i.campus_id', $user->campus_id)
              ->when($user->department_id, fn($v) => $v->where('i.department_id', $user->department_id));
        } elseif (!empty($filters['campus_id'])) {
            $q->where('i.campus_id', (int)$filters['campus_id']);
        }

        if (!empty($filters['department_id'])) {
            $q->where('i.department_id', (int)$filters['department_id']);
        }

        if (!empty($filters['period'])) {
            $q->where('b.period_start', 'like', $filters['period'] . '%');
        }

        // Mode: Monthly vs Paid
        $isPaid = !empty($filters['paid_only']) || ($filters['webhook_type'] ?? '') === 'paid';
        if ($isPaid) {
            $q->where(function ($sub) {
                $sub->where('i.status', 'paid')
                    ->orWhere(function ($sq) {
                        $sq->where('i.total_minor', '>', 0)
                           ->whereColumn('i.paid_minor', '>=', 'i.total_minor');
                    });
            });
        } else {
            // Routine monthly: unsettled invoices
            $q->where('i.status', '!=', 'paid')
              ->whereColumn('i.paid_minor', '<', 'i.total_minor');
        }

        // Payment status filter
        if (!empty($filters['payment_status']) && $filters['payment_status'] !== 'all') {
            if ($filters['payment_status'] === 'unpaid') {
                $q->where('i.paid_minor', 0);
            } elseif ($filters['payment_status'] === 'partial') {
                $q->where('i.paid_minor', '>', 0)
                  ->whereColumn('i.paid_minor', '<', 'i.total_minor');
            } elseif ($filters['payment_status'] === 'paid') {
                $q->where(function ($sub) {
                    $sub->where('i.status', 'paid')
                        ->orWhere(function ($sq) {
                            $sq->where('i.total_minor', '>', 0)
                               ->whereColumn('i.paid_minor', '>=', 'i.total_minor');
                        });
                });
            }
        }

        // Grade filter
        if (!empty($filters['grade'])) {
            $q->whereExists(function ($sub) use ($filters) {
                $sub->selectRaw('1')
                    ->from('invoice_items as fi')
                    ->join('enrollments as fe', 'fe.id', '=', 'fi.enrollment_id')
                    ->whereColumn('fi.invoice_id', 'i.id')
                    ->where('fe.grade_label', $filters['grade']);
            });
        }

        // Contact eligibility filter
        if (!empty($filters['contact_status']) && $filters['contact_status'] !== 'all') {
            if ($filters['contact_status'] === 'eligible_only') {
                $q->where('f.contact_verified', true)
                  ->where('f.messaging_opt_in', true)
                  ->whereNotNull('f.whatsapp')
                  ->where('f.whatsapp', '!=', '');
            } elseif ($filters['contact_status'] === 'ineligible_only') {
                $q->where(function ($sq) {
                    $sq->where('f.contact_verified', false)
                       ->orWhere('f.messaging_opt_in', false)
                       ->orWhereNull('f.whatsapp')
                       ->orWhere('f.whatsapp', '');
                });
            }
        }

        return $q;
    }

    /**
     * Resolve all matching invoices server-side across all pages, applying
     * latest consolidated monthly challan rules, supersession, and contact eligibility.
     */
    public function resolveFilteredSelection(array $filters, object $user, array $channels = ['whatsapp']): array
    {
        $q = $this->buildFilterQuery($filters, $user);

        $channelsStr = implode(',', $channels);
        $webhookType = (!empty($filters['paid_only']) || ($filters['webhook_type'] ?? '') === 'paid') ? 'paid' : 'monthly';

        $allInvoices = $q->select(
            'i.*',
            'f.id as family_id',
            'f.family_code',
            'f.guardian_name',
            'f.whatsapp',
            'f.email',
            'f.contact_verified',
            'f.whatsapp_verified',
            'f.email_verified',
            'f.messaging_opt_in',
            'd.name as department_name',
            'b.period_start as billing_period_start',
            'b.period_key as billing_period_key',
            'b.frequency'
        )
        ->orderByDesc('i.id')
        ->get();

        $totalMatchedInvoices = $allInvoices->count();
        if ($totalMatchedInvoices === 0) {
            return [
                'snapshot_id' => $this->generateSnapshotId($filters, $user->id),
                'filters' => $filters,
                'webhook_type' => $webhookType,
                'total_matched_invoices' => 0,
                'total_eligible_recipients' => 0,
                'total_eligible_invoices' => 0,
                'distinct_family_count' => 0,
                'currencies_summary' => [],
                'all_grades' => [],
                'target_grade' => $filters['grade'] ?? null,
                'sibling_grades' => [],
                'sibling_students_count' => 0,
                'sibling_breakdown' => [],
                'billing_cycle' => !empty($filters['period']) ? $filters['period'] : date('Y-m'),
                'eligible_recipients' => [],
                'eligible_invoice_ids' => [],
                'superseded_invoice_ids' => [],
                'excluded_breakdown' => [
                    'superseded' => 0,
                    'contact_ineligible' => 0,
                    'already_submitted' => 0,
                    'already_delivered' => 0,
                    'already_queued' => 0,
                    'total_excluded' => 0,
                ],
                'excluded_reasons' => [],
                'sample_preview_family_id' => null,
            ];
        }

        // Group invoices by family and currency
        // For monthly mode, find the latest consolidated monthly challan per family + currency
        $grouped = $allInvoices->groupBy(fn($inv) => "{$inv->family_id}_{$inv->currency}");

        $eligibleRecipients = [];
        $eligibleInvoiceIds = [];
        $supersededInvoiceIds = [];
        $excludedReasons = [];
        $currenciesSummary = [];
        $allFamilyIds = [];
        $samplePreviewFamilyId = null;

        $excludedBreakdown = [
            'superseded' => 0,
            'contact_ineligible' => 0,
            'already_submitted' => 0,
            'already_delivered' => 0,
            'already_queued' => 0,
        ];

        foreach ($grouped as $groupKey => $invoicesGroup) {
            // Sort group so that the latest appropriate invoice comes first
            // If period filter is supplied, prefer invoices belonging to that period
            $sorted = $invoicesGroup->sort(function ($a, $b) use ($filters) {
                if (!empty($filters['period'])) {
                    $aMatch = str_starts_with((string)$a->billing_period_start, $filters['period']);
                    $bMatch = str_starts_with((string)$b->billing_period_start, $filters['period']);
                    if ($aMatch && !$bMatch) return -1;
                    if (!$aMatch && $bMatch) return 1;
                }
                // Order by period_start desc, then invoice id desc
                $cmp = strcmp((string)$b->billing_period_start, (string)$a->billing_period_start);
                return $cmp !== 0 ? $cmp : ($b->id <=> $a->id);
            })->values();

            $primaryInvoice = $sorted->first();

            // Any older invoices in the same group or in previous billing runs are superseded for delivery
            if ($webhookType === 'monthly') {
                if ($sorted->count() > 1) {
                    for ($idx = 1; $idx < $sorted->count(); $idx++) {
                        $superseded = $sorted->get($idx);
                        $supersededInvoiceIds[] = $superseded->id;
                        $excludedBreakdown['superseded']++;
                        $excludedReasons[$superseded->id] = "Superseded for delivery by invoice {$primaryInvoice->invoice_number}";
                    }
                }

                // Check for older unsettled invoices in the database for this family and currency
                if (!empty($primaryInvoice->billing_period_start)) {
                    $olderUnsettled = DB::table('invoices as older')
                        ->join('billing_runs as ob', 'ob.id', '=', 'older.billing_run_id')
                        ->where('older.family_id', $primaryInvoice->family_id)
                        ->where('older.currency', $primaryInvoice->currency)
                        ->where('older.id', '<>', $primaryInvoice->id)
                        ->where('older.status', '!=', 'paid')
                        ->whereColumn('older.paid_minor', '<', 'older.total_minor')
                        ->where('ob.period_start', '<', $primaryInvoice->billing_period_start)
                        ->pluck('older.id')
                        ->all();

                    foreach ($olderUnsettled as $oldId) {
                        if (!in_array($oldId, $supersededInvoiceIds, true)) {
                            $supersededInvoiceIds[] = $oldId;
                            $excludedBreakdown['superseded']++;
                            $excludedReasons[$oldId] = "Superseded for delivery by invoice {$primaryInvoice->invoice_number}";
                        }
                    }
                }
            }

            // Check contact eligibility for primary invoice recipient
            $familyObj = (object)[
                'id' => $primaryInvoice->family_id,
                'family_code' => $primaryInvoice->family_code,
                'guardian_name' => $primaryInvoice->guardian_name,
                'whatsapp' => $primaryInvoice->whatsapp,
                'email' => $primaryInvoice->email,
                'contact_verified' => (bool)$primaryInvoice->contact_verified,
                'whatsapp_verified' => (bool)$primaryInvoice->whatsapp_verified,
                'email_verified' => (bool)$primaryInvoice->email_verified,
                'messaging_opt_in' => (bool)$primaryInvoice->messaging_opt_in,
            ];

            $contactError = WebhookSettingService::evaluateContactEligibility($familyObj, $channels);

            // Check existing notification
            $existingNotif = DB::table('notifications')
                ->where('invoice_id', $primaryInvoice->id)
                ->where('channel', $channelsStr)
                ->where('kind', $webhookType)
                ->first();

            if ($existingNotif) {
                if (in_array($existingNotif->status, ['submitted', 'delivered', 'read'], true)) {
                    if ($existingNotif->status === 'submitted') {
                        $excludedBreakdown['already_submitted']++;
                        $excludedReasons[$primaryInvoice->id] = "Already submitted to webhook (Status: SUBMITTED)";
                    } else {
                        $excludedBreakdown['already_delivered']++;
                        $excludedReasons[$primaryInvoice->id] = "Already delivered to recipient (Status: " . strtoupper($existingNotif->status) . ")";
                    }
                    continue;
                }

                if ($existingNotif->status === 'queued') {
                    $excludedBreakdown['already_queued']++;
                    $excludedReasons[$primaryInvoice->id] = "Already queued for dispatch";
                    continue;
                }

                if ($existingNotif->status === 'blocked') {
                    if ($contactError !== null) {
                        $excludedBreakdown['contact_ineligible']++;
                        $excludedReasons[$primaryInvoice->id] = "Blocked: {$contactError}";
                        continue;
                    }
                    // Contact is now eligible: allow authorized retry
                }

                if ($existingNotif->status === 'failed') {
                    if ($contactError !== null) {
                        $excludedBreakdown['contact_ineligible']++;
                        $excludedReasons[$primaryInvoice->id] = "Previously failed and contact ineligible: {$contactError}";
                        continue;
                    }
                    // Eligible for retry
                }
            } else {
                if ($contactError !== null) {
                    $excludedBreakdown['contact_ineligible']++;
                    $excludedReasons[$primaryInvoice->id] = "Ineligible: {$contactError}";
                    continue;
                }
            }

            // This recipient is eligible!
            if (!$samplePreviewFamilyId) {
                $samplePreviewFamilyId = $primaryInvoice->family_id;
            }

            $eligibleRecipients[] = [
                'invoice_id' => $primaryInvoice->id,
                'invoice_number' => $primaryInvoice->invoice_number,
                'family_id' => $primaryInvoice->family_id,
                'family_code' => $primaryInvoice->family_code ?: 'Unnumbered family #' . $primaryInvoice->family_id,
                'guardian_name' => $primaryInvoice->guardian_name,
                'whatsapp' => $primaryInvoice->whatsapp,
                'currency' => $primaryInvoice->currency,
                'total_minor' => (int)$primaryInvoice->total_minor,
                'paid_minor' => (int)$primaryInvoice->paid_minor,
                'outstanding_minor' => max(0, (int)$primaryInvoice->total_minor - (int)$primaryInvoice->paid_minor),
                'billing_period' => $primaryInvoice->billing_period_start ? substr($primaryInvoice->billing_period_start, 0, 7) : null,
                'is_retry' => ($existingNotif && in_array($existingNotif->status, ['blocked', 'failed'], true)),
                'existing_notification_id' => $existingNotif?->id,
            ];

            $eligibleInvoiceIds[] = $primaryInvoice->id;
            $allFamilyIds[$primaryInvoice->family_id] = true;

            $curr = $primaryInvoice->currency;
            if (!isset($currenciesSummary[$curr])) {
                $currenciesSummary[$curr] = ['total_minor' => 0, 'outstanding_minor' => 0, 'count' => 0];
            }
            $currenciesSummary[$curr]['total_minor'] += (int)$primaryInvoice->total_minor;
            $currenciesSummary[$curr]['outstanding_minor'] += max(0, (int)$primaryInvoice->total_minor - (int)$primaryInvoice->paid_minor);
            $currenciesSummary[$curr]['count']++;
        }

        // Get all grades and sibling student details across the eligible invoices
        $allGrades = [];
        $siblingGrades = [];
        $siblingBreakdown = [];
        $siblingStudentsCount = 0;
        $targetGrade = $filters['grade'] ?? null;

        if (!empty($eligibleInvoiceIds)) {
            $studentEnrollments = DB::table('invoice_items as ii')
                ->join('enrollments as e', 'e.id', '=', 'ii.enrollment_id')
                ->join('students as s', 's.id', '=', 'ii.student_id')
                ->whereIn('ii.invoice_id', $eligibleInvoiceIds)
                ->whereNotNull('e.grade_label')
                ->select('e.grade_label', 's.id as student_id')
                ->distinct()
                ->get();

            $allGrades = $studentEnrollments->pluck('grade_label')->unique()->sort()->values()->all();

            if ($targetGrade) {
                $groupedByGrade = $studentEnrollments->groupBy('grade_label');
                foreach ($groupedByGrade as $gLabel => $items) {
                    if ($gLabel !== $targetGrade) {
                        $count = $items->count();
                        $siblingGrades[] = $gLabel;
                        $siblingStudentsCount += $count;
                        $siblingBreakdown[] = [
                            'grade' => $gLabel,
                            'student_count' => $count,
                        ];
                    }
                }
                usort($siblingBreakdown, fn($a, $b) => strcmp($a['grade'], $b['grade']));
                sort($siblingGrades);
            }
        }

        $snapshotId = $this->generateSnapshotId($filters, $user->id, count($eligibleInvoiceIds), $eligibleInvoiceIds);
        $billingCycle = !empty($filters['period']) ? $filters['period'] : ($eligibleRecipients[0]['billing_period'] ?? date('Y-m'));
        $excludedBreakdown['total_excluded'] = array_sum($excludedBreakdown);

        return [
            'snapshot_id' => $snapshotId,
            'filters' => $filters,
            'webhook_type' => $webhookType,
            'total_matched_invoices' => $totalMatchedInvoices,
            'total_eligible_recipients' => count($eligibleRecipients),
            'total_eligible_invoices' => count($eligibleInvoiceIds),
            'distinct_family_count' => count($allFamilyIds),
            'currencies_summary' => $currenciesSummary,
            'all_grades' => $allGrades,
            'target_grade' => $targetGrade,
            'sibling_grades' => $siblingGrades,
            'sibling_students_count' => $siblingStudentsCount,
            'sibling_breakdown' => $siblingBreakdown,
            'billing_cycle' => $billingCycle,
            'eligible_recipients' => $eligibleRecipients,
            'eligible_invoice_ids' => $eligibleInvoiceIds,
            'superseded_invoice_ids' => $supersededInvoiceIds,
            'excluded_breakdown' => $excludedBreakdown,
            'excluded_reasons' => $excludedReasons,
            'sample_preview_family_id' => $samplePreviewFamilyId,
        ];
    }

    /**
     * Normalize filter criteria to ensure consistent hashing across direct calls and form submissions.
     */
    public static function normalizeFilters(array $filters): array
    {
        $normalized = [];
        if (!empty($filters['campus_id'])) $normalized['campus_id'] = (int)$filters['campus_id'];
        if (!empty($filters['department_id'])) $normalized['department_id'] = (int)$filters['department_id'];
        if (!empty($filters['grade'])) $normalized['grade'] = (string)$filters['grade'];
        if (!empty($filters['period'])) $normalized['period'] = (string)$filters['period'];
        if (!empty($filters['contact_status']) && $filters['contact_status'] !== 'all') {
            $normalized['contact_status'] = (string)$filters['contact_status'];
        }
        if (!empty($filters['payment_status']) && $filters['payment_status'] !== 'all') {
            $normalized['payment_status'] = (string)$filters['payment_status'];
        }
        if (!empty($filters['paid_only']) || ($filters['webhook_type'] ?? '') === 'paid') {
            $normalized['webhook_type'] = 'paid';
        } else {
            $normalized['webhook_type'] = 'monthly';
        }
        if (!empty($filters['send_mode'])) $normalized['send_mode'] = (string)$filters['send_mode'];
        ksort($normalized);
        return $normalized;
    }

    /**
     * Generate a unique deterministic snapshot ID based on filter criteria, user, and eligible records.
     */
    public function generateSnapshotId(array $filters, int $userId, int $eligibleCount = 0, array $invoiceIds = []): string
    {
        $normalizedFilters = self::normalizeFilters($filters);
        sort($invoiceIds);
        $idsHash = !empty($invoiceIds) ? substr(md5(implode(',', $invoiceIds)), 0, 10) : 'none';
        $payload = json_encode($normalizedFilters) . "-user:{$userId}-c:{$eligibleCount}-h:{$idsHash}";
        return "SNP-" . substr(hash('sha256', $payload), 0, 16);
    }

    /**
     * Atomically create the approved batch, update/insert recipient notifications,
     * record supersessions, and dispatch the background job AFTER transaction commit.
     */
    public function createAndDispatchBatch(
        array $filters,
        array $confirmedGrades,
        array $channels,
        object $user,
        ?string $billingPeriod = null,
        ?array $explicitInvoiceIds = null,
        ?string $snapshotId = null,
        bool $reviewConfirmed = false
    ): array {
        // Prevent concurrent batch dispatch double-clicks
        $webhookType = (!empty($filters['paid_only']) || ($filters['webhook_type'] ?? '') === 'paid') ? 'paid' : 'monthly';
        $eventType = ($webhookType === 'paid') ? 'invoice.paid.batch' : 'invoice.monthly.batch';
        $channelsStr = implode(',', $channels);

        $webhookService = app(WebhookSettingService::class);
        $setting = $webhookService->getSettings($webhookType);

        if (!$setting || !$setting->is_enabled || empty($setting->url)) {
            throw new RuntimeException(ucfirst($webhookType) . " Invoice Webhook is disabled or not configured in Settings → Webhooks.");
        }

        // Resolve snapshot server-side
        $snapshot = $this->resolveFilteredSelection($filters, $user, $channels);

        // Stale snapshot validation
        if ($snapshotId !== null && $snapshot['snapshot_id'] !== $snapshotId) {
            throw new RuntimeException("The selection snapshot is outdated or does not match current filter criteria. Please review the updated selection before dispatching.");
        }

        // If explicitInvoiceIds provided (e.g. current page mode), restrict to those IDs
        if ($explicitInvoiceIds !== null) {
            $explicitMap = array_flip($explicitInvoiceIds);
            $snapshot['eligible_recipients'] = array_values(array_filter(
                $snapshot['eligible_recipients'],
                fn($r) => isset($explicitMap[$r['invoice_id']])
            ));
            $snapshot['eligible_invoice_ids'] = array_values(array_intersect($snapshot['eligible_invoice_ids'], $explicitInvoiceIds));
            $snapshot['total_eligible_recipients'] = count($snapshot['eligible_recipients']);
            $snapshot['total_eligible_invoices'] = count($snapshot['eligible_invoice_ids']);
        }

        if (empty($snapshot['eligible_invoice_ids'])) {
            return [
                'batch_id' => null,
                'status' => 'empty',
                'eligible_count' => 0,
                'message' => 'No eligible invoices were found for delivery. All matching records may already be submitted, delivered, or pending verification.',
            ];
        }

        // Validate target grade filter vs recipients to ensure no unrelated families enter selection
        if (!empty($filters['grade'])) {
            $targetGrade = $filters['grade'];
            $matchingInvoices = DB::table('invoice_items as ii')
                ->join('enrollments as e', 'e.id', '=', 'ii.enrollment_id')
                ->whereIn('ii.invoice_id', $snapshot['eligible_invoice_ids'])
                ->where('e.grade_label', $targetGrade)
                ->distinct()
                ->pluck('ii.invoice_id')
                ->all();

            $unrelated = array_diff($snapshot['eligible_invoice_ids'], $matchingInvoices);
            if (!empty($unrelated)) {
                throw new RuntimeException("Unrelated families detected in selection. Every selected invoice must contain students in target grade {$targetGrade}.");
            }
        }

        // Validate grade confirmation
        // If the mandatory explicit acknowledgment is accepted:
        // "I have reviewed the selected families, their included students and grades, and approve this delivery batch."
        // Then all grades in snapshot['all_grades'] (including legitimate sibling grades) are acknowledged!
        $missingGrades = array_diff($snapshot['all_grades'], $confirmedGrades);
        if ($snapshot['all_grades'] && $missingGrades) {
            $targetGrade = $filters['grade'] ?? null;
            $targetConfirmed = $targetGrade ? in_array($targetGrade, $confirmedGrades, true) : false;
            // If the user acknowledged the batch (reviewConfirmed=true) OR the target grade is confirmed,
            // legitimate sibling grades belonging to the same families do not block delivery
            if (!$reviewConfirmed && !$targetConfirmed) {
                throw new RuntimeException("Confirm every grade included in the selected invoices: " . implode(', ', $missingGrades));
            }
        }

        // Generate unique batch ID
        $batchId = 'BATCH-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
        $chunkSize = max(1, (int)config('services.n8n.batch_chunk_size', 50));
        $totalRecipients = count($snapshot['eligible_recipients']);
        $chunksTotal = max(1, (int)ceil($totalRecipients / $chunkSize));

        $batchRecordId = null;

        DB::transaction(function () use (
            $batchId, $webhookType, $eventType, $billingPeriod, $channels, $filters,
            $totalRecipients, $chunksTotal, $chunkSize, $user, $snapshot, $channelsStr, &$batchRecordId
        ) {
            // Insert notification batch record
            $batchRecordId = DB::table('notification_batches')->insertGetId([
                'batch_id' => $batchId,
                'webhook_type' => $webhookType,
                'event_type' => $eventType,
                'billing_period' => $billingPeriod ?: ($snapshot['filters']['period'] ?? null),
                'channels' => json_encode($channels),
                'filter_criteria' => json_encode($filters),
                'status' => 'queued',
                'total_recipients' => $totalRecipients,
                'total_invoices' => $snapshot['total_eligible_invoices'],
                'chunks_total' => $chunksTotal,
                'chunks_sent' => 0,
                'approved_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Mark superseded invoices
            if (!empty($snapshot['superseded_invoice_ids'])) {
                foreach ($snapshot['superseded_invoice_ids'] as $supId) {
                    $latestId = $snapshot['eligible_invoice_ids'][0] ?? null;
                    DB::table('notifications')
                        ->where('invoice_id', $supId)
                        ->where('kind', $webhookType)
                        ->update([
                            'is_superseded' => true,
                            'superseded_by_invoice_id' => $latestId,
                            'updated_at' => now(),
                        ]);
                }
            }

            // Save or update recipient notification records
            foreach ($snapshot['eligible_recipients'] as $idx => $recipient) {
                $invId = $recipient['invoice_id'];
                $chunkIndex = (int)floor($idx / $chunkSize) + 1;

                $existing = DB::table('notifications')
                    ->where('invoice_id', $invId)
                    ->where('channel', $channelsStr)
                    ->where('kind', $webhookType)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    // Update existing notification to queued with new batch_id and chunk_index
                    DB::table('notifications')->where('id', $existing->id)->update([
                        'status' => 'queued',
                        'batch_id' => $batchId,
                        'chunk_index' => $chunkIndex,
                        'error' => null,
                        'approved_by' => $user->id,
                        'updated_at' => now(),
                    ]);
                } else {
                    $idempotencyKey = "inv-{$invId}-{$webhookType}-" . hash('sha256', "{$invId}-{$webhookType}-{$channelsStr}-" . now()->toDateString());
                    DB::table('notifications')->insert([
                        'invoice_id' => $invId,
                        'channel' => $channelsStr,
                        'kind' => $webhookType,
                        'webhook_type' => $webhookType,
                        'event_type' => "invoice.{$webhookType}",
                        'channels' => json_encode($channels),
                        'idempotency_key' => $idempotencyKey,
                        'event_id' => (string)Str::uuid(),
                        'batch_id' => $batchId,
                        'chunk_index' => $chunkIndex,
                        'status' => 'queued',
                        'approved_by' => $user->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('invoices')->where('id', $invId)->update(['approved_to_send' => true]);
            }
        });

        // Dispatch background job AFTER transaction has successfully committed
        DispatchBatchToN8n::dispatch($batchId)->onQueue('notifications');

        return [
            'batch_id' => $batchId,
            'status' => 'queued',
            'eligible_count' => $totalRecipients,
            'chunks_total' => $chunksTotal,
            'message' => "Batch queued successfully: {$totalRecipients} eligible recipients. n8n submission is pending. (Batch ID: {$batchId})",
        ];
    }

    /**
     * Retry only failed recipients of a previous batch.
     */
    public function retryFailedBatchRecipients(string $batchId, object $user): array
    {
        $batch = DB::table('notification_batches')->where('batch_id', $batchId)->first();
        if (!$batch) {
            throw new RuntimeException("Batch {$batchId} not found.");
        }

        $failedNotifications = DB::table('notifications')
            ->where('batch_id', $batchId)
            ->where('status', 'failed')
            ->get();

        if ($failedNotifications->isEmpty()) {
            return [
                'batch_id' => $batchId,
                'retried_count' => 0,
                'message' => 'No failed notifications found in this batch to retry.',
            ];
        }

        $channels = !empty($batch->channels) ? json_decode($batch->channels, true) : ['whatsapp'];

        // Re-evaluate eligibility and update to queued
        $retriedCount = 0;
        foreach ($failedNotifications as $notif) {
            $invoice = DB::table('invoices')->where('id', $notif->invoice_id)->first();
            $family = $invoice ? DB::table('families')->where('id', $invoice->family_id)->first() : null;
            $failureReason = WebhookSettingService::evaluateContactEligibility($family, $channels);

            if ($failureReason !== null) {
                DB::table('notifications')->where('id', $notif->id)->update([
                    'status' => 'blocked',
                    'error' => $failureReason,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('notifications')->where('id', $notif->id)->update([
                    'status' => 'queued',
                    'error' => null,
                    'updated_at' => now(),
                ]);
                $retriedCount++;
            }
        }

        if ($retriedCount > 0) {
            // Update batch status to queued and dispatch
            DB::table('notification_batches')->where('id', $batch->id)->update([
                'status' => 'queued',
                'error' => null,
                'updated_at' => now(),
            ]);

            DispatchBatchToN8n::dispatch($batchId)->onQueue('notifications');
        }

        return [
            'batch_id' => $batchId,
            'retried_count' => $retriedCount,
            'message' => "Retried {$retriedCount} failed recipient(s) in Batch {$batchId}.",
        ];
    }
}
