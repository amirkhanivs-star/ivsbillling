<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Official Payment Receipt</title>
<style>
  @page { size:A4 portrait; margin:28px 36px 48px; }
  * { box-sizing:border-box; }
  body { margin:0; font-family:DejaVu Sans,sans-serif; font-size:8.8px; line-height:1.42; color:#253044; }
  .banner { display:block; width:100%; height:auto; margin:0 0 6px; }
  .title { text-align:center; font-size:17px; font-weight:bold; color:#972930; margin:3px 0 2px; letter-spacing:.4px; }
  .subtitle { text-align:center; text-transform:uppercase; font-size:7.7px; color:#687282; margin-bottom:10px; }
  .status { border:1px solid #d7e6df; color:#16643d; background:#f2faf5; text-align:center; font-size:8px; padding:5px 7px; margin:7px 0 9px; }
  .status.void { background:#fff0f1; border-color:#f5c8ce; color:#ae2032; }
  .meta { width:100%; table-layout:fixed; border-collapse:collapse; margin:3px 0 12px; }
  .meta td { padding:3px 5px; vertical-align:top; overflow-wrap:break-word; }
  .meta .k { width:16%; font-size:7.7px; color:#677180; }
  .meta .v { width:34%; font-weight:bold; }
  .bar { padding:6px 8px; background:#972930; color:white; font-size:9px; text-transform:uppercase; font-weight:bold; }
  .fees { table-layout:fixed; width:100%; border-collapse:collapse; font-size:8px; margin-bottom:6px; }
  .fees thead { display:table-header-group; }
  .fees th { background:#b43239; color:#fff; text-align:left; padding:7px 5px; }
  .fees td { border-bottom:1px solid #e5e7eb; padding:6px 5px; vertical-align:top; word-wrap:break-word; }
  .fees tbody tr:nth-child(even) td { background:#fbfbfc; }
  .fees tr { page-break-inside:avoid; }
  .muted { color:#6b7685; font-size:7.5px; }
  .num { text-align:right; white-space:nowrap; }
  .summary { page-break-inside:avoid; width:100%; border-collapse:collapse; margin-top:9px; }
  .summary td { padding:6px 8px; text-align:right; border-bottom:1px solid #e9ebef; }
  .summary .money { width:27%; white-space:nowrap; }
  .summary .total td { background:#962a31; color:white; font-size:10px; padding:8px; font-weight:bold; border:0; }
  .summary .family td { background:#f8fafc; }
  .note { padding:8px; border:1px solid #e4e7eb; background:#f8fafc; margin-top:9px; font-size:8px; line-height:1.5; }
  .stamp { text-align:right; margin:10px 0 3px; }
  .stamp img { height:68px; width:auto; }
  .voidword { color:#ad2430; font-weight:bold; font-size:14px; }
  .sign { text-align:right; margin-top:7px; }
  .sign img { width:107px; height:auto; }
  .footer { position:fixed; bottom:-33px; left:-36px; right:-36px; background:#962a31; color:white; text-align:center; padding:9px 20px; font-size:7.7px; }
</style>
</head>
<body>
@php
  $money=fn($minor)=>\App\Services\Money::format(max(0,(int)$minor));
  $currency=strtoupper((string)($p->currency ?? ''));
  $isReversed=($p->status ?? 'received')==='reversed';
  $stampPath=public_path('assets/fee-paid-stamp.png');
  $signPath=public_path('assets/receipt-sign.jpg');
  $stampSrc=is_file($stampPath)?'data:image/png;base64,'.base64_encode(file_get_contents($stampPath)):null;
  $signSrc=is_file($signPath)?'data:image/jpeg;base64,'.base64_encode(file_get_contents($signPath)):null;
  $footerPhone=trim((string)($branding?->footer_phone ?? '')) ?: '+92 325 524 5551';
  $footerEmail=trim((string)($branding?->footer_email ?? '')) ?: 'acivs2021@gmail.com';
@endphp
@if(!empty($banner))<img class="banner" src="{{ $banner }}" alt="IQRA Virtual School">@endif
<div class="title">{{ $isReversed ? 'REVERSED PAYMENT RECEIPT' : 'OFFICIAL PAYMENT RECEIPT' }}</div>
<div class="subtitle">IQRA Virtual School &bull; Department of Accounts</div>
<div class="status {{ $isReversed ? 'void' : '' }}">
  @if($isReversed) <strong>VOID / REVERSED</strong> &bull; This payment no longer counts as received.
  @else <strong>PAYMENT RECORDED</strong> &bull; Official acknowledgement of a recorded payment.
  @endif
</div>
<table class="meta">
  <tr><td class="k">Paid By:</td><td class="v">{{ $resolvedGuardian ?? (\App\Services\GuardianNameResolver::isValid($family?->guardian_name) ? $family->guardian_name : ($family?->family_code ?: 'Not provided')) }}</td><td class="k">Receipt Date:</td><td class="v">{{ !empty($p->received_on) ? \Carbon\Carbon::parse($p->received_on)->format('d-F-Y') : 'N/A' }}</td></tr>
  <tr><td class="k">Receipt No:</td><td class="v">{{ $p->receipt_number }}</td><td class="k">Currency:</td><td class="v">{{ $currency }}</td></tr>
  <tr><td class="k">Family Code:</td><td class="v">{{ $family?->family_code ?: 'Not assigned' }}</td><td class="k">Recorded:</td><td class="v">{{ !empty($p->created_at) ? \Carbon\Carbon::parse($p->created_at)->format('d-M-Y H:i') : 'N/A' }}</td></tr>
  @if(!empty($p->method) || !empty($p->reference))
    <tr><td class="k">Payment Method:</td><td class="v">{{ !empty($p->method) ? ucfirst((string)$p->method) : 'Not recorded' }}</td><td class="k">Reference:</td><td class="v">{{ $p->reference ?: 'Not provided' }}</td></tr>
  @endif
  @if($isReversed)
    <tr><td class="k">Reversed On:</td><td class="v">{{ !empty($p->reversed_at) ? \Carbon\Carbon::parse($p->reversed_at)->format('d-F-Y') : 'Not recorded' }}</td><td class="k">Reason:</td><td class="v">{{ $p->reversal_reason ?: 'Not recorded' }}</td></tr>
  @endif
</table>
<div class="bar">PAYMENT ALLOCATION DETAILS ({{ $currency }})</div>
<table class="fees">
  <thead><tr>
    <th style="width:18%;">Student / GR</th>
    <th style="width:27%;">Fee Particular / Description</th>
    <th style="width:12%;">Month</th>
    <th style="width:11%;" class="num">Original</th>
    <th style="width:9%;" class="num">Discount</th>
    <th style="width:11%;" class="num">Applied</th>
  </tr></thead>
  <tbody>
    @forelse($items as $row)
      <tr>
        <td><strong>{{ $row->name }}</strong><br><span class="muted">GR: {{ $row->gr_number ?: '—' }}</span></td>
        <td><strong>{{ $row->fee_type_name ?: 'Fee' }}</strong>
          @if(!empty($row->description) && $row->description !== $row->fee_type_name)<br><span class="muted">{{ $row->description }}</span>@endif
        </td>
        <td>{{ !empty($row->period_start) ? \Carbon\Carbon::parse($row->period_start)->format('M Y') : '—' }}</td>
        <td class="num">{{ $money($row->original_amount_minor ?? $row->amount_minor) }}</td>
        <td class="num">{{ (int)($row->discount_minor ?? 0) > 0 ? $money($row->discount_minor) : '—' }}</td>
        <td class="num" style="font-weight:bold;">{{ $money($row->amount_minor) }}</td>
      </tr>
    @empty
      <tr><td colspan="6" style="text-align:center;padding:10px;">No individual fee allocations were recorded.</td></tr>
    @endforelse
    @if((int)($p->unallocated_minor ?? 0) > 0)
      <tr><td>—</td><td><strong>Advance Credit</strong></td><td>Future billing</td><td class="num">—</td><td class="num">—</td><td class="num">{{ $money($p->unallocated_minor) }}</td></tr>
    @endif
  </tbody>
</table>
<table class="summary">
  <tr class="total"><td> {{ $isReversed ? 'ORIGINAL PAYMENT AMOUNT (REVERSED)' : 'TOTAL PAYMENT RECEIVED' }}:</td><td class="money">{{ $currency }} {{ $money($p->amount_minor ?? 0) }}</td></tr>
  <tr class="family"><td>Remaining family billed balance ({{ $currency }}):</td><td class="money">{{ $currency }} {{ $money($remainingBalanceMinor ?? 0) }}</td></tr>
</table>
@if($isReversed)
  <div class="stamp"><span class="voidword">VOID / REVERSED &bull; NOT PROOF OF PAYMENT</span></div>
  <div class="note">The original payment was reversed. This document is retained for audit reference only. Please contact Accounts for a current balance.</div>
@else
  <div class="stamp">@if($stampSrc)<img src="{{ $stampSrc }}" alt="Paid stamp">@else <strong style="color:#15803d;font-size:14px;">PAYMENT RECEIVED</strong> @endif</div>
  <div class="note">
    Please retain this document as payment evidence.
  </div>
  @if($signSrc)<div class="sign"><img src="{{ $signSrc }}" alt="Accounts Manager Signature"></div>@endif
@endif
<div class="footer">IQRA VIRTUAL SCHOOL &bull; {{ $footerPhone }} &bull; {{ $footerEmail }} &bull; Receipt {{ $p->receipt_number }}</div>
</body>
</html>
