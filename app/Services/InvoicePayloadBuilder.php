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

        // 1. Line items and students breakdown
        $rawItems = DB::table('invoice_items as ii')
            ->leftJoin('students as s', 's.id', '=', 'ii.student_id')
            ->leftJoin('enrollments as e', 'e.id', '=', 'ii.enrollment_id')
            ->where('ii.invoice_id', $invoice->id)
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

        $studentsMap = [];
        $totalDiscountMinor = 0;

        foreach ($rawItems as $item) {
            $totalDiscountMinor += (int)$item->discount_minor;
            $sId = (int)($item->student_id ?: 0);

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
                'item_id' => (int)$item->item_id,
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

        // 4. Payment status
        $totalMinor = (int)$invoice->total_minor;
        $paidMinor = (int)$invoice->paid_minor;
        $remainingMinor = max(0, $totalMinor - $paidMinor);

        $paymentStatus = 'unpaid';
        if ($totalMinor > 0 && $paidMinor >= $totalMinor) {
            $paymentStatus = 'paid';
        } elseif ($paidMinor > 0) {
            $paymentStatus = 'partial';
        }

        // Multi-currency breakdown for family if applicable
        $familyCurrencies = DB::table('invoices as fi')
            ->where('fi.family_id', $family->id)
            ->whereNotIn('fi.status', ['void', 'voided', 'paid'])
            ->whereRaw('fi.total_minor > fi.paid_minor')
            ->select('fi.currency', DB::raw('SUM(fi.total_minor - fi.paid_minor) as outstanding_minor'))
            ->groupBy('fi.currency')
            ->get();

        $currenciesBreakdown = [];
        foreach ($familyCurrencies as $fc) {
            $currenciesBreakdown[] = [
                'currency' => $fc->currency,
                'amount' => Money::format($fc->outstanding_minor),
                'amount_minor' => (int)$fc->outstanding_minor,
            ];
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

        // 6. Signed PDF Attachment
        $pdfService = app(\App\Services\PdfAccessService::class);
        $pdfUrl = $pdfService->getInvoicePdfUrl((int)$invoice->id);
        $consolidatedChallanUrl = $family ? $pdfService->getFamilyChallanPdfUrl((int)$family->id) : null;

        $payload = [
            'event' => $eventType,
            'event_type' => $eventType,
            'event_id' => $notification->event_id ?: (string)Str::uuid(),
            'invoice_id' => (int)$invoice->id,
            'notification_id' => (int)$notification->id,
            'idempotency_key' => $notification->idempotency_key ?: "inv-{$invoice->id}-" . Str::random(16),
            'timestamp' => now()->toIso8601String(),
            'source_system' => 'IQRA Virtual School',
            'requested_channels' => $requestedChannels,

            'invoice' => [
                'invoice_number' => $invoice->invoice_number,
                'invoice_status' => $invoice->status,
                'invoice_date' => $invoice->issued_on,
                'due_date' => $invoice->due_date,
                'billing_period' => $billingRun?->period_start ? substr($billingRun->period_start, 0, 7) : null,
                'invoice_type' => $billingRun?->frequency ?? 'monthly',
                'original_invoice_reference' => $lineageRef ?: null,
                'currency' => $invoice->currency,
                'total_amount' => Money::format($totalMinor),
                'discount_amount' => Money::format($totalDiscountMinor),
                'amount_already_paid' => Money::format($paidMinor),
                'remaining_amount' => Money::format($remainingMinor),
                'payment_status' => $paymentStatus,
                'is_multi_currency' => ($familyCurrencies->count() > 1),
                'currencies_breakdown' => $currenciesBreakdown,
            ],

            'family' => [
                'family_id' => (int)$family->id,
                'family_code' => $family->family_code ?: 'Unnumbered family #' . $family->id,
                'guardian_name' => $guardianName,
                'whatsapp_number' => $family->whatsapp,
                'email_address' => $family->email,
                'contact_verified' => (bool)$family->contact_verified,
                'messaging_opt_in' => (bool)$family->messaging_opt_in,
                'campus' => $campus?->name ?? 'Main Campus',
                'department' => $department?->name ?? 'School',
            ],

            'students' => $students,

            'pdf_attachment' => [
                'pdf_url' => $pdfUrl,
                'consolidated_challan_url' => $consolidatedChallanUrl,
                'suggested_filename' => "IQRA_Invoice_{$invoice->invoice_number}.pdf",
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
