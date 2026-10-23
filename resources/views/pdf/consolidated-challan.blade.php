<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
  @page { margin: 28px 36px; }
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9.5px; color: #1f2937; line-height: 1.35; }
  .banner { width: 100%; max-height: 130px; object-fit: contain; }
  .header-table { width: 100%; margin-top: 6px; margin-bottom: 8px; border-bottom: 2px solid #962a31; padding-bottom: 6px; }
  .title { color: #91252b; text-align: center; font-size: 18px; font-weight: bold; margin: 6px 0 2px 0; text-transform: uppercase; letter-spacing: 0.5px; }
  .subtitle { text-align: center; color: #4b5563; font-size: 9px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
  
  .statement-badge { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 4px 8px; border-radius: 4px; font-size: 8.5px; text-align: center; margin-bottom: 8px; }
  .currency-notice { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; padding: 4px 8px; border-radius: 4px; font-size: 8.5px; margin-bottom: 8px; }

  .meta-table { width: 100%; margin-bottom: 10px; border-collapse: collapse; }
  .meta-table td { padding: 3px 5px; font-size: 9px; vertical-align: top; }
  .meta-label { color: #4b5563; font-weight: 600; width: 16%; }
  .meta-val { color: #111827; font-weight: bold; width: 34%; }

  .currency-header { background: #962a31; color: white; padding: 6px 10px; font-weight: bold; font-size: 11px; margin-top: 10px; border-radius: 2px 2px 0 0; }
  .currency-header span { float: right; font-size: 10px; font-weight: normal; }

  .data-table { width: 100%; border-collapse: collapse; font-size: 8.5px; margin-bottom: 8px; }
  .data-table th { background: #b91c1c; color: white; text-align: left; padding: 5px 6px; font-size: 8.5px; }
  .data-table td { border-bottom: 1px solid #e5e7eb; padding: 4.5px 6px; vertical-align: top; }
  .data-table tr:nth-child(even) td { background: #fbfbfb; }

  .student-subheading td { background: #f3f4f6; font-weight: bold; color: #1f2937; font-size: 9px; padding: 4px 6px; border-top: 1px solid #d1d5db; border-bottom: 1px solid #d1d5db; }
  .student-subtotal td { background: #fef3c7; font-weight: bold; color: #92400e; font-size: 8.5px; border-top: 1px solid #f59e0b; padding: 4px 6px; }

  .total-card { width: 100%; border-collapse: collapse; margin-top: 4px; margin-bottom: 12px; }
  .total-card td { padding: 6px 10px; font-size: 11px; font-weight: bold; }
  .total-label { background: #fee2e2; color: #991b1b; text-align: right; width: 75%; border: 1px solid #fca5a5; }
  .total-amount { background: #991b1b; color: white; text-align: right; width: 25%; border: 1px solid #991b1b; font-size: 12px; }

  .multi-curr-summary { width: 100%; border-collapse: collapse; margin-top: 8px; margin-bottom: 12px; background: #fafafa; border: 1px solid #e5e7eb; }
  .multi-curr-summary th { background: #4b5563; color: white; text-align: left; padding: 5px 8px; font-size: 9px; }
  .multi-curr-summary td { padding: 5px 8px; font-size: 9px; border-bottom: 1px solid #e5e7eb; }

  .bar { background: #962a31; color: white; text-transform: uppercase; padding: 4px 8px; font-weight: bold; font-size: 9px; margin-top: 10px; }
  .payment-box { border: 1px solid #d1d5db; padding: 8px; font-size: 8.5px; background: #f9fafb; white-space: pre-wrap; line-height: 1.4; }
  .instr { margin-top: 8px; font-size: 8px; color: #4b5563; line-height: 1.4; }
  .foot { position: fixed; bottom: -14px; left: -36px; right: -36px; background: #962a31; color: white; padding: 6px 36px; text-align: center; font-size: 8px; }
  .page-break { page-break-before: always; }
  .muted { color: #6b7280; font-size: 8px; }
  .pill { display: inline-block; padding: 1px 4px; font-size: 7.5px; border-radius: 3px; font-weight: bold; }
  .pill-disc { background: #e0e7ff; color: #3730a3; }
  .pill-part { background: #fef3c7; color: #92400e; }
</style>
</head>
<body>

@php
  $firstCurrency = true;
@endphp

@foreach($currencyGroups as $currency => $cData)
  @if(!$loop->first)
    <div class="page-break"></div>
  @endif

  @if(!empty($banner))
    <img class="banner" src="{{ $banner }}" alt="Banner">
  @endif

  <div class="title">Consolidated Fee Challan / Outstanding Statement</div>
  <div class="subtitle">Official Statement of Outstanding Fees &mdash; Department of Accounts</div>

  <div class="statement-badge">
    <strong>CONSOLIDATED STATEMENT</strong> &bull; This statement aggregates all currently unpaid fee obligations across outstanding billing periods.
    @if(count($currencyGroups) > 1)
      &bull; <strong>Multi-Currency Scope:</strong> Section for <strong>{{ $currency }}</strong> dues only. Currencies are strictly segregated without conversion.
    @endif
  </div>

  <table class="meta-table">
    <tr>
      @if($mode === 'student')
        <td class="meta-label">Student Name:</td>
        <td class="meta-val">{{ $student->name }}</td>
        <td class="meta-label">Reg # (GR):</td>
        <td class="meta-val"><span style="font-family: monospace; font-size: 11px;">{{ $student->gr_number }}</span></td>
      @else
        <td class="meta-label">Family Code:</td>
        <td class="meta-val"><span style="font-family: monospace; font-size: 11px;">{{ $family->family_code ?: ('FAM-' . $family->id) }}</span></td>
        <td class="meta-label">Total Students:</td>
        <td class="meta-val">{{ count($cData['students']) }} student(s) with {{ $currency }} dues</td>
      @endif
    </tr>
    <tr>
      <td class="meta-label">{{ $parentDisplay['label'] }}:</td>
      <td class="meta-val">{{ $parentDisplay['name'] }}</td>
      @if($mode === 'student')
        <td class="meta-label">Class / Program:</td>
        <td class="meta-val">{{ $enrollment->grade_label ?? 'Class not assigned' }}@if(!empty($student->section_current)) - {{ $student->section_current }}@endif</td>
      @else
        <td class="meta-label">Contact / WhatsApp:</td>
        <td class="meta-val">{{ $family->whatsapp ?: 'Not provided' }}</td>
      @endif
    </tr>
    <tr>
      @if($mode === 'student')
        <td class="meta-label">Family Code:</td>
        <td class="meta-val">{{ $student->family_code ?: 'Not assigned' }}</td>
      @else
        <td class="meta-label">Statement Ref:</td>
        <td class="meta-val" style="font-family: monospace;">{{ $statementNumber }}</td>
      @endif
      <td class="meta-label">Issue Date:</td>
      <td class="meta-val">{{ \Carbon\Carbon::parse($generatedAt)->format('d-F-Y') }}</td>
    </tr>
    @if($mode === 'student')
    <tr>
      <td class="meta-label">Contact / WhatsApp:</td>
      <td class="meta-val">{{ $student->whatsapp ?: 'Not provided' }}</td>
      <td class="meta-label">Statement Ref:</td>
      <td class="meta-val" style="font-family: monospace;">{{ $statementNumber }}</td>
    </tr>
    @endif
  </table>

  <div class="currency-header">
    OUTSTANDING FEE PARTICULARS ({{ $currency }})
    <span>Currency Isolation: {{ $currency }}</span>
  </div>

  <table class="data-table">
    <thead>
      <tr>
        <th style="width: 5%;">#</th>
        <th style="width: 14%;">Month / Period</th>
        @if($mode === 'family')
          <th style="width: 20%;">Student</th>
          <th style="width: 25%;">Particular / Description</th>
        @else
          <th style="width: 41%;">Particular / Description</th>
        @endif
        <th style="width: 18%;">Invoice Ref</th>
        <th style="width: 11%; text-align: right;">Billed</th>
        <th style="width: 11%; text-align: right;">Balance ({{ $currency }})</th>
      </tr>
    </thead>
    <tbody>
      @php $rowIdx = 1; @endphp
      @if($mode === 'student')
        @foreach($cData['items'] as $item)
          @php
            $itemBal = max(0, (int)$item->net_minor - (int)$item->paid_minor);
            $isPartial = (int)$item->paid_minor > 0;
          @endphp
          <tr>
            <td>{{ $rowIdx++ }}</td>
            <td><strong>{{ \Carbon\Carbon::parse($item->period_start)->format('M-Y') }}</strong></td>
            <td>
              <strong>{{ $item->description ?: 'Monthly Fee' }}</strong>
              @if(!empty($item->grade_label) && $item->grade_label !== ($enrollment->grade_label ?? ''))
                <br><span class="muted">Class: {{ $item->grade_label }}</span>
              @endif
              @if($isPartial)
                <br><span class="pill pill-part">Partially Paid (Paid: {{ number_format($item->paid_minor / 100, 2) }})</span>
              @endif
              @if((int)$item->discount_minor > 0)
                <br><span class="pill pill-disc">Discount: {{ number_format($item->discount_minor / 100, 2) }}</span>
              @endif
            </td>
            <td><span style="font-family: monospace; font-size: 8px;">{{ $item->invoice_number }}</span></td>
            <td style="text-align: right;">{{ number_format($item->net_minor / 100, 2) }}</td>
            <td style="text-align: right; font-weight: bold; color: #991b1b;">{{ number_format($itemBal / 100, 2) }}</td>
          </tr>
        @endforeach
      @else
        {{-- Family Mode: Grouped by Student --}}
        @foreach($cData['students'] as $sGroup)
          <tr class="student-subheading">
            <td colspan="6">
              &#128100; {{ $sGroup['student_name'] }} (GR: {{ $sGroup['gr_number'] }}) &bull; {{ $sGroup['grade_label'] ?? 'Class not assigned' }}
            </td>
          </tr>
          @foreach($sGroup['items'] as $item)
            @php
              $itemBal = max(0, (int)$item->net_minor - (int)$item->paid_minor);
              $isPartial = (int)$item->paid_minor > 0;
            @endphp
            <tr>
              <td>{{ $rowIdx++ }}</td>
              <td><strong>{{ \Carbon\Carbon::parse($item->period_start)->format('M-Y') }}</strong></td>
              <td>{{ $sGroup['student_name'] }}</td>
              <td>
                <strong>{{ $item->description ?: 'Monthly Fee' }}</strong>
                @if($isPartial)
                  <br><span class="pill pill-part">Partially Paid (Paid: {{ number_format($item->paid_minor / 100, 2) }})</span>
                @endif
              </td>
              <td><span style="font-family: monospace; font-size: 8px;">{{ $item->invoice_number }}</span></td>
              <td style="text-align: right;">{{ number_format($item->net_minor / 100, 2) }}</td>
              <td style="text-align: right; font-weight: bold; color: #991b1b;">{{ number_format($itemBal / 100, 2) }}</td>
            </tr>
          @endforeach
          <tr class="student-subtotal">
            <td colspan="5" style="text-align: right;">Subtotal for {{ $sGroup['student_name'] }} ({{ $currency }}):</td>
            <td style="text-align: right;">{{ number_format($sGroup['student_subtotal_balance_minor'] / 100, 2) }}</td>
          </tr>
        @endforeach
      @endif
    </tbody>
  </table>

  <table class="total-card">
    <tr>
      <td class="total-label">TOTAL OUTSTANDING PAYABLE ({{ $currency }}):</td>
      <td class="total-amount">{{ $currency }} {{ number_format($cData['total_balance_minor'] / 100, 2) }}</td>
    </tr>
  </table>

  @if(count($currencyGroups) > 1 && $loop->last)
    <div style="margin-top: 10px;">
      <div style="font-weight: bold; color: #111827; font-size: 9.5px; margin-bottom: 4px;">CONSOLIDATED SUMMARY ACROSS CURRENCIES:</div>
      <table class="multi-curr-summary">
        <thead>
          <tr>
            <th>Currency</th>
            <th style="text-align: center;">Total Line Items</th>
            <th style="text-align: right;">Total Amount Payable</th>
            <th>Status / Scope</th>
          </tr>
        </thead>
        <tbody>
          @foreach($currencyGroups as $cName => $summary)
            <tr>
              <td><strong>{{ $cName }}</strong></td>
              <td style="text-align: center;">{{ $mode === 'student' ? count($summary['items']) : $summary['students']->sum(fn($s) => count($s['items'])) }}</td>
              <td style="text-align: right; font-weight: bold; color: #991b1b;">{{ $cName }} {{ number_format($summary['total_balance_minor'] / 100, 2) }}</td>
              <td style="color: #4b5563; font-size: 8px;">Isolated Currency Ledger</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif

  <div class="bar">PAYMENT INSTRUCTIONS & BANK ACCOUNTS</div>
  <div class="payment-box">{{ $branding?->payment_instructions ?: 'Payment can be made via bank transfer or deposit. Please contact the Accounts Department for authorized bank details.' }}</div>

  <div class="instr">
    <strong>Notice to Parents / Guardians:</strong><br>
    &bull; This document is a consolidated statement of existing billed dues. Previous payments already verified have been credited.<br>
    &bull; Please mention the Reg # (GR) and Statement Ref in your bank transfer description.<br>
    &bull; Send the transaction receipt/slip to Accounts via WhatsApp: {{ $branding?->footer_phone ?? '+920000000000' }} or Email: {{ $branding?->footer_email ?? 'accounts@iqra.edu.pk' }}.
  </div>

  <div class="foot">
    IQRA VIRTUAL SCHOOL &bull; Accounts Department &bull; {{ $branding?->footer_phone ?? '' }} &bull; {{ $branding?->footer_email ?? 'accounts@iqra.edu.pk' }}
  </div>

@endforeach

</body>
</html>
