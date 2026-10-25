<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Fee Invoice</title>
<style>
  @page { size: A4 portrait; margin: 24px 32px 42px 32px; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: DejaVu Sans, sans-serif; color: #283244; font-size: 9px; line-height: 1.38; }

  /* School header: use the complete supplied artwork, without cropping it. */
  .banner { display: block; width: 92%; height: auto; margin: 0 auto 8px; }
  .title { text-align: center; color: #97272f; font-size: 16px; font-weight: bold; letter-spacing: .3px; margin: 5px 0 2px; }
  .subtitle { text-align: center; color: #687383; font-size: 8px; letter-spacing: .1px; margin: 0 0 10px; }

  /* Fixed columns keep references and family details in alignment. */
  .ref { width: 100%; table-layout: fixed; border-collapse: collapse; background: #fff6f6; border: 1px solid #eccbd0; margin: 0 0 9px; }
  .ref td { padding: 7px 8px; vertical-align: middle; font-size: 8.5px; border-right: 1px solid #eed4d7; overflow-wrap: anywhere; word-wrap: break-word; }
  .ref td:last-child { border-right: 0; }
  .ref .k { display: block; color: #687383; font-size: 8.2px; }
  .ref .v { display: block; color: #8e2028; font-weight: bold; font-size: 8.9px; }
  .meta { width: 100%; table-layout: fixed; border-collapse: collapse; margin: 0 0 10px; }
  .meta td { padding: 4px 6px; vertical-align: top; border-bottom: 1px solid #edf0f3; overflow-wrap: anywhere; word-wrap: break-word; }
  .meta .key { width: 15%; color: #677485; font-size: 8.2px; }
  .meta .val { width: 35%; color: #253044; font-size: 8.9px; font-weight: bold; }

  .section { width: 100%; padding: 7px 9px; background: #952730; color: #fff; font-size: 9px; font-weight: bold; text-transform: uppercase; margin: 9px 0 0; }
  table.fees { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 8.6px; margin: 0 0 6px; }
  table.fees thead { display: table-header-group; }
  table.fees th { background: #b1353c; color: #fff; text-align: left; padding: 6px 7px; font-size: 8.3px; font-weight: bold; }
  table.fees td { border-bottom: 1px solid #e5e8ed; padding: 6px 7px; line-height: 1.34; vertical-align: top; word-wrap: break-word; overflow-wrap: anywhere; }
  table.fees tbody tr:nth-child(even) td { background: #fcfcfd; }
  table.fees tr { page-break-inside: avoid; }
  .desc { font-weight: bold; color: #263141; }
  .muted { font-size: 7.9px; color: #687585; }
  .green { font-size: 7.9px; color: #16774d; font-weight: bold; }
  .right { text-align: right !important; }
  .num { text-align: right; white-space: nowrap; font-weight: bold; }

  .summary { width: 100%; table-layout: fixed; border-collapse: collapse; margin: 7px 0 0; page-break-inside: avoid; font-size: 9px; }
  .summary td { padding: 4px 8px; border-bottom: 1px solid #e9ebee; }
  .summary .label { text-align: right; color: #425065; font-weight: bold; }
  .summary .money { text-align: right; width: 27%; white-space: nowrap; font-weight: bold; }
  .summary .separate td { background: #fff3f3; color: #8d2931; }
  .summary .final td { background: #9d2630; color: #fff; border: 0; padding: 8px 10px; font-weight: bold; font-size: 10px; }

  .note { background: #f8fafc; border: 1px solid #e4e8ec; padding: 5px 7px; font-size: 7.5px; margin: 6px 0 0; }
  .paid { text-align: right; color: #147346; margin: 5px 0 0; font-weight: bold; }

  /* Do not keep the entire payment block on one page: that caused the blank first page. */
  .settlement { margin: 9px 0 0; }
  .settlement .section { page-break-after: avoid; }
  .payment-grid { width: 100%; table-layout: fixed; border-collapse: collapse; margin: 0; }
  .payment-grid tr { page-break-inside: avoid; }
  .payment-grid td { vertical-align: top; border: 1px solid #e0e4ea; padding: 9px 8px; font-size: 8.4px; line-height: 1.44; word-wrap: break-word; }
  .payment-grid .bank-title { display: block; color: #942830; font-size: 9px; font-weight: bold; margin: 0 0 4px; }
  .bank { white-space: pre-line; line-height: 1.36; word-wrap: break-word; }
  .scan { display: block; width: 98px; height: auto; max-width: 100%; margin: 0 auto; }
  .pay-note { font-size: 8px; line-height: 1.44; color: #5f6d7d; margin: 7px 0 0; }
  .instruction { font-size: 8.4px; line-height: 1.55; margin: 10px 0 0; }
  .instruction strong { color: #263d68; }
  .signature { text-align: right; margin: 7px 0 0; page-break-inside: avoid; }
  .signature img { display: inline-block; width: 106px; height: auto; }
  .footer { position: fixed; bottom: -29px; left: -32px; right: -32px; padding: 8px 16px; text-align: center; background: #962a31; color: #fff; font-size: 7.8px; }

  /* Large statements retain compact fee rows and payment details to paginate neatly. */
  .long-invoice table.fees { font-size: 8px; }
  .long-invoice table.fees td { padding: 4px 6px; line-height: 1.25; }
  .long-invoice table.fees th { padding: 5px 6px; }
  .long-invoice .muted, .long-invoice .green { font-size: 7.2px; }
  .long-invoice .payment-grid td { padding: 6px 7px; font-size: 7.7px; line-height: 1.36; }
  .long-invoice .payment-grid .bank-title { font-size: 8px; }
  .long-invoice .scan { width: 78px; }
  .long-invoice .instruction { font-size: 7.6px; line-height: 1.4; }
  .long-invoice .signature img { width: 86px; }
</style>
</head>
<body class="{{ count($items) >= 14 ? 'long-invoice' : 'short-invoice' }}">
@php
  $money = fn($minor) => \App\Services\Money::format(max(0, (int) $minor));
  $currency = strtoupper((string) ($i->currency ?? ''));
  $invoiceTotal = max(0, (int)($i->total_minor ?? 0));
  $paidOnInvoice = min($invoiceTotal, max(0, (int)($i->paid_minor ?? 0)));
  $invoiceBalance = max(0, $invoiceTotal - $paidOnInvoice);
  $olderBalance = max(0, (int)($previous ?? 0));
  $payable = $invoiceBalance + $olderBalance;
  $fullyPaid = ($invoiceBalance === 0 && $invoiceTotal > 0);
  $isVoid = in_array(strtolower((string)($i->status ?? '')), ['void', 'voided'], true);
  $payText = trim((string)($branding?->payment_instructions ?? ''));
  $hasSavedBankAccounts = (bool) preg_match('/\bIBAN\b|\b(?:A\/C|ACCOUNT)\s*(?:NUMBER|NO\.?|#)?\s*[:#]\s*\d{6,}/i', $payText);
  $qrPath = public_path('assets/receipt-qr.jpg');
  $signPath = public_path('assets/receipt-sign.jpg');
  $stampPath = public_path('assets/fee-paid-stamp.png');
  $qrSrc = is_file($qrPath) ? 'data:image/jpeg;base64,'.base64_encode(file_get_contents($qrPath)) : null;
  $signSrc = is_file($signPath) ? 'data:image/jpeg;base64,'.base64_encode(file_get_contents($signPath)) : null;
  $stampSrc = is_file($stampPath) ? 'data:image/png;base64,'.base64_encode(file_get_contents($stampPath)) : null;
  $footerPhone = trim((string)($branding?->footer_phone ?? '')) ?: '+92 335 524 5551';
  $footerEmail = trim((string)($branding?->footer_email ?? '')) ?: 'acivs2021@gmail.com';
@endphp

@if(!empty($banner))
  <img class="banner" src="{{ $banner }}" alt="IQRA Virtual School">
@endif
<div class="title">{{ $isVoid ? 'VOID FEE INVOICE' : 'FEE INVOICE' }}</div>
<div class="subtitle">IQRA VIRTUAL SCHOOL &bull; DEPARTMENT OF ACCOUNTS</div>

<table class="ref">
  <tr>
    <td style="width:48%;"><span class="k">Invoice Reference</span><span class="v">{{ $i->invoice_number ?? 'N/A' }}</span></td>
    <td style="width:32%;"><span class="k">Department</span><span class="v">{{ $branding?->display_name ?? $dept?->name ?? 'School' }}</span></td>
    <td style="width:20%;"><span class="k">Currency</span><span class="v">{{ $currency }}</span></td>
  </tr>
</table>
<table class="meta">
  <tr>
    <td class="key">{{ $parentDisplay['label'] ?? "Parent's Name" }}:</td>
    <td class="val">{{ \App\Services\GuardianNameResolver::clean($parentDisplay['name'] ?? null) ?? (\App\Services\GuardianNameResolver::clean($family?->guardian_name ?? null) ?? 'Not provided') }}</td>
    <td class="key">Issue Date:</td>
    <td class="val">{{ !empty($i->issued_on) ? \Carbon\Carbon::parse($i->issued_on)->format('d-F-Y') : 'N/A' }}</td>
  </tr>
  <tr>
    <td class="key">Family Code:</td>
    <td class="val">{{ $family?->family_code ?: 'Not assigned' }}</td>
    <td class="key">Due Date:</td>
    <td class="val" style="color:#a0272f;">{{ !empty($i->due_date) ? \Carbon\Carbon::parse($i->due_date)->format('d-F-Y') : 'N/A' }}</td>
  </tr>
</table>

<div class="section">FEE PARTICULARS ({{ $currency }})</div>
<table class="fees">
  <thead><tr>
    <th style="width:13%;">Reg / GR</th>
    <th style="width:49%;">Description / Student</th>
    <th style="width:20%;">Month / Period</th>
    <th style="width:18%;" class="right">Net Amount</th>
  </tr></thead>
  <tbody>
  @forelse($items as $item)
    <tr>
      <td>{{ $item->gr_number ?: '—' }}</td>
      <td>
        <span class="desc">{{ $item->description ?: 'Fee Charge' }}</span><br>
        <span class="muted">{{ $item->name ?? 'Student' }}</span>
        @if((int)($item->discount_minor ?? 0) > 0)
          <br><span class="green">Discount: {{ $currency }} {{ $money($item->discount_minor) }}</span>
        @endif
        @if(($item->status ?? '') === 'exempted')
          <br><span class="green">EXEMPTED</span>
        @elseif((int)($item->exemption_minor ?? 0) > 0)
          <br><span class="green">Exemption: {{ $currency }} {{ $money($item->exemption_minor) }}</span>
        @endif
      </td>
      <td>{{ !empty($item->period_start) ? \Carbon\Carbon::parse($item->period_start)->format('M Y') : '—' }}</td>
      <td class="num">{{ $money($item->net_minor ?? 0) }}</td>
    </tr>
  @empty
    <tr><td colspan="4" style="text-align:center;">No fee particulars recorded.</td></tr>
  @endforelse
  </tbody>
</table>

<table class="summary">
  <tr class="final"><td class="label">{{ $isVoid ? 'ACCOUNT SUMMARY (VOID INVOICE)' : 'TOTAL CURRENTLY PAYABLE' }}:</td><td class="money">{{ $currency }} {{ $money($payable) }}</td></tr>
</table>

@if($isVoid)
  <div class="note"><strong>VOID:</strong> This invoice is void. Do not pay against this document. Contact Accounts for a current statement.</div>
@elseif($fullyPaid)
  <div class="paid">
    @if($stampSrc)<img src="{{ $stampSrc }}" alt="Paid" style="height:58px;width:auto;vertical-align:middle;">@else PAID @endif
    <span style="font-size:8px; color:#385747;">Invoice balance fully settled</span>
  </div>
  @if($olderBalance > 0)
    <div class="note">This <strong>invoice</strong> is fully paid. The earlier unpaid invoices shown above remain separate outstanding obligations.</div>
  @endif
@elseif($paidOnInvoice > 0)
  <div class="note"><strong>PARTIALLY PAID:</strong> {{ $currency }} {{ $money($paidOnInvoice) }} has already been credited to this invoice.</div>
@endif

@if(!$isVoid && $payable > 0)
<div class="settlement">
  <div class="section">PAYMENT INFORMATION &amp; BANK ACCOUNTS</div>
  <table class="payment-grid">
    <tr>
      @if($hasSavedBankAccounts)
        <td colspan="2" style="width:78%;"><div class="bank">{{ $payText }}</div></td>
      @else
        <td style="width:39%;">
          <span class="bank-title">IQRA VIRTUALSOLUTIONS</span>
          Meezan Bank, Peshawar<br>
          IBAN: PK71MEZN0007810111367793<br>
          Account: 07810111367793<br>
          Branch: 0781<br>
          Contact: +92 335 524 5551
        </td>
        <td style="width:39%;">
          <span class="bank-title">IVSGLOBAL</span>
          Mashreq Bank<br>
          IBAN: AE620330000019101455931<br>
          Account: 019101455931<br>
          Mobile: +971528838128<br>
          Ajman Industrial Area 2, UAE
        </td>
      @endif
      <td style="width:22%;text-align:center;">
        @if($qrSrc)<img class="scan" src="{{ $qrSrc }}" alt="Scan to pay">@else Scan to pay details via Accounts @endif
      </td>
    </tr>
  </table>
  @if(!$hasSavedBankAccounts && $payText !== '')
    <div class="pay-note">{{ $payText }}</div>
  @endif
  <div class="instruction"><strong>Instructions for Parents / Guardians</strong><br>
    &bull; Use the official payment method and include the invoice reference and student GR number.<br>
    &bull; Submit the transfer slip using the official form: https://forms.gle/W4y3Q1VjyU8cRDvp7<br>
    &bull; Keep your proof of payment; contact Accounts if a receipt has not arrived within 72 hours.<br>
    &bull; Rejected or refunded transactions are not treated as paid.
  </div>
  @if($signSrc)<div class="signature"><img src="{{ $signSrc }}" alt="Accounts Manager Signature"></div>@endif
</div>
@else
  <div class="instruction" style="margin-top:10px;">For payment allocation details, please refer to your official payment receipt. This document is not proof that unrelated family invoices have been settled.</div>
@endif
<div class="footer">IQRA VIRTUAL SCHOOL &bull; {{ $footerPhone }} &bull; {{ $footerEmail }} &bull; Invoice {{ $i->invoice_number }}</div>
</body>
</html>
