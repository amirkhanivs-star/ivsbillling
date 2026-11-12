<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>MONTHLY FEE INVOICE</title>
<style>
  @page { size:A4 portrait; margin:10px 28px 28px; }
  * { box-sizing:border-box; }
  body { margin:0; color:#1e293b; font-family:DejaVu Sans,sans-serif; font-size:9pt; line-height:1.32; background:#ffffff; }

  /* School Logo and Header */
  .banner-wrap { width:100%; text-align:center; margin:0 0 2px; }
  .banner { display:inline-block; width:56%; max-height:68px; height:auto; vertical-align:top; }

  /* Monthly Fee Invoice Prominent Heading & Number */
  .header-title-block { text-align:center; margin:1px 0 4px; }
  .invoice-title { color:#982931; font-size:12.5pt; font-weight:bold; letter-spacing:.4px; text-transform:uppercase; margin:0 0 1px; }
  .invoice-number { color:#1e293b; font-size:10pt; font-weight:bold; margin:0 0 1px; }
  .invoice-issue-date { color:#475569; font-size:8pt; margin:0 0 3px; }

  .notice { border:1px solid #fecaca; background:#fff5f5; color:#982931; padding:3px 6px; font-size:8pt; margin:0 0 4px; text-align:center; }

  /* Compact Family Information Strip */
  .info-strip { width:100%; border-collapse:collapse; table-layout:fixed; margin:3px 0 6px; background:#ffffff; border:1px solid #e2e8f0; }
  .info-strip th { background:#f8fafc; color:#475569; font-size:7.5pt; font-weight:bold; text-transform:uppercase; letter-spacing:.3px; padding:4px 7px; border-bottom:1px solid #cbd5e1; text-align:left; }
  .info-strip td { padding:4.5px 7px; font-size:9pt; color:#1e293b; font-weight:bold; border-bottom:1px solid #e2e8f0; }
  .info-strip .due-val { color:#982931; }
  .info-strip .curr-month-tag { font-size:7.5pt; color:#166534; font-weight:normal; margin-left:3px; }
  .info-strip .text-right { text-align:right; }

  /* Section Title */
  .section-title { font-size:9pt; font-weight:bold; color:#982931; text-transform:uppercase; letter-spacing:.3px; margin:4px 0 3px; padding-bottom:2px; border-bottom:1.5px solid #982931; }

  /* 4-Column Continuous Outstanding Fee Breakdown Table */
  .fees-table { width:100%; border-collapse:collapse; table-layout:fixed; font-size:8.5pt; margin-bottom:3px; }
  .fees-table thead { display:table-header-group; }
  .fees-table th { background:#f1f5f9; color:#334155; font-size:8pt; font-weight:bold; text-transform:uppercase; letter-spacing:.3px; padding:4.5px 6px; border-top:1px solid #cbd5e1; border-bottom:1.5px solid #94a3b8; text-align:left; }
  .fees-table th.right { text-align:right; }
  .fees-table td { padding:4px 6px; border-bottom:1px solid #e2e8f0; vertical-align:middle; color:#1e293b; font-size:8.5pt; }
  .fees-table .right { text-align:right; }
  .fees-table tr.fee-row { page-break-inside:avoid; }

  /* Student Group Heading Row */
  .fees-table tr.student-group-row { page-break-inside:avoid; page-break-after:avoid; }
  .fees-table .student-group-row td { background:#f8fafc; color:#0f172a; font-weight:bold; font-size:9pt; padding:4.5px 6px; border-top:1px solid #cbd5e1; border-bottom:1px solid #cbd5e1; }

  /* Subdued Fee Item Details */
  .fee-month-val { font-weight:bold; color:#1e293b; font-size:8.5pt; }
  .fee-desc-title { font-weight:bold; color:#1e293b; font-size:8.5pt; }
  .fee-desc-meta { font-size:7pt; color:#64748b; margin-top:1px; line-height:1.2; }
  .fee-status-badge { font-size:7pt; margin-top:1px; display:block; }
  .badge-overdue { color:#982931; font-weight:bold; }
  .badge-upcoming { color:#1d4ed8; font-weight:bold; }
  .fee-balance-val { font-weight:bold; color:#982931; white-space:nowrap; }

  /* Student Subtotal Row */
  .fees-table tr.student-subtotal-row { page-break-inside:avoid; }
  .fees-table .student-subtotal-row td { background:#fffbfb; color:#475569; font-weight:bold; font-size:8.5pt; border-top:1px solid #fecaca; border-bottom:1.5px solid #cbd5e1; padding:4px 6px; }
  .student-subtotal-label { color:#475569; font-weight:bold; }
  .student-subtotal-val { color:#982931; font-weight:bold; white-space:nowrap; }

  /* Total Outstanding Section */
  .total-outstanding-block { margin:5px 0 6px; page-break-inside:avoid; }
  .total-table { width:100%; border-collapse:collapse; table-layout:fixed; }
  .total-label-cell { text-align:right; padding:6px 10px; font-size:10.5pt; font-weight:bold; color:#1e293b; letter-spacing:.3px; width:72%; background:#fdf2f2; border:1px solid #fecaca; }
  .total-amount-cell { text-align:right; padding:6px 10px; font-size:12pt; font-weight:bold; color:#982931; width:28%; white-space:nowrap; background:#fff5f5; border:1px solid #fecaca; border-left:0; }

  /* Multi-currency Summary */
  .multicurr { margin-top:6px; width:100%; border-collapse:collapse; font-size:8pt; page-break-inside:avoid; }
  .multicurr td,.multicurr th { padding:4px 6px; border:1px solid #e5e7eb; }
  .multicurr th { background:#687381; color:#ffffff; text-align:left; }

  /* Payment Details Section */
  .settlement { margin-top:4px; }
  .payment-table { width:100%; border-collapse:collapse; table-layout:fixed; background:#ffffff; border:1px solid #e2e8f0; margin-bottom:4px; page-break-inside:avoid; }
  .payment-table td { vertical-align:top; }
  .bank-accounts-table { width:100%; border-collapse:collapse; table-layout:fixed; }
  .bank-col { vertical-align:top; font-size:8pt; line-height:1.28; }
  .bank-entity { font-weight:bold; color:#982931; font-size:8.5pt; margin-bottom:1px; }
  .bank-name { font-weight:bold; color:#1e293b; font-size:8pt; margin-bottom:2px; }
  .bank-field { font-size:7.5pt; color:#334155; margin-bottom:1px; }
  .bank-field strong { color:#1e293b; font-weight:600; }
  .bank-extra-notes { margin-top:4px; color:#64748b; font-size:7.5pt; }
  .bank-text { white-space:pre-line; word-wrap:break-word; font-size:8pt; line-height:1.3; color:#1e293b; }
  .bank-text strong { color:#982931; }

  .qr-cell { text-align:center; vertical-align:middle; background:#fafbfc; border-left:1px solid #e2e8f0; padding:4px; }
  .qr { max-width:100%; width:72px; height:auto; display:inline-block; }
  .qr-caption { font-size:7pt; color:#475569; font-weight:bold; margin-top:2px; }
  .qr-placeholder { font-size:7.5pt; color:#64748b; }

  /* Already Paid & Authorization Section */
  .settlement-lower { width:100%; border-collapse:collapse; table-layout:fixed; margin-top:3px; page-break-inside:avoid; }
  .settlement-lower td { border:0; padding:0; vertical-align:top; }
  .receipt-instruction-col { width:68%; padding-right:12px; }
  .auth-col { width:32%; text-align:center; vertical-align:middle; padding-left:10px; }

  .receipt-instruction-block { text-align:left; }
  .receipt-heading { font-weight:bold; color:#1e3a8a; font-size:8pt; margin-bottom:1px; text-transform:uppercase; letter-spacing:.3px; }
  .receipt-subtext { font-size:7.5pt; color:#1e293b; line-height:1.25; margin-bottom:2px; }
  .receipt-btn-wrap { margin:2px 0 3px; }
  .btn-upload { display:inline-block; background-color:#982931; color:#ffffff; font-size:7.8pt; font-weight:bold; text-decoration:none; padding:4px 10px; border-radius:3px; line-height:1.2; text-align:center; }
  .receipt-notice { font-size:7pt; color:#475569; line-height:1.22; margin-top:1px; }

  /* Authorized Signature and Official Stamp Section */
  .auth-signature { text-align:center; margin-bottom:2px; }
  .auth-signature img { width:72px; height:auto; display:inline-block; }
  .auth-stamp { text-align:center; margin-top:1px; }
  .auth-stamp img { width:62px; height:auto; display:inline-block; }

  /* Errors & Omissions Statement */
  .eoe-statement { margin-top:4px; font-size:7pt; line-height:1.25; color:#64748b; text-align:left; page-break-inside:avoid; }
  .eoe-statement strong { color:#334155; font-weight:bold; }

  .page-break { page-break-before:always; }
  .footer { position:fixed; bottom:-22px; left:-28px; right:-28px; height:18px; background:#ffffff; border-top:1px solid #982931; color:#475569; text-align:center; font-size:8pt; font-weight:normal; line-height:18px; padding:0 20px; box-sizing:border-box; }
</style>
</head>
<body>
@php
  $qrPath=public_path('assets/receipt-qr.jpg');
  $signPath=public_path('assets/receipt-sign.jpg');
  $stampPath=public_path('assets/fee-paid-stamp.png');
  $qrSrc=is_file($qrPath)?'data:image/jpeg;base64,'.base64_encode(file_get_contents($qrPath)):null;
  $signSrc=is_file($signPath)?'data:image/jpeg;base64,'.base64_encode(file_get_contents($signPath)):null;
  $stampSrc=is_file($stampPath)?'data:image/png;base64,'.base64_encode(file_get_contents($stampPath)):null;
  $payText=trim((string)($branding?->payment_instructions ?? ''));
  $hasSavedBankAccounts = (bool) preg_match('/\bIBAN\b|\b(?:A\/C|ACCOUNT)\s*(?:NUMBER|NO\.?|#)?\s*[:#]\s*\d{6,}/i', $payText);
  $footerPhone = '+92 325 524551';
  $footerEmail = 'acivs2021@gmail.com';
  $todayStr = \Carbon\Carbon::now()->toDateString();
@endphp

<div class="footer">IQRA VIRTUAL SCHOOL &bull; {{ $footerPhone }} &bull; {{ $footerEmail }}</div>

@forelse($currencyGroups as $currency => $cData)
  @if(!$loop->first)<div class="page-break"></div>@endif
  @if(!empty($banner))
    <div class="banner-wrap">
      <img class="banner" src="{{ $banner }}" alt="IQRA Virtual School">
    </div>
  @endif

  @php
    $allGroupItems = $mode === 'student'
      ? collect($cData['items'] ?? [])
      : collect($cData['students'] ?? [])->flatMap(fn($s) => $s['items'] ?? []);

    $cDueDate = $cData['due_date'] ?? ($dueDate ?? null);
    if (!$cDueDate && $allGroupItems->isNotEmpty()) {
      $cDueDate = $allGroupItems->pluck('due_date')->filter()->map(fn($d) => \Carbon\Carbon::parse($d)->toDateString())->sortDesc()->first();
    }
    $cDueDateFormatted = !empty($cDueDate) ? \Carbon\Carbon::parse($cDueDate)->format('d F Y') : 'N/A';

    $cIssueDate = $cData['issued_on'] ?? ($issuedOn ?? ($generatedAt ?? null));
    $cIssueDateFormatted = !empty($cIssueDate) ? \Carbon\Carbon::parse($cIssueDate)->format('d F Y') : 'N/A';

    $currentInvoiceId = $cData['current_invoice_id'] ?? null;
    $currentPeriodStart = $cData['current_period_start'] ?? (
      $allGroupItems->filter(fn($it) => (($it->billing_frequency ?? 'monthly') !== 'opening_balance') && !str_contains((string)($it->invoice_number ?? ''), '-OB-'))
        ->pluck('period_start')->filter()->map(fn($p) => \Carbon\Carbon::parse($p)->toDateString())->sortDesc()->first()
      ?? $allGroupItems->pluck('period_start')->filter()->map(fn($p) => \Carbon\Carbon::parse($p)->toDateString())->sortDesc()->first()
    );

    $historicalDueList = $cData['historical_due_dates'] ?? [];
    $upcomingDueList = $cData['upcoming_due_dates'] ?? [];
    if (empty($historicalDueList) && empty($upcomingDueList) && $allGroupItems->count() > 1) {
      $tmpHist = [];
      $tmpUpc = [];
      foreach ($allGroupItems as $gIt) {
        $gPeriod = !empty($gIt->period_start) ? \Carbon\Carbon::parse($gIt->period_start)->toDateString() : null;
        $gDue = !empty($gIt->due_date) ? \Carbon\Carbon::parse($gIt->due_date)->toDateString() : null;
        $gIsOb = (($gIt->billing_frequency ?? 'monthly') === 'opening_balance') || str_contains((string)($gIt->invoice_number ?? ''), '-OB-');
        $gIsCurrent = !$gIsOb && (
          ($currentInvoiceId !== null && isset($gIt->invoice_id) && (int)$gIt->invoice_id === (int)$currentInvoiceId)
          || ($currentPeriodStart !== null && $gPeriod === $currentPeriodStart)
        );
        if (!$gIsCurrent && $gDue) {
          $hKey = ($gIt->invoice_number ?? '') . '|' . ($gPeriod ?? '') . '|' . $gDue;
          $gIsLater = !$gIsOb && $currentPeriodStart !== null && $gPeriod !== null && $gPeriod > $currentPeriodStart;
          if ($gIsLater) {
            $tmpUpc[$hKey] = [
              'period_label' => $gPeriod ? \Carbon\Carbon::parse($gPeriod)->format('M-Y') : 'Later',
              'due_date_formatted' => \Carbon\Carbon::parse($gDue)->format('d F Y'),
              'is_overdue' => $gDue < $todayStr,
            ];
          } else {
            $tmpHist[$hKey] = [
              'period_label' => $gPeriod ? \Carbon\Carbon::parse($gPeriod)->format('M-Y') : 'Prior',
              'due_date_formatted' => \Carbon\Carbon::parse($gDue)->format('d F Y'),
              'is_opening_balance' => $gIsOb,
            ];
          }
        }
      }
      $historicalDueList = array_values($tmpHist);
      $upcomingDueList = array_values($tmpUpc);
    }

    // Derive deterministic Invoice Display Number (e.g. 001, 002, 003...)
    $rawInvNumber = $referenceInvoice['invoice_number']
      ?? ($allGroupItems->firstWhere('invoice_id', $currentInvoiceId)?->invoice_number ?? null)
      ?? ($allGroupItems->first()?->invoice_number ?? null);
    $invId = $referenceInvoice['id'] ?? $currentInvoiceId ?? ($allGroupItems->first()?->invoice_id ?? null);

    $displayInvoiceNumber = '001';
    if (!empty($rawInvNumber)) {
      $parts = explode('-', trim((string)$rawInvNumber));
      $pCount = count($parts);
      if ($pCount >= 2) {
        $lastPart = $parts[$pCount - 1];
        $prevPart = $parts[$pCount - 2];
        if (ctype_digit($lastPart) && strlen($lastPart) <= 2 && ctype_digit($prevPart) && strlen($prevPart) >= 3) {
          $displayInvoiceNumber = sprintf('%03d', (int)$prevPart) . '-' . $lastPart;
        } elseif (ctype_digit($lastPart)) {
          $displayInvoiceNumber = sprintf('%03d', (int)$lastPart);
        }
      } elseif (preg_match('/(\d+)/', (string)$rawInvNumber, $m)) {
        $displayInvoiceNumber = sprintf('%03d', (int)$m[1]);
      } else {
        $displayInvoiceNumber = (string)$rawInvNumber;
      }
    } elseif (!empty($invId)) {
      $displayInvoiceNumber = sprintf('%03d', (int)$invId);
    }

    // Genuinely fully paid status check:
    $groupBalanceMinor = (int)($cData['total_balance_minor'] ?? 0);
    $isFullyPaidInvoice = false;
    if ($groupBalanceMinor === 0 && !empty($invId)) {
      $isFullyPaidInvoice = class_exists(\App\Services\PaidInvoiceEligibility::class)
        ? \App\Services\PaidInvoiceEligibility::eligible((int)$invId)
        : false;
      if (!$isFullyPaidInvoice && !empty($referenceInvoice) && is_array($referenceInvoice)) {
        $refTotal = (int)($referenceInvoice['total_minor'] ?? $referenceInvoice['header_total_minor'] ?? 0);
        $refRemaining = (int)($referenceInvoice['remaining_minor'] ?? $referenceInvoice['header_remaining_minor'] ?? 0);
        $refStatus = strtolower((string)($referenceInvoice['raw_status'] ?? $referenceInvoice['status'] ?? ''));
        if ($refTotal > 0 && $refRemaining === 0 && in_array($refStatus, ['paid', 'settled'], true)) {
          $isFullyPaidInvoice = true;
        }
      }
    }
  @endphp

  <div class="header-title-block">
    <div class="invoice-title">MONTHLY FEE INVOICE</div>
    <div class="invoice-number">Invoice No: {{ $displayInvoiceNumber }}</div>
    <div class="invoice-issue-date">Issue Date: {{ $cIssueDateFormatted }}</div>
  </div>

  @if(count($currencyGroups) > 1)
    <div class="notice">
      This page contains <strong>{{ $currency }}</strong> only. Other currencies are separate and are not converted.
    </div>
  @endif

  <!-- Compact Family Information Strip -->
  <table class="info-strip">
    <thead>
      <tr>
        <th style="width:32%;">Guardian</th>
        <th style="width:20%;">Family Code</th>
        <th style="width:26%;">Payment Due</th>
        <th style="width:22%;" class="text-right">Students with Dues</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>{{ $parentDisplay['name'] ?? ($family->guardian_name ?? 'Not provided') }}</td>
        <td>{{ isset($family) ? ($family?->family_code ?: 'FAM-'.$family?->id) : ($student->family_code ?? 'Not assigned') }}</td>
        <td class="due-val">
          <!-- Due Date: -->{{ $cDueDateFormatted }}@if(!empty($historicalDueList) || !empty($upcomingDueList))<span class="curr-month-tag">(Current Month)</span>@endif
        </td>
        <td class="text-right">{{ $mode === 'student' ? 1 : count($cData['students'] ?? []) }}</td>
      </tr>
    </tbody>
  </table>

  <div class="section-title">OUTSTANDING FEE BREAKDOWN ({{ $currency }})</div>
  <table class="fees-table">
    <thead>
      <tr>
        <th style="width:18%;">Month</th>
        <th style="width:48%;">Particular / Description</th>
        <th style="width:17%;" class="right">Total Fee</th>
        <th style="width:17%;" class="right">To Pay</th>
      </tr>
    </thead>
    <tbody>
    @if($mode === 'student')
      <tr class="student-group-row">
        <td colspan="4">{{ $student->name ?? 'N/A' }} &middot; GR {{ $student->gr_number ?? 'N/A' }}@if(!empty($enrollment?->grade_label) && $enrollment?->grade_label !== 'Class not assigned') &middot; {{ $enrollment?->grade_label }}@endif</td>
      </tr>
      @foreach($cData['items'] as $item)
        @php
          $balance=max(0, (int)$item->net_minor - (int)$item->paid_minor);
          $itemPeriod = !empty($item->period_start) ? \Carbon\Carbon::parse($item->period_start)->toDateString() : null;
          $itemPeriodRaw = !empty($item->period_start) ? \Carbon\Carbon::parse($item->period_start)->format('M-Y') : 'N/A';
          $itemPeriodDisplay = !empty($item->period_start) ? \Carbon\Carbon::parse($item->period_start)->format('M Y') : 'N/A';
          $itemDueRaw = !empty($item->due_date) ? \Carbon\Carbon::parse($item->due_date)->toDateString() : null;
          $isItemOb = (($item->billing_frequency ?? 'monthly') === 'opening_balance') || str_contains((string)($item->invoice_number ?? ''), '-OB-');
          $isItemCurrent = !$isItemOb && (
            ($currentInvoiceId !== null && isset($item->invoice_id) && (int)$item->invoice_id === (int)$currentInvoiceId)
            || ($currentPeriodStart !== null && $itemPeriod === $currentPeriodStart)
          );
          $isItemLater = !$isItemOb && !$isItemCurrent && $currentPeriodStart !== null && $itemPeriod !== null && $itemPeriod > $currentPeriodStart;
          $isItemLaterOverdue = $isItemLater && $itemDueRaw !== null && $itemDueRaw < $todayStr;
          $origMinor = (int)($item->amount_minor ?? $item->net_minor);
          $discMinor = (int)($item->discount_minor ?? 0) + (int)($item->exemption_minor ?? 0);
        @endphp
        <tr class="fee-row">
          <td>
            <div class="fee-month-val">{{ $itemPeriodDisplay }}<!-- {{ $itemPeriodRaw }} --></div>
            @if($isItemOb)
              <span class="fee-status-badge badge-overdue">Previous Dues</span>
            @elseif($isItemLater && $isItemLaterOverdue)
              <span class="fee-status-badge badge-overdue">Overdue</span>
            @elseif($isItemLater)
              <span class="fee-status-badge badge-upcoming">Later Period</span>
            @elseif(!$isItemCurrent)
              <span class="fee-status-badge badge-overdue">Prior Overdue</span>
            @endif
          </td>
          <td>
            <div class="fee-desc-title">{{ $item->description ?: 'Monthly Fee' }}</div>
            @if(!empty($item->grade_label) && $item->grade_label !== ($enrollment?->grade_label ?? ''))
              <div class="fee-desc-meta">Class: {{ $item->grade_label }}</div>
            @endif
            @if($discMinor > 0 || $origMinor !== (int)$item->net_minor)
              <div class="fee-desc-meta">Original: {{ number_format($origMinor / 100, 2) }} &bull; Discount: {{ number_format($discMinor / 100, 2) }}</div>
            @endif
            @if((int)$item->paid_minor > 0)
              <div class="fee-desc-meta">Paid: {{ number_format($item->paid_minor / 100, 2) }} &bull; Remaining: {{ number_format($balance / 100, 2) }}</div>
            @endif
          </td>
          <td class="right">{{ number_format($item->net_minor / 100, 2) }}</td>
          <td class="right fee-balance-val">{{ number_format($balance / 100, 2) }}</td>
        </tr>
      @endforeach
      <tr class="student-subtotal-row">
        <td></td>
        <td class="student-subtotal-label">Student Subtotal</td>
        <td></td>
        <td class="right student-subtotal-val">{{ number_format($cData['total_balance_minor'] / 100, 2) }}</td>
      </tr>
    @else
      @foreach($cData['students'] as $sGroup)
        <tr class="student-group-row">
          <td colspan="4">{{ $sGroup['student_name'] }} &middot; GR {{ $sGroup['gr_number'] }}@if(!empty($sGroup['grade_label']) && $sGroup['grade_label'] !== 'Class not assigned') &middot; {{ $sGroup['grade_label'] }}@endif</td>
        </tr>
        @foreach($sGroup['items'] as $item)
          @php
            $balance=max(0, (int)$item->net_minor - (int)$item->paid_minor);
            $itemPeriod = !empty($item->period_start) ? \Carbon\Carbon::parse($item->period_start)->toDateString() : null;
            $itemPeriodRaw = !empty($item->period_start) ? \Carbon\Carbon::parse($item->period_start)->format('M-Y') : 'N/A';
            $itemPeriodDisplay = !empty($item->period_start) ? \Carbon\Carbon::parse($item->period_start)->format('M Y') : 'N/A';
            $itemDueRaw = !empty($item->due_date) ? \Carbon\Carbon::parse($item->due_date)->toDateString() : null;
            $isItemOb = (($item->billing_frequency ?? 'monthly') === 'opening_balance') || str_contains((string)($item->invoice_number ?? ''), '-OB-');
            $isItemCurrent = !$isItemOb && (
              ($currentInvoiceId !== null && isset($item->invoice_id) && (int)$item->invoice_id === (int)$currentInvoiceId)
              || ($currentPeriodStart !== null && $itemPeriod === $currentPeriodStart)
            );
            $isItemLater = !$isItemOb && !$isItemCurrent && $currentPeriodStart !== null && $itemPeriod !== null && $itemPeriod > $currentPeriodStart;
            $isItemLaterOverdue = $isItemLater && $itemDueRaw !== null && $itemDueRaw < $todayStr;
            $origMinor = (int)($item->amount_minor ?? $item->net_minor);
            $discMinor = (int)($item->discount_minor ?? 0) + (int)($item->exemption_minor ?? 0);
          @endphp
          <tr class="fee-row">
            <td>
              <div class="fee-month-val">{{ $itemPeriodDisplay }}<!-- {{ $itemPeriodRaw }} --></div>
              @if($isItemOb)
                <span class="fee-status-badge badge-overdue">Previous Dues</span>
              @elseif($isItemLater && $isItemLaterOverdue)
                <span class="fee-status-badge badge-overdue">Overdue</span>
              @elseif($isItemLater)
                <span class="fee-status-badge badge-upcoming">Later Period</span>
              @elseif(!$isItemCurrent)
                <span class="fee-status-badge badge-overdue">Prior Overdue</span>
              @endif
            </td>
            <td>
              <div class="fee-desc-title">{{ $item->description ?: 'Monthly Fee' }}</div>
              @if(!empty($item->grade_label) && $item->grade_label !== ($sGroup['grade_label'] ?? ''))
                <div class="fee-desc-meta">Class: {{ $item->grade_label }}</div>
              @endif
              @if($discMinor > 0 || $origMinor !== (int)$item->net_minor)
                <div class="fee-desc-meta">Original: {{ number_format($origMinor / 100, 2) }} &bull; Discount: {{ number_format($discMinor / 100, 2) }}</div>
              @endif
              @if((int)$item->paid_minor > 0)
                <div class="fee-desc-meta">Paid: {{ number_format($item->paid_minor / 100, 2) }} &bull; Remaining: {{ number_format($balance / 100, 2) }}</div>
              @endif
            </td>
            <td class="right">{{ number_format($item->net_minor / 100, 2) }}</td>
            <td class="right fee-balance-val">{{ number_format($balance / 100, 2) }}</td>
          </tr>
        @endforeach
        <tr class="student-subtotal-row">
          <td></td>
          <td class="student-subtotal-label">Student Subtotal</td>
          <td></td>
          <td class="right student-subtotal-val">{{ number_format($sGroup['student_subtotal_balance_minor']/100, 2) }}</td>
        </tr>
      @endforeach
    @endif
    </tbody>
  </table>

  <!-- Total Outstanding Section -->
  <div class="total-outstanding-block">
    <table class="total-table">
      <tr>
        <td class="total-label-cell">TOTAL OUTSTANDING:</td>
        <td class="total-amount-cell">{{ $currency }} {{ number_format($cData['total_balance_minor']/100, 2) }}</td>
      </tr>
    </table>
  </div>

  @if(count($currencyGroups)>1 && $loop->last)
    <table class="multicurr">
      <tr><th>Separate Currency</th><th>Outstanding (Not Converted)</th></tr>
      @foreach($currencyGroups as $cName=>$summary)
        <tr><td>{{ $cName }}</td><td><strong>{{ $cName }} {{ number_format($summary['total_balance_minor']/100, 2) }}</strong></td></tr>
      @endforeach
    </table>
  @endif

  <div class="settlement">
    <div class="section-title">PAYMENT DETAILS</div>
    <table class="payment-table">
      <tr>
        <td style="width:78%; padding:6px 8px;">
          @if($hasSavedBankAccounts)
            <div class="bank-text">{{ $payText }}</div>
          @else
            <table class="bank-accounts-table">
              <tr>
                <td class="bank-col" style="width:50%; padding-right:8px; border-right:1px dashed #cbd5e1;">
                  <div class="bank-entity">IQRA VIRTUAL SOLUTIONS</div>
                  <div class="bank-name">Meezan Bank Peshawar</div>
                  <div class="bank-field"><strong>IBAN:</strong> PK71MEZN0007810111367793</div>
                  <div class="bank-field"><strong>Account:</strong> 07810111367793</div>
                  <div class="bank-field"><strong>Branch:</strong> 0781</div>
                  <div class="bank-field"><strong>Contact:</strong> +92 325 524551</div>
                </td>
                <td class="bank-col" style="width:50%; padding-left:8px;">
                  <div class="bank-entity">IVSGLOBAL</div>
                  <div class="bank-name">Mashreq Bank</div>
                  <div class="bank-field"><strong>IBAN:</strong> AE620330000019101455931</div>
                  <div class="bank-field"><strong>Account:</strong> 019101455931</div>
                  <div class="bank-field"><strong>Mobile:</strong> +971528838128</div>
                  <div class="bank-field">Ajman Industrial Area 2, UAE</div>
                </td>
              </tr>
            </table>
            @if($payText !== '')
              <div class="bank-extra-notes">{{ $payText }}</div>
            @endif
          @endif
        </td>
        <td class="qr-cell" style="width:22%;">
          @if($qrSrc)
            <img src="{{ $qrSrc }}" class="qr" alt="Scan to pay">
            <div class="qr-caption">PayPal QR Code</div>
          @else
            <div class="qr-placeholder">Please contact Accounts</div>
          @endif
        </td>
      </tr>
    </table>

    <table class="settlement-lower">
      <tr>
        <td class="receipt-instruction-col">
          <div class="receipt-instruction-block">
            <div class="receipt-heading">ALREADY PAID?</div>
            <div class="receipt-subtext">If you have already made your payment, please submit your payment receipt for verification.</div>
            <div class="receipt-btn-wrap">
              <a href="https://forms.gle/W4y3Q1VjyU8cRDvp7" class="btn-upload">&#8679;&nbsp;&nbsp;Upload Payment Receipt</a>
            </div>
            <div class="receipt-notice">Please retain your payment receipt. Contact Accounts if payment confirmation is not received within 72 hours.</div>
          </div>
        </td>
        <td class="auth-col">
          @if($signSrc)
            <div class="auth-signature"><img src="{{ $signSrc }}" alt="Authorized Signature"></div>
          @endif
          @if($isFullyPaidInvoice && $stampSrc)
            <div class="auth-stamp"><img src="{{ $stampSrc }}" alt="Fee Paid Stamp"></div>
          @endif
        </td>
      </tr>
    </table>
  </div>

  <div class="eoe-statement">
    <strong>Errors &amp; Omissions Excepted (E&amp;OE):</strong> Every effort has been made to ensure the accuracy of this statement. However, errors or omissions may occur. If you notice any discrepancy in fees, payments, or outstanding balances, kindly contact on this number. Any verified discrepancies will be reviewed and corrected accordingly. We appreciate your understanding and cooperation.
  </div>
@empty
  @if(!empty($banner))
    <div class="banner-wrap">
      <img class="banner" src="{{ $banner }}" alt="IQRA Virtual School">
    </div>
  @endif

  @php
    $displayInvoiceNumber = !empty($referenceInvoice['invoice_number']) ? sprintf('%03d', (int)preg_replace('/\D+/', '', substr($referenceInvoice['invoice_number'], strrpos($referenceInvoice['invoice_number'], '-') ?: 0) ?: 1)) : '001';
    $emptyInvId = $referenceInvoice['id'] ?? null;
    $isFullyPaidEmpty = false;
    if (!empty($emptyInvId) && class_exists(\App\Services\PaidInvoiceEligibility::class)) {
      $isFullyPaidEmpty = \App\Services\PaidInvoiceEligibility::eligible((int)$emptyInvId);
    }
    if (!$isFullyPaidEmpty && !empty($referenceInvoice) && is_array($referenceInvoice)) {
      $refTotal = (int)($referenceInvoice['total_minor'] ?? $referenceInvoice['header_total_minor'] ?? 0);
      $refRemaining = (int)($referenceInvoice['remaining_minor'] ?? $referenceInvoice['header_remaining_minor'] ?? 0);
      $refStatus = strtolower((string)($referenceInvoice['raw_status'] ?? $referenceInvoice['status'] ?? ''));
      if ($refTotal > 0 && $refRemaining === 0 && in_array($refStatus, ['paid', 'settled'], true)) {
        $isFullyPaidEmpty = true;
      }
    }
  @endphp

  <div class="header-title-block">
    <div class="invoice-title">MONTHLY FEE INVOICE</div>
    <div class="invoice-number">Invoice No: {{ $displayInvoiceNumber }}</div>
    @if(!empty($referenceInvoice['issued_on']))
      <div class="invoice-issue-date">Issue Date: {{ \Carbon\Carbon::parse($referenceInvoice['issued_on'])->format('d F Y') }}</div>
    @endif
  </div>

  @if(!empty($referenceInvoice))
    <!-- Compact Family Information Strip -->
    <table class="info-strip">
      <thead>
        <tr>
          <th style="width:32%;">Guardian</th>
          <th style="width:20%;">Family Code</th>
          <th style="width:26%;">Payment Due</th>
          <th style="width:22%;" class="text-right">Students with Dues</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>{{ $parentDisplay['name'] ?? 'Not provided' }}</td>
          <td>{{ isset($family) ? ($family?->family_code ?: 'FAM-'.$family?->id) : ($student->family_code ?? 'Not assigned') }}</td>
          <td class="due-val">
            <!-- Due Date: -->{{ !empty($referenceInvoice['due_date']) ? \Carbon\Carbon::parse($referenceInvoice['due_date'])->format('d F Y') : 'N/A' }}
          </td>
          <td class="text-right">0</td>
        </tr>
      </tbody>
    </table>
  @endif

  @if($isFullyPaidEmpty)
    <div class="notice" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">This invoice is fully settled and paid in full.</div>
    @if($signSrc || $stampSrc)
      <table style="width:100%; margin-top:10px; border-collapse:collapse;"><tr>
        <td style="width:70%; border:0;"></td>
        <td style="width:30%; text-align:center; border:0;">
          @if($signSrc)<div class="auth-signature"><img src="{{ $signSrc }}" alt="Authorized Signature"></div>@endif
          @if($stampSrc)<div class="auth-stamp"><img src="{{ $stampSrc }}" alt="Fee Paid Stamp"></div>@endif
        </td>
      </tr></table>
    @endif
  @else
    <div class="notice">There are no unpaid billed fee items to display for this account.</div>
  @endif

  <div class="eoe-statement">
    <strong>Errors &amp; Omissions Excepted (E&amp;OE):</strong> Every effort has been made to ensure the accuracy of this statement. However, errors or omissions may occur. If you notice any discrepancy in fees, payments, or outstanding balances, kindly contact on this number. Any verified discrepancies will be reviewed and corrected accordingly. We appreciate your understanding and cooperation.
  </div>
@endforelse
</body>
</html>
