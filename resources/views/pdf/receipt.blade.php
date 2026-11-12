@php
  $money = fn($minor) => \App\Services\Money::format(max(0, (int)$minor));
  $currency = strtoupper((string)($p->currency ?? ''));
  $isReversed = ($p->status ?? 'received') === 'reversed';
  $stampPath = public_path('assets/fee-paid-stamp.png');
  $signPath = public_path('assets/receipt-sign.jpg');
  $stampSrc = is_file($stampPath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($stampPath)) : null;
  $signSrc = is_file($signPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($signPath)) : null;
  $footerPhone = trim((string)($branding?->footer_phone ?? '')) ?: '+92325524551';
  $footerEmail = trim((string)($branding?->footer_email ?? '')) ?: 'acivs2021@gmail.com';
  $payerName = $resolvedGuardian ?? (\App\Services\GuardianNameResolver::isValid($family?->guardian_name ?? null) ? $family->guardian_name : ($family?->family_code ? 'Family ' . $family->family_code : 'Parent / Guardian'));
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $isReversed ? 'REVERSED PAYMENT RECEIPT' : 'OFFICIAL PAYMENT RECEIPT' }}</title>
<style>
  @page { size:A4 portrait; margin:10px 28px 28px; }
  * { box-sizing:border-box; }
  body { margin:0; color:#1e293b; font-family:DejaVu Sans,sans-serif; font-size:8.5pt; line-height:1.32; background:#ffffff; }

  /* School Logo and Header */
  .banner-wrap { width:100%; text-align:center; margin:0 0 2px; }
  .banner { display:inline-block; width:56%; max-height:68px; height:auto; vertical-align:top; }

  /* Title Block */
  .header-title-block { text-align:center; margin:1px 0 4px; }
  .receipt-title { color:#982931; font-size:12.5pt; font-weight:bold; letter-spacing:.4px; text-transform:uppercase; margin:0 0 1px; }
  .receipt-subtitle { color:#64748b; font-size:7.5pt; text-transform:uppercase; letter-spacing:.3px; margin:0 0 3px; }

  /* Status Notice */
  .status-notice { border:1px solid #bbf7d0; background:#f0fdf4; color:#166534; font-size:7.5pt; padding:3px 8px; margin:0 0 5px; text-align:center; border-radius:2px; }
  .status-notice.void { border-color:#fecaca; background:#fff5f5; color:#982931; }

  /* Compact Receipt Information Strip */
  .info-strip { width:100%; border-collapse:collapse; table-layout:fixed; margin:2px 0 5px; background:#ffffff; border:1px solid #e2e8f0; }
  .info-strip th { background:#f8fafc; color:#475569; font-size:7.5pt; font-weight:bold; text-transform:uppercase; letter-spacing:.3px; padding:4px 7px; border:1px solid #e2e8f0; text-align:left; vertical-align:middle; width:16%; }
  .info-strip td { padding:4px 7px; font-size:8.5pt; color:#1e293b; font-weight:bold; border:1px solid #e2e8f0; vertical-align:middle; width:34%; word-wrap:break-word; }
  .info-strip .highlight-val { color:#982931; }

  /* Section Title */
  .section-title { font-size:8.5pt; font-weight:bold; color:#982931; text-transform:uppercase; letter-spacing:.3px; margin:4px 0 3px; padding-bottom:2px; border-bottom:1.5px solid #982931; }

  /* Payment Allocation Table */
  .fees-table { width:100%; border-collapse:collapse; table-layout:fixed; font-size:8pt; margin-bottom:4px; }
  .fees-table thead { display:table-header-group; }
  .fees-table th { background:#f1f5f9; color:#334155; font-size:7.5pt; font-weight:bold; text-transform:uppercase; letter-spacing:.3px; padding:4.5px 6px; border-top:1px solid #cbd5e1; border-bottom:1.5px solid #94a3b8; text-align:left; }
  .fees-table th.num { text-align:right; }
  .fees-table td { padding:4.5px 6px; border-bottom:1px solid #e2e8f0; vertical-align:middle; color:#1e293b; font-size:8pt; word-wrap:break-word; }
  .fees-table td.num { text-align:right; white-space:nowrap; }
  .fees-table tr { page-break-inside:avoid; }
  .student-name { font-weight:bold; color:#0f172a; font-size:8pt; }
  .fee-name { font-weight:bold; color:#1e293b; font-size:8pt; }
  .muted-subtext { font-size:7pt; color:#64748b; margin-top:1px; line-height:1.2; }
  .applied-val { font-weight:bold; color:#0f172a; }

  /* Summary Section */
  .total-block { margin:5px 0 6px; page-break-inside:avoid; }
  .total-table { width:100%; border-collapse:collapse; table-layout:fixed; }
  .total-label-cell { text-align:right; padding:6px 10px; font-size:9.5pt; font-weight:bold; color:#1e293b; letter-spacing:.3px; width:70%; background:#fdf2f2; border:1px solid #fecaca; }
  .total-amount-cell { text-align:right; padding:6px 10px; font-size:11.5pt; font-weight:bold; color:#982931; width:30%; white-space:nowrap; background:#fff5f5; border:1px solid #fecaca; border-left:0; }
  .balance-label-cell { text-align:right; padding:4px 10px; font-size:8pt; font-weight:bold; color:#475569; width:70%; background:#f8fafc; border:1px solid #e2e8f0; border-top:0; }
  .balance-amount-cell { text-align:right; padding:4px 10px; font-size:8.5pt; font-weight:bold; color:#1e293b; width:30%; white-space:nowrap; background:#ffffff; border:1px solid #e2e8f0; border-left:0; border-top:0; }

  /* Stamp, Signature & Lower Section */
  .receipt-lower { width:100%; border-collapse:collapse; table-layout:fixed; margin-top:4px; page-break-inside:avoid; }
  .receipt-lower td { border:0; padding:0; vertical-align:top; }
  .receipt-note-col { width:65%; padding-right:14px; }
  .receipt-auth-col { width:35%; text-align:right; vertical-align:top; }

  .receipt-note-box { padding:6px 8px; border:1px solid #e2e8f0; background:#f8fafc; font-size:7.5pt; line-height:1.35; color:#475569; border-radius:2px; }
  .receipt-note-box strong { color:#1e293b; }
  .receipt-note-box.void-note { border-color:#fecaca; background:#fff5f5; color:#982931; }

  .auth-stamp-wrap { text-align:right; margin-bottom:2px; }
  .auth-stamp-wrap img { width:68px; height:auto; display:inline-block; }
  .auth-sign-wrap { text-align:right; margin-top:1px; }
  .auth-sign-wrap img { width:78px; height:auto; display:inline-block; }
  .auth-sign-caption { font-size:6.8pt; color:#64748b; font-weight:bold; margin-top:1px; text-transform:uppercase; letter-spacing:.2px; }

  .void-stamp-box { text-align:right; padding:6px; border:1.5px dashed #dc2626; background:#fff5f5; display:inline-block; }
  .void-stamp-text { color:#dc2626; font-size:11pt; font-weight:bold; letter-spacing:.5px; }
  .void-stamp-sub { font-size:7pt; color:#64748b; }

  /* Footer */
  .footer { position:fixed; bottom:-22px; left:-28px; right:-28px; height:18px; background:#ffffff; border-top:1px solid #982931; color:#475569; text-align:center; font-size:7.8pt; font-weight:normal; line-height:18px; padding:0 20px; box-sizing:border-box; }
</style>
</head>
<body>

<div class="footer">IQRA VIRTUAL SCHOOL &bull; {{ $footerPhone }} &bull; {{ $footerEmail }} &bull; Receipt {{ $p->receipt_number }}</div>

@if(!empty($banner))
  <div class="banner-wrap">
    <img class="banner" src="{{ $banner }}" alt="IQRA Virtual School">
  </div>
@endif

<div class="header-title-block">
  <div class="receipt-title">{{ $isReversed ? 'REVERSED PAYMENT RECEIPT' : 'OFFICIAL PAYMENT RECEIPT' }}</div>
  <div class="receipt-subtitle">IQRA Virtual School &bull; Department of Accounts</div>
</div>

<div class="status-notice {{ $isReversed ? 'void' : '' }}">
  @if($isReversed)
    <strong>VOID / REVERSED</strong> &bull; This payment no longer counts as received.
  @else
    <strong>PAYMENT RECORDED</strong> &bull; Official acknowledgement of a recorded payment.
  @endif
</div>

<table class="info-strip">
  <tr>
    <th>Paid By</th>
    <td>{{ $payerName }}</td>
    <th>Receipt No</th>
    <td class="highlight-val">{{ $p->receipt_number }}</td>
  </tr>
  <tr>
    <th>Family Code</th>
    <td>{{ $family?->family_code ?: 'Not assigned' }}</td>
    <th>Receipt Date</th>
    <td>{{ !empty($p->received_on) ? \Carbon\Carbon::parse($p->received_on)->format('d F Y') : 'N/A' }}</td>
  </tr>
  <tr>
    <th>Currency</th>
    <td>{{ $currency }}</td>
    <th>Recorded</th>
    <td>{{ !empty($p->created_at) ? \Carbon\Carbon::parse($p->created_at)->format('d M Y, H:i') : 'N/A' }}</td>
  </tr>
  @if(!empty($p->method) || !empty($p->reference))
    <tr>
      <th>Payment Method</th>
      <td>{{ !empty($p->method) ? ucfirst((string)$p->method) : 'Not recorded' }}</td>
      <th>Reference</th>
      <td>{{ $p->reference ?: 'Not provided' }}</td>
    </tr>
  @endif
  @if($isReversed)
    <tr>
      <th>Reversed On</th>
      <td>{{ !empty($p->reversed_at) ? \Carbon\Carbon::parse($p->reversed_at)->format('d F Y') : 'Not recorded' }}</td>
      <th>Reason</th>
      <td>{{ $p->reversal_reason ?: 'Not recorded' }}</td>
    </tr>
  @endif
</table>

<div class="section-title">PAYMENT ALLOCATION DETAILS ({{ $currency }})</div>

<table class="fees-table">
  <thead>
    <tr>
      <th style="width:22%;">Student / GR</th>
      <th style="width:29%;">Particular / Description</th>
      <th style="width:13%;">Month</th>
      <th style="width:12%;" class="num">Original</th>
      <th style="width:11%;" class="num">Discount</th>
      <th style="width:13%;" class="num">Paid</th>
    </tr>
  </thead>
  <tbody>
    @forelse($items as $row)
      <tr>
        <td>
          <div class="student-name">{{ $row->name }}</div>
          <div class="muted-subtext">GR: {{ $row->gr_number ?: '—' }}</div>
        </td>
        <td>
          <div class="fee-name">{{ $row->fee_type_name ?: 'Fee' }}</div>
          @if(!empty($row->description) && $row->description !== $row->fee_type_name)
            <div class="muted-subtext">{{ $row->description }}</div>
          @endif
        </td>
        <td>{{ !empty($row->period_start) ? \Carbon\Carbon::parse($row->period_start)->format('M Y') : '—' }}</td>
        <td class="num">{{ $money($row->original_amount_minor ?? $row->amount_minor) }}</td>
        <td class="num">{{ (int)($row->discount_minor ?? 0) > 0 ? $money($row->discount_minor) : '—' }}</td>
        <td class="num applied-val">{{ $money($row->amount_minor) }}</td>
      </tr>
    @empty
      <tr>
        <td colspan="6" style="text-align:center; padding:12px; color:#64748b;">No individual fee allocations were recorded.</td>
      </tr>
    @endforelse
    @if((int)($p->unallocated_minor ?? 0) > 0)
      <tr>
        <td>—</td>
        <td>
          <div class="fee-name">Advance Credit</div>
          <div class="muted-subtext">Unallocated advance payment</div>
        </td>
        <td>Future billing</td>
        <td class="num">—</td>
        <td class="num">—</td>
        <td class="num applied-val">{{ $money($p->unallocated_minor) }}</td>
      </tr>
    @endif
  </tbody>
</table>

<div class="total-block">
  <table class="total-table">
    <tr>
      <td class="total-label-cell">{{ $isReversed ? 'ORIGINAL PAYMENT AMOUNT (REVERSED)' : 'TOTAL PAYMENT RECEIVED' }} ({{ $currency }}):</td>
      <td class="total-amount-cell">{{ $currency }} {{ $money($p->amount_minor ?? 0) }}</td>
    </tr>
    <tr>
      <td class="balance-label-cell">Remaining family billed balance ({{ $currency }}):</td>
      <td class="balance-amount-cell">{{ $currency }} {{ $money($remainingBalanceMinor ?? 0) }}</td>
    </tr>
  </table>
</div>

<table class="receipt-lower">
  <tr>
    <td class="receipt-note-col">
      @if($isReversed)
        <div class="receipt-note-box void-note">
          <strong>VOID / REVERSED PAYMENT NOTICE</strong><br>
          The original payment was reversed. This document is retained for audit reference only. Please contact Accounts for a current balance.
        </div>
      @else
        <div class="receipt-note-box">
          <strong>OFFICIAL PAYMENT ACKNOWLEDGEMENT</strong><br>
          Please retain this official receipt for your records as proof of fee payment. For questions regarding this receipt or your account balance, please contact the Accounts Department.
        </div>
      @endif
    </td>
    <td class="receipt-auth-col">
      @if($isReversed)
        <div class="void-stamp-box">
          <div class="void-stamp-text">VOID / REVERSED</div>
          <div class="void-stamp-sub">Not Proof of Payment</div>
        </div>
      @else
        @if($stampSrc)
          <div class="auth-stamp-wrap">
            <img src="{{ $stampSrc }}" alt="Fee Paid Stamp">
          </div>
        @endif
        @if($signSrc)
          <div class="auth-sign-wrap">
            <img src="{{ $signSrc }}" alt="Authorized Signature">
            <div class="auth-sign-caption">Authorized Signature</div>
          </div>
        @endif
      @endif
    </td>
  </tr>
</table>

</body>
</html>
