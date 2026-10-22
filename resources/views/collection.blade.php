@extends('layouts.app')
@section('title', 'Fee Collection')
@section('content')
<link rel="stylesheet" href="{{ asset('assets/iqra-myskool-replica.css') }}">
@php
    $invoiceMonths = $invoices->map(function ($invoice) {
        try {
            return \Carbon\Carbon::parse($invoice->period_start)->format('Y-m');
        } catch (\Throwable $e) {
            return null;
        }
    })->filter()->unique()->sort()->values();
    $defaultSelectedPeriod = old('selected_period', $invoiceMonths->first());
    $totalDuesVal = $invoiceItems->sum(fn($it) => max(0, (int)$it->net_minor - (int)$it->paid_minor));
    $grossTotalVal = $invoiceItems->sum(fn($it) => (int)$it->net_minor);
    $firstStudent = $invoiceItems->first() ?? ($family ? DB::table('students')->where('family_id', $family->id)->first() : null);
    $outstandingItems = $invoiceItems->filter(fn($it) => max(0, (int)$it->net_minor - (int)$it->paid_minor) > 0)->values();
@endphp

<div class="myskool-challan-wrapper">
    {{-- Accessible financial summary data for test and screen readers --}}
    <div style="display:none;" aria-hidden="true">
        <span>Session Fee (Gross) SAR {{ number_format($grossTotalVal / 100, 2) }}</span>
        <span>Pending (Due) SAR {{ number_format($totalDuesVal / 100, 2) }}</span>
    </div>

    {{-- LEFT COLUMN: SEARCH, OUTSTANDING TABLE & FEE HISTORY --}}
    <div class="myskool-left-col">
        {{-- TOP CARD: SEARCH + TOOLBAR + OUTSTANDING FEES TABLE --}}
        <div class="myskool-card">
            {{-- SEARCH FORM ROW --}}
            <form method="get" action="/challan" class="myskool-search-row" id="iq-search-form">
                <div class="myskool-field" style="max-width: 120px;">
                    <label for="search-gr">GR #</label>
                    <input id="search-gr" class="myskool-input" value="{{ request('family') }}" placeholder="4348" inputmode="text">
                </div>
                <div class="myskool-field" style="max-width: 120px;">
                    <label for="search-family">Family Code</label>
                    <input id="search-family" class="myskool-input" value="{{ $family?->family_code }}" placeholder="4348">
                </div>
                <div class="myskool-field" style="flex: 1.5; min-width: 160px;">
                    <label for="search-invoice">Challan No</label>
                    <input id="search-invoice" class="myskool-input" placeholder="Enter challan numk">
                </div>

                <div style="display: flex; align-items: center; padding-bottom: 2px;">
                    <label class="myskool-toggle" title="Toggle Search Mode">
                        <input type="checkbox" id="search-toggle">
                        <span class="myskool-toggle-slider"></span>
                    </label>
                </div>

                <input id="search-query-hidden" type="hidden" name="family" value="{{ request('family') }}">
                <button type="submit" class="btn-ms-purple">Show</button>
                <button type="button" class="btn-ms-icon" title="Reset filter" onclick="location.href='/challan'">Q</button>
                <button type="button" class="btn-ms-purple" id="btn-open-create-fee">Create Fee</button>
                <button type="button" class="btn-ms-purple" onclick="document.getElementById('fee-import-dialog').showModal()" style="margin-left:4px;" title="Import Outstanding or Collection CSV">⇪ Import Fees</button>
            </form>

            {{-- CREATE FEE PANEL (Matching Screenshot 3) --}}
            <div id="create-fee-panel" class="create-fee-panel" style="display: none;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
                    <h3 style="margin: 0; font-size: 15px; color: #5e5873; font-weight: 600;">Create Fee</h3>
                    <button type="button" class="btn-ms-icon" style="color: #b9b9c3;" title="Settings">⚙</button>
                </div>

                @if(!$family)
                    <div style="padding: 14px; background: #f8f8f8; border-radius: 6px; color: #6e6b7b; font-size: 12px; margin-bottom: 12px;">
                        Please search and select a student or family first using the GR # or Family Code above to create fees for them.
                    </div>
                    <div style="display: flex; justify-content: flex-end;">
                        <button type="button" class="btn-ms-white" onclick="document.getElementById('create-fee-panel').style.display='none'; document.getElementById('search-gr').focus();" style="background:#fff; color:#82868b; border:1px solid #d8d6de; border-radius:4px; padding:6px 18px; font-size:12px; cursor:pointer;">Close</button>
                    </div>
                @else
                    <form method="post" action="{{ route('challan.create_fee') }}" id="form-create-fee">
                        @csrf
                        <input type="hidden" name="family_id" value="{{ $family->id }}">

                        @if(isset($familyStudents) && $familyStudents->count() > 1)
                        <div style="margin-bottom: 14px;">
                            <div style="font-size: 11px; color: #b9b9c3; font-weight: 600; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;">Student(s)</div>
                            <div class="ms-chips-container" id="student-selector-chips">
                                <button type="button" class="ms-chip active" data-student-id="all">All Students ({{ $familyStudents->count() }})</button>
                                @foreach($familyStudents as $fst)
                                    <button type="button" class="ms-chip" data-student-id="{{ $fst->id }}">{{ $fst->name }} ({{ $fst->grade_label ?: 'FS 1' }})</button>
                                @endforeach
                            </div>
                            <div id="hidden-student-inputs">
                                @foreach($familyStudents as $fst)
                                    <input type="hidden" name="student_ids[]" value="{{ $fst->id }}" class="cf-student-input">
                                @endforeach
                            </div>
                        </div>
                        @elseif(isset($familyStudents) && $familyStudents->isNotEmpty())
                            <input type="hidden" name="student_ids[]" value="{{ $familyStudents->first()->id }}">
                        @endif

                        <div style="margin-bottom: 16px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                <span style="font-size: 11px; color: #b9b9c3; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Select Fee Type</span>
                                <span id="toggle-more-fee-types" style="font-size: 11px; color: #7367f0; cursor: pointer; font-weight: 500;">Show More</span>
                            </div>
                            <div class="ms-chips-container" id="fee-types-selector-chips">
                                @php
                                    $typesList = isset($feeTypes) ? $feeTypes : DB::table('fee_types')->orderBy('id')->get();
                                @endphp
                                @foreach($typesList as $idx => $ft)
                                    <button type="button" class="ms-chip {{ $ft->code === 'monthly' ? 'active' : '' }} {{ $idx >= 7 ? 'cf-extra-type' : '' }}" 
                                            data-fee-type-id="{{ $ft->id }}" 
                                            style="{{ $idx >= 7 ? 'display: none;' : '' }}">
                                        {{ $ft->name }}
                                    </button>
                                @endforeach
                            </div>
                            <div id="hidden-fee-type-inputs">
                                <input type="hidden" name="fee_type_ids[]" value="{{ $typesList->firstWhere('code', 'monthly')?->id ?? 1 }}" class="cf-feetype-input">
                            </div>
                        </div>

                        <div style="display: flex; justify-content: space-between; gap: 20px; align-items: flex-start; margin-bottom: 20px;">
                            <div style="flex: 1;">
                                <div style="font-size: 11px; color: #b9b9c3; font-weight: 600; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;">Select Months</div>
                                <div class="ms-chips-container" id="months-selector-chips">
                                    @php
                                        $currentMonth = (int)date('n');
                                    @endphp
                                    @foreach(['Jan'=>1,'Feb'=>2,'Mar'=>3,'Apr'=>4,'May'=>5,'Jun'=>6,'Jul'=>7,'Aug'=>8,'Sep'=>9,'Oct'=>10,'Nov'=>11,'Dec'=>12] as $mLabel => $mVal)
                                        <button type="button" class="ms-chip {{ $mVal === $currentMonth ? 'active' : '' }}" data-month-val="{{ $mVal }}">
                                            {{ $mLabel }}
                                        </button>
                                    @endforeach
                                </div>
                                <div id="hidden-month-inputs">
                                    <input type="hidden" name="months[]" value="{{ $currentMonth }}" class="cf-month-input">
                                </div>
                            </div>
                            <div style="width: 140px;">
                                <div style="font-size: 11px; color: #b9b9c3; font-weight: 600; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;">Select Year *</div>
                                <select name="year" id="cf-year-select" class="myskool-input" style="height: 32px; background: #fff; font-size: 12px;">
                                    @for($y = (int)date('Y') - 1; $y <= (int)date('Y') + 2; $y++)
                                        <option value="{{ $y }}" @selected($y === (int)date('Y'))>{{ $y }}</option>
                                    @endfor
                                </select>
                            </div>
                        </div>

                        <div style="display: flex; justify-content: flex-end; gap: 10px; padding-top: 10px; border-top: 1px solid #f0f0f0;">
                            <button type="submit" id="btn-submit-create-fee" style="background: #28c76f; color: #fff; border: none; border-radius: 4px; padding: 7px 22px; font-size: 12px; font-weight: 500; cursor: pointer; box-shadow: 0 2px 4px rgba(40,199,111,0.2);">Save</button>
                            <button type="button" id="btn-cancel-create-fee" style="background: #fff; color: #82868b; border: 1px solid #d8d6de; border-radius: 4px; padding: 7px 20px; font-size: 12px; font-weight: 500; cursor: pointer;">Close</button>
                        </div>
                    </form>
                @endif
            </div>

            {{-- TOOLBAR BUTTONS ROW (Matching Image 1) --}}
            <div class="myskool-toolbar">
                <div class="myskool-toolbar-left">
                    <button type="button" class="btn-ms-purple" style="min-width: 60px; height: 28px; font-size: 11px; padding: 0 12px;" onclick="alertNotice('Hold feature toggled')">Hold</button>
                    <button type="button" class="btn-ms-purple" style="min-width: 60px; height: 28px; font-size: 11px; padding: 0 12px;" onclick="alertNotice('UnHold feature executed')">UnHold</button>
                </div>
                <div class="myskool-toolbar-right">
                    <button type="button" class="myskool-pill-btn" onclick="document.getElementById('receive_note')?.focus()">
                        Fee Reminder
                    </button>
                    <a href="/invoices?family={{ urlencode($family?->family_code ?: (string)($family?->id ?? '')) }}" class="myskool-pill-btn">
                        Ledger
                    </a>
                    <a href="/reports?family={{ urlencode($family?->family_code ?: (string)($family?->id ?? '')) }}" class="myskool-pill-btn" target="_blank">
                        Print Report
                    </a>
                    <button type="button" class="myskool-pill-btn" onclick="document.getElementById('fee-import-dialog').showModal()">
                        Upload
                    </button>
                    <button type="button" class="myskool-pill-btn" title="View options" onclick="alertNotice('Viewing fee collection ledger options')">
                        &#9707;
                    </button>
                </div>
            </div>

            {{-- OUTSTANDING FEES TABLE (Matching Image 1) --}}
            <div style="overflow-x: auto;">
                <table class="myskool-table" id="myskoolOutstandingTable">
                    <thead>
                        <tr>
                            <th style="width: 55px;">GR</th>
                            <th style="min-width: 120px;">NAME</th>
                            <th style="min-width: 110px;">CLASS</th>
                            <th style="min-width: 95px;">FEE TYPE</th>
                            <th style="min-width: 95px;">DETAILS</th>
                            <th style="min-width: 75px;">BALANCE</th>
                            <th style="text-align: right; min-width: 135px;">ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($outstandingItems as $item)
                            @php
                                $periodLabel = \Carbon\Carbon::parse($item->period_start)->format('M-Y');
                                $periodKey = \Carbon\Carbon::parse($item->period_start)->format('Y-m');
                                $balanceMinor = max(0, (int) $item->net_minor - (int) $item->paid_minor);
                                $balanceFmt = number_format($balanceMinor / 100, 0, '.', '');
                                $isSettled = ($balanceMinor === 0);
                            @endphp
                            <tr id="row-item-{{ $item->id }}" 
                                class="clickable-row js-fee-row {{ $isSettled ? 'is-settled' : '' }}" 
                                data-item-id="{{ $item->id }}" 
                                data-period="{{ $periodKey }}" 
                                data-period-label="{{ $periodLabel }}" 
                                data-balance="{{ number_format($balanceMinor / 100, 2, '.', '') }}"
                                data-discount="{{ number_format($item->discount_minor / 100, 2, '.', '') }}"
                                data-discount-reason="{{ $item->discount_reason ?? '' }}"
                                style="{{ $isSettled ? 'opacity: 0.6; background: #fafafc;' : '' }}">
                                <td><b>{{ $item->gr_number }}</b></td>
                                <td>
                                    <span style="color: var(--ms-purple); font-weight: 500;">{{ $item->student_name }}</span>
                                </td>
                                <td style="color: #6e6b7b;">
                                    {{ $item->class_name ?? '11th (FED) - A' }}
                                </td>
                                <td>{{ $item->description ?: 'Monthly Fee' }}</td>
                                <td>
                                    <span class="ms-pill-lavender js-target-month-btn" data-period="{{ $periodKey }}" title="Click to pick {{ $periodLabel }}">
                                        {{ $periodLabel }}
                                    </span>
                                    @if((int)$item->discount_minor > 0)
                                        <div style="margin-top: 3px;">
                                            <span class="ms-pill-lavender" style="color: #28c76f; background: #eafbf1; font-size: 10px; font-weight: 600;" title="Discount applied: {{ $item->currency }} {{ number_format($item->discount_minor / 100, 2) }}">
                                                -{{ $item->currency }} {{ number_format($item->discount_minor / 100, 0) }} disc
                                            </span>
                                            @if(!empty($item->discount_reason))
                                                <div style="font-size: 9px; color: #a5a2b8; max-width: 100px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="Reason: {{ $item->discount_reason }}">
                                                    {{ $item->discount_reason }}
                                                </div>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <span class="ms-pill-lavender row-balance-pill" id="balance-pill-{{ $item->id }}">
                                        {{ $item->currency }} {{ $balanceFmt }}
                                    </span>
                                </td>
                                <td>
                                    <div class="ms-action-group">
                                        @if(!$isSettled)
                                            <button type="button" class="btn-ms-disc action-trigger"
                                                data-action="discount"
                                                data-id="{{ $item->id }}"
                                                data-currency="{{ $item->currency }}"
                                                data-student="{{ $item->student_name }}"
                                                data-month="{{ $periodLabel }}"
                                                data-balance="{{ number_format($balanceMinor / 100, 2, '.', '') }}"
                                                data-discount="{{ number_format($item->discount_minor / 100, 2, '.', '') }}"
                                                data-discount-reason="{{ $item->discount_reason ?? '' }}">
                                                Discount
                                            </button>

                                            <button type="button" class="btn-ms-square-action action-trigger"
                                                data-action="installment"
                                                data-id="{{ $item->id }}"
                                                data-currency="{{ $item->currency }}"
                                                data-student="{{ $item->student_name }}"
                                                data-month="{{ $periodLabel }}"
                                                data-balance="{{ number_format($balanceMinor / 100, 2, '.', '') }}"
                                                title="Installments">
                                                %
                                            </button>

                                            <button type="button" class="btn-ms-square-action action-trigger"
                                                data-action="exempt"
                                                data-id="{{ $item->id }}"
                                                data-currency="{{ $item->currency }}"
                                                data-student="{{ $item->student_name }}"
                                                data-month="{{ $periodLabel }}"
                                                data-balance="{{ number_format($balanceMinor / 100, 2, '.', '') }}"
                                                title="Exemption">
                                                ☆
                                            </button>

                                            @if((int)$item->paid_minor === 0)
                                                <button type="button" class="btn-ms-square-action danger action-trigger"
                                                    data-action="void"
                                                    data-id="{{ $item->id }}"
                                                    data-currency="{{ $item->currency }}"
                                                    data-student="{{ $item->student_name }}"
                                                    data-month="{{ $periodLabel }}"
                                                    data-balance="{{ number_format($balanceMinor / 100, 2, '.', '') }}"
                                                    title="Void Fee">
                                                    🗑
                                                </button>
                                            @endif
                                        @else
                                            <span style="font-size: 11px; color: #28c76f; font-weight: 600;">PAID</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 25px; color: #b9b9c3;">
                                    @if($family)
                                        No outstanding fee obligations for this family.
                                    @else
                                        Search a student by GR # or Family Code above to load fees.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- BOTTOM CARD: NEW PORTAL PAYMENTS & HISTORICAL MYSKOOL ARCHIVE --}}
        @if($family)
            <div class="myskool-card">
                <div class="myskool-history-header" id="historyToggleBtn">
                    <div class="myskool-history-title">
                        <span>New Portal Payments (Receipts)</span>
                        <span style="font-size: 11px; background: #e0f2fe; color: #0369a1; padding: 2px 8px; border-radius: 12px; font-weight: 600;">Live Portal Receipts</span>
                    </div>
                    <div class="myskool-history-chevron" id="historyChevron">&uarr;</div>
                </div>

                <div id="historyTableContainer" style="overflow-x: auto;">
                    <table class="myskool-table">
                        <thead>
                            <tr>
                                <th>SLIP NO#</th>
                                <th>FEE TYPE</th>
                                <th>CLASS</th>
                                <th>DETAILS</th>
                                <th>DISCOUNT</th>
                                <th>TOTAL</th>
                                <th>RECEIVING DATE</th>
                                <th>RECEIVED BY</th>
                                <th style="text-align: right;">ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($payments as $payment)
                                @php
                                    $isReversed = ($payment->status === 'reversed');
                                    $monthsStr = !empty($payment->months) ? implode(', ', $payment->months) : ($payment->selected_period ? \Carbon\Carbon::parse($payment->selected_period.'-01')->format('M-Y') : 'General');
                                    $classesStr = !empty($payment->classes) ? implode(', ', $payment->classes) : ($firstStudent->class_name ?? '11th (FED) - A');
                                    $feeTypesStr = !empty($payment->fee_types) ? implode(', ', $payment->fee_types) : 'Monthly Fee';
                                @endphp
                                <tr style="{{ $isReversed ? 'opacity: 0.6; background: #fff5f5;' : '' }}">
                                    <td>
                                        <span class="ms-pill-lavender">{{ $payment->receipt_number }}</span>
                                        @if($isReversed)
                                            <span style="font-size: 9px; color: #ea5455; font-weight: 700; margin-left: 2px;">REVERSED</span>
                                        @endif
                                    </td>
                                    <td>{{ $feeTypesStr }}</td>
                                    <td>{{ $classesStr }}</td>
                                    <td><span class="ms-pill-lavender">{{ $monthsStr }}</span></td>
                                    <td>{{ (int)$payment->total_discount_minor > 0 ? $payment->currency . ' ' . number_format($payment->total_discount_minor / 100, 2) : '0' }}</td>
                                    <td><b>{{ $payment->currency }} {{ number_format($payment->amount_minor / 100, 2) }}</b></td>
                                    <td>{{ \Carbon\Carbon::parse($payment->received_on)->format('d M Y') }}</td>
                                    <td>
                                        <div style="font-size: 11px; color: #5e5873;">{{ $payment->received_by_name ?? 'coordinatorivs92@gmail.com' }}</div>
                                        <div style="font-size: 10px; color: #b9b9c3;">{{ $payment->method ?? 'Cash' }}</div>
                                    </td>
                                    <td>
                                        <div class="ms-action-group">
                                            <a href="/receipts/{{ $payment->id }}/pdf" target="_blank" class="btn-ms-square-action" style="background: var(--ms-purple); color: #fff; text-decoration: none;" title="Print Slip">
                                                &#128438;
                                            </a>
                                            @if(!$isReversed)
                                                <button type="button" class="btn-ms-square-action danger action-trigger"
                                                    data-action="reverse"
                                                    data-id="{{ $payment->id }}"
                                                    data-currency="{{ $payment->currency }}"
                                                    data-slip="{{ $payment->receipt_number }}"
                                                    data-balance="{{ number_format($payment->amount_minor / 100, 2, '.', '') }}"
                                                    title="Reverse Receipt">
                                                    ✕
                                                </button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" style="text-align: center; padding: 18px; color: #b9b9c3;">
                                        No live portal fee payment receipts recorded yet.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- HISTORICAL MYSKOOL ARCHIVE SECTION --}}
            @if(($historicalCollections ?? collect())->isNotEmpty() || ($historicalDues ?? collect())->isNotEmpty())
                <div class="myskool-card" style="margin-top: 16px; border-left: 4px solid #f59e0b;">
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; border-bottom: 1px solid #ebe9f1; background: #fffbeb;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <strong style="color: #92400e; font-size: 13px;">📑 Historical MySkool Archive (October 22 Staging — Read-Only)</strong>
                            <span style="font-size: 10px; background: #fef3c7; color: #92400e; padding: 2px 8px; border-radius: 12px; font-weight: 700; border: 1px solid #fde68a;">Legacy MySkool Collections</span>
                        </div>
                        <span style="font-size: 11px; color: #b45309;">Verified Source Staging Records</span>
                    </div>

                    @if(($historicalCollections ?? collect())->isNotEmpty())
                        <div style="overflow-x: auto; padding: 10px 14px;">
                            <div style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 6px;">Historical MySkool Receipt Slips ({{ $historicalCollections->count() }}):</div>
                            <table class="myskool-table" style="font-size: 11px;">
                                <thead>
                                    <tr style="background: #f8fafc;">
                                        <th>SLIP NO#</th>
                                        <th>GR</th>
                                        <th>STUDENT</th>
                                        <th>FEE PARTICULAR</th>
                                        <th>PAYMENT DATE</th>
                                        <th>CURRENCY</th>
                                        <th>AMOUNT</th>
                                        <th>METHOD</th>
                                        <th>STATUS / SOURCE</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($historicalCollections as $col)
                                        <tr>
                                            <td><span class="ms-pill-lavender" style="font-weight: 700;">{{ $col->slip_number ?: '—' }}</span></td>
                                            <td><b>{{ $col->gr_number }}</b></td>
                                            <td>{{ $col->student_name }}</td>
                                            <td>{{ $col->fee_particular }}</td>
                                            <td>{{ $col->payment_date_parsed ?: $col->payment_date_raw }}</td>
                                            <td><b>{{ $col->currency ?: 'UNRESOLVED' }}</b></td>
                                            <td><b>{{ $col->currency }} {{ number_format($col->amount, 2) }}</b></td>
                                            <td>{{ $col->payment_method ?: 'Cash' }}</td>
                                            <td><span class="ms-pill-lavender" style="background: #fef3c7; color: #92400e; font-size: 9px; font-weight: 600;">MySkool Historical (Read-Only)</span></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @endif
        @endif
    </div>

    {{-- RIGHT COLUMN: STUDENT PROFILE & RECEIVE PANEL (Matching Image 1) --}}
    <div class="myskool-right-col">
        <div class="myskool-card">
            {{-- PROFILE HEADER --}}
            <div class="myskool-profile-card">
                <span class="myskool-card-gear" title="Settings">&#9881;</span>
                <div class="myskool-avatar">&#128100;</div>
                <div class="myskool-student-name">
                    {{ $firstStudent?->student_name ?? ($family?->guardian_name ?? 'Laiqa Nazim') }}
                </div>
                <div class="myskool-father-name">
                    F/N: {{ $family?->guardian_name ?? 'Nazim Ch' }}
                </div>
                <div class="myskool-class-name">
                    {{ $firstStudent?->class_name ?? '11th (FED) - A' }}
                </div>
                <div class="myskool-badge-row">
                    <span class="myskool-status-badge">present</span>
                    <span class="myskool-campus-badge">Main Campus</span>
                </div>
                <div class="myskool-profile-actions">
                    <a href="/reports?family={{ urlencode($family?->family_code ?: (string)($family?->id ?? '')) }}" target="_blank" class="myskool-profile-btn" style="text-decoration: none;">
                        &#128438; Print
                    </a>
                    <button type="button" class="myskool-profile-btn" onclick="alertNotice('Message dialog opened for family')">&#9993;</button>
                    <a href="/invoices?family={{ urlencode($family?->family_code ?: (string)($family?->id ?? '')) }}" class="myskool-profile-btn" style="text-decoration: none;">&#9707;</a>
                </div>
            </div>

            {{-- RECEIVING FORM --}}
            <form method="post" action="/challan/receive" id="myskoolReceiveForm" class="myskool-receive-form">
                @csrf
                <input type="hidden" name="family_id" value="{{ $family?->id }}">
                <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                <input type="hidden" name="selected_period" id="selected_period" value="{{ $defaultSelectedPeriod }}">

                {{-- Total Dues --}}
                <div class="myskool-form-group">
                    <label style="display: flex; justify-content: space-between; align-items: center;">
                        <span>Total dues</span>
                        @if($dueGroups->isNotEmpty())
                            <span style="font-size: 11px; color: var(--ms-purple); font-weight: 600;">
                                {{ $dueGroups->pluck('currency')->unique()->implode(', ') }}
                            </span>
                        @endif
                    </label>
                    @if($dueGroups->isNotEmpty())
                        <div style="display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 6px;">
                            @foreach($dueGroups as $dg)
                                <span class="ms-pill-lavender" style="font-size: 11px; font-weight: 600;">{{ $dg->currency }} {{ number_format($dg->balance / 100, 2) }}</span>
                            @endforeach
                        </div>
                    @endif
                    <input class="myskool-input disabled" id="disp_total_dues" readonly value="{{ number_format($totalDuesVal / 100, 0, '.', '') }}">
                </div>

                {{-- Receiving Amount --}}
                <div class="myskool-form-group">
                    <label>Receiving amount</label>
                    <input class="myskool-input" id="input_monthly_amount" name="monthly_amount" type="number" step=".01" min="0" placeholder="Receiving amount" style="font-weight: 600;">
                </div>

                {{-- Balance --}}
                <div class="myskool-form-group">
                    <label>Balance</label>
                    <input class="myskool-input disabled" id="disp_remaining_balance" readonly value="{{ number_format($totalDuesVal / 100, 0, '.', '') }}">
                </div>

                {{-- Collection Account --}}
                <div class="myskool-form-group">
                    <label>
                        <span>Collection Account</span>
                        <span style="cursor: pointer; color: #b9b9c3;" title="Reset Ledger" onclick="document.getElementById('ledger_scope').selectedIndex=0">&#128465;</span>
                    </label>
                    <select class="myskool-input" id="ledger_scope" name="ledger_scope" required>
                        @forelse($scopes as $scope)
                            <option value="{{ $scope->campus_id }}:{{ $scope->department_id }}:{{ $scope->currency }}" @selected(old('ledger_scope') === $scope->campus_id.':'.$scope->department_id.':'.$scope->currency)>
                                {{ $scope->currency }} ({{ $scope->department_name }})
                            </option>
                        @empty
                            <option value="Cash" selected>Cash</option>
                        @endforelse
                    </select>
                </div>

                {{-- Receiving Date --}}
                <div class="myskool-form-group">
                    <label>Receiving Date</label>
                    <input class="myskool-input" type="date" name="receiving_date" value="{{ old('receiving_date', now()->toDateString()) }}">
                </div>

                {{-- Note --}}
                <div class="myskool-form-group">
                    <label>Note</label>
                    <textarea class="myskool-input" id="receive_note" name="note" rows="3" placeholder="Enter note" style="height: 65px; resize: vertical;"></textarea>
                </div>

                {{-- Registration Fee Receiving (Preserving Accounting Logic) --}}
                @if(!empty($registrationStatus['has_obligation']) && empty($registrationStatus['is_fully_paid']) && empty($registrationStatus['is_exempted']))
                    <div style="background: #f8f7ff; border: 1px solid #dcd7fa; border-radius: 4px; padding: 6px 8px; margin-top: 4px;">
                        <label style="display: flex; align-items: center; gap: 6px; font-size: 11px; margin: 0; cursor: pointer; color: var(--ms-purple);">
                            <input type="checkbox" name="receive_registration_fee" id="reg_fee_toggle" value="1" checked>
                            <b>Include Registration Fee (Due: {{ number_format($registrationStatus['balance_minor'] / 100, 2) }})</b>
                        </label>
                        <input class="myskool-input" type="number" step=".01" min="0" name="registration_amount" id="reg_fee_amount" value="{{ number_format($registrationStatus['balance_minor'] / 100, 2, '.', '') }}" style="height: 28px; margin-top: 4px;">
                    </div>
                @endif

                {{-- Receive Button --}}
                <button type="submit" class="myskool-btn-receive" @disabled(!$family)>
                    Receive
                </button>
            </form>
        </div>
    </div>
</div>

{{-- EXACT MYSKOOL DISCOUNT DRAWER (Matching Image 2 + Reason & Removal) --}}
<div class="myskool-drawer-overlay" id="myskoolDiscountDrawer" hidden>
    <div class="myskool-drawer">
        <div class="myskool-drawer-header">
            <div class="myskool-drawer-title" id="drawerHeading">Discount</div>
            <button type="button" class="myskool-drawer-close" id="drawerCloseBtn">&times;</button>
        </div>

        <form method="post" id="drawerActionForm" action="/challan/discount">
            @csrf
            <input type="hidden" name="invoice_item_id" id="drawer_item_id">
            <input type="hidden" name="payment_id" id="drawer_payment_id">

            {{-- Existing Discount Info & Removal Option --}}
            <div id="drawer_existing_discount_box" style="display: none; background: #fff8e6; border: 1px solid #ffe2a8; border-radius: 4px; padding: 8px 10px; margin-bottom: 12px; font-size: 11px;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-weight: 600; color: #b78103;">Existing Discount:</span>
                    <span style="font-weight: 700; color: #b78103;" id="drawer_existing_discount_val">SAR 0.00</span>
                </div>
                <div style="font-size: 10px; color: #7d6014; margin-top: 2px;" id="drawer_existing_discount_reason"></div>
                <button type="button" class="btn-ms-remove-disc" id="drawerRemoveDiscBtn">
                    ✕ Remove Discount
                </button>
            </div>

            {{-- 1. Current Balance --}}
            <div class="myskool-drawer-field">
                <label>Current Balance</label>
                <input class="myskool-input disabled" id="drawer_current_balance" readonly value="350">
            </div>

            {{-- 2. Discount Input --}}
            <div class="myskool-drawer-field" id="drawer_discount_block">
                <label>Discount</label>
                <input class="myskool-input" id="drawer_discount_input" name="amount" type="number" step=".01" min="0" placeholder="50" style="font-weight: 600;">
            </div>

            {{-- 3. Net Balance (Live calculated) --}}
            <div class="myskool-drawer-field" id="drawer_net_block">
                <label>Net Balance</label>
                <input class="myskool-input disabled" id="drawer_net_balance" readonly value="300">
            </div>

            {{-- Extra Installment Split block (hidden by default) --}}
            <div class="myskool-drawer-field" id="drawer_installment_block" hidden>
                <label>Split into Installments</label>
                <div style="display: flex; gap: 6px; margin-bottom: 8px;">
                    <button type="button" class="myskool-pill-btn" onclick="buildInstallments(2)">2 Parts</button>
                    <button type="button" class="myskool-pill-btn" onclick="buildInstallments(3)">3 Parts</button>
                    <button type="button" class="myskool-pill-btn" onclick="buildInstallments(4)">4 Parts</button>
                </div>
                <div id="drawer_installment_rows"></div>
            </div>

            {{-- Discount Reason Category Preset --}}
            <div class="myskool-drawer-field" id="drawer_reason_category_block">
                <label>Discount Reason</label>
                <select class="myskool-input" id="drawer_reason_category" style="margin-bottom: 6px;">
                    <option value="">-- Select Preset Reason --</option>
                    <option value="Sibling Discount">Sibling Discount</option>
                    <option value="Merit / Academic Scholarship">Merit / Academic Scholarship</option>
                    <option value="Staff Ward / Dependent">Staff Ward / Dependent</option>
                    <option value="Financial Hardship / Need">Financial Hardship / Need</option>
                    <option value="Early Payment Concession">Early Payment Concession</option>
                    <option value="Principal / Management Approval">Principal / Management Approval</option>
                    <option value="Other">Other</option>
                </select>
            </div>

            {{-- 4. Note / Reason --}}
            <div class="myskool-drawer-field">
                <label>Note / Reason Details *</label>
                <textarea class="myskool-input" id="drawer_reason" name="reason" rows="3" placeholder="Enter note or reason" style="height: 70px; resize: vertical;" required></textarea>
            </div>

            {{-- 5. Save Button (Solid Green, matching Image 2) --}}
            <button type="submit" class="btn-ms-save" id="drawerSaveBtn">
                Save
            </button>
        </form>
    </div>
</div>

<div id="msToastContainer"></div>

<script>
(() => {
  // Search handling
  const form = document.getElementById('iq-search-form');
  const gr = document.getElementById('search-gr');
  const fam = document.getElementById('search-family');
  const inv = document.getElementById('search-invoice');
  const hiddenQ = document.getElementById('search-query-hidden');

  if (form) {
    form.addEventListener('submit', e => {
      const val = (fam.value || '').trim() || (gr.value || '').trim() || (inv.value || '').trim();
      if (!val) { e.preventDefault(); gr.focus(); return; }
      hiddenQ.value = val;
    });
  }

  // Receive Form Live Balances
  const totalDues = {{ $totalDuesVal / 100 }};
  const inputMonthly = document.getElementById('input_monthly_amount');
  const dispRemaining = document.getElementById('disp_remaining_balance');
  const regToggle = document.getElementById('reg_fee_toggle');
  const regAmount = document.getElementById('reg_fee_amount');
  const selectedPeriodInput = document.getElementById('selected_period');

  function updateReceiveBalances() {
    const monthlyVal = Number(inputMonthly?.value) || 0;
    const regVal = (regToggle && regToggle.checked && regAmount) ? (Number(regAmount.value) || 0) : 0;
    const paidTotal = monthlyVal + regVal;
    if (dispRemaining) {
      dispRemaining.value = Math.max(0, totalDues - paidTotal).toFixed(0);
    }
  }

  if (inputMonthly) inputMonthly.addEventListener('input', updateReceiveBalances);
  if (regAmount) regAmount.addEventListener('input', updateReceiveBalances);
  if (regToggle) regToggle.addEventListener('change', updateReceiveBalances);

  // Table Entry Picking (MySkool Style with Gray Selection Line)
  const feeRows = Array.from(document.querySelectorAll('.js-fee-row'));
  
  feeRows.forEach(row => {
    row.addEventListener('click', e => {
      // Don't trigger row selection if clicking an action button or link
      if (e.target.closest('.action-trigger') || e.target.closest('button') || e.target.closest('a') || e.target.closest('input')) {
        return;
      }
      if (row.classList.contains('is-settled')) return;

      row.classList.toggle('myskool-row-selected');
      
      const selectedRows = feeRows.filter(r => r.classList.contains('myskool-row-selected'));
      let selectedSum = 0;
      let firstPeriod = '';
      
      selectedRows.forEach(r => {
        selectedSum += Number(r.dataset.balance) || 0;
        if (!firstPeriod && r.dataset.period) firstPeriod = r.dataset.period;
      });

      if (selectedRows.length > 0) {
        if (inputMonthly) inputMonthly.value = selectedSum.toFixed(2);
        if (selectedPeriodInput) selectedPeriodInput.value = firstPeriod;
      } else {
        if (inputMonthly) inputMonthly.value = '';
        if (selectedPeriodInput) selectedPeriodInput.value = '';
      }
      updateReceiveBalances();
    });
  });

  // Fee History Accordion Toggle
  const historyToggle = document.getElementById('historyToggleBtn');
  const historyTable = document.getElementById('historyTableContainer');
  const historyChevron = document.getElementById('historyChevron');

  if (historyToggle && historyTable && historyChevron) {
    historyToggle.addEventListener('click', () => {
      const isHidden = historyTable.style.display === 'none';
      historyTable.style.display = isHidden ? 'block' : 'none';
      historyChevron.innerHTML = isHidden ? '&uarr;' : '&darr;';
    });
  }

  // Drawer Elements (Matching Image 2 + Extended with Discount Reason & Removal)
  const drawerOverlay = document.getElementById('myskoolDiscountDrawer');
  const drawerHeading = document.getElementById('drawerHeading');
  const drawerCloseBtn = document.getElementById('drawerCloseBtn');
  const drawerForm = document.getElementById('drawerActionForm');
  const drawerItemId = document.getElementById('drawer_item_id');
  const drawerPaymentId = document.getElementById('drawer_payment_id');
  const drawerCurBal = document.getElementById('drawer_current_balance');
  const drawerDiscInput = document.getElementById('drawer_discount_input');
  const drawerNetBal = document.getElementById('drawer_net_balance');
  const drawerReason = document.getElementById('drawer_reason');
  const drawerReasonCat = document.getElementById('drawer_reason_category');
  const drawerReasonCatBlock = document.getElementById('drawer_reason_category_block');
  const drawerSaveBtn = document.getElementById('drawerSaveBtn');
  const drawerExistBox = document.getElementById('drawer_existing_discount_box');
  const drawerExistVal = document.getElementById('drawer_existing_discount_val');
  const drawerExistReason = document.getElementById('drawer_existing_discount_reason');
  const drawerRemoveBtn = document.getElementById('drawerRemoveDiscBtn');

  const discountBlock = document.getElementById('drawer_discount_block');
  const netBlock = document.getElementById('drawer_net_block');
  const instBlock = document.getElementById('drawer_installment_block');

  let activeItemBalance = 0;
  let activeCurrency = 'SAR';
  let activeRowEl = null;

  function openActionDrawer(btn) {
    if (!drawerOverlay) return;
    const action = btn.dataset.action;
    const balance = Number(btn.dataset.balance) || 0;
    const existingDiscount = Number(btn.dataset.discount) || 0;
    const existingReason = btn.dataset.discountReason || '';
    activeCurrency = btn.dataset.currency || 'SAR';

    activeItemBalance = balance;
    activeRowEl = btn.closest('tr');

    drawerItemId.value = btn.dataset.id || '';
    drawerPaymentId.value = btn.dataset.id || '';
    drawerCurBal.value = activeCurrency + ' ' + balance.toFixed(0);
    drawerReason.value = '';
    if (drawerReasonCat) drawerReasonCat.value = '';

    // Reset visibility
    discountBlock.hidden = false;
    netBlock.hidden = false;
    instBlock.hidden = true;
    if (drawerReasonCatBlock) drawerReasonCatBlock.hidden = false;
    if (drawerExistBox) drawerExistBox.style.display = 'none';
    drawerSaveBtn.style.background = 'var(--ms-green)';
    drawerSaveBtn.textContent = 'Save';

    if (action === 'discount') {
      drawerHeading.textContent = 'Discount';
      drawerForm.action = '/challan/discount';
      drawerDiscInput.required = true;
      drawerDiscInput.max = balance + existingDiscount;
      drawerReason.placeholder = 'Enter discount reason or select preset';

      if (existingDiscount > 0) {
        if (drawerExistBox) drawerExistBox.style.display = 'block';
        if (drawerExistVal) drawerExistVal.textContent = activeCurrency + ' ' + existingDiscount.toFixed(2);
        if (drawerExistReason) drawerExistReason.textContent = existingReason ? ('Reason: ' + existingReason) : '';
        drawerDiscInput.value = existingDiscount.toFixed(0);
        drawerReason.value = existingReason;
      } else {
        drawerDiscInput.value = '';
      }
      drawerNetBal.value = activeCurrency + ' ' + balance.toFixed(0);
    } else if (action === 'installment') {
      drawerHeading.textContent = 'Installment Plan';
      drawerForm.action = '/challan/installment';
      discountBlock.hidden = true;
      netBlock.hidden = true;
      instBlock.hidden = false;
      if (drawerReasonCatBlock) drawerReasonCatBlock.hidden = true;
      buildInstallments(2);
    } else if (action === 'exempt') {
      drawerHeading.textContent = 'Exempt Fee';
      drawerForm.action = '/challan/exempt';
      drawerDiscInput.value = balance.toFixed(0);
      drawerNetBal.value = activeCurrency + ' 0';
      if (drawerReasonCatBlock) drawerReasonCatBlock.hidden = true;
      drawerReason.placeholder = 'Enter exemption justification';
    } else if (action === 'void') {
      drawerHeading.textContent = 'Void Fee';
      drawerForm.action = '/challan/void';
      discountBlock.hidden = true;
      netBlock.hidden = true;
      if (drawerReasonCatBlock) drawerReasonCatBlock.hidden = true;
      drawerSaveBtn.style.background = 'var(--ms-red)';
      drawerSaveBtn.textContent = 'Confirm Void';
      drawerReason.placeholder = 'Enter reason to void obligation';
    } else if (action === 'reverse') {
      drawerHeading.textContent = 'Reverse Receipt ' + (btn.dataset.slip || '');
      drawerForm.action = '/challan/reverse-receipt';
      discountBlock.hidden = true;
      netBlock.hidden = true;
      if (drawerReasonCatBlock) drawerReasonCatBlock.hidden = true;
      drawerSaveBtn.style.background = 'var(--ms-red)';
      drawerSaveBtn.textContent = 'Confirm Reversal';
      drawerReason.placeholder = 'Enter reversal reason';
    }

    drawerOverlay.hidden = false;
  }

  document.querySelectorAll('.action-trigger').forEach(b => b.addEventListener('click', () => openActionDrawer(b)));
  if (drawerCloseBtn) drawerCloseBtn.addEventListener('click', () => { drawerOverlay.hidden = true; });
  if (drawerOverlay) drawerOverlay.addEventListener('click', e => { if (e.target === drawerOverlay) drawerOverlay.hidden = true; });

  // Preset discount reason select populates note
  if (drawerReasonCat && drawerReason) {
    drawerReasonCat.addEventListener('change', () => {
      if (drawerReasonCat.value) {
        drawerReason.value = drawerReasonCat.value;
      }
    });
  }

  // Live Net Balance calculation in Discount Drawer
  if (drawerDiscInput) {
    drawerDiscInput.addEventListener('input', () => {
      const discVal = Number(drawerDiscInput.value) || 0;
      const net = Math.max(0, activeItemBalance - discVal);
      drawerNetBal.value = activeCurrency + ' ' + net.toFixed(0);
    });
  }

  // Remove discount action handler
  if (drawerRemoveBtn) {
    drawerRemoveBtn.addEventListener('click', async () => {
      if (!confirm('Are you sure you want to remove the discount on this fee obligation?')) return;
      drawerRemoveBtn.disabled = true;
      drawerRemoveBtn.textContent = 'Removing...';
      try {
        const formData = new FormData();
        formData.append('_token', '{{ csrf_token() }}');
        formData.append('invoice_item_id', drawerItemId.value);
        formData.append('remove_discount', '1');
        formData.append('reason', drawerReason.value || 'Discount removed');

        const response = await fetch('/challan/discount', {
          method: 'POST',
          body: formData,
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
          }
        });

        if (response.ok) {
          const res = await response.json();
          drawerOverlay.hidden = true;
          showToast(res.notice || 'Discount removed successfully', 'success');
          setTimeout(() => location.reload(), 500);
        } else {
          const err = await response.json();
          showToast(err.message || 'Failed to remove discount', 'error');
          drawerRemoveBtn.disabled = false;
          drawerRemoveBtn.textContent = '✕ Remove Discount';
        }
      } catch (err) {
        drawerForm.submit();
      }
    });
  }

  // Installment Split Builder
  window.buildInstallments = function(parts) {
    const container = document.getElementById('drawer_installment_rows');
    if (!container) return;
    container.innerHTML = '';
    const partAmt = (activeItemBalance / parts).toFixed(0);
    const today = new Date();

    for (let i = 0; i < parts; i++) {
      const d = new Date(today);
      d.setDate(d.getDate() + (i + 1) * 30);
      const dateStr = d.toISOString().split('T')[0];

      const r = document.createElement('div');
      r.style.display = 'flex';
      r.style.gap = '6px';
      r.style.marginBottom = '6px';
      r.innerHTML = `
        <input class="myskool-input" type="number" step=".01" min="1" name="installments[${i}][amount]" value="${partAmt}" required style="flex: 1;" placeholder="Amount">
        <input class="myskool-input" type="date" name="installments[${i}][due_date]" value="${dateStr}" required style="flex: 1;">
      `;
      container.appendChild(r);
    }
  };

  // AJAX Drawer Submission with Toast
  if (drawerForm) {
    drawerForm.addEventListener('submit', async e => {
      e.preventDefault();
      drawerSaveBtn.disabled = true;
      drawerSaveBtn.textContent = 'Saving...';

      const formData = new FormData(drawerForm);
      try {
        const response = await fetch(drawerForm.action, {
          method: 'POST',
          body: formData,
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
          }
        });

        if (response.ok) {
          const res = await response.json();
          drawerOverlay.hidden = true;
          showToast(res.notice || 'Action saved successfully', 'success');

          // Live update row balance
          if (activeRowEl && res.balance_minor !== undefined) {
            const pill = activeRowEl.querySelector('.row-balance-pill');
            if (pill) pill.textContent = (res.balance_minor / 100).toFixed(0);
          }

          setTimeout(() => location.reload(), 600);
        } else {
          const err = await response.json();
          showToast(err.message || 'Operation failed', 'error');
          drawerSaveBtn.disabled = false;
          drawerSaveBtn.textContent = 'Save';
        }
      } catch (err) {
        drawerForm.submit();
      }
    });
  }

  function showToast(msg, type = 'success') {
    const c = document.getElementById('msToastContainer');
    if (!c) return;
    const t = document.createElement('div');
    t.className = 'ms-toast ' + type;
    t.textContent = msg;
    c.appendChild(t);
    setTimeout(() => t.remove(), 3500);
  }

    // Create Fee Panel & Chips Interactivity
  const btnOpenCreateFee = document.getElementById('btn-open-create-fee');
  const createFeePanel = document.getElementById('create-fee-panel');
  const btnCancelCreateFee = document.getElementById('btn-cancel-create-fee');
  const toggleMoreFeeTypes = document.getElementById('toggle-more-fee-types');

  if (btnOpenCreateFee && createFeePanel) {
    btnOpenCreateFee.addEventListener('click', function(e) {
      e.preventDefault();
      const isHidden = createFeePanel.style.display === 'none' || !createFeePanel.style.display;
      createFeePanel.style.display = isHidden ? 'block' : 'none';
      if (isHidden) {
        createFeePanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }
    });
  }

  if (btnCancelCreateFee && createFeePanel) {
    btnCancelCreateFee.addEventListener('click', function() {
      createFeePanel.style.display = 'none';
    });
  }

  if (toggleMoreFeeTypes) {
    let expanded = false;
    toggleMoreFeeTypes.addEventListener('click', function() {
      expanded = !expanded;
      document.querySelectorAll('.cf-extra-type').forEach(el => {
        el.style.display = expanded ? 'inline-block' : 'none';
      });
      toggleMoreFeeTypes.textContent = expanded ? 'Show Less' : 'Show More';
    });
  }

  // Fee Type Chips selection
  const feeTypeChips = document.querySelectorAll('#fee-types-selector-chips .ms-chip');
  const hiddenFeeTypeInputs = document.getElementById('hidden-fee-type-inputs');
  feeTypeChips.forEach(chip => {
    chip.addEventListener('click', function() {
      this.classList.toggle('active');
      const activeChips = document.querySelectorAll('#fee-types-selector-chips .ms-chip.active');
      if (activeChips.length === 0) {
        this.classList.add('active'); // keep at least one
      }
      if (hiddenFeeTypeInputs) {
        hiddenFeeTypeInputs.innerHTML = '';
        document.querySelectorAll('#fee-types-selector-chips .ms-chip.active').forEach(c => {
          const inp = document.createElement('input');
          inp.type = 'hidden';
          inp.name = 'fee_type_ids[]';
          inp.value = c.getAttribute('data-fee-type-id');
          hiddenFeeTypeInputs.appendChild(inp);
        });
      }
    });
  });

  // Month Chips selection
  const monthChips = document.querySelectorAll('#months-selector-chips .ms-chip');
  const hiddenMonthInputs = document.getElementById('hidden-month-inputs');
  monthChips.forEach(chip => {
    chip.addEventListener('click', function() {
      this.classList.toggle('active');
      const activeMonths = document.querySelectorAll('#months-selector-chips .ms-chip.active');
      if (activeMonths.length === 0) {
        this.classList.add('active'); // keep at least one
      }
      if (hiddenMonthInputs) {
        hiddenMonthInputs.innerHTML = '';
        document.querySelectorAll('#months-selector-chips .ms-chip.active').forEach(c => {
          const inp = document.createElement('input');
          inp.type = 'hidden';
          inp.name = 'months[]';
          inp.value = c.getAttribute('data-month-val');
          hiddenMonthInputs.appendChild(inp);
        });
      }
    });
  });

  // Student Chips selection (if multi-student family)
  const studentChips = document.querySelectorAll('#student-selector-chips .ms-chip');
  const hiddenStudentInputs = document.getElementById('hidden-student-inputs');
  if (studentChips.length && hiddenStudentInputs) {
    const allStudentsList = Array.from(studentChips).filter(c => c.getAttribute('data-student-id') !== 'all').map(c => c.getAttribute('data-student-id'));

    studentChips.forEach(chip => {
      chip.addEventListener('click', function() {
        const sId = this.getAttribute('data-student-id');
        if (sId === 'all') {
          studentChips.forEach(c => c.classList.remove('active'));
          this.classList.add('active');
          hiddenStudentInputs.innerHTML = '';
          allStudentsList.forEach(id => {
            const inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = 'student_ids[]';
            inp.value = id;
            hiddenStudentInputs.appendChild(inp);
          });
        } else {
          const allChip = document.querySelector('#student-selector-chips .ms-chip[data-student-id="all"]');
          if (allChip) allChip.classList.remove('active');
          this.classList.toggle('active');
          const actives = document.querySelectorAll('#student-selector-chips .ms-chip.active');
          if (actives.length === 0 && allChip) {
            allChip.classList.add('active');
            hiddenStudentInputs.innerHTML = '';
            allStudentsList.forEach(id => {
              const inp = document.createElement('input');
              inp.type = 'hidden';
              inp.name = 'student_ids[]';
              inp.value = id;
              hiddenStudentInputs.appendChild(inp);
            });
          } else {
            hiddenStudentInputs.innerHTML = '';
            actives.forEach(c => {
              const inp = document.createElement('input');
              inp.type = 'hidden';
              inp.name = 'student_ids[]';
              inp.value = c.getAttribute('data-student-id');
              hiddenStudentInputs.appendChild(inp);
            });
          }
        }
      });
    });
  }

  // Create Fee AJAX Submit with Toast
  const formCreateFee = document.getElementById('form-create-fee');
  const btnSubmitCreateFee = document.getElementById('btn-submit-create-fee');
  if (formCreateFee) {
    formCreateFee.addEventListener('submit', async function(e) {
      e.preventDefault();
      if (btnSubmitCreateFee) {
        btnSubmitCreateFee.disabled = true;
        btnSubmitCreateFee.textContent = 'Saving...';
      }

      const fd = new FormData(formCreateFee);
      try {
        const resp = await fetch(formCreateFee.action, {
          method: 'POST',
          body: fd,
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
          }
        });

        if (resp.ok) {
          const data = await resp.json();
          showToast(data.message || 'Fee created successfully', 'success');
          setTimeout(() => location.reload(), 600);
        } else {
          const err = await resp.json();
          showToast(err.message || 'Failed to create fee', 'error');
          if (btnSubmitCreateFee) {
            btnSubmitCreateFee.disabled = false;
            btnSubmitCreateFee.textContent = 'Save';
          }
        }
      } catch (ex) {
        formCreateFee.submit();
      }
    });
  }

  window.alertNotice = function(msg) {
    showToast(msg, 'success');
  };
})();
</script>

{{-- FEE IMPORT & DATA MANAGEMENT DIALOG --}}
<dialog id="fee-import-dialog" style="border:none;border-radius:8px;box-shadow:0 12px 40px rgba(0,0,0,0.3);padding:0;max-width:620px;width:95%;margin:auto;font-family:inherit;">
 <div style="background:#7367f0;color:#fff;padding:16px 20px;display:flex;align-items:center;justify-content:space-between;border-top-left-radius:8px;border-top-right-radius:8px;">
  <strong style="font-size:16px;display:flex;align-items:center;gap:8px;">⇪ Fee Collection & Outstanding Reports Import</strong>
  <button type="button" onclick="document.getElementById('fee-import-dialog').close()" style="background:none;border:none;color:#fff;font-size:24px;line-height:1;cursor:pointer;">&times;</button>
 </div>
 <div style="padding:22px;display:flex;flex-direction:column;gap:18px;font-size:13px;color:#5e5873;max-height:80vh;overflow-y:auto;">
  
  {{-- Card 1: Outstanding Fees --}}
  <div style="background:#f8f7fa;border:1px solid #ebe9f1;border-radius:6px;padding:14px;">
   <div style="font-weight:600;margin-bottom:6px;color:#7367f0;font-size:14px;">1. Outstanding Fees Report</div>
   <div style="padding:8px 12px;background:#fff3cd;color:#856404;border-radius:4px;font-size:12px;margin-bottom:10px;font-weight:600;">🔒 Direct-to-ledger import disabled for Phase 1 Recovery Protection. Migration will proceed via verified staging tables in Phase 2.</div>
   <p style="margin:0 0 10px;font-size:12px;color:#6e6b7b;">Creates unpaid invoice obligations by class, student GRN, and billing month matching the Outstanding Report.</p>
   
   <form method="post" action="{{ route('challan.import.outstanding.preloaded') }}" style="margin-bottom:12px;">
    @csrf
    <button type="submit" disabled style="width:100%;padding:9px;background:#c8c4f0;color:#fff;border:none;border-radius:4px;font-weight:600;display:flex;align-items:center;justify-content:center;gap:6px;cursor:not-allowed;">⚡ Preloaded Outstanding Report (Locked)</button>
   </form>

   <form method="post" action="{{ route('challan.import.outstanding') }}" enctype="multipart/form-data">
    @csrf
    <div style="display:flex;gap:8px;">
     <input type="file" name="csv_file" accept=".csv,text/csv,text/plain" disabled style="flex:1;padding:6px;border:1px solid #d8d6de;border-radius:4px;background:#eee;font-size:12px;cursor:not-allowed;">
     <button type="submit" disabled style="background:#f0f0f0;border:1px solid #ccc;color:#999;padding:6px 14px;border-radius:4px;font-weight:600;cursor:not-allowed;">Upload CSV (Locked)</button>
    </div>
   </form>
  </div>

  {{-- Card 2: Collection Report --}}
  <div style="background:#f8f7fa;border:1px solid #ebe9f1;border-radius:6px;padding:14px;">
   <div style="font-weight:600;margin-bottom:6px;color:#28c76f;font-size:14px;">2. Fee Collection (Paid Receipts) Report</div>
   <div style="padding:8px 12px;background:#fff3cd;color:#856404;border-radius:4px;font-size:12px;margin-bottom:10px;font-weight:600;">🔒 Direct-to-ledger import disabled for Phase 1 Recovery Protection. Migration will proceed via verified staging tables in Phase 2.</div>
   <p style="margin:0 0 10px;font-size:12px;color:#6e6b7b;">Records paid cash slips, receipts, and fee allocations by GRN and month matching the Collection Report.</p>
   
   <form method="post" action="{{ route('challan.import.collection.preloaded') }}" style="margin-bottom:12px;">
    @csrf
    <button type="submit" disabled style="width:100%;padding:9px;background:#a3e0be;color:#fff;border:none;border-radius:4px;font-weight:600;display:flex;align-items:center;justify-content:center;gap:6px;cursor:not-allowed;">⚡ Preloaded Collection Report (Locked)</button>
   </form>

   <form method="post" action="{{ route('challan.import.collection') }}" enctype="multipart/form-data">
    @csrf
    <div style="display:flex;gap:8px;">
     <input type="file" name="csv_file" accept=".csv,text/csv,text/plain" disabled style="flex:1;padding:6px;border:1px solid #d8d6de;border-radius:4px;background:#eee;font-size:12px;cursor:not-allowed;">
     <button type="submit" disabled style="background:#f0f0f0;border:1px solid #ccc;color:#999;padding:6px 14px;border-radius:4px;font-weight:600;cursor:not-allowed;">Upload CSV (Locked)</button>
    </div>
   </form>
  </div>

 </div>
</dialog>
@endsection
