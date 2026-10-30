<?php

namespace App\Services;

use App\Services\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class InvoicePayloadBuilder
{
    /**
     * Build the structured JSON payload for an invoice notification event.
     */
    public function build(object $notification, object $invoice, ?string $eventType = null): array
    {
        $eventType = $eventType ?: ($notification->event_type ?? ($notification->kind === 'paid' ? 'invoice.paid' : 'invoice.monthly'));

        $billingRun = DB::table('billing_runs')->where('id', $invoice->billing_run_id)->first();
        $family = DB::table('families')->where('id', $invoice->family_id)->first();
        $campus = DB::table('campuses')->where('id', $invoice->campus_id)->first();
        $department = DB::table('departments')->where('id', $invoice->department_id)->first();

        $isPaidNotification = ($eventType === 'invoice.paid');
        $campaignPeriod = $billingRun?->period_start ? substr($billingRun->period_start, 0, 7) : now()->format('Y-m');
        $calculatedAt = now()->toIso8601String();

        $consolidatedBuilder = app(ConsolidatedInvoiceBuilder::class);
        $familySummary = (!$isPaidNotification && $family)
            ? ($consolidatedBuilder->summarizeFamiliesOutstanding([(int) $family->id], $campaignPeriod, null)[(int) $family->id] ?? null)
            : null;
        $familyViewData = (!$isPaidNotification && $family)
            ? $consolidatedBuilder->buildFamilyViewData($family, null, true, (int) $invoice->id, $campaignPeriod, $invoice)
            : null;

        // 1. Line items and students breakdown
        $studentsMap = [];
        $totalDiscountMinor = 0;

        if (!$isPaidNotification && $familyViewData && ($familyViewData['totalItemsCount'] ?? 0) > 0) {
            foreach ($familyViewData['currencyGroups'] as $currCode => $cGroup) {
                foreach ($cGroup['items'] as $item) {
                    $itemDiscount = (int) ($item->discount_minor ?? 0) + (int) ($item->exemption_minor ?? 0);
                    $totalDiscountMinor += $itemDiscount;
                    $sId = (int) ($item->student_id ?: 0);

                    if (!isset($studentsMap[$sId])) {
                        $studentsMap[$sId] = [
                            'student_id' => $sId,
                            'name' => $item->student_name ?: 'Student #' . $sId,
                            'gr_number' => $item->gr_number ?: 'Not assigned',
                            'class_grade' => $item->grade_label ?: 'Not assigned',
                            'guardian_name' => GuardianNameResolver::forStudent($sId, $family),
                            'itemized_fee_particulars' => [],
                        ];
                    }

                    $itemNetMinor = (int) $item->net_minor;
                    $itemPaidMinor = (int) $item->paid_minor;
                    $itemRemainingMinor = max(0, $itemNetMinor - $itemPaidMinor);
                    $rawPeriod = !empty($item->period_start) ? $item->period_start : ($item->billing_period ?? null);

                    $studentsMap[$sId]['itemized_fee_particulars'][] = [
                        'item_id' => (int) $item->id,
                        'invoice_id' => (int) ($item->invoice_id ?? $invoice->id),
                        'invoice_number' => (string) ($item->invoice_number ?? $invoice->invoice_number),
                        'currency' => (string) ($item->currency ?? $currCode),
                        'particulars' => $item->description,
                        'period_key' => $item->period_key,
                        'billing_period' => $rawPeriod ? substr((string) $rawPeriod, 0, 7) : null,
                        'period_start' => $item->period_start,
                        'period_end' => $item->period_end,
                        'due_date' => $item->due_date,
                        'amount' => Money::format((int) ($item->amount_minor ?? $itemNetMinor)),
                        'discount' => Money::format($itemDiscount),
                        'net_amount' => Money::format($itemNetMinor),
                        'paid_amount' => Money::format($itemPaidMinor),
                        'remaining_amount' => Money::format($itemRemainingMinor),
                        'remaining_minor' => $itemRemainingMinor,
                    ];
                }
            }
        } else {
            $rawItems = DB::table('invoice_items as ii')
                ->leftJoin('students as s', 's.id', '=', 'ii.student_id')
                ->leftJoin('enrollments as e', 'e.id', '=', 'ii.enrollment_id')
                ->where('ii.invoice_id', $invoice->id)
                ->where('ii.status', '!=', 'voided')
                ->select(
                    'ii.id as item_id',
                    'ii.student_id',
                    'ii.description',
                    'ii.period_key',
                    'ii.period_start',
                    'ii.period_end',
                    'ii.amount_minor',
                    'ii.discount_minor',
                    'ii.net_minor',
                    'ii.paid_minor',
                    's.name as student_name',
                    's.gr_number',
                    'e.grade_label'
                )
                ->get();

            foreach ($rawItems as $item) {
                $totalDiscountMinor += (int) $item->discount_minor;
                $sId = (int) ($item->student_id ?: 0);

                if (!isset($studentsMap[$sId])) {
                    $studentsMap[$sId] = [
                        'student_id' => $sId,
                        'name' => $item->student_name ?: 'Student #' . $sId,
                        'gr_number' => $item->gr_number ?: 'Not assigned',
                        'class_grade' => $item->grade_label ?: 'Not assigned',
                        'guardian_name' => GuardianNameResolver::forStudent($sId, $family),
                        'itemized_fee_particulars' => [],
                    ];
                }

                $studentsMap[$sId]['itemized_fee_particulars'][] = [
                    'item_id' => (int) $item->item_id,
                    'particulars' => $item->description,
                    'period_key' => $item->period_key,
                    'billing_period' => $item->period_start ? substr($item->period_start, 0, 7) : null,
                    'period_start' => $item->period_start,
                    'period_end' => $item->period_end,
                    'amount' => Money::format($item->amount_minor),
                    'discount' => Money::format($item->discount_minor),
                    'net_amount' => Money::format($item->net_minor),
                    'paid_amount' => Money::format($item->paid_minor),
                ];
            }
        }

        $students = array_values($studentsMap);

        // 2. Guardian resolution
        $guardianName = $this->resolveGuardianName($family, $students);

        // 3. Requested channels
        $requestedChannels = [];
        if (!empty($notification->channels)) {
            $decoded = is_string($notification->channels) ? json_decode($notification->channels, true) : $notification->channels;
            if (is_array($decoded)) {
                $requestedChannels = array_values($decoded);
            }
        }
        if (empty($requestedChannels) && !empty($notification->channel)) {
            $requestedChannels = explode(',', $notification->channel);
        }

        // 4. Payment status & canonical consolidated totals
        $headerTotalMinor = (int) $invoice->total_minor;
        $headerPaidMinor = (int) $invoice->paid_minor;
        $headerRemainingMinor = max(0, $headerTotalMinor - $headerPaidMinor);

        $totalMinor = $headerTotalMinor;
        $paidMinor = $headerPaidMinor;
        $remainingMinor = $headerRemainingMinor;

        $currenciesBreakdown = [];
        $formattedCurrencyTotals = [];
        $isMultiCurrency = false;
        $primaryCurrency = strtoupper(trim((string) ($invoice->currency ?: 'SAR')));

        if (!$isPaidNotification && $familySummary !== null) {
            foreach ($familySummary['currencies_map'] ?? [] as $curr => $cSummary) {
                $cPayableMinor = (int) $cSummary['total_payable_minor'];
                $formattedCurrencyTotals[$curr] = $cSummary['formatted_total_payable'];
                $currenciesBreakdown[] = [
                    'currency' => $curr,
                    'amount' => Money::format($cPayableMinor),
                    'amount_minor' => $cPayableMinor,
                    'formatted_total_payable' => $cSummary['formatted_total_payable'],
                    'previous_dues_minor' => (int) $cSummary['previous_dues_minor'],
                    'previous_dues_amount' => Money::format((int) $cSummary['previous_dues_minor']),
                    'selected_month_minor' => (int) $cSummary['selected_month_minor'],
                    'selected_month_amount' => Money::format((int) $cSummary['selected_month_minor']),
                    'later_period_minor' => (int) $cSummary['later_period_minor'],
                    'later_period_amount' => Money::format((int) $cSummary['later_period_minor']),
                    'total_payable_minor' => $cPayableMinor,
                    'total_payable_amount' => Money::format($cPayableMinor),
                ];
            }

            $isMultiCurrency = (bool) ($familySummary['is_multi_currency'] ?? (count($currenciesBreakdown) > 1));

            if (!isset($familySummary['currencies_map'][$primaryCurrency]) && !empty($familySummary['currencies_map'])) {
                $primaryCurrency = (string) array_key_first($familySummary['currencies_map']);
            }

            if (isset($familySummary['currencies_map'][$primaryCurrency])) {
                $primarySummary = $familySummary['currencies_map'][$primaryCurrency];
                $primaryGroup = $familyViewData['currencyGroups'][$primaryCurrency] ?? null;
                $consolidatedPayableMinor = (int) $primarySummary['total_payable_minor'];
                $totalMinor = $consolidatedPayableMinor;
                $remainingMinor = $consolidatedPayableMinor;
                $paidMinor = $primaryGroup ? (int) $primaryGroup['total_paid_minor'] : $headerPaidMinor;
                $totalDiscountMinor = $primaryGroup ? (int) $primaryGroup['total_discount_minor'] : $totalDiscountMinor;
            } elseif (!empty($familySummary['has_data_integrity_issue']) || empty($familySummary['is_payable_verified'])) {
                $totalMinor = 0;
                $remainingMinor = 0;
            }
        } else {
            $familyCurrencies = DB::table('invoices as fi')
                ->where('fi.family_id', $family->id)
                ->whereNotIn('fi.status', ['void', 'voided', 'paid'])
                ->whereRaw('fi.total_minor > fi.paid_minor')
                ->select('fi.currency', DB::raw('SUM(fi.total_minor - fi.paid_minor) as outstanding_minor'))
                ->groupBy('fi.currency')
                ->get();

            foreach ($familyCurrencies as $fc) {
                $currenciesBreakdown[] = [
                    'currency' => $fc->currency,
                    'amount' => Money::format($fc->outstanding_minor),
                    'amount_minor' => (int) $fc->outstanding_minor,
                ];
            }
            $isMultiCurrency = ($familyCurrencies->count() > 1);
        }

        $paymentStatus = 'unpaid';
        if ($isPaidNotification || ($headerTotalMinor > 0 && $headerPaidMinor >= $headerTotalMinor && $remainingMinor === 0)) {
            $paymentStatus = 'paid';
        } elseif ($paidMinor > 0) {
            $paymentStatus = 'partial';
        }

        // 5. Lineage reference (if opening balance or external reference)
        $lineageRef = null;
        if (Schema::hasTable('qa_opening_balance_lineage')) {
            $lineage = DB::table('qa_opening_balance_lineage')
                ->where('invoice_id', $invoice->id)
                ->first();
            if ($lineage) {
                $lineageRef = "batch:{$lineage->batch_id}|row:{$lineage->source_row}|period:{$lineage->period_key}";
            }
        }

        // 6. Canonical PDF Attachment & School Details
        $pdfService = app(\App\Services\PdfAccessService::class);
        $invoicePdfUrl = $pdfService->getInvoicePdfUrl((int) $invoice->id);
        $consolidatedChallanUrl = $family ? $pdfService->getFamilyChallanPdfUrl((int) $family->id) : null;
        $primaryPdfUrl = (!$isPaidNotification && $consolidatedChallanUrl)
            ? $consolidatedChallanUrl
            : $invoicePdfUrl;

        $branding = $familyViewData['branding']
            ?? DB::table('department_branding')->where('department_id', $invoice->department_id ?: 1)->first();

        $resolvedDueDate = (!$isPaidNotification && !empty($familyViewData['dueDate']))
            ? $familyViewData['dueDate']
            : $invoice->due_date;
        $resolvedIssuedOn = (!$isPaidNotification && !empty($familyViewData['issuedOn']))
            ? $familyViewData['issuedOn']
            : $invoice->issued_on;

        $primaryCurrencySummary = (!$isPaidNotification && $familySummary && isset($familySummary['currencies_map'][$primaryCurrency]))
            ? $familySummary['currencies_map'][$primaryCurrency]
            : null;

        $payload = [
            'event' => $eventType,
            'event_type' => $eventType,
            'event_id' => ($notification->event_id ?? null) ?: (string) Str::uuid(),
            'invoice_id' => (int) $invoice->id,
            'notification_id' => (int) ($notification->id ?? 0),
            'idempotency_key' => ($notification->idempotency_key ?? null) ?: "inv-{$invoice->id}-" . Str::random(16),
            'timestamp' => $calculatedAt,
            'source_system' => 'IQRA Virtual School',
            'requested_channels' => $requestedChannels,

            'invoice' => [
                'invoice_number' => $invoice->invoice_number,
                'statement_number' => $familyViewData['statementNumber'] ?? null,
                'invoice_status' => $invoice->status,
                'invoice_date' => $resolvedIssuedOn,
                'due_date' => $resolvedDueDate,
                'billing_period' => $campaignPeriod,
                'campaign_period' => $campaignPeriod,
                'invoice_type' => $billingRun?->frequency ?? 'monthly',
                'original_invoice_reference' => $lineageRef ?: null,
                'currency' => $primaryCurrency,
                'total_amount' => Money::format($totalMinor),
                'discount_amount' => Money::format($totalDiscountMinor),
                'amount_already_paid' => Money::format($paidMinor),
                'remaining_amount' => Money::format($remainingMinor),
                'total_payable_amount' => Money::format($remainingMinor),
                'total_payable_minor' => $remainingMinor,
                'previous_dues_amount' => $primaryCurrencySummary ? Money::format((int) $primaryCurrencySummary['previous_dues_minor']) : '0.00',
                'previous_dues_minor' => $primaryCurrencySummary ? (int) $primaryCurrencySummary['previous_dues_minor'] : 0,
                'selected_month_amount' => $primaryCurrencySummary ? Money::format((int) $primaryCurrencySummary['selected_month_minor']) : Money::format($headerRemainingMinor),
                'selected_month_minor' => $primaryCurrencySummary ? (int) $primaryCurrencySummary['selected_month_minor'] : $headerRemainingMinor,
                'later_period_amount' => $primaryCurrencySummary ? Money::format((int) $primaryCurrencySummary['later_period_minor']) : '0.00',
                'later_period_minor' => $primaryCurrencySummary ? (int) $primaryCurrencySummary['later_period_minor'] : 0,
                'selected_invoice_total_amount' => Money::format($headerTotalMinor),
                'selected_invoice_remaining_amount' => Money::format($headerRemainingMinor),
                'payment_status' => $paymentStatus,
                'is_multi_currency' => $isMultiCurrency,
                'currencies_breakdown' => $currenciesBreakdown,
                'formatted_totals_by_currency' => $formattedCurrencyTotals,
                'dues_by_month' => $familySummary['dues_by_month'] ?? [],
                'later_dues_by_month' => $familySummary['later_dues_by_month'] ?? [],
                'has_data_integrity_issue' => (bool) ($familySummary['has_data_integrity_issue'] ?? false),
                'is_payable_verified' => (bool) ($familySummary['is_payable_verified'] ?? true),
                'data_integrity_status' => $familySummary['data_integrity_status'] ?? 'verified',
            ],

            'family' => [
                'family_id' => (int) $family->id,
                'family_code' => $family->family_code ?: 'Unnumbered family #' . $family->id,
                'guardian_name' => $guardianName,
                'whatsapp_number' => $family->whatsapp,
                'email_address' => $family->email,
                'contact_verified' => (bool) $family->contact_verified,
                'messaging_opt_in' => (bool) $family->messaging_opt_in,
                'campus' => $campus?->name ?? 'Main Campus',
                'department' => $department?->name ?? 'School',
            ],

            'students' => $students,

            'school_details' => [
                'school_name' => $branding?->display_name ?? $branding?->header_title ?? 'IQRA Virtual School',
                'department_name' => $department?->name ?? 'School',
                'campus_name' => $campus?->name ?? 'Main Campus',
                'contact_email' => $branding?->footer_email ?? $branding?->contact_email ?? 'acivs2021@gmail.com',
                'contact_phone' => $branding?->footer_phone ?? $branding?->contact_phone ?? '+92 325 524551',
                'whatsapp_support' => $branding?->footer_phone ?? $branding?->whatsapp_number ?? '+92 325 524551',
                'payment_instructions' => $branding?->payment_instructions ?? $branding?->footer_note ?? 'IQRA Virtual School billing portal. Please deposit fees to authorized bank accounts.',
            ],

            'pdf_attachment' => [
                'pdf_url' => $primaryPdfUrl,
                'consolidated_challan_url' => $consolidatedChallanUrl,
                'invoice_pdf_url' => $invoicePdfUrl,
                'document_type' => $isPaidNotification ? 'payment_receipt' : 'consolidated_fee_invoice',
                'template' => $isPaidNotification ? 'pdf.receipt' : 'pdf.consolidated-challan',
                'is_live_statement' => !$isPaidNotification,
                'is_immutable_snapshot' => false,
                'suggested_filename' => $isPaidNotification
                    ? "IQRA_Receipt_{$invoice->invoice_number}.pdf"
                    : "IQRA_Consolidated_Invoice_" . ($family->family_code ?: $family->id) . ".pdf",
            ],

            'statement_metadata' => [
                'statement_mode' => $isPaidNotification ? 'verified_payment_receipt' : 'live_consolidated_ledger',
                'is_live_statement' => !$isPaidNotification,
                'is_immutable_snapshot' => false,
                'calculated_at' => $calculatedAt,
                'campaign_billing_period' => $campaignPeriod,
                'dispatched_totals_by_currency' => $currenciesBreakdown,
                'live_statement_notice' => $isPaidNotification
                    ? 'Payment receipt reflects verified allocated payment record.'
                    : 'Public family consolidated PDF URL dynamically renders current outstanding ledger obligations when opened; dispatched_totals_by_currency records the exact verified balances calculated at preparation/dispatch time.',
            ],
        ];

        // 7. Additional fields for paid invoices
        if ($eventType === 'invoice.paid') {
            $allocations = DB::table('payment_allocations as pa')
                ->join('invoice_items as ii', 'ii.id', '=', 'pa.invoice_item_id')
                ->join('payments as p', 'p.id', '=', 'pa.payment_id')
                ->where('ii.invoice_id', $invoice->id)
                ->select(
                    'p.id as payment_id',
                    'p.receipt_number',
                    'p.received_on',
                    'p.note',
                    'pa.amount_minor'
                )
                ->orderBy('p.received_on')
                ->get();

            $paymentDates = $allocations->pluck('received_on')->unique()->values()->all();
            $receiptNumbers = $allocations->pluck('receipt_number')->unique()->values()->all();
            $paymentMethods = $allocations->map(fn($a) => $a->note ?: 'direct_receipt')->unique()->values()->all();

            $receiptPdfLinks = $allocations->pluck('receipt_number', 'payment_id')->map(function ($receiptNumber, $paymentId) use ($pdfService) {
                return [
                    'payment_id' => (int)$paymentId,
                    'receipt_number' => $receiptNumber,
                    'receipt_pdf_url' => $pdfService->getReceiptPdfUrl((int)$paymentId),
                ];
            })->values()->all();

            $primaryReceiptPdfUrl = !empty($receiptPdfLinks) ? $receiptPdfLinks[0]['receipt_pdf_url'] : null;

            if ($primaryReceiptPdfUrl) {
                $payload['pdf_attachment']['receipt_pdf_url'] = $primaryReceiptPdfUrl;
            }

            $payload['payment_details'] = [
                'actual_paid_amount' => Money::format($paidMinor),
                'payment_dates' => $paymentDates,
                'verified_receipt_numbers' => $receiptNumbers,
                'payment_methods' => $paymentMethods,
                'receipt_pdf_url' => $primaryReceiptPdfUrl,
                'related_receipt_pdf_links' => $receiptPdfLinks,
                'actual_remaining_balance' => Money::format($remainingMinor),
            ];
        }

        return $payload;
    }

    /**
     * Resolve guardian or father name for the family or student.
     */
    private function resolveGuardianName(?object $family, array $students = []): string
    {
        if (!$family) {
            return GuardianNameResolver::NOT_PROVIDED;
        }

        if (count($students) === 1 && !empty($students[0]['student_id'])) {
            return GuardianNameResolver::forStudent((int)$students[0]['student_id'], $family);
        }

        return GuardianNameResolver::forFamily($family);
    }
}
