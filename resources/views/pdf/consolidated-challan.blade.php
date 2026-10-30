<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>MONTHLY FEE INVOICE</title>
<style>
  @page { size:A4 portrait; margin:18px 32px 36px; }
  * { box-sizing:border-box; }
  body { margin:0; color:#283344; font-family:DejaVu Sans,sans-serif; font-size:8.2px; line-height:1.32; }
  /* Center the complete 2172 x 724 letterhead inside the printable page. */
  .banner-wrap { width:100%; text-align:center; margin:0 0 3px; }
  .banner { display:inline-block; width:86%; height:auto; vertical-align:top; }
  .title { text-align:center; color:#982931; font-size:15px; font-weight:bold; letter-spacing:.4px; margin:2px 0 2px; text-transform:uppercase; }
  .sub { text-align:center; color:#677282; text-transform:uppercase; font-size:7.5px; margin:0 0 6px; }
  .notice { border:1px solid #f2cacc; background:#fff4f4; color:#a22a31; padding:4px 7px; font-size:7.7px; margin:0 0 6px; text-align:center; }
  .meta { width:100%; table-layout:fixed; border-collapse:collapse; margin:2px 0 7px; }
  .meta td { vertical-align:top; padding:2px 4px; word-wrap:break-word; }
  .meta .k { width:18%; font-size:7.6px; color:#606d7d; }
  .meta .v { width:32%; font-weight:bold; }
  .bar { background:#962a31; color:white; padding:5px 8px; font-size:8.4px; text-transform:uppercase; font-weight:bold; }
  .smallnote { float:right; font-size:7.6px; font-weight:normal; }
  .fees { width:100%; border-collapse:collapse; table-layout:fixed; font-size:7.5px; }
  .fees thead { display:table-header-group; }
  .fees th { background:#b53139; color:#fff; padding:5px 4px; text-align:left; font-size:7.3px; }
  .fees td { vertical-align:top; border-bottom:1px solid #e5e7eb; padding:4px 4px; word-wrap:break-word; }
  .fees tbody tr:nth-child(even) td { background:#fcfcfd; }
  .fees tr { page-break-inside:avoid; }
  .fees .right { text-align:right; }
  .fees .balance { text-align:right; color:#992630; font-weight:bold; white-space:nowrap; }
  .fees .invoice-ref { word-wrap:break-word; font-size:6.9px; color:#455263; }
  .fees .due-cell { font-size:7px; white-space:nowrap; }
  .fees .due-current { color:#166534; font-weight:bold; font-size:6.6px; display:block; }
  .fees .due-overdue { color:#a0272f; font-weight:bold; font-size:6.6px; display:block; }
  .fees .due-upcoming { color:#1d4ed8; font-weight:bold; font-size:6.6px; display:block; }
  .fees .muted { font-size:6.9px; color:#6e7785; }
  .fees .student-label td { background:#eff1f4!important; color:#263546; font-weight:bold; font-size:7.9px; border-top:1px solid #d1d5db; padding:4px 6px; }
  .fees .student-total td { background:#fff7ec!important; color:#864818; font-weight:bold; border-top:1px solid #e9d4af; padding:4px 5px; }
  .total { width:100%; table-layout:fixed; border-collapse:collapse; margin-top:5px; page-break-inside:avoid; }
  .total td { padding:6px 8px; font-size:9.5px; font-weight:bold; }
  .total .left { background:#f9e7e7; color:#9c242e; text-align:right; width:72%; border:1px solid #efc0c0; }
  .total .right { background:#a0272f; color:#fff; text-align:right; width:28%; white-space:nowrap; }
  .multicurr { margin-top:6px; width:100%; border-collapse:collapse; font-size:7.7px; page-break-inside:avoid; }
  .multicurr td,.multicurr th { padding:4px 6px; border:1px solid #e5e7eb; }
  .multicurr th { background:#687381; color:white; text-align:left; }
  .settlement { page-break-inside:avoid; margin-top:8px; }
  .payment { width:100%; border-collapse:collapse; table-layout:fixed; }
  .payment td { border:1px solid #e0e4e8; padding:6px 6px; vertical-align:top; font-size:7.4px; line-height:1.38; }
  .bank { white-space:pre-line; word-wrap:break-word; }
  .bank strong { color:#962a31; }
  .qr { max-width:100%; width:82px; height:auto; }
  .instruction { margin-top:6px; font-size:7.3px; line-height:1.38; }
  .instruction strong { color:#263d69; }
  .signature { text-align:right; margin:2px 0 0; }
  .signature img { width:84px; height:auto; }
  .page-break { page-break-before:always; }
  .footer { position:fixed; left:-32px; right:-32px; bottom:-26px; padding:7px 20px; text-align:center; background:#962a31; color:white; font-size:7.5px; }
</style>
</head>
<body>
@php
  $qrPath=public_path('assets/receipt-qr.jpg');
  $signPath=public_path('assets/receipt-sign.jpg');
  $qrSrc=is_file($qrPath)?'data:image/jpeg;base64,'.base64_encode(file_get_contents($qrPath)):null;
  $signSrc=is_file($signPath)?'data:image/jpeg;base64,'.base64_encode(file_get_contents($signPath)):null;
  $payText=trim((string)($branding?->payment_instructions ?? ''));
  $hasSavedBankAccounts = (bool) preg_match('/\bIBAN\b|\b(?:A\/C|ACCOUNT)\s*(?:NUMBER|NO\.?|#)?\s*[:#]\s*\d{6,}/i', $payText);
  $footerPhone = '+92 325 524551';
  $footerEmail = 'acivs2021@gmail.com';
  $todayStr = \Carbon\Carbon::now()->toDateString();
@endphp

@forelse($currencyGroups as $currency => $cData)
  @if(!$loop->first)<div class="page-break"></div>@endif
  @if(!empty($banner))
    <div class="banner-wrap">
      <img class="banner" src="{{ $banner }}" alt="IQRA Virtual School">
    </div>
  @endif
  <div class="title">MONTHLY FEE INVOICE</div>
  <div class="notice">
    @if(count($currencyGroups) > 1)
      <br>This page contains <strong>{{ $currency }}</strong> only. Other currencies are separate and are not converted.
    @endif
  </div>
  @php
    $allGroupItems = $mode === 'student'
      ? collect($cData['items'] ?? [])
      : collect($cData['students'] ?? [])->flatMap(fn($s) => $s['items'] ?? []);
    $cDueDate = $cData['due_date'] ?? ($dueDate ?? null);
    if (!$cDueDate && $allGroupItems->isNotEmpty()) {
      $cDueDate = $allGroupItems->pluck('due_date')->filter()->map(fn($d) => \Carbon\Carbon::parse($d)->toDateString())->sortDesc()->first();
    }
    $cDueDateFormatted = !empty($cDueDate) ? \Carbon\Carbon::parse($cDueDate)->format('d-F-Y') : 'N/A';
    $cIssueDate = $cData['issued_on'] ?? ($issuedOn ?? ($generatedAt ?? null));
    $cIssueDateFormatted = !empty($cIssueDate) ? \Carbon\Carbon::parse($cIssueDate)->format('d-F-Y') : 'N/A';
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
              'due_date_formatted' => \Carbon\Carbon::parse($gDue)->format('d-F-Y'),
              'is_overdue' => $gDue < $todayStr,
            ];
          } else {
            $tmpHist[$hKey] = [
              'period_label' => $gPeriod ? \Carbon\Carbon::parse($gPeriod)->format('M-Y') : 'Prior',
              'due_date_formatted' => \Carbon\Carbon::parse($gDue)->format('d-F-Y'),
              'is_opening_balance' => $gIsOb,
            ];
          }
        }
      }
      $historicalDueList = array_values($tmpHist);
      $upcomingDueList = array_values($tmpUpc);
    }
  @endphp
  <table class="meta">
    @if($mode === 'student')
      <tr><td class="k">Student:</td><td class="v">{{ $student->name ?? 'N/A' }}</td><td class="k">Reg / GR:</td><td class="v">{{ $student->gr_number ?? 'N/A' }}</td></tr>
      <tr><td class="k">{{ $parentDisplay['label'] ?? 'Guardian' }}:</td><td class="v">{{ $parentDisplay['name'] ?? 'Not provided' }}</td><td class="k">Class / Program:</td><td class="v">{{ $enrollment?->grade_label ?: 'Not assigned' }}@if(!empty($student->section_current)) &bull; {{ $student->section_current }}@endif</td></tr>
      <tr><td class="k">Family Code:</td><td class="v">{{ $student->family_code ?: 'Not assigned' }}</td><td class="k">Issue Date:</td><td class="v">{{ $cIssueDateFormatted }}</td></tr>
      <tr><td class="k">Due Date:</td><td class="v" style="color:#a0272f;">{{ $cDueDateFormatted }}@if(!empty($historicalDueList) || !empty($upcomingDueList)) <span style="font-size:7px;color:#166534;">(Current Month)</span>@endif</td><td class="k">Statement Ref:</td><td class="v">{{ $statementNumber }}</td></tr>
    @else
      <tr><td class="k">Family Code:</td><td class="v">{{ $family?->family_code ?: 'FAM-'.$family?->id }}</td><td class="k">Students with dues:</td><td class="v">{{ count($cData['students']) }}</td></tr>
      <tr><td class="k">{{ $parentDisplay['label'] ?? 'Guardian' }}:</td><td class="v">{{ $parentDisplay['name'] ?? 'Not provided' }}</td><td class="k">Due Date:</td><td class="v" style="color:#a0272f;">{{ $cDueDateFormatted }}@if(!empty($historicalDueList) || !empty($upcomingDueList)) <span style="font-size:7px;color:#166534;">(Current Month)</span>@endif</td></tr>
      <tr><td class="k">Statement Ref:</td><td class="v">{{ $statementNumber }}</td><td class="k">Issue Date:</td><td class="v">{{ $cIssueDateFormatted }}</td></tr>
    @endif
    @if(!empty($referenceInvoice))
      <tr>
        <td class="k">Referenced Invoice:</td>
        <td class="v" colspan="3" style="font-size:7.6px;color:#1e293b;">
          {{ $referenceInvoice['invoice_number'] }}
          @if(!empty($referenceInvoice['period_label']) && $referenceInvoice['period_label'] !== 'N/A') ({{ $referenceInvoice['period_label'] }}) @endif
          &bull; Specific Invoice Billed: {{ $referenceInvoice['currency'] }} {{ number_format(($referenceInvoice['total_minor'] ?? 0) / 100, 2) }}
          &bull; Specific Invoice Remaining: {{ $referenceInvoice['currency'] }} {{ number_format(($referenceInvoice['remaining_minor'] ?? 0) / 100, 2) }}
        </td>
      </tr>
    @endif
    @if(!empty($upcomingDueList))
      <tr><td class="k">Later Period Due Dates:</td><td class="v" colspan="3" style="font-size:7.4px;color:#1d4ed8;font-weight:normal;">
        @foreach($upcomingDueList as $uDue)
          <strong>{{ $uDue['period_label'] }}</strong>: {{ $uDue['due_date_formatted'] }} ({{ !empty($uDue['is_overdue']) ? 'Later Period Overdue' : 'Generated Later Period' }})@if(!$loop->last) &bull; @endif
        @endforeach
      </td></tr>
    @endif
  </table>
  <div class="bar">OUTSTANDING FEE PARTICULARS ({{ $currency }}) <span class="smallnote">Single-currency ledger: {{ $currency }}</span></div>
  <table class="fees">
    <thead>
      <tr>
        <th style="width:4%;">#</th>
        <th style="width:9%;">Month</th>
        @if($mode === 'family')
          <th style="width:14%;">Student</th>
          <th style="width:21%;">Particular / Description</th>
          <th style="width:14%;">Invoice Ref</th>
          <th style="width:16%;">Saved Due Date</th>
          <th style="width:11%;" class="right">Billed</th>
          <th style="width:11%;" class="right">Balance</th>
        @else
          <th style="width:31%;">Particular / Description</th>
          <th style="width:16%;">Invoice Ref</th>
          <th style="width:18%;">Saved Due Date</th>
          <th style="width:11%;" class="right">Billed</th>
          <th style="width:11%;" class="right">Balance</th>
        @endif
      </tr>
    </thead>
    <tbody>
    @php $rowIdx = 1; @endphp
    @if($mode === 'student')
      @foreach($cData['items'] as $item)
        @php
          $balance=max(0, (int)$item->net_minor - (int)$item->paid_minor);
          $itemPeriod = !empty($item->period_start) ? \Carbon\Carbon::parse($item->period_start)->toDateString() : null;
          $itemPeriodLabel = !empty($item->period_start) ? \Carbon\Carbon::parse($item->period_start)->format('M-Y') : 'N/A';
          $itemDueRaw = !empty($item->due_date) ? \Carbon\Carbon::parse($item->due_date)->toDateString() : null;
          $itemDueFormatted = $itemDueRaw ? \Carbon\Carbon::parse($itemDueRaw)->format('d-F-Y') : 'Historical';
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
        <tr>
          <td>{{ $rowIdx++ }}</td>
          <td>{{ $itemPeriodLabel }}</td>
          <td>
            <strong>{{ $item->description ?: 'Monthly Fee' }}</strong>
            @if(!empty($item->grade_label) && $item->grade_label !== ($enrollment?->grade_label ?? ''))<br><span class="muted">Class: {{ $item->grade_label }}</span>@endif
            @if($discMinor > 0 || $origMinor !== (int)$item->net_minor)
              <br><span class="muted">Original: {{ number_format($origMinor / 100, 2) }} &bull; Discount: {{ number_format($discMinor / 100, 2) }}</span>
            @endif
            @if((int)$item->paid_minor > 0)
              <br><span class="muted">Paid: {{ number_format($item->paid_minor / 100, 2) }} &bull; Remaining: {{ number_format($balance / 100, 2) }}</span>
            @endif
          </td>
          <td class="invoice-ref">{{ $item->invoice_number }}</td>
          <td class="due-cell">
            <strong>{{ $itemDueFormatted }}</strong>
            @if($isItemOb)
              <span class="due-overdue">Historical Overdue</span>
            @elseif($isItemLater)
              @if($isItemLaterOverdue)
                <span class="due-overdue">Later Period Overdue ({{ $itemPeriodLabel }})</span>
              @else
                <span class="due-upcoming">Generated Later Period ({{ $itemPeriodLabel }})</span>
              @endif
            @elseif(!$isItemCurrent)
              <span class="due-overdue">Prior Overdue</span>
            @else
              <span class="due-current">Current Monthly Due</span>
            @endif
          </td>
          <td class="right">{{ number_format($item->net_minor / 100, 2) }}</td>
          <td class="balance">{{ number_format($balance / 100, 2) }}</td>
        </tr>
      @endforeach
    @else
      @foreach($cData['students'] as $sGroup)
        <tr class="student-label"><td colspan="8">{{ $sGroup['student_name'] }} &bull; GR {{ $sGroup['gr_number'] }} &bull; {{ $sGroup['grade_label'] ?? 'Class not assigned' }}</td></tr>
        @foreach($sGroup['items'] as $item)
          @php
            $balance=max(0, (int)$item->net_minor - (int)$item->paid_minor);
            $itemPeriod = !empty($item->period_start) ? \Carbon\Carbon::parse($item->period_start)->toDateString() : null;
            $itemPeriodLabel = !empty($item->period_start) ? \Carbon\Carbon::parse($item->period_start)->format('M-Y') : 'N/A';
            $itemDueRaw = !empty($item->due_date) ? \Carbon\Carbon::parse($item->due_date)->toDateString() : null;
            $itemDueFormatted = $itemDueRaw ? \Carbon\Carbon::parse($itemDueRaw)->format('d-F-Y') : 'Historical';
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
          <tr>
            <td>{{ $rowIdx++ }}</td>
            <td>{{ $itemPeriodLabel }}</td>
            <td>{{ $sGroup['student_name'] }}</td>
            <td><strong>{{ $item->description ?: 'Monthly Fee' }}</strong>
              @if($discMinor > 0 || $origMinor !== (int)$item->net_minor)
                <br><span class="muted">Original: {{ number_format($origMinor / 100, 2) }} &bull; Discount: {{ number_format($discMinor / 100, 2) }}</span>
              @endif
              @if((int)$item->paid_minor > 0)
                <br><span class="muted">Paid: {{ number_format($item->paid_minor / 100, 2) }} &bull; Remaining: {{ number_format($balance / 100, 2) }}</span>
              @endif
            </td>
            <td class="invoice-ref">{{ $item->invoice_number }}</td>
            <td class="due-cell">
              <strong>{{ $itemDueFormatted }}</strong>
              @if($isItemOb)
                <span class="due-overdue">Historical Overdue</span>
              @elseif($isItemLater)
                @if($isItemLaterOverdue)
                  <span class="due-overdue">Later Period Overdue ({{ $itemPeriodLabel }})</span>
                @else
                  <span class="due-upcoming">Generated Later Period ({{ $itemPeriodLabel }})</span>
                @endif
              @elseif(!$isItemCurrent)
                <span class="due-overdue">Prior Overdue</span>
              @else
                <span class="due-current">Current Monthly Due</span>
              @endif
            </td>
            <td class="right">{{ number_format($item->net_minor / 100, 2) }}</td>
            <td class="balance">{{ number_format($balance / 100, 2) }}</td>
          </tr>
        @endforeach
        <tr class="student-total"><td colspan="7" class="right">Subtotal for {{ $sGroup['student_name'] }} ({{ $currency }}):</td><td class="balance">{{ number_format($sGroup['student_subtotal_balance_minor']/100, 2) }}</td></tr>
      @endforeach
    @endif
    </tbody>
  </table>
  <table class="total"><tr>
    <td class="left">TOTAL OUTSTANDING PAYABLE ({{ $currency }}):</td>
    <td class="right">{{ $currency }} {{ number_format($cData['total_balance_minor']/100, 2) }}</td>
  </tr></table>

  @if(count($currencyGroups)>1 && $loop->last)
    <table class="multicurr">
      <tr><th>Separate Currency</th><th>Outstanding (Not Converted)</th></tr>
      @foreach($currencyGroups as $cName=>$summary)
        <tr><td>{{ $cName }}</td><td><strong>{{ $cName }} {{ number_format($summary['total_balance_minor']/100, 2) }}</strong></td></tr>
      @endforeach
    </table>
  @endif

  <div class="settlement">
    <div class="bar">PAYMENT INSTRUCTIONS &amp; BANK ACCOUNTS</div>
    <table class="payment"><tr>
      <td style="width:78%;">
        @if($hasSavedBankAccounts)
          <div class="bank">{{ $payText }}</div>
        @else
          <table style="width:100%; border-collapse:collapse;"><tr>
            <td style="border:0; padding:0 8px 0 0; width:50%;"><strong>IQRA VIRTUALSOLUTIONS</strong><br>Meezan Bank Peshawar<br>IBAN: PK71MEZN0007810111367793<br>Account: 07810111367793<br>Branch: 0781<br>Contact: +92 325 524551</td>
            <td style="border:0; padding:0; width:50%;"><strong>IVSGLOBAL</strong><br>Mashreq Bank<br>IBAN: AE620330000019101455931<br>Account: 019101455931<br>Mobile: +971528838128<br>Ajman Industrial Area 2, UAE</td>
          </tr></table>
          @if($payText !== '')
            <div style="margin-top:5px; color:#647080; font-size:7.3px;">{{ $payText }}</div>
          @endif
        @endif
      </td>
      <td style="width:22%; text-align:center;">@if($qrSrc)<img src="{{ $qrSrc }}" class="qr" alt="Scan to pay">@else Please contact Accounts @endif</td>
    </tr></table>
    <div class="instruction"><strong>Notice to Parents / Guardians:</strong><br>
      &bull; This statement lists unpaid obligations already billed. Verified previous payments have already been credited.<br>
      &bull; Quote the Reg / GR and Statement Ref when making payment.<br>
      &bull; Upload payment evidence: https://forms.gle/W4y3Q1VjyU8cRDvp7<br>
      &bull; Contact Accounts if your receipt has not arrived within 72 hours. Refunded/rejected payments are not paid.
    </div>
    @if($signSrc)<div class="signature"><img src="{{ $signSrc }}" alt="Accounts Manager Signature"></div>@endif
  </div>
@empty
  @if(!empty($banner))
    <div class="banner-wrap">
      <img class="banner" src="{{ $banner }}" alt="IQRA Virtual School">
    </div>
  @endif
  <div class="title">MONTHLY FEE INVOICE</div>
  @if(!empty($referenceInvoice))
    <table class="meta">
      <tr>
        <td class="k">Family Code:</td>
        <td class="v">{{ $family?->family_code ?: 'FAM-'.$family?->id }}</td>
        <td class="k">Referenced Invoice:</td>
        <td class="v">{{ $referenceInvoice['invoice_number'] }}</td>
      </tr>
      <tr>
        <td class="k">{{ $parentDisplay['label'] ?? 'Guardian' }}:</td>
        <td class="v">{{ $parentDisplay['name'] ?? 'Not provided' }}</td>
        <td class="k">Issue / Due Date:</td>
        <td class="v">{{ $referenceInvoice['issued_on_formatted'] ?? 'N/A' }} / {{ $referenceInvoice['due_date_formatted'] ?? 'N/A' }}</td>
      </tr>
      <tr>
        <td class="k">Statement Ref:</td>
        <td class="v">{{ $statementNumber }}</td>
        <td class="k">Invoice Status:</td>
        <td class="v">{{ strtoupper($referenceInvoice['status'] ?? 'SETTLED') }} ({{ $referenceInvoice['currency'] }} {{ number_format(($referenceInvoice['total_minor'] ?? 0) / 100, 2) }})</td>
      </tr>
    </table>
  @endif
  <div class="notice">There are no unpaid billed fee items to display for this account.</div>
@endforelse
<div class="footer">IQRA VIRTUAL SCHOOL &bull; {{ $footerPhone }} &bull; {{ $footerEmail }} &bull; {{ $statementNumber }}</div>
</body>
</html>
