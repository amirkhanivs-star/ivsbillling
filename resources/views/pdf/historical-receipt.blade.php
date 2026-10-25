<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Paid Receipt</title>
<style>
  @page { size: A4 portrait; margin: 30px 40px 48px; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: DejaVu Sans, Arial, sans-serif; font-size: 10px; line-height: 1.42; color: #1f2937; }
  .banner { display: block; width: 100%; max-height: 140px; margin: 0 auto; }
  .title { color: #a02730; text-align: center; font-size: 19px; font-weight: bold; margin: 14px 0 12px; }
  .details { width: 100%; margin: 6px 0 13px; border-collapse: collapse; table-layout: fixed; }
  .details td { padding: 4px 5px; font-size: 9.5px; vertical-align: top; word-wrap: break-word; }
  .label { color: #5c6877; }
  .status { font-weight: bold; color: #15803d; }
  .data { border-collapse: collapse; table-layout: fixed; width: 100%; font-size: 9px; margin-top: 7px; }
  .data thead { display: table-header-group; }
  .data th { background: #962a31; color: #fff; text-align: left; padding: 7px 7px; }
  .data td { border-bottom: 1px solid #e5e7eb; padding: 6px 7px; vertical-align: top; word-wrap: break-word; }
  .data tbody tr { page-break-inside: avoid; }
  .data tbody tr:nth-child(even) td { background: #fafafa; }
  .data .amount { text-align: right; white-space: nowrap; }
  .data .total-row td { font-size: 10px; color: #962a31; font-weight: bold; border-top: 2px solid #962a31; border-bottom: 1px solid #962a31; padding: 8px 7px; background: #fff; }
  .small { color: #6b7280; font-size: 8px; line-height: 1.45; }
  .confirmation { margin: 13px 0 4px; color: #4b5563; font-size: 9px; }
  .closing { width: 100%; margin-top: 7px; border-collapse: collapse; page-break-inside: avoid; }
  .closing td { border: 0; vertical-align: middle; }
  .paid-stamp { display: block; width: 90px; height: auto; margin-left: auto; }
  .signature { display: block; width: 105px; height: auto; margin-left: auto; }
  .source { margin-top: 7px; font-size: 7.5px; color: #6b7280; }
  .foot { position: fixed; bottom: -32px; left: -40px; right: -40px; background: #962a31; color: #fff; text-align: center; padding: 9px 12px; font-size: 8.5px; }
</style>
</head>
<body>
@php
  $paidStampPath = public_path('assets/fee-paid-stamp.png');
  $signaturePath = public_path('assets/receipt-sign.jpg');
  $paidStampSrc = is_file($paidStampPath)
    ? 'data:image/png;base64,'.base64_encode(file_get_contents($paidStampPath))
    : null;
  $signatureSrc = is_file($signaturePath)
    ? 'data:image/jpeg;base64,'.base64_encode(file_get_contents($signaturePath))
    : null;
  $method = trim((string) ($receipt->payment_method ?? ''));
@endphp

@if(!empty($banner))
  <img class="banner" src="{{ $banner }}" alt="IQRA Virtual School">
@endif

<h1 class="title">PAID RECEIPT</h1>

<table class="details">
  <tr>
    <td class="label" style="width: 16%;">Paid By:</td>
    <td style="width: 34%;"><strong>{{ $resolvedGuardian ?? (\App\Services\GuardianNameResolver::isValid($family->guardian_name ?? null) ? $family->guardian_name : ($receipt->family_code ? 'Family ' . $receipt->family_code : 'Parent / Guardian')) }}</strong></td>
    <td class="label" style="width: 18%;">Payment Date:</td>
    <td style="width: 32%;"><strong>{{ !empty($receipt->payment_date) ? \Carbon\Carbon::parse($receipt->payment_date)->format('d-F-Y') : '—' }}</strong></td>
  </tr>
  <tr>
    <td class="label">Slip Number:</td>
    <td><strong>{{ $receipt->slip_number }}</strong></td>
    <td class="label">Status:</td>
    <td class="status">PAID</td>
  </tr>
  <tr>
    <td class="label">Family Code:</td>
    <td><strong>{{ $receipt->family_code ?: ($family->family_code ?? '—') }}</strong></td>
    <td class="label">Currency:</td>
    <td><strong>{{ $receipt->currency }}</strong></td>
  </tr>
  <tr>
    <td class="label">Method:</td>
    <td>{{ $method !== '' ? strtoupper($method) : 'Not recorded' }}</td>
    <td class="label">Source:</td>
    <td>MySkool records</td>
  </tr>
</table>

<table class="data">
  <thead>
    <tr>
      <th style="width: 28%;">Student</th>
      <th style="width: 37%;">Fee Particular / Item</th>
      <th style="width: 15%;">Billing Month</th>
      <th style="width: 20%; text-align: right;">Amount ({{ $receipt->currency }})</th>
    </tr>
  </thead>
  <tbody>
    @forelse($receipt->items as $row)
      <tr>
        <td>
          <strong>{{ $row->name }}</strong><br>
          <span class="small">GR: {{ $row->gr_number }}</span>
        </td>
        <td>
          <strong>{{ $row->fee_type_name }}</strong>
          @if(!empty($row->description) && $row->description !== $row->fee_type_name)
            <br><span class="small">{{ $row->description }}</span>
          @endif
        </td>
        <td>{{ $row->period_start ?: '—' }}</td>
        <td class="amount"><strong>{{ number_format($row->amount, 2) }}</strong></td>
      </tr>
    @empty
      <tr>
        <td colspan="4" style="text-align: center; color: #6b7280; padding: 12px;">No fee items are available for this receipt.</td>
      </tr>
    @endforelse
    <tr class="total-row">
      <td colspan="3" style="text-align: right;">TOTAL RECEIVED:</td>
      <td class="amount">{{ $receipt->currency }} {{ number_format($receipt->amount, 2) }}</td>
    </tr>
  </tbody>
</table>

<p class="confirmation">
  This receipt confirms the payment amount and the fee allocations listed above.
</p>

<table class="closing">
  <tr>
    <td style="width: 70%;"></td>
    <td style="width: 30%; text-align: right;">
      @if($paidStampSrc)
        <img class="paid-stamp" src="{{ $paidStampSrc }}" alt="Fee Paid">
      @endif
    </td>
  </tr>
  @if($signatureSrc)
    <tr>
      <td></td>
      <td style="text-align: right; padding-top: 5px;">
        <img class="signature" src="{{ $signatureSrc }}" alt="Accounts Signature">
      </td>
    </tr>
  @endif
</table>

<div class="source">
  Payment source: imported MySkool financial records.
  @if(!empty($receipt->batch_id))
    Batch reference: {{ $receipt->batch_id }}.
  @endif
</div>

<div class="foot">
  IQRA VIRTUAL SCHOOL &middot; PAYMENT RECEIPT &middot; Slip #{{ $receipt->slip_number }}
</div>
</body>
</html>
