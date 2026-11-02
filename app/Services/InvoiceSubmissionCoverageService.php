<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Derives coverage from retained submissions; never changes notification or ledger records. */
class InvoiceSubmissionCoverageService
{
    public function forPeriod(string $period, object $user): array
    {
        $query = DB::table('notifications as n')
            ->join('invoices as i', 'i.id', '=', 'n.invoice_id')
            ->leftJoin('billing_runs as b', 'b.id', '=', 'i.billing_run_id')
            ->leftJoin('notification_batches as nb', 'nb.batch_id', '=', 'n.batch_id')
            ->where(function ($q) {
                $q->where('n.webhook_type', 'monthly')->orWhere('n.kind', 'monthly');
            })
            ->where(function ($q) {
                $q->whereIn('n.status', ['submitted', 'delivered', 'read', 'queued', 'processing'])
                    ->orWhere(function ($accepted) {
                        $accepted->whereNotNull('n.submitted_at')->whereIn('n.response_code', [200, 202]);
                    });
            });
        if (!in_array($user->role, ['super_admin', 'finance_admin'], true)) {
            $query->where('i.campus_id', $user->campus_id);
            if (!empty($user->department_id)) {
                $query->where('i.department_id', $user->department_id);
            }
        }
        $rows = $query->select('n.id', 'n.invoice_id', 'n.batch_id', 'n.chunk_index', 'n.status',
            'n.submitted_at', 'n.response_code', 'n.provider_message_id', 'n.delivered_at', 'n.channel',
            'i.family_id', 'b.period_start', 'nb.billing_period as campaign')->orderBy('n.id')->get();
        $families = [];
        // Payloads are full chunks repeated on each notification. Decode one at a time,
        // and match the exact notification entry; never attribute a sibling's entry.
        foreach ($rows->groupBy(fn ($n) => $n->batch_id === null ? 'single:'.$n->id : $n->batch_id.':'.$n->chunk_index) as $chunk) {
            $payload = $this->decodePayload(DB::table('notifications')->where('id', $chunk->first()->id)->value('payload'));
            foreach ($chunk as $row) {
                $entry = isset($payload['invoices']) || (int) $row->id === (int) $chunk->first()->id
                    ? $this->entryForNotification($payload, $row) : null;
                if ($entry === null && (int) $row->id !== (int) $chunk->first()->id) {
                    $ownPayload = $this->decodePayload(DB::table('notifications')->where('id', $row->id)->value('payload'));
                    $entry = $this->entryForNotification($ownPayload, $row);
                }
                $invoiceMonth = substr((string) $row->period_start, 0, 7);
                $payloadMonth = substr((string) ($entry['invoice']['billing_period'] ?? ''), 0, 7);
                $explicit = false;
                foreach (array_merge($entry['invoice']['dues_by_month'] ?? [], $entry['invoice']['later_dues_by_month'] ?? []) as $due) {
                    if (($due['month'] ?? '') === $period && (int) ($due['dues_minor'] ?? 0) > 0) {
                        $explicit = true;
                    }
                }
                if ($invoiceMonth !== $period && $payloadMonth !== $period && !$explicit) {
                    continue;
                }
                $accepted = in_array($row->status, ['submitted', 'delivered', 'read'], true)
                    || ($row->submitted_at !== null && in_array((int) $row->response_code, [200, 202], true));
                // Current invoice ownership follows family merges, even if the payload retains an old ID.
                $fid = (int) $row->family_id;
                $family = $families[$fid] ?? ['accepted' => false, 'earlier' => false, 'specific' => false,
                    'confirmed' => false, 'pending' => false, 'notification_ids' => []];
                $family['accepted'] = $family['accepted'] || $accepted;
                $campaign = substr((string) ($row->campaign ?: ($entry['invoice']['campaign_period'] ?? $invoiceMonth)), 0, 7);
                $family['earlier'] = $family['earlier'] || ($accepted && $explicit && $campaign < $period);
                $family['specific'] = $family['specific'] || ($accepted && ($invoiceMonth === $period || $payloadMonth === $period));
                $family['confirmed'] = $family['confirmed'] || ($accepted
                    && in_array($row->status, ['delivered', 'read'], true) && !empty($row->provider_message_id)
                    && in_array('whatsapp', explode(',', (string) $row->channel), true));
                $family['pending'] = $family['pending'] || in_array($row->status, ['queued', 'processing'], true);
                $family['notification_ids'][] = (int) $row->id;
                $families[$fid] = $family;
            }
        }
        return $families;
    }

    private function decodePayload(?string $raw): array
    {
        $decoded = json_decode($raw ?? '', true);
        return is_array($decoded) ? $decoded : [];
    }

    private function entryForNotification(array $payload, object $row): ?array
    {
        if (isset($payload['invoices']) && is_array($payload['invoices'])) {
            foreach ($payload['invoices'] as $entry) {
                if (is_array($entry) && (int) ($entry['notification_id'] ?? 0) === (int) $row->id) {
                    return $entry;
                }
            }
            return null;
        }
        // Older individual notification payloads belong to this row only.
        return isset($payload['invoice']) ? $payload : null;
    }

    public function summarize(string $period, object $user, ?array $familyIds = null): array
    {
        $families = $this->forPeriod($period, $user);
        if ($familyIds !== null) {
            $families = array_intersect_key($families, array_fill_keys($familyIds, true));
        }
        $count = fn (string $key) => count(array_filter($families, fn ($f) => $f[$key]));
        return ['earlier_accepted_families' => $count('earlier'), 'specific_accepted_families' => $count('specific'),
            'covered_families' => $count('accepted'), 'confirmed_families' => $count('confirmed'),
            'overlap_families' => count(array_filter($families, fn ($f) => $f['earlier'] && $f['specific']))];
    }

    public function assertInitialAllowed(int $familyId, string $period, object $user): void
    {
        if (isset($this->forPeriod($period, $user)[$familyId])) {
            throw new RuntimeException('This family already has a submission covering '.$period.' dues or a pending initial submission. Review a separately authorized reminder instead.');
        }
    }
}
