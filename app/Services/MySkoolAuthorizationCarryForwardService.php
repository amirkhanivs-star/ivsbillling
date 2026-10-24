<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Services\WebhookSettingService;

class MySkoolAuthorizationCarryForwardService
{
    /**
     * Standard international phone format (E.164 without spaces/dashes).
     * e.g. +966500000000, +971500000000, +923000000000
     */
    public const VALID_PHONE_REGEX = '/^\+[1-9][0-9]{7,14}$/';

    /**
     * Generate preview of eligible and excluded families and invoice impact.
     */
    public function preview(): array
    {
        $allFamilies = DB::table('families')->get();
        $myskoolImportDate = '2026-10-23 11:12:21';

        // Check if bulk authorization was already executed
        $lastAudit = DB::table('audit_logs')
            ->where('action', 'myskool_whatsapp_authorizations_carried_forward')
            ->latest('id')
            ->first();

        // Check for any recorded withdrawals of consent / opt-outs in audit_logs
        $revokedFamilyIds = DB::table('audit_logs')
            ->where('subject_type', 'family')
            ->where(function ($q) {
                $q->where('action', 'like', '%opt_out%')
                  ->orWhere('action', 'like', '%revoke%')
                  ->orWhere('action', 'like', '%consent_withdrawn%');
            })
            ->pluck('subject_id')
            ->map(fn($id) => (int)$id)
            ->all();

        $eligible = [];
        $alreadyAuthorized = [];
        $excluded = [
            'missing_whatsapp' => [],
            'invalid_whatsapp' => [],
            'test_or_new_admission' => [],
            'consent_withdrawn' => [],
        ];

        foreach ($allFamilies as $family) {
            $famId = (int)$family->id;

            // 1. Check if family is from approved MySkool import dataset
            $isMySkoolFamily = ($family->created_at === $myskoolImportDate);
            if (!$isMySkoolFamily || $family->guardian_name === 'test' || strtolower($family->family_code ?? '') === 'f01') {
                $excluded['test_or_new_admission'][] = [
                    'id' => $family->id,
                    'family_code' => $family->family_code,
                    'guardian_name' => $family->guardian_name,
                    'whatsapp' => $family->whatsapp,
                    'reason' => 'Manually created test family or post-import new admission (not part of MySkool import)',
                ];
                continue;
            }

            // 2. Check for recorded withdrawal of consent
            if (in_array($famId, $revokedFamilyIds, true)) {
                $excluded['consent_withdrawn'][] = [
                    'id' => $family->id,
                    'family_code' => $family->family_code,
                    'guardian_name' => $family->guardian_name,
                    'whatsapp' => $family->whatsapp,
                    'reason' => 'Recorded withdrawal of consent / opt-out',
                ];
                continue;
            }

            // 3. Check WhatsApp presence
            $phone = trim((string)($family->whatsapp ?? ''));
            if ($phone === '') {
                $excluded['missing_whatsapp'][] = [
                    'id' => $family->id,
                    'family_code' => $family->family_code,
                    'guardian_name' => $family->guardian_name,
                    'reason' => 'WhatsApp number missing in family record',
                ];
                continue;
            }

            // 4. Validate WhatsApp format
            if (!preg_match(self::VALID_PHONE_REGEX, $phone)) {
                $excluded['invalid_whatsapp'][] = [
                    'id' => $family->id,
                    'family_code' => $family->family_code,
                    'guardian_name' => $family->guardian_name,
                    'whatsapp' => $phone,
                    'reason' => 'Invalid WhatsApp format (missing international + prefix or invalid digits)',
                ];
                continue;
            }

            // 5. Eligible MySkool family
            if ($family->contact_verified && $family->messaging_opt_in) {
                $alreadyAuthorized[] = $famId;
            } else {
                $eligible[] = $famId;
            }
        }

        // Calculate before and after invoice impact
        $invoices = DB::table('invoices as i')
            ->leftJoin('families as f', 'f.id', '=', 'i.family_id')
            ->select('i.id', 'i.family_id', 'f.whatsapp', 'f.contact_verified', 'f.messaging_opt_in', 'f.whatsapp_verified', 'f.email_verified', 'f.email')
            ->get();

        $beforeEligibleInvoices = 0;
        $beforeBlockedInvoices = 0;
        $projectedEligibleInvoices = 0;
        $projectedBlockedInvoices = 0;

        $allEligibleSet = array_flip(array_merge($eligible, $alreadyAuthorized));

        foreach ($invoices as $inv) {
            $isEligibleNow = WebhookSettingService::evaluateContactEligibility($inv, ['whatsapp']) === null;
            if ($isEligibleNow) {
                $beforeEligibleInvoices++;
            } else {
                $beforeBlockedInvoices++;
            }

            // Projected state: if family is in eligible set, whatsapp is verified & opted in
            $projected = clone $inv;
            if (isset($allEligibleSet[(int)$inv->family_id])) {
                $projected->contact_verified = 1;
                $projected->whatsapp_verified = 1;
                $projected->messaging_opt_in = 1;
                $projected->email_verified = 0; // email remains unverified
            }

            $isEligibleProj = WebhookSettingService::evaluateContactEligibility($projected, ['whatsapp']) === null;
            if ($isEligibleProj) {
                $projectedEligibleInvoices++;
            } else {
                $projectedBlockedInvoices++;
            }
        }

        return [
            'total_families' => $allFamilies->count(),
            'eligible_to_authorize_count' => count($eligible),
            'already_authorized_count' => count($alreadyAuthorized),
            'total_eligible_count' => count($eligible) + count($alreadyAuthorized),
            'eligible_ids' => $eligible,
            'excluded_counts' => [
                'total' => count($excluded['missing_whatsapp']) + count($excluded['invalid_whatsapp']) + count($excluded['test_or_new_admission']) + count($excluded['consent_withdrawn']),
                'missing_whatsapp' => count($excluded['missing_whatsapp']),
                'invalid_whatsapp' => count($excluded['invalid_whatsapp']),
                'test_or_new_admission' => count($excluded['test_or_new_admission']),
                'consent_withdrawn' => count($excluded['consent_withdrawn']),
            ],
            'excluded_samples' => [
                'missing_whatsapp' => array_slice($excluded['missing_whatsapp'], 0, 5),
                'invalid_whatsapp' => $excluded['invalid_whatsapp'],
                'test_or_new_admission' => $excluded['test_or_new_admission'],
                'consent_withdrawn' => $excluded['consent_withdrawn'],
            ],
            'invoice_impact' => [
                'total_invoices' => $invoices->count(),
                'before_eligible' => $beforeEligibleInvoices,
                'before_blocked' => $beforeBlockedInvoices,
                'after_eligible' => $projectedEligibleInvoices,
                'after_blocked' => $projectedBlockedInvoices,
            ],
            'last_execution' => $lastAudit ? [
                'timestamp' => $lastAudit->created_at,
                'actor_id' => $lastAudit->actor_id,
                'metadata' => json_decode($lastAudit->metadata ?? '{}', true),
            ] : null,
        ];
    }

    /**
     * Execute the one-time bulk carry forward authorization.
     */
    public function execute(int $adminUserId, ?string $notes = null): array
    {
        $preview = $this->preview();
        $eligibleIds = $preview['eligible_ids'];
        $now = now();

        $updatedCount = 0;

        DB::transaction(function () use ($eligibleIds, $preview, $adminUserId, $notes, $now, &$updatedCount) {
            if (!empty($eligibleIds)) {
                $updatedCount = DB::table('families')
                    ->whereIn('id', $eligibleIds)
                    ->update([
                        'contact_verified' => true,
                        'whatsapp_verified' => true,
                        'messaging_opt_in' => true,
                        'email_verified' => false, // Carrying forward WhatsApp does NOT grant email verification
                        'updated_at' => $now,
                    ]);
            }

            // Record comprehensive audit log entry
            DB::table('audit_logs')->insert([
                'actor_id' => $adminUserId,
                'action' => 'myskool_whatsapp_authorizations_carried_forward',
                'subject_type' => 'system',
                'subject_id' => null,
                'metadata' => json_encode([
                    'source' => 'Previous MySkool verification and messaging consent',
                    'authorized_count' => $updatedCount,
                    'already_authorized_count' => $preview['already_authorized_count'],
                    'total_eligible_families' => $preview['total_eligible_count'],
                    'excluded_count' => $preview['excluded_counts']['total'],
                    'excluded_reasons' => $preview['excluded_counts'],
                    'invoice_impact' => [
                        'before_eligible' => $preview['invoice_impact']['before_eligible'],
                        'after_eligible' => $preview['invoice_impact']['after_eligible'],
                        'newly_eligible_invoices' => $preview['invoice_impact']['after_eligible'] - $preview['invoice_impact']['before_eligible'],
                    ],
                    'policy_statement' => 'School administration confirms parent WhatsApp numbers were verified and fee messaging consent agreed in MySkool. Authorizations carried forward to IQRA portal.',
                    'notes' => $notes ?: 'Carried forward pre-existing MySkool parent WhatsApp authorizations.',
                    'timestamp' => $now->toIso8601String(),
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        // Recalculate post-execution statistics
        $postPreview = $this->preview();

        return [
            'success' => true,
            'authorized_count' => $updatedCount,
            'already_authorized_count' => $preview['already_authorized_count'],
            'total_eligible_count' => $preview['total_eligible_count'],
            'excluded_count' => $preview['excluded_counts']['total'],
            'excluded_breakdown' => $preview['excluded_counts'],
            'invoice_impact' => [
                'before_eligible' => $preview['invoice_impact']['before_eligible'],
                'after_eligible' => $postPreview['invoice_impact']['before_eligible'],
                'remaining_blocked' => $postPreview['invoice_impact']['before_blocked'],
            ],
            'timestamp' => $now->toIso8601String(),
        ];
    }
}
