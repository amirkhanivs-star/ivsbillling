<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
@page { margin: 30px 40px; }
body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10px; color: #1f2937; }
.banner { width: 100%; max-height: 140px; object-fit: contain; }
.title { color: #92282e; text-align: center; font-size: 19px; font-weight: bold; margin: 12px 0 4px 0; }
.subtitle { text-align: center; color: #6b7280; font-size: 9px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
.historical-alert { background: #fffbeb; border: 1px solid #fde68a; border-radius: 4px; padding: 7px 10px; margin: 8px 0 12px 0; color: #92400e; font-size: 8.5px; line-height: 1.4; }
.details { width: 100%; margin: 8px 0 14px 0; border-collapse: collapse; }
.details td { padding: 4px 6px; font-size: 10px; }
.data { border-collapse: collapse; width: 100%; font-size: 9px; margin-top: 8px; }
.data th { background: #962a31; color: white; text-align: left; padding: 6px 8px; }
.data td { border-bottom: 1px solid #e5e7eb; padding: 6px 8px; vertical-align: top; }
.total-row td { font-size: 11px; font-weight: bold; border-top: 2px solid #962a31; padding: 6px 8px; }
.highlight-row td { background: #f9fafb; font-weight: 600; padding: 6px 8px; }
.badge-historical { color: #b45309; font-weight: bold; }
.bar { background: #962a31; color: white; text-align: center; padding: 5px; margin-top: 18px; font-weight: bold; font-size: 9px; }
.fine { margin-top: 14px; font-size: 8.5px; line-height: 1.5; color: #4b5563; }
.foot { position: fixed; bottom: -15px; left: -40px; right: -40px; background: #962a31; color: white; text-align: center; padding: 8px; font-size: 9px; }
</style>
</head>
<body>

@if(!empty($banner))
  <img class="banner" src="{{ $banner }}" alt="Banner">
@endif

<h1 class="title">HISTORICAL MYSKOOL PAYMENT RECORD</h1>
<div class="subtitle">Historical MySkool Payment Record &mdash; Reconstructed from Imported Data</div>

<div class="historical-alert">
  <strong>Historical MySkool Payment Record &mdash; Reconstructed from Imported Data</strong><br>
  This payment record has been reconstructed from verified historical MySkool collection records (Batch: {{ $receipt->batch_id }}). 
  It accurately reflects legacy payments received prior to portal migration. This document is a reconstructed ledger record and does not imply an original physical slip scan.
</div>

<table class="details">
  <tr>
    <td style="width: 15%;"><strong>Paid By:</strong></td>
    <td style="width: 35%;">{{ $family->guardian_name ?? ($receipt->family_code ? 'Family ' . $receipt->family_code : 'Parent / Guardian') }}</td>
    <td style="width: 20%;"><strong>Payment Date:</strong></td>
    <td style="width: 30%;"><b>{{ !empty($receipt->payment_date) ? \Carbon\Carbon::parse($receipt->payment_date)->format('d-F-Y') : '—' }}</b></td>
  </tr>
  <tr>
    <td><strong>Slip Number:</strong></td>
    <td><span style="font-family: monospace; font-size: 11px; font-weight: bold;">{{ $receipt->slip_number }}</span></td>
    <td><strong>Record Type:</strong></td>
    <td><span class="badge-historical">Historical Imported Record (Read-Only)</span></td>
  </tr>
  <tr>
    <td><strong>Family Code:</strong></td>
    <td><strong>{{ $receipt->family_code ?: ($family->family_code ?? '—') }}</strong></td>
    <td><strong>Currency:</strong></td>
    <td><b>{{ $receipt->currency }}</b></td>
  </tr>
  <tr>
    <td><strong>Method:</strong></td>
    <td>{{ strtoupper($receipt->payment_method ?? 'Cash') }}</td>
    <td><strong>Source Batch:</strong></td>
    <td><span style="font-size: 8.5px; color: #6b7280;">{{ $receipt->batch_id }}</span></td>
  </tr>
</table>

<table class="data">
  <thead>
    <tr>
      <th style="width: 30%;">Student</th>
      <th style="width: 35%;">Fee Particular / Item</th>
      <th style="width: 15%;">Billing Month</th>
      <th style="width: 20%; text-align: right;">Amount ({{ $receipt->currency }})</th>
    </tr>
  </thead>
  <tbody>
    @forelse($receipt->items as $row)
      <tr>
        <td>
          <strong>{{ $row->name }}</strong><br>
          <span style="color: #6b7280; font-size: 8px;">GR: {{ $row->gr_number }}</span>
        </td>
        <td>
          <strong>{{ $row->fee_type_name }}</strong>
          @if(!empty($row->description) && $row->description !== $row->fee_type_name)
            <br><span style="color: #4b5563; font-size: 8px;">{{ $row->description }}</span>
          @endif
        </td>
        <td>
          {{ $row->period_start ?: '—' }}
        </td>
        <td style="text-align: right; font-weight: bold;">
          {{ number_format($row->amount, 2) }} {{ $receipt->currency }}
        </td>
      </tr>
    @empty
      <tr>
        <td colspan="4" style="text-align: center; color: #6b7280; padding: 12px;">No underlying items found for this historical receipt.</td>
      </tr>
    @endforelse

    <tr class="total-row" style="color: #962a31;">
      <td colspan="3" style="text-align: right;">TOTAL RECEIVED:</td>
      <td style="text-align: right;">{{ $receipt->currency }} {{ number_format($receipt->amount, 2) }}</td>
    </tr>
  </tbody>
</table>

<p class="fine">
  This historical payment receipt confirms that the amount shown was verified from imported MySkool financial archives. 
  All underlying fee lines and student allocations have been preserved without alteration. No discounts, payment methods, or receiving staff have been fabricated.
</p>

<div style="text-align: right; margin-top: 10px;">
  <div style="display: inline-block; border: 2px solid #b45309; color: #b45309; font-weight: bold; font-size: 13px; padding: 4px 10px; transform: rotate(-3deg);">
    HISTORICAL PAYMENT RECORD
  </div>
</div>

<div class="bar">HISTORICAL ARCHIVE &amp; VERIFICATION POLICY</div>
<div class="fine">
  This document is generated for audit and parent records. It is verified against the school's approved import batch. For questions regarding historical ledgers, please contact accounts@iqraschool.edu.sa.
</div>

<div style="text-align: right; margin-top: 8px;">
  @if(file_exists(public_path('assets/receipt-sign.jpg')))
    <img src="data:image/jpeg;base64,{{ base64_encode(file_get_contents(public_path('assets/receipt-sign.jpg'))) }}" width="115" alt="Accounts Signature">
  @endif
</div>

<div class="foot">
  IQRA VIRTUAL SCHOOL &middot; Historical Fee Record &middot; Slip #{{ $receipt->slip_number }}
</div>

</body>
</html>
