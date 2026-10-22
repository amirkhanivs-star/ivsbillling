<!doctype html><html><head><meta charset="utf-8"><style>@page{margin:33px 43px}body{font-family:DejaVu Sans,Arial,sans-serif;font-size:10px;color:#333}.banner{width:100%;height:auto;max-height:157px;object-fit:contain}h1{color:#91252b;text-align:center;font-size:23px;margin:13px 0 11px}.sub{font-size:9px;width:100%;margin-bottom:9px}.sub td{padding:3px}.muted{color:#666}.emph{font-weight:bold;color:#9b222c}.bar{background:#96262e;color:white;text-transform:uppercase;padding:7px;text-align:center;margin:17px 0 7px}.data{width:100%;border-collapse:collapse;font-size:9px}.data th{background:#972931;color:white;text-align:left;padding:6px}.data td{border-bottom:1px solid #ddd;padding:6px}.data .total{border-top:2px solid #922;color:#922;font-weight:bold;font-size:12px}.accounts{display:table;width:100%;table-layout:fixed}.accounts>div{display:table-cell;width:50%;padding:8px;vertical-align:top;white-space:pre-wrap}.foot{position:fixed;bottom:-12px;left:-43px;right:-43px;background:#9b2930;color:white;padding:12px 43px;text-align:center;font-size:8px}.instr{margin-top:19px;line-height:1.8}</style></head><body>
<img class="banner" src="{{$banner}}">
<h1>FEE INVOICE</h1><table class="sub"><tr><td>Parent's Name:</td><td><b>{{$family->guardian_name??'Not provided'}}</b></td><td>Issued on:</td><td><b>{{\Carbon\Carbon::parse($i->issued_on)->format('d-F-Y')}}</b></td></tr><tr><td>F.Code:</td><td>{{$family->family_code}}</td><td>Due Date:</td><td class="emph">{{\Carbon\Carbon::parse($i->due_date)->format('d-F-Y')}}</td></tr></table><p>Invoice: <strong>{{$i->invoice_number}}</strong> &nbsp;&nbsp; Department: <strong>{{$branding->display_name??$dept->name}}</strong> &nbsp;&nbsp; Currency: <b>{{$i->currency}}</b></p>
<table class="data">
<thead>
  <tr>
    <th>Reg No</th>
    <th>Description</th>
    <th>Month / Period</th>
    <th style="text-align:right">Amount ({{$i->currency}})</th>
  </tr>
</thead>
<tbody>
@foreach($items as $item)
  <tr>
    <td>{{$item->gr_number}}</td>
    <td>
      {{$item->description}}<br>
      <span class="muted">{{$item->name}}</span>
      @if($item->discount_minor > 0)
        <br>Discount: {{\App\Services\Money::format($item->discount_minor)}} {{$i->currency}}
      @endif
      @if(($item->status ?? '') === 'exempted')
        <br><span style="color:#2b8a3e;font-weight:bold">EXEMPTED</span>
      @elseif(($item->exemption_minor ?? 0) > 0)
        <br>Exemption: {{\App\Services\Money::format($item->exemption_minor)}} {{$i->currency}}
      @endif
    </td>
    <td>{{\Carbon\Carbon::parse($item->period_start)->format('F Y')}}</td>
    <td style="text-align:right">{{\App\Services\Money::format($item->net_minor)}} {{$i->currency}}</td>
  </tr>
@endforeach
@if($previous > 0)
  <tr>
    <td>—</td>
    <td>Previous Dues (unpaid earlier invoices; not recharged)</td>
    <td>Previous Months</td>
    <td style="text-align:right">{{\App\Services\Money::format($previous)}} {{$i->currency}}</td>
  </tr>
@endif
  <tr>
    <td class="total" colspan="3">TOTAL PAYABLE (including previous dues)</td>
    <td class="total" style="text-align:right">{{$i->currency}} {{\App\Services\Money::format($i->total_minor - $i->paid_minor + $previous)}}</td>
  </tr>
</tbody>
</table>
<h3 style="color:#91252b;margin:17px 0 5px">Previous Months Record</h3><table class="data" style="text-align:center"><thead><tr>@foreach($history as $old)<th style="text-align:center">{{$old['month']}}</th>@endforeach</tr></thead><tbody><tr>@foreach($history as $old)<td style="text-align:center">{{$old['status']}}</td>@endforeach</tr></tbody></table><div class="bar">PAYMENT INFORMATION</div><div class="accounts"><div>{{ $branding?->payment_instructions ?: 'Payment information can be configured in department branding. Please contact the Accounts Department.' }}</div></div>
<div class="instr"><b>Instructions:</b><br>✓ Please submit your fee receipt through the official school process.<br>✓ Keep proof of payment for your records.<br>✓ If you have already paid, contact the Accounts Department for reconciliation.</div>
<div class="foot">IQRA VIRTUAL SCHOOL · {{$branding?->footer_phone??''}} &nbsp;&nbsp; {{$branding?->footer_email??''}}</div></body></html>
