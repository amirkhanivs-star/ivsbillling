<!doctype html><html><head><meta charset="utf-8"><style>@page{margin:33px 43px}body{font-family:DejaVu Sans,Arial,sans-serif;font-size:10px}.banner{width:100%;max-height:159px;object-fit:contain}.title{color:{{ ($p->status ?? 'received') === 'reversed' ? '#be123c' : '#92282e' }};text-align:center;font-size:22px;margin:16px 0}.details{width:100%;margin:14px 0}.details td{padding:5px}.data{border-collapse:collapse;width:100%;font-size:9px}.data th{background:{{ ($p->status ?? 'received') === 'reversed' ? '#be123c' : '#962a31' }};color:white;text-align:left;padding:6px}.data td{border-bottom:1px solid #ddd;padding:7px}.total{font-size:13px;font-weight:bold;color:#962a31;border-top:2px solid #962a31}.bar{background:{{ ($p->status ?? 'received') === 'reversed' ? '#be123c' : '#962a31' }};color:white;text-align:center;padding:7px;margin-top:24px}.fine{margin-top:26px;font-size:9px;line-height:1.6}.foot{position:fixed;bottom:-12px;left:-43px;right:-43px;background:{{ ($p->status ?? 'received') === 'reversed' ? '#be123c' : '#962a31' }};color:white;text-align:center;padding:12px}</style></head><body>
<img class="banner" src="{{$banner}}"><h1 class="title">{{ ($p->status ?? 'received') === 'reversed' ? 'REVERSED RECEIPT' : 'PAID RECEIPT' }}</h1><table class="details"><tr><td>Paid By:</td><td><strong>{{$family->guardian_name??$family->family_code}}</strong></td><td>Received on:</td><td><b>{{\Carbon\Carbon::parse($p->received_on)->format('d-F-Y')}}</b></td></tr><tr><td>Receipt No:</td><td>{{$p->receipt_number}}</td><td>Status:</td><td>@if(($p->status ?? 'received') === 'reversed')<b style="color:#be123c">REVERSED on {{\Carbon\Carbon::parse($p->reversed_at)->format('d-F-Y')}} (Reason: {{$p->reversal_reason}})</b>@else<b style="color:green">Payment Received</b>@endif</td></tr><tr><td>Family No:</td><td>{{$family->family_code}}</td><td>Currency:</td><td><b>{{$p->currency}}</b></td></tr></table><table class="data">
<thead>
  <tr>
    <th>Reg No</th>
    <th>Description</th>
    <th>Month</th>
    <th style="text-align:right">Allocated ({{$p->currency}})</th>
  </tr>
</thead>
<tbody>
@foreach($items as $row)
  <tr>
    <td>{{$row->gr_number}}</td>
    <td>{{$row->description}}<br>{{$row->name}}</td>
    <td>{{\Carbon\Carbon::parse($row->period_start)->format('F Y')}}</td>
    <td style="text-align:right">{{\App\Services\Money::format($row->amount_minor)}} {{$p->currency}}</td>
  </tr>
@endforeach
@if($p->unallocated_minor > 0)
  <tr>
    <td>—</td>
    <td>Advance Credit</td>
    <td>Future billing</td>
    <td style="text-align:right">{{\App\Services\Money::format($p->unallocated_minor)}} {{$p->currency}}</td>
  </tr>
@endif
  <tr>
    <td class="total" colspan="3">TOTAL AMOUNT</td>
    <td class="total" style="text-align:right">{{$p->currency}} {{\App\Services\Money::format($p->amount_minor)}}</td>
  </tr>
</tbody>
</table><p class="fine">This receipt confirms that the amount shown has been recorded by the Accounts Department of IQRA Virtual School. Payments may be allocated to outstanding fees and/or retained as advance credit.</p>@if(($p->status ?? 'received') !== 'reversed')<div style="text-align:right;margin-top:13px"><img src="data:image/png;base64,{{base64_encode(file_get_contents(public_path('assets/fee-paid-stamp.png')))}}" width="75" alt="Paid"></div>@else<div style="text-align:right;margin-top:13px;font-size:18px;font-weight:bold;color:#be123c;">VOID / REVERSED</div>@endif<div class="bar">PAYMENT INFORMATION</div><div class="fine">Please retain this paid receipt for your record. In case of any difference in fee allocation, contact the Accounts Department.</div><div style="text-align:right"><img src="data:image/jpeg;base64,{{base64_encode(file_get_contents(public_path('assets/receipt-sign.jpg')))}}" width="115" alt="Accounts signature"></div><div class="foot">IQRA VIRTUAL SCHOOL · Official Paid Receipt</div></body></html>
