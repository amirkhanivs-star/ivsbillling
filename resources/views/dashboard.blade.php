@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
@php
    $collectionToday = $todayCollections->count() === 1
        ? $todayCollections->first()->currency.' '.\App\Services\Money::format((int)$todayCollections->first()->amount)
        : ($todayCollections->isEmpty() ? 'No payments' : 'See reports');
    $metrics = [
        ["Today's Collection", $collectionToday, '↗'],
        ['Receipts Today', number_format($stats['receipts_today']), '▤'],
        ['Students', number_format($stats['students']), '♙'],
        ['Family Accounts', number_format($stats['families']), '♧'],
        ['Invoices', number_format($stats['invoices']), '▣'],
    ];
@endphp
<div class="section-heading">
    <div><p class="eyebrow">Billing Overview</p><h2 class="form-title">Dashboard</h2></div>
    <a class="btn purple" href="/fee-creator">+ Create Invoices</a>
</div>
<div class="grid kpi">
    @foreach($metrics as $metric)
        <div class="metric"><div><div class="metric-label">{{ $metric[0] }}</div><div class="metric-value">{{ $metric[1] }}</div></div><div class="metric-ico">{{ $metric[2] }}</div></div>
    @endforeach
</div>
<div class="grid twocol">
    <div class="panel">
        <h2>Department Collections & Outstanding Dues</h2>
        <div class="table-wrap"><table class="table"><thead><tr><th>Department</th><th>Currency</th><th>Billed</th><th>Allocated</th><th>Outstanding</th></tr></thead><tbody>
            @forelse($totals as $row)
                <tr>
                    <td><span class="chip">{{ $departments->firstWhere('id', $row->department_id)?->name ?? $row->department_id }}</span></td>
                    <td>{{ $row->currency }}</td>
                    <td>{{ \App\Services\Money::format($row->total) }}</td>
                    <td>{{ \App\Services\Money::format($row->paid) }}</td>
                    <td>{{ \App\Services\Money::format($row->total - $row->paid) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="center muted">Import verified students, assign fees, and generate invoices to see results.</td></tr>
            @endforelse
        </tbody></table></div>
        <p class="muted small">Amounts are grouped by currency. Allocated payments exclude unapplied advance credits.</p>
    </div>
    <div class="panel">
        <h2>Billing Actions</h2>
        <div class="stack">
            <a class="btn purple" href="/fee-creator">Create Fee Invoices</a>
            <a class="btn" href="/invoices">Review & Send Invoices</a>
            <a class="btn" href="/challan">Receive Payment</a>
            <a class="btn" href="/reports">Department Reports</a>
        </div>
    </div>
</div>
<div class="panel">
    <h2>Departments</h2>
    <div class="department-summary">
        @foreach($departments as $department)
            <div class="card-band">{{ $department->name }} Department</div>
        @endforeach
    </div>
</div>
@endsection
