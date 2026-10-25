<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Consolidated Fee Challan</title>
<style>
  @page { size:A4 portrait; margin:26px 35px 46px; }
  * { box-sizing:border-box; }
  body { margin:0; color:#283344; font-family:DejaVu Sans,sans-serif; font-size:8.5px; line-height:1.38; }
  .banner { display:block; width:100%; height:auto; margin:0 0 5px; }
  .title { text-align:center; color:#982931; font-size:16px; font-weight:bold; letter-spacing:.35px; margin:4px 0 1px; }
  .sub { text-align:center; color:#677282; text-transform:uppercase; font-size:7.7px; margin:0 0 9px; }
  .notice { border:1px solid #f2cacc; background:#fff4f4; color:#a22a31; padding:6px 7px; font-size:8px; margin:0 0 9px; text-align:center; }
  .meta { width:100%; table-layout:fixed; border-collapse:collapse; margin:4px 0 10px; }
  .meta td { vertical-align:top; padding:3px 5px; word-wrap:break-word; }
  .meta .k { width:17%; font-size:7.8px; color:#606d7d; }
  .meta .v { width:33%; font-weight:bold; }
  .bar { background:#962a31; color:white; padding:6px 8px; font-size:8.8px; text-transform:uppercase; font-weight:bold; }
  .smallnote { float:right; font-size:8px; font-weight:normal; }
  .fees { width:100%; border-collapse:collapse; table-layout:fixed; font-size:7.7px; }
  .fees thead { display:table-header-group; }
  .fees th { background:#b53139; color:#fff; padding:6px 4px; text-align:left; font-size:7.5px; }
  .fees td { vertical-align:top; border-bottom:1px solid #e5e7eb; padding:5px 4px; word-wrap:break-word; }
  .fees tbody tr:nth-child(even) td { background:#fcfcfd; }
  .fees tr { page-break-inside:avoid; }
  .fees .right { text-align:right; }
  .fees .balance { text-align:right; color:#992630; font-weight:bold; white-space:nowrap; }
  .fees .invoice-ref { word-wrap:break-word; font-size:7px; color:#455263; }
  .fees .muted { font-size:7.2px; color:#6e7785; }
  .fees .student-label td { background:#eff1f4!important; color:#263546; font-weight:bold; font-size:8.2px; border-top:1px solid #d1d5db; padding:5px 5px; }
  .fees .student-total td { background:#fff7ec!important; color:#864818; font-weight:bold; border-top:1px solid #e9d4af; padding:6px 5px; }
  .total { width:100%; table-layout:fixed; border-collapse:collapse; margin-top:7px; page-break-inside:avoid; }
  .total td { padding:7px 8px; font-size:10px; font-weight:bold; }
  .total .left { background:#f9e7e7; color:#9c242e; text-align:right; width:72%; border:1px solid #efc0c0; }
  .total .right { background:#a0272f; color:#fff; text-align:right; width:28%; white-space:nowrap; }
  .multicurr { margin-top:9px; width:100%; border-collapse:collapse; font-size:8px; }
  .multicurr td,.multicurr th { padding:5px 6px; border:1px solid #e5e7eb; }
  .multicurr th { background:#687381; color:white; text-align:left; }
  .settlement { page-break-inside:avoid; margin-top:12px; }
  .payment { width:100%; border-collapse:collapse; table-layout:fixed; }
  .payment td { border:1px solid #e0e4e8; padding:8px 7px; vertical-align:top; font-size:7.7px; line-height:1.48; }
  .bank { white-space:pre-line; word-wrap:break-word; }
  .bank strong { color:#962a31; }
  .qr { max-width:100%; width:100px; height:auto; }
  .instruction { margin-top:9px; font-size:7.6px; line-height:1.5; }
  .instruction strong { color:#263d69; }
  .signature { text-align:right; margin:4px 0 0; }
  .signature img { width:100px; height:auto; }
  .page-break { page-break-before:always; }
  .footer { position:fixed; left:-35px; right:-35px; bottom:-31px; padding:9px 20px; text-align:center; background:#962a31; color:white; font-size:7.7px; }
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
  $footerPhone=trim((string)($branding?->footer_phone ?? '')) ?: '+92 335 524 5551';
  $footerEmail=trim((string)($branding?->footer_email ?? '')) ?: 'acivs2021@gmail.com';
@endphp

@forelse($currencyGroups as $currency => $cData)
  @if(!$loop->first)<div class="page-break"></div>@endif
  @if(!empty($banner))<img class="banner" src="{{ $banner }}" alt="IQRA Virtual School">@endif
  <div class="title">Monthly FEE CHALLAN </div>
    <div class="notice">
    <strong> Monthly FEE STATEMENT</strong> 
    @if(count($currencyGroups) > 1)
      This page contains <strong>{{ $currency }}</strong> only. Other currencies are separate and are not converted.
    @endif
  </div>
  <table class="meta">
    @if($mode === 'student')
      <tr><td class="k">Student:</td><td class="v">{{ $student->name ?? 'N/A' }}</td><td class="k">Reg / GR:</td><td class="v">{{ $student->gr_number ?? 'N/A' }}</td></tr>
      <tr><td class="k">{{ $parentDisplay['label'] ?? 'Guardian' }}:</td><td class="v">{{ $parentDisplay['name'] ?? 'Not provided' }}</td><td class="k">Class / Program:</td><td class="v">{{ $enrollment?->grade_label ?: 'Not assigned' }}@if(!empty($student->section_current)) &bull; {{ $student->section_current }}@endif</td></tr>
      <tr><td class="k">Family Code:</td><td class="v">{{ $student->family_code ?: 'Not assigned' }}</td><td class="k">Issue Date:</td><td class="v">{{ \Carbon\Carbon::parse($generatedAt)->format('d-F-Y') }}</td></tr>
      <tr><td class="k">WhatsApp:</td><td class="v">{{ $student->whatsapp ?: 'Not provided' }}</td><td class="k">Statement Ref:</td><td class="v">{{ $statementNumber }}</td></tr>
    @else
      <tr><td class="k">Family Code:</td><td class="v">{{ $family?->family_code ?: 'FAM-'.$family?->id }}</td><td class="k">Students with dues:</td><td class="v">{{ count($cData['students']) }}</td></tr>
      <tr><td class="k">{{ $parentDisplay['label'] ?? 'Guardian' }}:</td><td class="v">{{ $parentDisplay['name'] ?? 'Not provided' }}</td><td class="k">WhatsApp:</td><td class="v">{{ $family?->whatsapp ?: 'Not provided' }}</td></tr>
      <tr><td class="k">Statement Ref:</td><td class="v">{{ $statementNumber }}</td><td class="k">Issue Date:</td><td class="v">{{ \Carbon\Carbon::parse($generatedAt)->format('d-F-Y') }}</td></tr>
    @endif
  </table>
  <div class="bar">OUTSTANDING FEE PARTICULARS ({{ $currency }}) <span class="smallnote">Single-currency ledger: {{ $currency }}</span></div>
  <table class="fees">
    <thead>
      <tr>
        <th style="width:4%;">#</th>
        <th style="width:10%;">Month</th>
        @if($mode === 'family')
          <th style="width:15%;">Student</th>
          <th style="width:20%;">Particular / Description</th>
          <th style="width:15%;">Invoice Ref</th>
          <th style="width:12%;" class="right">Billed</th>
          <th style="width:12%;" class="right">Balance</th>
        @else
          <th style="width:32%;">Particular / Description</th>
          <th style="width:16%;">Invoice Ref</th>
          <th style="width:12%;" class="right">Billed</th>
          <th style="width:12%;" class="right">Balance</th>
        @endif
      </tr>
    </thead>
    <tbody>
    @php $rowIdx = 1; @endphp
    @if($mode === 'student')
      @foreach($cData['items'] as $item)
        @php $balance=max(0, (int)$item->net_minor - (int)$item->paid_minor); @endphp
        <tr>
          <td>{{ $rowIdx++ }}</td>
          <td>{{ \Carbon\Carbon::parse($item->period_start)->format('M-Y') }}</td>
          <td>
            <strong>{{ $item->description ?: 'Monthly Fee' }}</strong>
            @if(!empty($item->grade_label) && $item->grade_label !== ($enrollment?->grade_label ?? ''))<br><span class="muted">Class: {{ $item->grade_label }}</span>@endif
            @if((int)$item->paid_minor > 0)<br><span class="muted">Paid: {{ number_format($item->paid_minor / 100, 2) }}</span>@endif
            @if((int)($item->discount_minor ?? 0) > 0)<br><span class="muted">Discount: {{ number_format($item->discount_minor / 100, 2) }}</span>@endif
          </td>
          <td class="invoice-ref">{{ $item->invoice_number }}</td>
          <td class="right">{{ number_format($item->net_minor / 100, 2) }}</td>
          <td class="balance">{{ number_format($balance / 100, 2) }}</td>
        </tr>
      @endforeach
    @else
      @foreach($cData['students'] as $sGroup)
        <tr class="student-label"><td colspan="7">{{ $sGroup['student_name'] }} &bull; GR {{ $sGroup['gr_number'] }} &bull; {{ $sGroup['grade_label'] ?? 'Class not assigned' }}</td></tr>
        @foreach($sGroup['items'] as $item)
          @php $balance=max(0, (int)$item->net_minor - (int)$item->paid_minor); @endphp
          <tr>
            <td>{{ $rowIdx++ }}</td>
            <td>{{ \Carbon\Carbon::parse($item->period_start)->format('M-Y') }}</td>
            <td>{{ $sGroup['student_name'] }}</td>
            <td><strong>{{ $item->description ?: 'Monthly Fee' }}</strong>
              @if((int)$item->paid_minor > 0)<br><span class="muted">Paid: {{ number_format($item->paid_minor / 100, 2) }}</span>@endif
              @if((int)($item->discount_minor ?? 0) > 0)<br><span class="muted">Discount: {{ number_format($item->discount_minor / 100, 2) }}</span>@endif
            </td>
            <td class="invoice-ref">{{ $item->invoice_number }}</td>
            <td class="right">{{ number_format($item->net_minor / 100, 2) }}</td>
            <td class="balance">{{ number_format($balance / 100, 2) }}</td>
          </tr>
        @endforeach
        <tr class="student-total"><td colspan="6" class="right">Subtotal for {{ $sGroup['student_name'] }} ({{ $currency }}):</td><td class="balance">{{ number_format($sGroup['student_subtotal_balance_minor']/100, 2) }}</td></tr>
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
            <td style="border:0; padding:0 8px 0 0; width:50%;"><strong>IQRA VIRTUALSOLUTIONS</strong><br>Meezan Bank Peshawar<br>IBAN: PK71MEZN0007810111367793<br>Account: 07810111367793<br>Branch: 0781<br>Contact: +92 335 524 5551</td>
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
  @if(!empty($banner))<img class="banner" src="{{ $banner }}" alt="IQRA Virtual School">@endif
  <div class="title">CONSOLIDATED FEE CHALLAN</div>
  <div class="notice">There are no unpaid billed fee items to display for this account.</div>
@endforelse
<div class="footer">IQRA VIRTUAL SCHOOL &bull; {{ $footerPhone }} &bull; {{ $footerEmail }} &bull; {{ $statementNumber }}</div>
</body>
</html>
