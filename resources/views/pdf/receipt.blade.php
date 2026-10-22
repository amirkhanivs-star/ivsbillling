<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
@page { margin: 30px 40px; }
body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10px; color: #1f2937; }
.banner { width: 100%; max-height: 140px; object-fit: contain; }
.title { color: {{ ($p->status ?? 'received') === 'reversed' ? '#be123c' : '#92282e' }}; text-align: center; font-size: 20px; font-weight: bold; margin: 12px 0; }
.details { width: 100%; margin: 12px 0 16px 0; border-collapse: collapse; }
.details td { padding: 4px 6px; font-size: 10px; }
.data { border-collapse: collapse; width: 100%; font-size: 9px; margin-top: 8px; }
.data th { background: {{ ($p->status ?? 'reversed') === 'reversed' ? '#be123c' : '#962a31' }}; color: white; text-align: left; padding: 6px 8px; }
.data td { border-bottom: 1px solid #e5e7eb; padding: 6px 8px; vertical-align: top; }
.total-row td { font-size: 11px; font-weight: bold; border-top: 2px solid #962a31; padding: 6px 8px; }
.highlight-row td { background: #f9fafb; font-weight: 600; padding: 6px 8px; }
.badge-reversed { color: #be123c; font-weight: bold; }
.badge-active { color: #15803d; font-weight: bold; }
.bar { background: {{ ($p->status ?? 'received') === 'reversed' ? '#be123c' : '#962a31' }}; color: white; text-align: center; padding: 5px; margin-top: 18px; font-weight: bold; font-size: 9px; }
.fine { margin-top: 16px; font-size: 8.5px; line-height: 1.5; color: #4b5563; }
.foot { position: fixed; bottom: -15px; left: -40px; right: -40px; background: {{ ($p->status ?? 'received') === 'reversed' ? '#be123c' : '#962a31' }}; color: white; text-align: center; padding: 8px; font-size: 9px; }
</style>
</head>
<body>

@if(!empty($banner))
  <img class="banner" src="{{$banner}}" alt="Banner">
@endif

<h1 class="title">{{ ($p->status ?? 'received') === 'reversed' ? 'REVERSED PAYMENT RECEIPT' : 'OFFICIAL PAYMENT RECEIPT' }}</h1>

<table class="details">
  <tr>
    <td style="width: 15%;"><strong>Paid By:</strong></td>
    <td style="width: 35%;">{{ $family->guardian_name ?? $family->family_code }}</td>
    <td style="width: 20%;"><strong>Receipt Date:</strong></td>
    <td style="width: 30%;"><b>{{ \Carbon\Carbon::parse($p->received_on)->format('d-F-Y') }}</b></td>
  </tr>
  <tr>
    <td><strong>Receipt No:</strong></td>
    <td><span style="font-family: monospace; font-size: 11px; font-weight: bold;">{{ $p->receipt_number }}</span></td>
    <td><strong>Payment Status:</strong></td>
    <td>
      @if(($p->status ?? 'received') === 'reversed')
        <span class="badge-reversed">REVERSED on {{ \Carbon\Carbon::parse($p->reversed_at)->format('d-F-Y') }} ({{ $p->reversal_reason }})</span>
      @else
        <span class="badge-active">Payment Received</span>
      @endif
    </td>
  </tr>
  <tr>
    <td><strong>Family ID:</strong></td>
    <td><strong>{{ $family->family_code }}</strong></td>
    <td><strong>Currency:</strong></td>
    <td><b>{{ $p->currency }}</b></td>
  </tr>
  <tr>
    <td><strong>Method / Ref:</strong></td>
    <td>{{ strtoupper($p->method ?? 'Cash') }} @if(!empty($p->reference)) (Ref: {{ $p->reference }}) @endif</td>
    <td><strong>Recorded At:</strong></td>
    <td>{{ \Carbon\Carbon::parse($p->created_at)->format('d-M-Y H:i') }}</td>
  </tr>
</table>

<table class="data">
  <thead>
    <tr>
      <th style="width: 20%;">Student</th>
      <th style="width: 22%;">Fee Type / Item</th>
      <th style="width: 14%;">Billing Month</th>
      <th style="width: 14%; text-align: right;">Original</th>
      <th style="width: 12%; text-align: right;">Discount</th>
      <th style="width: 18%; text-align: right;">Applied ({{ $p->currency }})</th>
    </tr>
  </thead>
  <tbody>
    @forelse($items as $row)
      <tr>
        <td>
          <strong>{{ $row->name }}</strong><br>
          <span style="color: #6b7280; font-size: 8px;">GR: {{ $row->gr_number }}</span>
        </td>
        <td>
          <strong>{{ $row->fee_type_name }}</strong>
          @if($row->description && $row->description !== $row->fee_type_name)
            <br><span style="color: #4b5563; font-size: 8px;">{{ $row->description }}</span>
          @endif
        </td>
        <td>
          @if(!empty($row->period_start))
            {{ \Carbon\Carbon::parse($row->period_start)->format('M Y') }}
          @else
            —
          @endif
        </td>
        <td style="text-align: right;">
          {{ \App\Services\Money::format($row->original_amount_minor ?? $row->amount_minor) }}
        </td>
        <td style="text-align: right; color: {{ ($row->discount_minor ?? 0) > 0 ? '#15803d' : '#9ca3af' }};">
          {{ ($row->discount_minor ?? 0) > 0 ? \App\Services\Money::format($row->discount_minor) : '—' }}
        </td>
        <td style="text-align: right; font-weight: bold;">
          {{ \App\Services\Money::format($row->amount_minor) }} {{ $p->currency }}
        </td>
      </tr>
    @empty
      <tr>
        <td colspan="6" style="text-align: center; color: #6b7280; padding: 12px;">No specific item allocations recorded.</td>
      </tr>
    @endforelse

    @if(($p->unallocated_minor ?? 0) > 0)
      <tr style="background: #fffbeb;">
        <td>—</td>
        <td><strong>Advance Credit</strong></td>
        <td>Future billing</td>
        <td style="text-align: right;">—</td>
        <td style="text-align: right;">—</td>
        <td style="text-align: right; font-weight: bold; color: #b45309;">
          {{ \App\Services\Money::format($p->unallocated_minor) }} {{ $p->currency }}
        </td>
      </tr>
    @endif

    <tr class="total-row" style="color: #962a31;">
      <td colspan="5" style="text-align: right;">TOTAL RECEIVED:</td>
      <td style="text-align: right;">{{ $p->currency }} {{ \App\Services\Money::format($p->amount_minor) }}</td>
    </tr>

    <tr class="highlight-row">
      <td colspan="5" style="text-align: right; color: #4b5563;">Remaining Family Balance ({{ $p->currency }}):</td>
      <td style="text-align: right; color: {{ ($remainingBalanceMinor ?? 0) > 0 ? '#b91c1c' : '#15803d' }};">
        {{ $p->currency }} {{ \App\Services\Money::format($remainingBalanceMinor ?? 0) }}
      </td>
    </tr>
  </tbody>
</table>

<p class="fine">
  This official receipt confirms that the amount shown has been recorded by the Accounts Department of IQRA Virtual School. 
  Payments are allocated across selected fee line items transactionally. Any excess unallocated amount is held safely as advance credit toward upcoming fees.
</p>

@if(($p->status ?? 'received') !== 'reversed')
  <div style="text-align: right; margin-top: 10px;">
    @if(file_exists(public_path('assets/fee-paid-stamp.png')))
      <img src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path('assets/fee-paid-stamp.png'))) }}" width="75" alt="Paid">
    @else
      <div style="display: inline-block; border: 2px solid #15803d; color: #15803d; font-weight: bold; font-size: 14px; padding: 4px 10px; transform: rotate(-5deg);">PAID</div>
    @endif
  </div>
@else
  <div style="text-align: right; margin-top: 10px; font-size: 18px; font-weight: bold; color: #be123c;">
    VOID / REVERSED
  </div>
@endif

<div class="bar">PAYMENT &amp; ALLOCATION POLICY</div>
<div class="fine">
  Please retain this receipt for your records. For questions regarding your account statement, fee breakdowns, or advance credit balance, please contact accounts@iqraschool.edu.sa.
</div>

<div style="text-align: right; margin-top: 8px;">
  @if(file_exists(public_path('assets/receipt-sign.jpg')))
    <img src="data:image/jpeg;base64,{{ base64_encode(file_get_contents(public_path('assets/receipt-sign.jpg'))) }}" width="115" alt="Accounts Signature">
  @endif
</div>

<div class="foot">
  IQRA VIRTUAL SCHOOL &middot; Financial Management &middot; Official Receipt #{{ $p->receipt_number }}
</div>

</body>
</html>
