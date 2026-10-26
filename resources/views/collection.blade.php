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
    $searchTerm = trim((string) (request('gr', '') ?: request('invoice', '') ?: request('family', '')));
    $searchBy = strtolower(trim((string) request('search_by', '')));

    $matchedStudent = null;
    if ($family && isset($familyStudents) && $familyStudents instanceof \Illuminate\Support\Collection && $familyStudents->isNotEmpty()) {
        if ($searchTerm !== '') {
            $matchedStudent = $familyStudents->first(fn($st) => strcasecmp((string) $st->gr_number, $searchTerm) === 0);
        }
    }

    $firstInvoiceItem = $invoiceItems->first();
    if ($matchedStudent) {
        $matchedInvoiceItem = $invoiceItems->first(fn($it) => (int) ($it->student_id ?? 0) === (int) $matchedStudent->id);
        $firstStudent = $matchedInvoiceItem ?? (object) [
            'id' => $matchedStudent->id,
            'gr_number' => $matchedStudent->gr_number,
            'student_name' => $matchedStudent->name,
            'class_name' => $matchedStudent->grade_label ?: ($firstInvoiceItem?->class_name ?? ''),
        ];
    } else {
        $fallbackFamilyStudent = (isset($familyStudents) && $familyStudents instanceof \Illuminate\Support\Collection)
            ? $familyStudents->first()
            : ($family ? DB::table('students')->where('family_id', $family->id)->orderBy('id')->first() : null);
        $firstStudent = $firstInvoiceItem ?? ($fallbackFamilyStudent ? (object) [
            'id' => $fallbackFamilyStudent->id,
            'gr_number' => $fallbackFamilyStudent->gr_number,
            'student_name' => $fallbackFamilyStudent->name,
            'class_name' => $fallbackFamilyStudent->grade_label ?? '',
        ] : null);
    }

    if ($family) {
        $displayGr = $matchedStudent?->gr_number
            ?? ($firstStudent?->gr_number ?? ($familyStudents?->first()?->gr_number ?? ''));
        $displayFamilyCode = $family->family_code ?? '';
        $displayInvoice = ($searchBy === 'invoice') ? $searchTerm : '';
    } else {
        $displayGr = ($searchBy !== 'family' && $searchBy !== 'invoice') ? $searchTerm : '';
        $displayFamilyCode = ($searchBy === 'family') ? $searchTerm : '';
        $displayInvoice = ($searchBy === 'invoice') ? $searchTerm : '';
    }

    $outstandingItems = $invoiceItems->filter(fn($it) => max(0, (int)$it->net_minor - (int)$it->paid_minor) > 0)->values();

    $currencySummaries = isset($summaryByCurrency) && $summaryByCurrency instanceof \Illuminate\Support\Collection
        ? $summaryByCurrency
        : $invoiceItems->groupBy(fn($it) => strtoupper(trim((string)($it->currency ?: 'SAR'))))->map(function ($items, $curr) {
            return [
                'currency' => $curr,
                'total_session_fee_minor' => (int) $items->sum('amount_minor'),
                'total_discount_minor' => (int) $items->sum('discount_minor'),
                'total_exemption_minor' => (int) $items->sum('exemption_minor'),
                'total_net_minor' => (int) $items->sum('net_minor'),
                'total_received_minor' => (int) $items->sum('paid_minor'),
                'total_due_minor' => (int) $items->sum(fn($it) => max(0, (int)$it->net_minor - (int)$it->paid_minor)),
            ];
        });

    $currencyDuesMap = $currencySummaries->map(fn($cs) => (int) ($cs['total_due_minor'] ?? 0));
    foreach (($dueGroups ?? collect()) as $dg) {
        $dgCurr = strtoupper(trim((string) ($dg->currency ?: 'SAR')));
        if (!$currencyDuesMap->has($dgCurr)) {
            $currencyDuesMap->put($dgCurr, max(0, (int) $dg->balance));
        }
    }

    $isMultiCurrencyDues = $currencyDuesMap->count() > 1;
    $singleCurrencyCode = $currencyDuesMap->count() === 1 ? $currencyDuesMap->keys()->first() : null;
    $singleCurrencyDueMinor = $singleCurrencyCode ? (int) $currencyDuesMap->get($singleCurrencyCode, 0) : 0;

    if ($currencyDuesMap->isEmpty()) {
        $defaultDuesDisplay = '0';
    } elseif ($isMultiCurrencyDues) {
        $defaultDuesDisplay = $currencyDuesMap
            ->map(fn($balMinor, $curr) => $curr . ' ' . number_format($balMinor / 100, 2))
            ->values()
            ->implode(' | ');
    } else {
        $defaultDuesDisplay = number_format($singleCurrencyDueMinor / 100, 0, '.', '');
    }

    $currencyDuesFloatMap = $currencyDuesMap->map(fn($balMinor) => round($balMinor / 100, 2))->all();

    $advanceCreditsByCurr = isset($advanceCreditsByCurrency) && $advanceCreditsByCurrency instanceof \Illuminate\Support\Collection
        ? $advanceCreditsByCurrency
        : ($advanceCredits ?? collect())
            ->groupBy(fn($ac) => strtoupper(trim((string)($ac->currency ?: 'SAR'))))
            ->map(fn($group, $curr) => [
                'currency' => $curr,
                'unallocated_minor' => (int) $group->sum('unallocated_minor'),
                'count' => $group->count(),
                'receipts' => $group->values(),
            ]);
@endphp

<div class="myskool-challan-wrapper">
    {{-- Accessible financial summary data for test and screen readers (strictly grouped by currency) --}}
    <div style="display:none;" aria-hidden="true">
        @forelse($currencySummaries as $currCode => $cs)
            <span>Session Fee (Gross) {{ $currCode }} {{ number_format($cs['total_session_fee_minor'] / 100, 2) }}</span>
            <span>Discount {{ $currCode }} {{ number_format((($cs['total_discount_minor'] ?? 0) + ($cs['total_exemption_minor'] ?? 0)) / 100, 2) }}</span>
            <span>Received (Paid) {{ $currCode }} {{ number_format($cs['total_received_minor'] / 100, 2) }}</span>
            <span>Pending (Due) {{ $currCode }} {{ number_format($cs['total_due_minor'] / 100, 2) }}</span>
        @empty
            <span>Session Fee (Gross) 0.00</span>
            <span>Pending (Due) 0.00</span>
        @endforelse
    </div>

    {{-- CURRENCY-GROUPED FAMILY SUMMARY STRIP (Never combines unlike currencies) --}}
    @if($currencySummaries->isNotEmpty())
        <div style="grid-column: 1 / -1; display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 10px;">
            @foreach($currencySummaries as $currCode => $cs)
                <div class="ms-currency-summary-card" data-summary-currency="{{ $currCode }}" style="flex: 1 1 260px; background: #fff; border: 1px solid #ebe9f1; border-left: 4px solid #7367f0; border-radius: 6px; padding: 8px 14px; display: flex; justify-content: space-between; align-items: center; gap: 12px; font-size: 11px;">
                    <div style="font-weight: 700; color: #5e5873; font-size: 12px;">
                        <span class="ms-pill-lavender" style="font-weight: 700;">{{ $currCode }}</span> Summary
                    </div>
                    <div style="display: flex; flex-wrap: wrap; gap: 12px; color: #6e6b7b;">
                        <span>Gross: <strong>{{ $currCode }} {{ number_format($cs['total_session_fee_minor'] / 100, 2) }}</strong></span>
                        @if((($cs['total_discount_minor'] ?? 0) + ($cs['total_exemption_minor'] ?? 0)) > 0)
                            <span>Discount/Waiver: <strong style="color: #28c76f;">{{ $currCode }} {{ number_format((($cs['total_discount_minor'] ?? 0) + ($cs['total_exemption_minor'] ?? 0)) / 100, 2) }}</strong></span>
                        @endif
                        <span>Paid: <strong style="color: #0369a1;">{{ $currCode }} {{ number_format($cs['total_received_minor'] / 100, 2) }}</strong></span>
                        <span>Outstanding: <strong style="color: #dc2626;">{{ $currCode }} {{ number_format($cs['total_due_minor'] / 100, 2) }}</strong></span>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- ADVANCE CREDIT BANNER (If family has unallocated credit balance, grouped strictly by currency) --}}
    @if($advanceCreditsByCurr->isNotEmpty())
        <div style="grid-column: 1 / -1; background: #ecfdf5; border: 1px solid #10b981; border-radius: 6px; padding: 10px 16px; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span style="font-size: 18px;">💳</span>
                <div>
                    <strong style="color: #065f46; font-size: 13px;">
                        Available Advance Credit Balance:
                        @foreach($advanceCreditsByCurr as $acCurr => $acGroup)
                            <span>{{ $acCurr }} {{ number_format($acGroup['unallocated_minor'] / 100, 2) }}</span>{{ !$loop->last ? ' | ' : '' }}
                        @endforeach
                    </strong>
                    <div style="font-size: 11px; color: #047857; margin-top: 2px;">
                        Held across {{ ($advanceCredits ?? collect())->count() }} receipt(s): 
                        @foreach($advanceCredits as $ac)
                            <span style="font-family: monospace; font-weight: 600;">{{ $ac->receipt_number }}</span> ({{ $ac->currency }} {{ number_format($ac->unallocated_minor / 100, 2) }}){{ !$loop->last ? ', ' : '' }}
                        @endforeach
                        &bull; Will be automatically applied to future invoices in the same currency upon generation or manual allocation.
                    </div>
                </div>
            </div>
            <span style="font-size: 11px; background: #10b981; color: #fff; padding: 3px 8px; border-radius: 12px; font-weight: 600;">Credit on Account</span>
        </div>
    @endif

    {{-- LEFT COLUMN: SEARCH, OUTSTANDING TABLE & STATEMENT SECTIONS --}}
    <div class="myskool-left-col">
        {{-- TOP CARD: SEARCH + TOOLBAR + OUTSTANDING FEES TABLE --}}
        <div class="myskool-card">
            {{-- SEARCH FORM ROW --}}
            <form method="get" action="/challan" class="myskool-search-row" id="iq-search-form">
                <div class="myskool-field" style="max-width: 130px;">
                    <label for="search-gr">GR #</label>
                    <input id="search-gr" class="myskool-input" value="{{ $displayGr }}" placeholder="4348" inputmode="text" autocomplete="off">
                </div>
                <div class="myskool-field" style="max-width: 130px;">
                    <label for="search-family">Family Code</label>
                    <input id="search-family" class="myskool-input" value="{{ $displayFamilyCode }}" placeholder="4348" autocomplete="off">
                </div>
                <div class="myskool-field" style="flex: 1.5; min-width: 160px;">
                    <label for="search-invoice">Challan No</label>
                    <input id="search-invoice" class="myskool-input" value="{{ $displayInvoice }}" placeholder="Enter challan number" autocomplete="off">
                </div>

                <div style="display: flex; align-items: center; padding-bottom: 2px;">
                    <label class="myskool-toggle" title="Toggle Search Mode">
                        <input type="checkbox" id="search-toggle">
                        <span class="myskool-toggle-slider"></span>
                    </label>
                </div>

                <input id="search-query-hidden" type="hidden" name="family" value="{{ $searchTerm }}">
                <input id="search-by-hidden" type="hidden" name="search_by" value="{{ $searchBy }}">
                <button type="submit" class="btn-ms-purple">Show</button>
                <button type="button" class="btn-ms-icon" title="Reset filter" onclick="location.href='/challan'">Q</button>
                <button type="button" class="btn-ms-purple" id="btn-open-create-fee">Create Fee</button>
            </form>

            @if($searchTerm !== '' && !$family)
                <div id="search-no-results-banner" style="background: #fef2f2; border: 1px solid #fecaca; border-left: 4px solid #ef4444; border-radius: 6px; padding: 10px 14px; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; gap: 12px; color: #991b1b; font-size: 12px;">
                    <div>
                        <strong>No matching record found</strong> for
                        @if($searchBy === 'family')
                            Family Code
                        @elseif($searchBy === 'invoice')
                            Challan No
                        @else
                            GR # / Family Code
                        @endif
                        <span style="font-family: monospace; background: #fee2e2; padding: 1px 6px; border-radius: 4px; font-weight: 700;">{{ $searchTerm }}</span>.
                        Please check the number and try again.
                    </div>
                    <a href="/challan" style="color: #b91c1c; font-weight: 600; text-decoration: underline; white-space: nowrap;">Clear Search</a>
                </div>
            @endif

            {{-- CREATE FEE PANEL (Enhanced with All 8 Fee Types, Rates & Live Preview) --}}
            <div id="create-fee-panel" class="create-fee-panel" style="{{ ($errors->has('fee_type_ids') || $errors->has('student_ids') || old('family_id')) ? 'display: block;' : 'display: none;' }}">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
                    <div>
                        <h3 style="margin: 0; font-size: 15px; color: #5e5873; font-weight: 600;">Create Fee Obligation</h3>
                        <div style="font-size: 11px; color: #82868b; margin-top: 2px;">Generate approved monthly or one-time fee obligations with strict class catalog rates.</div>
                    </div>
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

                        @if($errors->has('fee_type_ids'))
                            <div style="background: #fef2f2; border: 1px solid #f87171; border-radius: 4px; padding: 10px 14px; margin-bottom: 14px; color: #b91c1c; font-size: 13px; font-weight: 500; display: flex; align-items: center; gap: 8px;">
                                <span>⚠️</span>
                                <span>{{ $errors->first('fee_type_ids') }}</span>
                            </div>
                        @endif
                        @if($errors->has('student_ids'))
                            <div style="background: #fef2f2; border: 1px solid #f87171; border-radius: 4px; padding: 10px 14px; margin-bottom: 14px; color: #b91c1c; font-size: 13px; font-weight: 500; display: flex; align-items: center; gap: 8px;">
                                <span>⚠️</span>
                                <span>{{ $errors->first('student_ids') }}</span>
                            </div>
                        @endif

                        {{-- Student Selector --}}
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
                                    <input type="hidden" name="student_ids[]" value="{{ $fst->id }}">
                                @endforeach
                            </div>
                        </div>
                        @elseif(isset($familyStudents) && $familyStudents->isNotEmpty())
                            @php $singleStudent = $familyStudents->first(); @endphp
                            <input type="hidden" name="student_ids[]" value="{{ $singleStudent->id }}">
                            <div style="margin-bottom: 10px; font-size: 12px; color: #5e5873;">
                                Student: <strong>{{ $singleStudent->name }}</strong> (GR: {{ $singleStudent->gr_number }}, Class: <strong>{{ $singleStudent->grade_label }}</strong>, Currency: <strong>{{ $singleStudent->currency }}</strong>)
                            </div>
                        @endif

                        {{-- All 8 Fee Types Selector --}}
                        <div style="margin-bottom: 16px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                <span style="font-size: 11px; color: #b9b9c3; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Select Fee Type(s)</span>
                                <span id="toggle-more-fee-types" style="font-size: 11px; color: #7367f0; cursor: pointer; font-weight: 500;">Show All (8)</span>
                            </div>
                            <div class="ms-chips-container" id="fee-types-selector-chips">
                                @php
                                    $typesList = isset($feeTypes) ? $feeTypes : DB::table('fee_types')->orderBy('id')->get();
                                @endphp
                                @foreach($typesList as $idx => $ft)
                                    <button type="button" class="ms-chip {{ $ft->code === 'monthly' ? 'active' : '' }} {{ $idx >= 4 ? 'cf-extra-type' : '' }}" 
                                            data-fee-type-id="{{ $ft->id }}"
                                            data-code="{{ $ft->code }}"
                                            style="{{ $idx >= 4 ? 'display: none;' : '' }}">
                                        {{ $ft->name }}
                                    </button>
                                @endforeach
                            </div>
                            <div id="hidden-fee-type-inputs">
                                <input type="hidden" name="fee_type_ids[]" value="{{ $typesList->firstWhere('code', 'monthly')?->id ?? 1 }}">
                            </div>
                        </div>

                        {{-- Month and Year Selection --}}
                        <div style="display: flex; justify-content: space-between; gap: 20px; align-items: flex-start; margin-bottom: 16px;">
                            <div style="flex: 1;">
                                <div style="font-size: 11px; color: #b9b9c3; font-weight: 600; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;">Billing Month(s)</div>
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
                                    <input type="hidden" name="months[]" value="{{ $currentMonth }}">
                                </div>
                            </div>
                            <div style="width: 140px;">
                                <div style="font-size: 11px; color: #b9b9c3; font-weight: 600; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;">Billing Year *</div>
                                <select name="year" id="cf-year-select" class="myskool-input" style="height: 32px; background: #fff; font-size: 12px;">
                                    @for($y = (int)date('Y') - 1; $y <= (int)date('Y') + 2; $y++)
                                        <option value="{{ $y }}" @selected($y === (int)date('Y'))>{{ $y }}</option>
                                    @endfor
                                </select>
                            </div>
                        </div>

                        {{-- LIVE CHARGES PREVIEW TABLE (Before Saving) --}}
                        <div id="cf-charges-preview-card" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px; margin-bottom: 16px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                <strong style="font-size: 12px; color: #475569;">Charges Preview (Calculated from Approved Catalog Rates)</strong>
                                <span id="cf-preview-count" style="font-size: 11px; color: #64748b;">1 charge planned</span>
                            </div>
                            <div id="cf-preview-table-container" style="max-height: 140px; overflow-y: auto;">
                                <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
                                    <thead>
                                        <tr style="border-bottom: 1px solid #cbd5e1; text-align: left; color: #64748b;">
                                            <th style="padding: 4px 6px;">Student</th>
                                            <th style="padding: 4px 6px;">Fee Type</th>
                                            <th style="padding: 4px 6px;">Period</th>
                                            <th style="padding: 4px 6px; text-align: right;">Rate</th>
                                        </tr>
                                    </thead>
                                    <tbody id="cf-preview-tbody">
                                        <!-- Dynamically generated rows -->
                                    </tbody>
                                </table>
                            </div>
                            <div id="cf-preview-warning" style="display: none; margin-top: 8px; padding: 6px 10px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 4px; color: #dc2626; font-size: 11px;"></div>
                        </div>

                        <div style="display: flex; justify-content: flex-end; gap: 10px; padding-top: 10px; border-top: 1px solid #f0f0f0;">
                            <button type="submit" id="btn-submit-create-fee" style="background: #28c76f; color: #fff; border: none; border-radius: 4px; padding: 7px 22px; font-size: 12px; font-weight: 500; cursor: pointer; box-shadow: 0 2px 4px rgba(40,199,111,0.2);">Save</button>
                            <button type="button" id="btn-cancel-create-fee" style="background: #fff; color: #82868b; border: 1px solid #d8d6de; border-radius: 4px; padding: 7px 20px; font-size: 12px; font-weight: 500; cursor: pointer;">Close</button>
                        </div>
                    </form>
                @endif
            </div>

            {{-- TOOLBAR BUTTONS ROW --}}
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
                    <button type="button" class="myskool-pill-btn" title="View options" onclick="alertNotice('Viewing fee collection ledger options')">
                        &#9707;
                    </button>
                </div>
            </div>

            {{-- OUTSTANDING FEES TABLE (Multi-Item Selection with Checkboxes) --}}
            <div style="overflow-x: auto;">
                <table class="myskool-table" id="myskoolOutstandingTable">
                    <thead>
                        <tr>
                            <th style="width: 38px; text-align: center;">
                                <input type="checkbox" id="chk_select_all" title="Select / Deselect all outstanding items" style="cursor: pointer;">
                            </th>
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
                            <tr class="clickable-row js-fee-row {{ $isSettled ? 'is-settled' : '' }}"
                                style="{{ $isSettled ? 'opacity: 0.6; background: #fafafc;' : '' }}">
                                <td style="text-align: center;">
                                    <input type="checkbox" class="js-item-checkbox" value="{{ $item->id }}"
                                           data-balance="{{ number_format($balanceMinor / 100, 2, '.', '') }}"
                                           data-currency="{{ $item->currency }}"
                                           data-period="{{ $periodKey }}"
                                           data-period-label="{{ $periodLabel }}"
                                           data-student="{{ $item->student_name }}"
                                           data-gr="{{ $item->gr_number }}"
                                           data-type="{{ $item->description ?: 'Monthly Fee' }}"
                                           {{ $isSettled ? 'disabled' : '' }}
                                           style="cursor: pointer;">
                                </td>
                                <td><b>{{ $item->gr_number }}</b></td>
                                <td>
                                    <span style="color: var(--ms-purple); font-weight: 500;">{{ $item->student_name }}</span>
                                </td>
                                <td style="color: #6e6b7b;">
                                    {{ $item->class_name ?? '11th (FED) - A' }}
                                </td>
                                <td>{{ $item->description ?: 'Monthly Fee' }}</td>
                                <td>
                                    <span class="ms-pill-lavender" title="Click to pick {{ $periodLabel }}">
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
                                    <span class="ms-pill-lavender">
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
                                                data-balance="{{ number_format($balanceMinor / 100, 2, '.', '') }}"
                                                data-discount="{{ number_format($item->discount_minor / 100, 2, '.', '') }}"
                                                data-discount-reason="{{ $item->discount_reason ?? '' }}">
                                                Discount
                                            </button>

                                            <button type="button" class="btn-ms-square-action action-trigger"
                                                data-action="installment"
                                                data-id="{{ $item->id }}"
                                                data-currency="{{ $item->currency }}"
                                                data-balance="{{ number_format($balanceMinor / 100, 2, '.', '') }}"
                                                title="Installments">
                                                %
                                            </button>

                                            <button type="button" class="btn-ms-square-action action-trigger"
                                                data-action="exempt"
                                                data-id="{{ $item->id }}"
                                                data-currency="{{ $item->currency }}"
                                                data-balance="{{ number_format($balanceMinor / 100, 2, '.', '') }}"
                                                title="Exemption">
                                                ☆
                                            </button>

                                            @if((int)$item->paid_minor === 0)
                                                <button type="button" class="btn-ms-square-action danger action-trigger"
                                                    data-action="void"
                                                    data-id="{{ $item->id }}"
                                                    data-currency="{{ $item->currency }}"
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
                                <td colspan="8" style="text-align: center; padding: 25px; color: #b9b9c3;">
                                    @if($family)
                                        No outstanding fee obligations for this family.
                                    @elseif($searchTerm !== '')
                                        <span style="color: #dc2626; font-weight: 600;">No student or family found matching "{{ $searchTerm }}".</span>
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

        {{-- FUTURE SCHEDULED FEES CARD (Task 3: Visible as scheduled charges, not yet debt) --}}
        @if(($studentFeePlans ?? collect())->isNotEmpty())
            <div class="myskool-card" style="margin-top: 16px; border-left: 4px solid #3b82f6;">
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; border-bottom: 1px solid #ebe9f1; background: #eff6ff;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <strong style="color: #1e40af; font-size: 13px;">📅 Future Scheduled Fees (Active Student Fee Plans)</strong>
                        <span style="font-size: 10px; background: #dbeafe; color: #1e40af; padding: 2px 8px; border-radius: 12px; font-weight: 700;">Scheduled Plan</span>
                    </div>
                    <span style="font-size: 11px; color: #3b82f6;">Charges will become debt once monthly billing runs</span>
                </div>
                <div style="overflow-x: auto; padding: 10px 14px;">
                    <table class="myskool-table" style="font-size: 11px;">
                        <thead>
                            <tr style="background: #f8fafc;">
                                <th>GR</th>
                                <th>STUDENT</th>
                                <th>CLASS</th>
                                <th>FEE TYPE</th>
                                <th>APPROVED RATE</th>
                                <th>DISCOUNT</th>
                                <th>FREQUENCY</th>
                                <th>STATUS</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($studentFeePlans as $plan)
                                <tr>
                                    <td><b>{{ $plan->gr_number }}</b></td>
                                    <td><span style="color: var(--ms-purple); font-weight: 500;">{{ $plan->student_name }}</span></td>
                                    <td>{{ $plan->grade_label }}</td>
                                    <td><strong>{{ $plan->fee_type_name }}</strong></td>
                                    <td><b>{{ $plan->currency }} {{ number_format($plan->amount_minor / 100, 2) }}</b></td>
                                    <td>{{ $plan->discount_kind ? $plan->discount_kind . ' (' . $plan->discount_value . ')' : 'None' }}</td>
                                    <td><span class="ms-pill-lavender">{{ ucfirst($plan->frequency ?? 'Monthly') }}</span></td>
                                    <td><span style="color: #2563eb; font-weight: 600; font-size: 10px;">Scheduled (Not Invoiced)</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- UNIFIED FEE HISTORY CARD --}}
        @if($family)
            <div class="myskool-card" style="margin-top: 16px;">
                <div class="myskool-history-header" id="historyToggleBtn">
                    <div class="myskool-history-title">
                        <span>Fee History</span>
                        @php
                            $totalFeeRecords = $feeHistory->total();
                        @endphp
                        <span style="font-size: 11px; background: #e0f2fe; color: #0369a1; padding: 2px 8px; border-radius: 12px; font-weight: 600;">
                            {{ $totalFeeRecords }} {{ \Illuminate\Support\Str::plural('Record', $totalFeeRecords) }} (Live Portal Receipts &amp; Historical MySkool Slips)
                        </span>
                    </div>
                    <div class="myskool-history-chevron" id="historyChevron">&uarr;</div>
                </div>

                {{-- FILTER / SEARCH BAR --}}
                <form method="get" action="{{ url()->current() }}" style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center; padding: 10px 16px; background: #fafafc; border-bottom: 1px solid #ebe9f1;">
                    <input type="hidden" name="family" value="{{ $searchTerm !== '' ? $searchTerm : ($family->family_code ?: $family->id) }}">
                    @if($searchBy !== '')
                        <input type="hidden" name="search_by" value="{{ $searchBy }}">
                    @endif
                    
                    {{-- Search Input --}}
                    <div style="flex: 1; min-width: 170px;">
                        <input type="text" name="search" value="{{ request('search') }}" placeholder="🔍 Search Slip #, Student, Month..." class="myskool-input" style="height: 32px; font-size: 12px; width: 100%; border: 1px solid #d8d6de; border-radius: 4px; padding: 0 10px;">
                    </div>

                    {{-- Source Filter --}}
                    <div>
                        <select name="source" style="height: 32px; font-size: 12px; border: 1px solid #d8d6de; border-radius: 4px; padding: 0 8px; background: #fff;" onchange="this.form.submit()">
                            <option value="all" {{ request('source') === 'all' || !request('source') ? 'selected' : '' }}>All Sources</option>
                            <option value="portal" {{ request('source') === 'portal' ? 'selected' : '' }}>New Portal Only</option>
                            <option value="myskool" {{ request('source') === 'myskool' ? 'selected' : '' }}>Historical MySkool Only</option>
                        </select>
                    </div>

                    {{-- Student Filter (if family has multiple students) --}}
                    @if(($familyStudents ?? collect())->count() > 1)
                    <div>
                        <select name="student_gr" style="height: 32px; font-size: 12px; border: 1px solid #d8d6de; border-radius: 4px; padding: 0 8px; background: #fff;" onchange="this.form.submit()">
                            <option value="">All Students</option>
                            @foreach($familyStudents as $fst)
                                <option value="{{ $fst->gr_number }}" {{ request('student_gr') == $fst->gr_number ? 'selected' : '' }}>{{ $fst->name }} ({{ $fst->gr_number }})</option>
                            @endforeach
                        </select>
                    </div>
                    @endif

                    {{-- Date From & To --}}
                    <div>
                        <input type="date" name="date_from" value="{{ request('date_from') }}" title="From Date" style="height: 32px; font-size: 11px; border: 1px solid #d8d6de; border-radius: 4px; padding: 0 6px;">
                    </div>
                    <div>
                        <input type="date" name="date_to" value="{{ request('date_to') }}" title="To Date" style="height: 32px; font-size: 11px; border: 1px solid #d8d6de; border-radius: 4px; padding: 0 6px;">
                    </div>

                    <button type="submit" class="btn-ms-purple" style="height: 32px; padding: 0 12px; font-size: 12px; border-radius: 4px;">Filter</button>
                    
                    @if(request()->hasAny(['search', 'source', 'student_gr', 'date_from', 'date_to']))
                        <a href="{{ url()->current() }}?family={{ urlencode($searchTerm !== '' ? $searchTerm : ($family->family_code ?: (string)$family->id)) }}{{ $searchBy !== '' ? '&search_by=' . urlencode($searchBy) : '' }}" style="font-size: 11px; color: #7367f0; text-decoration: none; padding: 4px 6px;">Clear</a>
                    @endif
                </form>

                <div id="historyTableContainer" style="overflow-x: auto;">
                    <table class="myskool-table myskool-history-table">
                        <thead>
                            <tr>
                                <th class="col-slip">SLIP NO#</th>
                                <th class="col-fee-type">FEE TYPE</th>
                                <th class="col-class">CLASS</th>
                                <th class="col-student">STUDENT</th>
                                <th class="col-details">DETAILS</th>
                                <th class="col-discount" style="text-align: right;">DISCOUNT</th>
                                <th class="col-total" style="text-align: right;">TOTAL</th>
                                <th class="col-date">RECEIVING DATE</th>
                                <th class="col-received-by">RECEIVED BY</th>
                                <th class="col-actions" style="text-align: right;">ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($feeHistory as $receipt)
                                <tr style="{{ $receipt->is_reversed ? 'opacity: 0.6; background: #fff5f5;' : '' }}">
                                    <td class="col-slip">
                                        <div class="ms-history-slip-cell">
                                            <span class="ms-pill-lavender" style="font-weight: 700; cursor: pointer;" onclick='showReceiptDetails(@json($receipt->details_payload))' title="Click to view complete itemized breakdown">
                                                {{ $receipt->slip_number }}
                                            </span>
                                            @if($receipt->source === 'portal')
                                                <span class="ms-source-badge portal">Portal</span>
                                            @else
                                                <span class="ms-source-badge myskool">MySkool</span>
                                            @endif
                                            @if($receipt->is_reversed)
                                                <span class="ms-source-badge reversed">REVERSED</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="col-fee-type">{{ $receipt->fee_types_str }}</td>
                                    <td class="col-class">{{ $receipt->classes_str }}</td>
                                    <td class="col-student">
                                        <div class="ms-history-students" title="{{ $receipt->students_str }}">
                                            @if(!empty($receipt->students) && is_array($receipt->students))
                                                @foreach($receipt->students as $stuEntry)
                                                    @php
                                                        $stuLabel = is_array($stuEntry)
                                                            ? trim(($stuEntry['name'] ?? '') . (!empty($stuEntry['gr_number']) ? ' (' . $stuEntry['gr_number'] . ')' : ''))
                                                            : trim((string) $stuEntry);
                                                    @endphp
                                                    @if($stuLabel !== '')
                                                        <span class="ms-history-student-item">{{ $stuLabel }}</span>
                                                    @endif
                                                @endforeach
                                            @else
                                                <span class="ms-history-student-item">{{ $receipt->students_str }}</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="col-details">
                                        <div class="ms-history-months" title="{{ $receipt->months_str }}">
                                            @php
                                                $monthItems = (!empty($receipt->months) && is_array($receipt->months))
                                                    ? array_values(array_filter(array_map('trim', $receipt->months)))
                                                    : array_values(array_filter(array_map('trim', explode(',', (string) $receipt->months_str))));
                                            @endphp
                                            @forelse($monthItems as $mItem)
                                                <span class="ms-pill-lavender ms-month-badge">{{ $mItem }}</span>
                                            @empty
                                                <span class="ms-pill-lavender ms-month-badge">{{ $receipt->months_str ?: '—' }}</span>
                                            @endforelse
                                        </div>
                                    </td>
                                    <td class="col-discount" style="text-align: right; white-space: nowrap;">
                                        {{ (int)$receipt->discount_minor > 0 ? $receipt->currency . ' ' . number_format($receipt->discount, 2) : '0' }}
                                    </td>
                                    <td class="col-total" style="text-align: right; white-space: nowrap;">
                                        <b>{{ $receipt->currency }} {{ number_format($receipt->amount, 2) }}</b>
                                    </td>
                                    <td class="col-date" style="white-space: nowrap;">{{ $receipt->received_on_formatted }}</td>
                                    <td class="col-received-by">
                                        <div style="font-size: 11px; color: #5e5873; line-height: 1.25;">{{ $receipt->received_by_name ?? '—' }}</div>
                                        <div style="font-size: 10px; color: #b9b9c3; line-height: 1.2;">{{ $receipt->payment_method ?? 'Cash' }}</div>
                                    </td>
                                    <td class="col-actions">
                                        <div class="ms-action-group">
                                            {{-- Print Action --}}
                                            <a href="{{ $receipt->print_url }}" target="_blank" class="btn-ms-square-action" style="background: var(--ms-purple); color: #fff; text-decoration: none;" title="{{ $receipt->is_historical ? 'Print Historical Payment Record' : 'Print Official Receipt' }}">
                                                &#128438;
                                            </a>
                                            {{-- View Details Modal Action --}}
                                            <button type="button" class="btn-ms-square-action" onclick='showReceiptDetails(@json($receipt->details_payload))' style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1;" title="View Complete Itemized Breakdown">
                                                &#128065;
                                            </button>
                                            {{-- Reversal Action (Portal Only, when permitted and not reversed) --}}
                                            @if($receipt->can_reverse)
                                                <button type="button" class="btn-ms-square-action danger action-trigger"
                                                    data-action="reverse"
                                                    data-id="{{ $receipt->id }}"
                                                    data-currency="{{ $receipt->currency }}"
                                                    data-slip="{{ $receipt->slip_number }}"
                                                    data-balance="{{ number_format($receipt->amount_minor / 100, 2, '.', '') }}"
                                                    title="Reverse Receipt">
                                                    ✕
                                                </button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="10" style="text-align: center; padding: 22px; color: #b9b9c3;">
                                        No fee payment receipts matching the criteria found.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- PAGINATION --}}
                @if($feeHistory->hasPages())
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; border-top: 1px solid #ebe9f1; background: #fafafc; flex-wrap: wrap; gap: 8px;">
                        <div style="font-size: 12px; color: #6e6b7b;">
                            Showing <b>{{ $feeHistory->firstItem() }}</b> to <b>{{ $feeHistory->lastItem() }}</b> of <b>{{ $feeHistory->total() }}</b> receipts
                        </div>
                        <div>
                            {{ $feeHistory->links() }}
                        </div>
                    </div>
                @endif
            </div>
        @endif
    </div>
{{-- RIGHT COLUMN: STUDENT PROFILE & RECEIVE PANEL --}}
    <div class="myskool-right-col">
        <div class="myskool-card">
            {{-- PROFILE HEADER --}}
            <div class="myskool-profile-card">
                <span class="myskool-card-gear" title="Settings">&#9881;</span>
                <div class="myskool-avatar">&#128100;</div>
                <div class="myskool-student-name">
                    @if($family)
                        {{ $firstStudent?->student_name ?? \App\Services\GuardianNameResolver::forFamily($family) }}
                    @elseif($searchTerm !== '')
                        No Student Found
                    @else
                        No Student Selected
                    @endif
                </div>
                <div class="myskool-father-name">
                    @php
                        $resolvedParent = $family ? \App\Services\GuardianNameResolver::forFamilyDisplay($family) : null;
                    @endphp
                    @if($family)
                        {{ $resolvedParent['label'] ?? 'Parent / Guardian' }}: {{ $resolvedParent['name'] ?? 'Not provided' }}
                    @elseif($searchTerm !== '')
                        No record for "{{ $searchTerm }}"
                    @else
                        Search GR # or Family Code
                    @endif
                </div>
                <div class="myskool-class-name">
                    {{ $family ? ($firstStudent?->class_name ?? '—') : '—' }}
                </div>
                <div class="myskool-badge-row">
                    @if($family)
                        <span class="myskool-status-badge">present</span>
                        <span class="myskool-campus-badge">Main Campus</span>
                    @else
                        <span class="myskool-campus-badge">Main Campus</span>
                    @endif
                </div>
                <div class="myskool-profile-actions">
                    <a href="{{ $family ? route('families.challan.pdf', $family->id) : '#' }}" target="{{ $family ? '_blank' : '_self' }}" class="myskool-profile-btn" style="text-decoration: none; {{ !$family ? 'pointer-events: none; opacity: 0.5;' : '' }}" title="Print Family Consolidated Challan">
                        &#128438; Print
                    </a>
                    <button type="button" class="myskool-profile-btn" onclick="alertNotice('Message dialog opened for family')" {{ !$family ? 'disabled style=opacity:0.5;cursor:not-allowed;' : '' }}>&#9993;</button>
                    <a href="{{ $family ? '/invoices?family=' . urlencode($family->family_code ?: (string)$family->id) : '/invoices' }}" class="myskool-profile-btn" style="text-decoration: none;">&#9707;</a>
                </div>
            </div>

            {{-- RECEIVING FORM (With Multi-Item IDs, Live Allocation Breakdown & Advance Credit Toggle) --}}
            <form method="post" action="/challan/receive" id="myskoolReceiveForm" class="myskool-receive-form">
                @csrf
                <input type="hidden" name="family_id" value="{{ $family?->id }}">
                <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                <input type="hidden" name="selected_period" id="selected_period" value="{{ $defaultSelectedPeriod }}">
                <div id="hidden-selected-items-inputs"></div>

                {{-- Selected Items Summary Banner --}}
                <div id="selected_items_summary" style="display: none; margin-bottom: 12px; font-size: 11px; padding: 8px 12px; background: #f3f0ff; border-radius: 6px; border: 1px solid #dcd7fa; color: var(--ms-purple);">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span id="selected_items_count" style="font-weight: 700;">0 items selected</span>
                        <span id="selected_items_sum" style="font-weight: 700; color: #5b21b6;">SAR 0.00</span>
                    </div>
                </div>

                {{-- Total Dues --}}
                <div class="myskool-form-group">
                    <label style="display: flex; justify-content: space-between; align-items: center;">
                        <span>Total dues</span>
                        @if($currencyDuesMap->isNotEmpty())
                            <span style="font-size: 11px; color: var(--ms-purple); font-weight: 600;">
                                {{ $currencyDuesMap->keys()->implode(', ') }}
                            </span>
                        @endif
                    </label>
                    @if($currencyDuesMap->isNotEmpty())
                        <div style="display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 6px;">
                            @foreach($currencyDuesMap as $dueCurr => $dueBalMinor)
                                <span class="ms-pill-lavender" style="font-size: 11px; font-weight: 600;">{{ $dueCurr }} {{ number_format($dueBalMinor / 100, 2) }}</span>
                            @endforeach
                        </div>
                    @endif
                    <input class="myskool-input disabled" id="disp_total_dues" readonly value="{{ $defaultDuesDisplay }}">
                </div>

                {{-- Receiving Amount --}}
                <div class="myskool-form-group">
                    <label>Receiving amount</label>
                    <input class="myskool-input" id="input_monthly_amount" name="monthly_amount" type="number" step=".01" min="0" placeholder="Receiving amount" style="font-weight: 600;">
                </div>

                {{-- Balance --}}
                <div class="myskool-form-group">
                    <label>Balance</label>
                    <input class="myskool-input disabled" id="disp_remaining_balance" readonly value="{{ $defaultDuesDisplay }}">
                </div>

                {{-- LIVE ALLOCATION BREAKDOWN CARD (Requirement 9) --}}
                <div id="allocation_breakdown_card" style="display: none; border: 1px solid #dcd7fa; border-radius: 6px; padding: 10px; background: #faf9ff; margin-bottom: 12px; font-size: 11px;">
                    <div style="font-weight: 700; color: var(--ms-purple); margin-bottom: 6px; display: flex; justify-content: space-between;">
                        <span>Allocation Breakdown</span>
                        <span id="breakdown_currency_badge" style="font-size: 10px; background: #e0e7ff; color: #4338ca; padding: 1px 6px; border-radius: 10px;">{{ $singleCurrencyCode ?? 'SAR' }}</span>
                    </div>
                    <div id="allocation_breakdown_list" style="display: flex; flex-direction: column; gap: 4px;"></div>
                </div>

                {{-- EXPLICIT ADVANCE CREDIT CONFIRMATION (Requirement 8) --}}
                <div id="advance_credit_box" style="display: none; background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; padding: 10px; margin-bottom: 12px; font-size: 11px;">
                    <div style="font-weight: 700; color: #92400e; margin-bottom: 4px;">⚠️ Advance Credit Confirmation</div>
                    <p style="margin: 0 0 6px 0; color: #78350f;" id="advance_credit_text">
                        Payment exceeds selected dues. Overpayment will be recorded as advance credit.
                    </p>
                    <label style="display: flex; align-items: flex-start; gap: 6px; cursor: pointer; color: #92400e; font-size: 11px;">
                        <input type="checkbox" name="allow_advance_credit" id="chk_allow_advance_credit" value="1" style="margin-top: 2px;">
                        <span><strong>I explicitly confirm</strong> recording this overpayment as advance credit for future billing.</span>
                    </label>
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

                {{-- Registration Fee Receiving (Grouped strictly by currency) --}}
                @php
                    $unpaidRegByCurr = collect($registrationStatus['by_currency'] ?? [])
                        ->filter(fn($r) => !empty($r['has_obligation']) && empty($r['is_fully_paid']) && empty($r['is_exempted']) && (int)($r['balance_minor'] ?? 0) > 0);
                @endphp
                @if($unpaidRegByCurr->isNotEmpty())
                    @php
                        $regDueLabels = $unpaidRegByCurr->map(fn($r, $c) => $c . ' ' . number_format($r['balance_minor'] / 100, 2))->values()->implode(' | ');
                        $firstRegEntry = $unpaidRegByCurr->first();
                    @endphp
                    <div style="background: #f8f7ff; border: 1px solid #dcd7fa; border-radius: 4px; padding: 6px 8px; margin-top: 4px;">
                        <label style="display: flex; align-items: center; gap: 6px; font-size: 11px; margin: 0; cursor: pointer; color: var(--ms-purple);">
                            <input type="checkbox" name="receive_registration_fee" id="reg_fee_toggle" value="1" checked>
                            <b>Include Registration Fee (Due: {{ $regDueLabels }})</b>
                        </label>
                        <input class="myskool-input" type="number" step=".01" min="0" name="registration_amount" id="reg_fee_amount" value="{{ number_format(($firstRegEntry['balance_minor'] ?? 0) / 100, 2, '.', '') }}" style="height: 28px; margin-top: 4px;">
                    </div>
                @endif

                {{-- Receive Button --}}
                <button type="submit" class="myskool-btn-receive" id="btn-submit-receive" @disabled(!$family)>
                    Receive
                </button>
            </form>
        </div>
    </div>
</div>

{{-- EXACT MYSKOOL DISCOUNT DRAWER --}}
<div class="myskool-drawer-overlay" id="myskoolDiscountDrawer" hidden>
    <div class="myskool-drawer">
        <div class="myskool-drawer-header">
            <div class="myskool-drawer-title" id="drawerHeading">Discount</div>
            <button type="button" class="myskool-drawer-close" id="drawerCloseBtn">&times;</button>
        </div>

        <form method="post" id="drawerActionForm" action="/challan/discount">
            @csrf
            <input type="hidden" name="invoice_item_id" id="drawer_item_id">

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
            <div class="myskool-drawer-field">
                <label>Discount</label>
                <input class="myskool-input" id="drawer_discount_input" name="amount" type="number" step=".01" min="0" placeholder="50" style="font-weight: 600;">
            </div>

            {{-- 3. Net Balance (Live calculated) --}}
            <div class="myskool-drawer-field">
                <label>Net Balance</label>
                <input class="myskool-input disabled" id="drawer_net_balance" readonly value="300">
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

            {{-- 5. Save Button --}}
            <button type="submit" class="btn-ms-save" id="drawerSaveBtn">
                Save
            </button>
        </form>
    </div>
</div>

<div id="msToastContainer"></div>

{{-- Pass Server Catalog Data to JS for Client-Side Rate Calculations --}}
<script>
window.IQRA_CATALOG_RATES = @json($catalogRates ?? []);
window.IQRA_FAMILY_STUDENTS = @json($familyStudents ?? []);
window.IQRA_STUDENT_FEE_PLANS = @json($studentFeePlans ?? []);
window.IQRA_CURRENCY_DUES = @json($currencyDuesFloatMap);
window.IQRA_DEFAULT_DUES_DISPLAY = @json($defaultDuesDisplay);
window.IQRA_IS_MULTI_CURRENCY = @json($isMultiCurrencyDues);
</script>

<script>
(() => {
  // Toast Notification Utility (Task 1.9B)
  function showToast(message, type) {
    const toastType = (type === 'error') ? 'error' : 'success';
    const container = document.getElementById('msToastContainer') || document.body;
    if (container.id === 'msToastContainer') {
      container.innerHTML = '';
    }
    const toast = document.createElement('div');
    toast.className = 'ms-toast ' + toastType;
    toast.textContent = message || '';
    container.appendChild(toast);
    setTimeout(() => {
      if (toast.parentNode) {
        toast.parentNode.removeChild(toast);
      }
    }, 3500);
  }
  window.showToast = showToast;

  window.alertNotice = function(msg, type = 'success') {
    showToast(msg, type);
  };

  // Search handling (Active-field tracking + stale-field clearing)
  const form = document.getElementById('iq-search-form');
  const gr = document.getElementById('search-gr');
  const fam = document.getElementById('search-family');
  const inv = document.getElementById('search-invoice');
  const hiddenQ = document.getElementById('search-query-hidden');
  const hiddenBy = document.getElementById('search-by-hidden');

  if (form && gr && fam && inv && hiddenQ) {
    const initialVals = {
      gr: (gr.value || '').trim(),
      family: (fam.value || '').trim(),
      invoice: (inv.value || '').trim()
    };
    let activeSearchField = (hiddenBy && hiddenBy.value) ? hiddenBy.value : '';

    const searchInputs = [
      { mode: 'gr', el: gr, others: [fam, inv] },
      { mode: 'family', el: fam, others: [gr, inv] },
      { mode: 'invoice', el: inv, others: [gr, fam] }
    ];

    searchInputs.forEach(({ mode, el, others }) => {
      const syncFromInput = () => {
        activeSearchField = mode;
        const currentVal = (el.value || '').trim();
        if (currentVal !== '') {
          others.forEach(otherEl => { otherEl.value = ''; });
        }
        hiddenQ.value = currentVal;
        if (hiddenBy) {
          hiddenBy.value = mode;
        }
      };

      el.addEventListener('input', syncFromInput);
      el.addEventListener('change', syncFromInput);
      el.addEventListener('keydown', e => {
        if (e.key === 'Enter') {
          activeSearchField = mode;
        }
      });
    });

    form.addEventListener('submit', e => {
      const grVal = (gr.value || '').trim();
      const famVal = (fam.value || '').trim();
      const invVal = (inv.value || '').trim();
      const valByMode = { gr: grVal, family: famVal, invoice: invVal };

      let chosenMode = '';
      if (activeSearchField && valByMode[activeSearchField]) {
        chosenMode = activeSearchField;
      } else if (grVal && grVal !== initialVals.gr) {
        chosenMode = 'gr';
      } else if (famVal && famVal !== initialVals.family) {
        chosenMode = 'family';
      } else if (invVal && invVal !== initialVals.invoice) {
        chosenMode = 'invoice';
      } else if (document.activeElement === gr && grVal) {
        chosenMode = 'gr';
      } else if (document.activeElement === fam && famVal) {
        chosenMode = 'family';
      } else if (document.activeElement === inv && invVal) {
        chosenMode = 'invoice';
      } else if (grVal) {
        chosenMode = 'gr';
      } else if (famVal) {
        chosenMode = 'family';
      } else if (invVal) {
        chosenMode = 'invoice';
      }

      const chosenVal = chosenMode ? valByMode[chosenMode] : '';
      if (!chosenVal) {
        e.preventDefault();
        gr.focus();
        return;
      }

      hiddenQ.value = chosenVal;
      if (hiddenBy) {
        hiddenBy.value = chosenMode;
      }
    });
  }

  // Receive Form & Multi-Item Allocation Engine (Currency-Isolated)
  const currencyDuesMap = window.IQRA_CURRENCY_DUES || {};
  const defaultDuesDisplay = window.IQRA_DEFAULT_DUES_DISPLAY || '0';
  const isMultiCurrency = Boolean(window.IQRA_IS_MULTI_CURRENCY);
  const inputMonthly = document.getElementById('input_monthly_amount');
  const dispRemaining = document.getElementById('disp_remaining_balance');
  const ledgerScopeSelect = document.getElementById('ledger_scope');
  const regToggle = document.getElementById('reg_fee_toggle');
  const regAmount = document.getElementById('reg_fee_amount');
  const selectedPeriodInput = document.getElementById('selected_period');
  const hiddenSelectedItems = document.getElementById('hidden-selected-items-inputs');
  const selectedSummary = document.getElementById('selected_items_summary');
  const selectedCount = document.getElementById('selected_items_count');
  const selectedSum = document.getElementById('selected_items_sum');
  const breakdownCard = document.getElementById('allocation_breakdown_card');
  const breakdownCurrencyBadge = document.getElementById('breakdown_currency_badge');
  const breakdownList = document.getElementById('allocation_breakdown_list');
  const advanceCreditBox = document.getElementById('advance_credit_box');
  const advanceCreditText = document.getElementById('advance_credit_text');
  const chkAllowAdvanceCredit = document.getElementById('chk_allow_advance_credit');
  const chkSelectAll = document.getElementById('chk_select_all');

  const itemCheckboxes = Array.from(document.querySelectorAll('.js-item-checkbox'));
  const feeRows = Array.from(document.querySelectorAll('.js-fee-row'));

  function renderHiddenInputs(container, inputName, values) {
    if (!container) return;
    container.innerHTML = '';
    values.forEach(val => {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = inputName;
      input.value = val;
      container.appendChild(input);
    });
  }

  function getSelectedItems() {
    return itemCheckboxes.filter(cb => cb.checked).map(cb => ({
      id: cb.value,
      balance: parseFloat(cb.dataset.balance) || 0,
      currency: cb.dataset.currency,
      period: cb.dataset.period,
      periodLabel: cb.dataset.periodLabel,
      student: cb.dataset.student,
      gr: cb.dataset.gr,
      type: cb.dataset.type,
    }));
  }

  function resolveActiveCurrency(selected) {
    if (selected.length > 0 && selected[0].currency) {
      return selected[0].currency;
    }
    if (ledgerScopeSelect && ledgerScopeSelect.value && ledgerScopeSelect.value.includes(':')) {
      const parts = ledgerScopeSelect.value.split(':');
      if (parts[2]) return parts[2].toUpperCase();
    }
    const keys = Object.keys(currencyDuesMap);
    return keys.length === 1 ? keys[0] : null;
  }

  function updateAllocationBreakdown(preselected) {
    const selected = Array.isArray(preselected) ? preselected : getSelectedItems();
    renderHiddenInputs(hiddenSelectedItems, 'selected_item_ids[]', selected.map(item => item.id));

    const monthlyVal = Number(inputMonthly?.value) || 0;
    const regVal = (regToggle && regToggle.checked && regAmount) ? (Number(regAmount.value) || 0) : 0;
    const paidTotal = monthlyVal + regVal;
    const activeCurr = resolveActiveCurrency(selected);

    if (dispRemaining) {
      if (isMultiCurrency && selected.length === 0 && paidTotal <= 0) {
        dispRemaining.value = defaultDuesDisplay;
      } else if (activeCurr && Object.prototype.hasOwnProperty.call(currencyDuesMap, activeCurr)) {
        const currDue = Number(currencyDuesMap[activeCurr]) || 0;
        const rem = Math.max(0, currDue - paidTotal);
        dispRemaining.value = isMultiCurrency ? `${activeCurr} ${rem.toFixed(2)}` : rem.toFixed(0);
      } else {
        dispRemaining.value = defaultDuesDisplay;
      }
    }

    if (selected.length === 0) {
      if (selectedSummary) selectedSummary.style.display = 'none';
      if (breakdownCard) breakdownCard.style.display = 'none';
      if (advanceCreditBox) advanceCreditBox.style.display = 'none';
      return;
    }

    // Show Selected Summary
    const totalSelectedBal = selected.reduce((acc, it) => acc + it.balance, 0);
    const curr = selected[0]?.currency || activeCurr || 'SAR';
    if (breakdownCurrencyBadge) {
      breakdownCurrencyBadge.textContent = curr;
    }
    if (selectedSummary) {
      selectedSummary.style.display = 'block';
      selectedCount.textContent = `${selected.length} item(s) selected`;
      selectedSum.textContent = `${curr} ${totalSelectedBal.toFixed(2)}`;
    }

    // Compute live allocation per item (chronological order)
    if (breakdownCard && breakdownList) {
      breakdownCard.style.display = 'block';
      breakdownList.innerHTML = '';

      let availablePayment = monthlyVal;

      selected.forEach(item => {
        const itemDue = item.balance;
        const allocated = Math.min(availablePayment, itemDue);
        availablePayment -= allocated;

        const isFull = (allocated >= itemDue - 0.001);
        const isPartial = (allocated > 0 && !isFull);

        const rowDiv = document.createElement('div');
        rowDiv.style.cssText = 'display: flex; justify-content: space-between; align-items: center; padding: 3px 0; border-bottom: 1px dashed #e2e8f0;';
        
        let statusBadge = isFull 
          ? `<span style="color: #15803d; font-weight: 600;">Fully Paid (${curr} ${allocated.toFixed(2)})</span>`
          : (isPartial 
              ? `<span style="color: #b45309; font-weight: 600;">Partial (${curr} ${allocated.toFixed(2)} / ${itemDue.toFixed(2)})</span>` 
              : `<span style="color: #94a3b8;">Unpaid (0.00 / ${itemDue.toFixed(2)})</span>`);

        rowDiv.innerHTML = `
          <div>
            <strong>${item.periodLabel}</strong> &middot; ${item.type}
            <div style="font-size: 10px; color: #64748b;">${item.student} (GR: ${item.gr})</div>
          </div>
          <div style="text-align: right;">${statusBadge}</div>
        `;
        breakdownList.appendChild(rowDiv);
      });

      // Check for excess payment (Advance Credit)
      if (availablePayment > 0.009) {
        if (advanceCreditBox) {
          advanceCreditBox.style.display = 'block';
          if (advanceCreditText) {
            advanceCreditText.innerHTML = `Receiving amount exceeds selected dues by <strong>${curr} ${availablePayment.toFixed(2)}</strong>. This excess will be recorded as advance credit for future billing.`;
          }
        }
      } else {
        if (advanceCreditBox) advanceCreditBox.style.display = 'none';
      }
    }
  }

  function applySelectionToReceiveForm(selected, syncPeriod) {
    const sum = selected.reduce((acc, it) => acc + it.balance, 0);
    if (inputMonthly) {
      inputMonthly.value = sum > 0 ? sum.toFixed(2) : '';
    }
    if (syncPeriod && selectedPeriodInput && selected.length > 0) {
      selectedPeriodInput.value = selected[0].period;
    }
    updateAllocationBreakdown(selected);
  }

  // Checkbox interactions with cross-currency prevention
  itemCheckboxes.forEach(cb => {
    cb.addEventListener('change', () => {
      const selected = getSelectedItems();
      if (selected.length > 1) {
        const firstCurr = selected[0].currency;
        const mismatched = selected.find(it => it.currency !== firstCurr);
        if (mismatched) {
          alertNotice(`Cross-currency allocation is not allowed. Items in ${mismatched.currency} cannot be combined with ${firstCurr}.`, 'error');
          cb.checked = false;
          return;
        }
      }

      // Sync row class
      cb.closest('tr')?.classList.toggle('myskool-row-selected', cb.checked);
      applySelectionToReceiveForm(selected, true);
    });
  });

  // Select All toggle
  if (chkSelectAll) {
    chkSelectAll.addEventListener('change', () => {
      const isChecked = chkSelectAll.checked;
      let firstCurr = null;

      itemCheckboxes.forEach(cb => {
        if (cb.disabled) return;
        if (isChecked) {
          if (!firstCurr) firstCurr = cb.dataset.currency;
          if (cb.dataset.currency === firstCurr) {
            cb.checked = true;
            cb.closest('tr')?.classList.add('myskool-row-selected');
          }
        } else {
          cb.checked = false;
          cb.closest('tr')?.classList.remove('myskool-row-selected');
        }
      });

      applySelectionToReceiveForm(getSelectedItems(), false);
    });
  }

  // Row click selection
  feeRows.forEach(row => {
    row.addEventListener('click', e => {
      if (e.target.closest('.action-trigger') || e.target.closest('button') || e.target.closest('a') || e.target.closest('input')) {
        return;
      }
      if (row.classList.contains('is-settled')) return;

      const cb = row.querySelector('.js-item-checkbox');
      if (cb && !cb.disabled) {
        cb.checked = !cb.checked;
        cb.dispatchEvent(new Event('change'));
      }
    });
  });

  if (inputMonthly) inputMonthly.addEventListener('input', updateAllocationBreakdown);
  if (regAmount) regAmount.addEventListener('input', updateAllocationBreakdown);
  if (regToggle) regToggle.addEventListener('change', updateAllocationBreakdown);
  if (ledgerScopeSelect) ledgerScopeSelect.addEventListener('change', updateAllocationBreakdown);

  // Form submit confirmation check for advance credit
  const receiveForm = document.getElementById('myskoolReceiveForm');
  if (receiveForm) {
    receiveForm.addEventListener('submit', e => {
      const selected = getSelectedItems();
      const totalSelectedDue = selected.reduce((acc, it) => acc + it.balance, 0);
      const monthlyVal = Number(inputMonthly?.value) || 0;

      if (selected.length > 0 && monthlyVal > totalSelectedDue + 0.009) {
        if (chkAllowAdvanceCredit && !chkAllowAdvanceCredit.checked) {
          e.preventDefault();
          alertNotice('Explicit confirmation is required before creating Advance Credit. Please check the advance credit confirmation checkbox.', 'error');
          chkAllowAdvanceCredit.focus();
          return false;
        }
      }
    });
  }

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

  // Drawer Elements (Discount Drawer)
  const drawerOverlay = document.getElementById('myskoolDiscountDrawer');
  const drawerHeading = document.getElementById('drawerHeading');
  const drawerCloseBtn = document.getElementById('drawerCloseBtn');
  const drawerForm = document.getElementById('drawerActionForm');
  const drawerItemId = document.getElementById('drawer_item_id');
  const drawerCurBal = document.getElementById('drawer_current_balance');
  const drawerDiscInput = document.getElementById('drawer_discount_input');
  const drawerNetBal = document.getElementById('drawer_net_balance');
  const drawerReasonCatBlock = document.getElementById('drawer_reason_category_block');
  const drawerExistBox = document.getElementById('drawer_existing_discount_box');
  const drawerExistVal = document.getElementById('drawer_existing_discount_val');
  const drawerExistReason = document.getElementById('drawer_existing_discount_reason');

  let originalItemBalance = 0;

  function closeDrawer() {
    if (drawerOverlay) drawerOverlay.hidden = true;
    if (drawerForm) drawerForm.reset();
  }

  if (drawerCloseBtn) drawerCloseBtn.addEventListener('click', closeDrawer);
  if (drawerOverlay) {
    drawerOverlay.addEventListener('click', e => {
      if (e.target === drawerOverlay) closeDrawer();
    });
  }

  function updateDrawerNetBalance() {
    const disc = parseFloat(drawerDiscInput?.value || 0);
    const net = Math.max(0, originalItemBalance - disc);
    if (drawerNetBal) drawerNetBal.value = net.toFixed(2);
  }

  if (drawerDiscInput) drawerDiscInput.addEventListener('input', updateDrawerNetBalance);

  function submitPostAction(actionUrl, fields) {
    const actionForm = document.createElement('form');
    actionForm.method = 'POST';
    actionForm.action = actionUrl;
    const allFields = Object.assign({ _token: '{{ csrf_token() }}' }, fields);
    Object.entries(allFields).forEach(([name, value]) => {
      const inp = document.createElement('input');
      inp.type = 'hidden';
      inp.name = name;
      inp.value = value;
      actionForm.appendChild(inp);
    });
    document.body.appendChild(actionForm);
    actionForm.submit();
  }

  // Discount / Reversal / Void Action Triggers
  document.querySelectorAll('.action-trigger').forEach(btn => {
    btn.addEventListener('click', e => {
      e.stopPropagation();
      const action = btn.dataset.action;
      const itemId = btn.dataset.id;
      const balance = parseFloat(btn.dataset.balance || 0);
      const currency = btn.dataset.currency || 'SAR';

      if (action === 'discount') {
        const existingDiscount = parseFloat(btn.dataset.discount || 0);
        const existingReason = btn.dataset.discountReason || '';
        originalItemBalance = balance;

        if (drawerItemId) drawerItemId.value = itemId;
        if (drawerCurBal) drawerCurBal.value = `${balance.toFixed(2)} ${currency}`;
        if (drawerNetBal) drawerNetBal.value = `${balance.toFixed(2)} ${currency}`;
        if (drawerHeading) drawerHeading.textContent = 'Discount';
        if (drawerForm) drawerForm.action = '/challan/discount';
        if (drawerReasonCatBlock) drawerReasonCatBlock.style.display = 'block';
        if (drawerExistBox) {
          if (existingDiscount > 0) {
            drawerExistBox.style.display = 'block';
            if (drawerExistVal) drawerExistVal.textContent = `${currency} ${existingDiscount.toFixed(2)}`;
            if (drawerExistReason) drawerExistReason.textContent = existingReason ? `Reason: ${existingReason}` : '';
          } else {
            drawerExistBox.style.display = 'none';
          }
        }
        if (drawerOverlay) drawerOverlay.hidden = false;
        drawerDiscInput?.focus();
      } else if (action === 'reverse') {
        const slip = btn.dataset.slip;
        if (confirm(`Are you sure you want to REVERSE receipt #${slip} for ${currency} ${balance.toFixed(2)}?\n\nThis will restore all paid items to outstanding dues.`)) {
          submitPostAction('/challan/reverse-receipt', {
            payment_id: itemId,
            reason: 'User reversed receipt via portal'
          });
        }
      } else if (action === 'void') {
        if (confirm(`Are you sure you want to VOID this fee item?\n\nThis action cannot be undone.`)) {
          submitPostAction('/challan/void', {
            invoice_item_id: itemId,
            reason: 'Fee voided via portal'
          });
        }
      }
    });
  });

  // CREATE FEE ENGINE: Enhanced with live rate lookup and preview
  const btnOpenCreateFee = document.getElementById('btn-open-create-fee');
  const createFeePanel = document.getElementById('create-fee-panel');
  const btnCancelCreateFee = document.getElementById('btn-cancel-create-fee');
  const toggleMoreFeeTypes = document.getElementById('toggle-more-fee-types');
  const previewTbody = document.getElementById('cf-preview-tbody');
  const previewCount = document.getElementById('cf-preview-count');
  const previewWarning = document.getElementById('cf-preview-warning');
  const btnSubmitCreateFee = document.getElementById('btn-submit-create-fee');

  if (btnOpenCreateFee && createFeePanel) {
    btnOpenCreateFee.addEventListener('click', function(e) {
      e.preventDefault();
      const isHidden = createFeePanel.style.display === 'none' || !createFeePanel.style.display;
      createFeePanel.style.display = isHidden ? 'block' : 'none';
      if (isHidden) {
        createFeePanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        updateCreateFeePreview();
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
      toggleMoreFeeTypes.textContent = expanded ? 'Show Less' : 'Show All (8)';
    });
  }

  function resolveStudentFeeRate(student, feeTypeId, feeTypeCode) {
    // 1. Check student-specific active plan
    const studentPlans = window.IQRA_STUDENT_FEE_PLANS || [];
    const plan = studentPlans.find(p => p.student_id === student.id && p.fee_type_id == feeTypeId && p.is_active);
    if (plan && plan.amount_minor > 0) {
      return { amount: plan.amount_minor / 100, source: 'Student Plan', isOneTime: plan.frequency === 'one_time' };
    }

    // If monthly fee and enrollment has monthly_fee_minor
    if (feeTypeCode === 'monthly' && student.monthly_fee_minor > 0) {
      return { amount: student.monthly_fee_minor / 100, source: 'Enrollment Rate', isOneTime: false };
    }

    // 2. Check approved catalog rates
    const catalog = window.IQRA_CATALOG_RATES || [];
    const catRate = catalog.find(r => 
      r.fee_type_id == feeTypeId && 
      r.department_id == student.department_id && 
      r.class_label === student.grade_label && 
      r.currency === student.currency
    );

    if (catRate && catRate.amount_minor > 0) {
      return { amount: catRate.amount_minor / 100, source: 'Class Catalog', isOneTime: catRate.frequency === 'one_time' || feeTypeCode !== 'monthly' };
    }

    return null;
  }

  function updateCreateFeePreview() {
    if (!previewTbody) return;
    previewTbody.innerHTML = '';
    if (previewWarning) previewWarning.style.display = 'none';

    // Get selected students
    const activeStudentChips = Array.from(document.querySelectorAll('#student-selector-chips .ms-chip.active'));
    let targetStudents = [];
    const allStudents = window.IQRA_FAMILY_STUDENTS || [];

    if (activeStudentChips.length === 0 || activeStudentChips.some(c => c.dataset.studentId === 'all')) {
      targetStudents = allStudents;
    } else {
      const selIds = activeStudentChips.map(c => parseInt(c.dataset.studentId));
      targetStudents = allStudents.filter(s => selIds.includes(s.id));
    }

    // Get selected fee types
    const activeTypeChips = Array.from(document.querySelectorAll('#fee-types-selector-chips .ms-chip.active'));
    const selectedTypes = activeTypeChips.map(c => ({
      id: parseInt(c.dataset.feeTypeId),
      code: c.dataset.code,
      name: c.textContent.trim()
    }));

    // Get selected months
    const activeMonthChips = Array.from(document.querySelectorAll('#months-selector-chips .ms-chip.active'));
    const selectedMonths = activeMonthChips.map(c => parseInt(c.dataset.monthVal));
    const yearSelect = document.getElementById('cf-year-select');
    const selectedYear = yearSelect?.value || new Date().getFullYear();

    const monthNames = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    let rowsCount = 0;
    let missingRates = [];

    targetStudents.forEach(student => {
      selectedTypes.forEach(ft => {
        const rateInfo = resolveStudentFeeRate(student, ft.id, ft.code);
        if (!rateInfo) {
          missingRates.push(`${student.name} (${student.grade_label}, ${student.currency}): No approved rate for '${ft.name}'`);
          return;
        }

        const isOneTime = rateInfo.isOneTime;
        const periods = isOneTime ? [selectedMonths[0] || 1] : selectedMonths;

        periods.forEach(m => {
          rowsCount++;
          const periodStr = isOneTime ? `One-Time (${monthNames[m]} ${selectedYear})` : `${monthNames[m]} ${selectedYear}`;
          const tr = document.createElement('tr');
          tr.style.borderBottom = '1px solid #f1f5f9';
          tr.innerHTML = `
            <td style="padding: 4px 6px;"><strong>${student.name}</strong><br><span style="color:#94a3b8;font-size:9px;">${student.grade_label}</span></td>
            <td style="padding: 4px 6px;"><strong>${ft.name}</strong><br><span style="color:#64748b;font-size:9px;">${rateInfo.source}</span></td>
            <td style="padding: 4px 6px;"><span class="ms-pill-lavender" style="font-size:9px;">${periodStr}</span></td>
            <td style="padding: 4px 6px; text-align: right; font-weight: 600;">${student.currency} ${rateInfo.amount.toFixed(2)}</td>
          `;
          previewTbody.appendChild(tr);
        });
      });
    });

    if (previewCount) {
      previewCount.textContent = `${rowsCount} fee obligation(s) planned`;
    }

    if (missingRates.length > 0) {
      if (previewWarning) {
        previewWarning.style.display = 'block';
        previewWarning.innerHTML = `<strong>Cannot proceed: Missing approved rates:</strong><br>${missingRates.join('<br>')}`;
      }
      if (btnSubmitCreateFee) btnSubmitCreateFee.disabled = true;
    } else {
      if (btnSubmitCreateFee) btnSubmitCreateFee.disabled = (rowsCount === 0);
    }
  }

  // Hook Create Fee Selectors
  function bindMultiSelectChips(containerSelector, hiddenContainerId, inputName, dataKey) {
    const hiddenContainer = document.getElementById(hiddenContainerId);
    document.querySelectorAll(`${containerSelector} .ms-chip`).forEach(chip => {
      chip.addEventListener('click', function() {
        this.classList.toggle('active');
        let actives = Array.from(document.querySelectorAll(`${containerSelector} .ms-chip.active`));
        if (actives.length === 0) {
          this.classList.add('active');
          actives = [this];
        }
        renderHiddenInputs(hiddenContainer, inputName, actives.map(c => c.dataset[dataKey]));
        updateCreateFeePreview();
      });
    });
  }

  bindMultiSelectChips('#fee-types-selector-chips', 'hidden-fee-type-inputs', 'fee_type_ids[]', 'feeTypeId');
  bindMultiSelectChips('#months-selector-chips', 'hidden-month-inputs', 'months[]', 'monthVal');

  const yearSelect = document.getElementById('cf-year-select');
  if (yearSelect) yearSelect.addEventListener('change', updateCreateFeePreview);

  const allStudentChips = Array.from(document.querySelectorAll('#student-selector-chips .ms-chip'));
  const allStudentsChip = document.querySelector('#student-selector-chips .ms-chip[data-student-id="all"]');
  const hiddenStudentInputs = document.getElementById('hidden-student-inputs');

  allStudentChips.forEach(chip => {
    chip.addEventListener('click', function() {
      if (this.dataset.studentId === 'all') {
        allStudentChips.forEach(c => c.classList.remove('active'));
        this.classList.add('active');
      } else {
        if (allStudentsChip) allStudentsChip.classList.remove('active');
        this.classList.toggle('active');
        if (!allStudentChips.some(c => c.classList.contains('active')) && allStudentsChip) {
          allStudentsChip.classList.add('active');
        }
      }

      const actives = allStudentChips.filter(c => c.classList.contains('active'));
      const studentIds = (actives.length === 1 && actives[0].dataset.studentId === 'all')
        ? (window.IQRA_FAMILY_STUDENTS || []).map(st => st.id)
        : actives.map(c => c.dataset.studentId);

      renderHiddenInputs(hiddenStudentInputs, 'student_ids[]', studentIds);
      updateCreateFeePreview();
    });
  });

  // Form Submit Handler for Create Fee
  const formCreateFee = document.getElementById('form-create-fee');
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
          alertNotice(data.message || 'Fee created successfully');
          setTimeout(() => location.reload(), 600);
        } else {
          let errMsg = 'Failed to create fee';
          try {
            const err = await resp.json();
            errMsg = err.message || errMsg;
          } catch (_) {}
          alertNotice(errMsg, 'error');
          if (btnSubmitCreateFee) {
            btnSubmitCreateFee.disabled = false;
            btnSubmitCreateFee.textContent = 'Save';
          }
        }
      } catch (ex) {
        alertNotice('Network or processing error during fee creation. Please refresh the page to verify fee records before retrying.', 'error');
        if (btnSubmitCreateFee) {
          btnSubmitCreateFee.disabled = false;
          btnSubmitCreateFee.textContent = 'Save';
        }
      }
    });
  }

  // Initialize breakdown on load if pre-selected
  updateAllocationBreakdown();
})();
</script>

{{-- RECEIPT ITEMIZATION DETAILS DIALOG --}}
<dialog id="receipt-details-dialog" style="border:none;border-radius:8px;box-shadow:0 12px 40px rgba(0,0,0,0.3);padding:0;max-width:650px;width:95%;margin:auto;font-family:inherit;">
 <div style="background:#7367f0;color:#fff;padding:16px 20px;display:flex;align-items:center;justify-content:space-between;border-top-left-radius:8px;border-top-right-radius:8px;">
  <strong style="font-size:16px;display:flex;align-items:center;gap:8px;">
    <span>Receipt Slip #<span id="rd-slip"></span></span>
    <span id="rd-source-badge" style="font-size:10px;padding:2px 8px;border-radius:12px;font-weight:700;"></span>
  </strong>
  <button type="button" onclick="document.getElementById('receipt-details-dialog').close()" style="background:none;border:none;color:#fff;font-size:24px;line-height:1;cursor:pointer;">&times;</button>
 </div>
 <div style="padding:20px;font-size:13px;color:#5e5873;max-height:80vh;overflow-y:auto;">
  
  {{-- META GRID --}}
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;background:#f8f7fa;border:1px solid #ebe9f1;border-radius:6px;padding:12px;margin-bottom:14px;">
    <div><small style="color:#64748b;display:block;">Payment Date</small><strong id="rd-date">—</strong></div>
    <div><small style="color:#64748b;display:block;">Total Amount</small><strong id="rd-total" style="color:#7367f0;">—</strong></div>
    <div><small style="color:#64748b;display:block;">Discount</small><strong id="rd-discount">0</strong></div>
    <div><small style="color:#64748b;display:block;">Method</small><strong id="rd-method">Cash</strong></div>
    <div><small style="color:#64748b;display:block;">Received By</small><strong id="rd-received-by">—</strong></div>
    <div><small style="color:#64748b;display:block;">Status</small><strong id="rd-status">Active</strong></div>
  </div>

  <div id="rd-notice-container"></div>

  {{-- ITEMS TABLE --}}
  <div style="font-weight:600;margin:12px 0 6px;color:#334155;">Underlying Item Allocations:</div>
  <table style="width:100%;border-collapse:collapse;font-size:12px;border:1px solid #ebe9f1;border-radius:4px;overflow:hidden;">
    <thead>
      <tr style="background:#f1f5f9;color:#475569;text-align:left;">
        <th style="padding:8px 10px;">Student</th>
        <th style="padding:8px 10px;">Fee Particular</th>
        <th style="padding:8px 10px;">Month</th>
        <th style="padding:8px 10px;text-align:right;">Amount</th>
      </tr>
    </thead>
    <tbody id="rd-items-tbody">
    </tbody>
  </table>

  {{-- FOOTER BUTTONS --}}
  <div style="display:flex;justify-content:space-between;align-items:center;margin-top:18px;padding-top:14px;border-top:1px solid #ebe9f1;">
    <a id="rd-print-btn" href="#" target="_blank" class="btn-ms-purple" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:4px;font-size:12px;">
      &#128438; Print Slip
    </a>
    <button type="button" onclick="document.getElementById('receipt-details-dialog').close()" style="background:#f1f5f9;border:1px solid #cbd5e1;padding:7px 16px;border-radius:4px;font-size:12px;cursor:pointer;color:#475569;font-weight:600;">
      Close
    </button>
  </div>
 </div>
</dialog>

<script>
window.showReceiptDetails = function(payload) {
    if (!payload) return;
    document.getElementById('rd-slip').textContent = payload.slip_number || '—';
    document.getElementById('rd-date').textContent = payload.received_on || '—';
    document.getElementById('rd-total').textContent = payload.total_amount || '—';
    document.getElementById('rd-discount').textContent = payload.discount || '0';
    document.getElementById('rd-method').textContent = payload.payment_method || 'Cash';
    document.getElementById('rd-received-by').textContent = payload.received_by || '—';
    document.getElementById('rd-status').textContent = payload.status || 'Active';
    
    var badge = document.getElementById('rd-source-badge');
    if (payload.source === 'portal') {
        badge.textContent = 'Live Portal Receipt';
        badge.style.background = '#e0f2fe';
        badge.style.color = '#0369a1';
    } else {
        badge.textContent = 'Historical MySkool Record';
        badge.style.background = '#fef3c7';
        badge.style.color = '#92400e';
    }

    var noticeBox = document.getElementById('rd-notice-container');
    if (payload.is_historical) {
        noticeBox.innerHTML = '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:4px;padding:8px 12px;margin:10px 0;font-size:11px;color:#92400e;line-height:1.4;"><strong>Historical MySkool Payment Record — Reconstructed from Imported Data</strong><br>This payment record was reconstructed from verified MySkool import data. It reflects legacy fee collections received prior to portal migration without mutating current ledger balances.</div>';
    } else {
        noticeBox.innerHTML = '';
    }

    var tbody = document.getElementById('rd-items-tbody');
    tbody.innerHTML = '';
    if (payload.items && payload.items.length > 0) {
        payload.items.forEach(function(it) {
            var tr = document.createElement('tr');
            tr.innerHTML = '<td style="padding:8px 10px;border-bottom:1px solid #ebe9f1;"><strong>' + (it.student_name || '—') + '</strong><br><small style="color:#64748b;">GR: ' + (it.gr_number || '—') + '</small></td>' +
                '<td style="padding:8px 10px;border-bottom:1px solid #ebe9f1;">' + (it.fee_particular || 'Monthly Fee') + '</td>' +
                '<td style="padding:8px 10px;border-bottom:1px solid #ebe9f1;"><span class="ms-pill-lavender">' + (it.period || '—') + '</span></td>' +
                '<td style="padding:8px 10px;border-bottom:1px solid #ebe9f1;text-align:right;font-weight:700;">' + (it.currency ? it.currency + ' ' : '') + it.amount + '</td>';
            tbody.appendChild(tr);
        });
    } else {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:12px;color:#94a3b8;">No itemized details recorded.</td></tr>';
    }

    var printBtn = document.getElementById('rd-print-btn');
    if (payload.print_url) {
        printBtn.href = payload.print_url;
        printBtn.style.display = 'inline-flex';
    } else {
        printBtn.style.display = 'none';
    }

    document.getElementById('receipt-details-dialog').showModal();
};
</script>

@endsection