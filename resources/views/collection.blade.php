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
        $firstStudent = (object) [
            'id' => $matchedStudent->id,
            'gr_number' => $matchedStudent->gr_number,
            'student_name' => $matchedStudent->name,
            'class_name' => $matchedStudent->grade_label ?: ($matchedInvoiceItem?->class_name ?? ($firstInvoiceItem?->class_name ?? '')),
        ];
    } else {
        $fallbackFamilyStudent = (isset($familyStudents) && $familyStudents instanceof \Illuminate\Support\Collection)
            ? $familyStudents->first()
            : ($family ? DB::table('students')->where('family_id', $family->id)->orderBy('id')->first() : null);
        if ($firstInvoiceItem) {
            $itemStudent = (isset($familyStudents) && $familyStudents instanceof \Illuminate\Support\Collection)
                ? $familyStudents->first(fn($st) => (int) $st->id === (int) ($firstInvoiceItem->student_id ?? 0))
                : null;
            $firstStudent = (object) [
                'id' => $firstInvoiceItem->student_id ?? ($fallbackFamilyStudent->id ?? null),
                'gr_number' => $firstInvoiceItem->gr_number ?? ($fallbackFamilyStudent->gr_number ?? ''),
                'student_name' => $firstInvoiceItem->student_name ?? ($fallbackFamilyStudent->name ?? ''),
                'class_name' => $itemStudent?->grade_label ?: ($firstInvoiceItem->class_name ?: ($fallbackFamilyStudent->grade_label ?? '')),
            ];
        } else {
            $firstStudent = $fallbackFamilyStudent ? (object) [
                'id' => $fallbackFamilyStudent->id,
                'gr_number' => $fallbackFamilyStudent->gr_number,
                'student_name' => $fallbackFamilyStudent->name,
                'class_name' => $fallbackFamilyStudent->grade_label ?? '',
            ] : null;
        }
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
                                            <th style="padding: 4px 6px; text-align: center;">Status</th>
                                            <th style="padding: 4px 6px; text-align: right;">Action</th>
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
                                    {{ $item->class_name ?: '—' }}
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
                                    @if((int)$item->paid_minor > 0)
                                        <div style="margin-top: 3px;">
                                            <span class="ms-pill-lavender" style="color: #0369a1; background: #e0f2fe; font-size: 10px; font-weight: 600;" title="Paid: {{ $item->currency }} {{ number_format($item->paid_minor / 100, 2) }} of {{ $item->currency }} {{ number_format($item->net_minor / 100, 2) }}">
                                                Paid: {{ $item->currency }} {{ number_format($item->paid_minor / 100, 0) }} / {{ number_format($item->net_minor / 100, 0) }}
                                            </span>
                                            @if(!empty($item->historical_settlement) && ($item->historical_settlement->guard_class ?? '') === 'CONCESSION_REVIEW')
                                                <div style="font-size: 9px; color: #b45309; background: #fef3c7; padding: 1px 5px; border-radius: 4px; margin-top: 2px; display: inline-block; font-weight: 600;" title="{{ $item->historical_settlement->myskool_slip_numbers }} — Remaining {{ $item->currency }} {{ number_format($balanceMinor / 100, 2) }} held for concession review">
                                                    Concession Review ({{ $item->currency }} {{ $balanceFmt }})
                                                </div>
                                            @endif
                                        </div>
                                    @endif
                                    @if(!empty($item->currency_conflict_hold))
                                        <div style="margin-top: 3px;">
                                            <span style="font-size: 9px; color: #b91c1c; background: #fee2e2; padding: 2px 6px; border-radius: 4px; display: inline-block; font-weight: 600;" title="Held for manual review: MySkool Slip #{{ $item->currency_conflict_hold->slip_number }} recorded in {{ $item->currency_conflict_hold->currency }} {{ number_format($item->currency_conflict_hold->amount_minor / 100, 2) }} against {{ $item->currency }} {{ number_format($item->net_minor / 100, 2) }} obligation">
                                                ⚠ Currency Review (Slip #{{ $item->currency_conflict_hold->slip_number }}: {{ $item->currency_conflict_hold->currency }} {{ number_format($item->currency_conflict_hold->amount_minor / 100, 0) }})
                                            </span>
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
                                <tr style="{{ $receipt->is_reversed ? 'opacity: 0.65; background: #fff5f5;' : (($receipt->correction_status ?? '') === 'partially_reversed' ? 'background: #fffbeb;' : '') }}">
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
                                            @elseif(!empty($receipt->correction_badge))
                                                @php
                                                    $cStatus = (string) ($receipt->correction_status ?? '');
                                                    $badgeStyle = match ($cStatus) {
                                                        'partially_reversed' => 'background: #fef3c7; color: #92400e; border: 1px solid #fde68a;',
                                                        'disputed' => 'background: #fee2e2; color: #991b1b; border: 1px solid #fecaca;',
                                                        'corrected' => 'background: #e0e7ff; color: #3730a3; border: 1px solid #c7d2fe;',
                                                        'under_review' => 'background: #ffedd5; color: #9a3412; border: 1px solid #fed7aa;',
                                                        'reconciled' => 'background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;',
                                                        default => 'background: #f1f5f9; color: #475569;',
                                                    };
                                                @endphp
                                                <span class="ms-source-badge" style="{{ $badgeStyle }}">{{ strtoupper($receipt->correction_badge) }}</span>
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
                                        @if((int) ($receipt->reversed_amount_minor ?? 0) > 0)
                                            <div style="font-size: 10px; color: #b45309; line-height: 1.2;">
                                                Orig: {{ $receipt->currency }} {{ number_format((float) ($receipt->original_amount ?? $receipt->amount), 2) }}
                                                &bull; Rev: {{ number_format((float) ($receipt->reversed_amount ?? 0), 2) }}
                                            </div>
                                        @endif
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

            {{-- MYSKOOL DISPUTES SECTION (Task 3) --}}
            <div class="myskool-card" id="myskool-disputes-section" style="margin-top: 16px; border-left: 4px solid #f59e0b;">
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; border-bottom: 1px solid #ebe9f1; background: #fffbeb;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <strong style="color: #92400e; font-size: 13px;">Disputes (Historical MySkool Payment &amp; Fee Review Cases)</strong>
                        <span style="font-size: 10px; background: #fef3c7; color: #92400e; border: 1px solid #fde68a; padding: 2px 8px; border-radius: 12px; font-weight: 700;">
                            {{ ($myskoolDisputes ?? collect())->count() }} {{ \Illuminate\Support\Str::plural('Case', ($myskoolDisputes ?? collect())->count()) }}
                        </span>
                    </div>
                    <span style="font-size: 11px; color: #b45309;">Submitting or approving a dispute preserves original records and makes no automatic balance changes</span>
                </div>
                <div style="overflow-x: auto; padding: 10px 14px;">
                    <table class="myskool-table" style="font-size: 11px;">
                        <thead>
                            <tr style="background: #f8fafc;">
                                <th>CASE #</th>
                                <th>STUDENT</th>
                                <th>FEE TYPE &amp; MONTH</th>
                                <th>ORIGINAL RECORD</th>
                                <th>AMOUNT</th>
                                <th>REASON</th>
                                <th>STATUS</th>
                                @if(in_array(auth()->user()?->role, ['super_admin', 'finance_admin', 'admin'], true))
                                    <th style="text-align: right;">FINANCE ADMIN ACTIONS</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody id="myskool-disputes-tbody">
                            @forelse(($myskoolDisputes ?? collect()) as $disp)
                                @php
                                    $dStatus = strtolower((string) ($disp->status ?? 'open'));
                                    $dBadgeStyle = match ($dStatus) {
                                        'approved' => 'background:#dcfce7;color:#166534;border:1px solid #bbf7d0;',
                                        'rejected' => 'background:#fee2e2;color:#991b1b;border:1px solid #fecaca;',
                                        'resolved' => 'background:#e0e7ff;color:#3730a3;border:1px solid #c7d2fe;',
                                        'under_review' => 'background:#ffedd5;color:#9a3412;border:1px solid #fed7aa;',
                                        default => 'background:#fef3c7;color:#92400e;border:1px solid #fde68a;',
                                    };
                                @endphp
                                <tr data-dispute-id="{{ $disp->id ?? '' }}">
                                    <td><b>{{ $disp->dispute_code ?? '' }}</b></td>
                                    <td>
                                        <span style="color: var(--ms-purple); font-weight: 600;">{{ ($disp->student_name ?? '') ?: '—' }}</span>
                                        <div style="font-size: 10px; color: #64748b;">GR: {{ ($disp->student_gr ?? '') ?: '—' }}</div>
                                    </td>
                                    <td>
                                        <strong>{{ ($disp->fee_type ?? '') ?: 'Monthly Fee' }}</strong>
                                        <div style="font-size: 10px; color: #64748b;">{{ ($disp->billing_month ?? '') ?: '—' }}</div>
                                    </td>
                                    <td>{{ ($disp->original_record_label ?? null) ?: (($disp->original_record_ref ?? null) ?: (!empty($disp->slip_number) ? 'Slip #' . $disp->slip_number : '—')) }}</td>
                                    <td><b>{{ $disp->currency ?? '' }} {{ number_format(((int) ($disp->amount_minor ?? 0)) / 100, 2) }}</b></td>
                                    <td style="max-width: 240px;">
                                        <div>{{ $disp->reason ?? '' }}</div>
                                        @if(!empty($disp->review_notes))
                                            <div style="font-size: 10px; color: #475569; margin-top: 2px;"><em>Note: {{ $disp->review_notes }}</em></div>
                                        @endif
                                    </td>
                                    <td>
                                        <span style="display:inline-block;padding:2px 7px;border-radius:10px;font-size:10px;font-weight:700;{{ $dBadgeStyle }}">
                                            {{ strtoupper(str_replace('_', ' ', $dStatus)) }}
                                        </span>
                                    </td>
                                    @if(in_array(auth()->user()?->role, ['super_admin', 'finance_admin', 'admin'], true))
                                        <td style="text-align: right; white-space: nowrap;">
                                            <div style="display: inline-flex; gap: 4px; flex-wrap: wrap; justify-content: flex-end;">
                                                <button type="button" onclick="updateMySkoolDisputeStatus({{ (int) ($disp->id ?? 0) }}, 'under_review')" style="background:#ffedd5;color:#9a3412;border:1px solid #fed7aa;border-radius:4px;padding:2px 6px;font-size:10px;font-weight:600;cursor:pointer;">Review</button>
                                                <button type="button" onclick="updateMySkoolDisputeStatus({{ (int) ($disp->id ?? 0) }}, 'approved')" style="background:#dcfce7;color:#166534;border:1px solid #bbf7d0;border-radius:4px;padding:2px 6px;font-size:10px;font-weight:600;cursor:pointer;">Approve</button>
                                                <button type="button" onclick="updateMySkoolDisputeStatus({{ (int) ($disp->id ?? 0) }}, 'rejected')" style="background:#fee2e2;color:#991b1b;border:1px solid #fecaca;border-radius:4px;padding:2px 6px;font-size:10px;font-weight:600;cursor:pointer;">Reject</button>
                                                <button type="button" onclick="updateMySkoolDisputeStatus({{ (int) ($disp->id ?? 0) }}, 'resolved')" style="background:#e0e7ff;color:#3730a3;border:1px solid #c7d2fe;border-radius:4px;padding:2px 6px;font-size:10px;font-weight:600;cursor:pointer;">Resolve</button>
                                            </div>
                                        </td>
                                    @endif
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 16px; color: #94a3b8;">
                                        No MySkool dispute cases recorded for this family.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
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

{{-- SAFE VOIDED FEE RESTORATION DRAWER --}}
<div class="myskool-drawer-overlay" id="myskoolRestorationDrawer" hidden>
    <div class="myskool-drawer" style="max-width: 440px;">
        <div class="myskool-drawer-header">
            <div class="myskool-drawer-title" id="restorationHeading">Safe Fee Restoration</div>
            <button type="button" class="myskool-drawer-close" id="restorationCloseBtn">&times;</button>
        </div>

        <form method="post" id="form-restore-voided-fee" action="{{ route('challan.restore_voided_fee') }}">
            @csrf
            <input type="hidden" name="invoice_item_id" id="restore_item_id">

            <div id="restoration_loading_spinner" style="display: none; padding: 25px 10px; text-align: center; color: #64748b; font-size: 12px;">
                Checking obligation forensics and historical evidence...
            </div>

            <div id="restoration_content_block">
                {{-- Forensic summary box --}}
                <div id="restore_summary_box" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; margin-bottom: 12px; font-size: 11px;">
                    <div style="font-weight: 700; color: #334155; margin-bottom: 4px;" id="restore_student_info">Student: GR 0000</div>
                    <div style="color: #64748b;" id="restore_invoice_info">Parent Invoice: SCH-...</div>
                    <div style="color: #64748b;" id="restore_period_info">Period: ...</div>
                    <div style="margin-top: 6px; padding-top: 6px; border-top: 1px dashed #cbd5e1; display: flex; justify-content: space-between;">
                        <span>Original (Voided):</span>
                        <strong id="restore_original_net" style="color: #dc2626;">0.00</strong>
                    </div>
                    <div style="margin-top: 4px; display: flex; justify-content: space-between;">
                        <span>Replacement (Current Approved Settings):</span>
                        <strong id="restore_current_net" style="color: #15803d;">0.00</strong>
                    </div>
                    <div id="restore_recreate_status_row" style="margin-top: 4px; font-size: 10px; color: #475569;"></div>
                </div>

                {{-- Historical settlement badge / notice --}}
                <div id="restore_settlement_box" style="display: none; background: #fdf4ff; border: 1px solid #f0abfc; border-radius: 6px; padding: 10px; margin-bottom: 12px; font-size: 11px; color: #86198f;">
                    <div style="font-weight: 700; display: flex; align-items: center; gap: 6px;">
                        <span>🛡️</span>
                        <span>Verified Historical MySkool Settlement</span>
                    </div>
                    <div style="margin-top: 4px; font-size: 10px;" id="restore_settlement_details"></div>
                </div>

                <div class="myskool-drawer-field">
                    <label>Action Classification</label>
                    <div id="restore_safety_badge" style="padding: 6px 10px; border-radius: 4px; font-size: 11px; font-weight: 600;"></div>
                    <div id="restore_action_desc" style="font-size: 10px; color: #64748b; margin-top: 4px;"></div>
                </div>

                <div class="myskool-drawer-field">
                    <label>Administrative Justification / Reason *</label>
                    <textarea class="myskool-input" id="restore_reason" name="reason" rows="3" placeholder="Enter mandatory reason for recreating or restoring this voided obligation" style="height: 70px; resize: vertical;" required></textarea>
                </div>

                <button type="button" class="btn-ms-save" id="btn-submit-recreate-current" style="background: #28c76f; margin-bottom: 8px; width: 100%;">
                    Recreate Using Current Settings
                </button>
                <div id="recreate_current_hint" style="font-size: 10px; color: #15803d; margin-bottom: 10px; text-align: center;">
                    Creates a new invoice obligation using current approved student currency &amp; fee while keeping the original voided invoice for audit.
                </div>

                <button type="submit" class="btn-ms-save" id="btn-submit-restoration" style="background: #64748b; width: 100%;">
                    Restore Original (Historical Values)
                </button>
                <div style="font-size: 10px; color: #64748b; margin-top: 4px; text-align: center;">
                    Restores the original historical invoice currency &amp; amount in place.
                </div>
            </div>
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
window.IQRA_IS_ADMIN = {{ in_array(auth()->user()?->role, ['super_admin', 'finance_admin', 'admin'], true) ? 'true' : 'false' }};
window.IQRA_CSRF_TOKEN = '{{ csrf_token() }}';
window.IQRA_FAMILY_ID = {{ $family ? $family->id : 'null' }};
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

  let previewDebounceTimer = null;

  async function updateCreateFeePreview() {
    if (!previewTbody) return;

    if (previewDebounceTimer) {
      clearTimeout(previewDebounceTimer);
    }

    previewDebounceTimer = setTimeout(async () => {
      // Get selected students
      const activeStudentChips = Array.from(document.querySelectorAll('#student-selector-chips .ms-chip.active'));
      const allStudents = window.IQRA_FAMILY_STUDENTS || [];
      let studentIds = [];

      if (activeStudentChips.length === 0 || activeStudentChips.some(c => c.dataset.studentId === 'all')) {
        studentIds = allStudents.map(s => s.id);
      } else {
        studentIds = activeStudentChips.map(c => parseInt(c.dataset.studentId));
      }

      // Get selected fee types
      const activeTypeChips = Array.from(document.querySelectorAll('#fee-types-selector-chips .ms-chip.active'));
      const feeTypeIds = activeTypeChips.map(c => parseInt(c.dataset.feeTypeId));

      // Get selected months
      const activeMonthChips = Array.from(document.querySelectorAll('#months-selector-chips .ms-chip.active'));
      const months = activeMonthChips.map(c => parseInt(c.dataset.monthVal));
      const yearSelect = document.getElementById('cf-year-select');
      const year = parseInt(yearSelect?.value || new Date().getFullYear());

      const familyId = window.IQRA_FAMILY_ID;
      if (!familyId || feeTypeIds.length === 0 || months.length === 0) {
        previewTbody.innerHTML = '<tr><td colspan="6" style="padding: 10px; text-align: center; color: #94a3b8;">Select students, fee types, and billing months to preview obligations</td></tr>';
        if (btnSubmitCreateFee) btnSubmitCreateFee.disabled = true;
        return;
      }

      previewTbody.innerHTML = '<tr><td colspan="6" style="padding: 12px; text-align: center; color: #64748b;">Evaluating billing eligibility & historical protections...</td></tr>';

      try {
        const response = await fetch('/challan/preview-fee', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': window.IQRA_CSRF_TOKEN || document.querySelector('meta[name="csrf-token"]')?.content
          },
          body: JSON.stringify({
            family_id: familyId,
            student_ids: studentIds,
            fee_type_ids: feeTypeIds,
            months: months,
            year: year
          })
        });

        if (!response.ok) {
          throw new Error('Eligibility evaluation failed');
        }

        const data = await response.json();
        previewTbody.innerHTML = '';

        if (!data.items || data.items.length === 0) {
          previewTbody.innerHTML = '<tr><td colspan="6" style="padding: 10px; text-align: center; color: #94a3b8;">No fee combinations found</td></tr>';
          if (btnSubmitCreateFee) btnSubmitCreateFee.disabled = true;
          return;
        }

        let recreatableCount = 0;

        data.items.forEach(item => {
          const tr = document.createElement('tr');
          let statusBadge = '';
          let rowStyle = '';
          let rateHtml = '';
          let actionHtml = '';

          const rateStr = `${item.currency} ${(item.amount_minor / 100).toFixed(2)}`;

          if (item.status === 'Eligible for creation') {
            statusBadge = `<span class="badge" style="background: #eafbf1; color: #28c76f; font-size: 10px; padding: 2px 6px; border-radius: 4px; font-weight: 600;">Eligible for creation</span>`;
            rateHtml = `<strong style="color: #28c76f;">${rateStr}</strong>`;
            actionHtml = `<span style="color: #94a3b8; font-size: 10px;">Ready to create</span>`;
          } else {
            rowStyle = 'background: #fbfbfb; color: #64748b;';
            rateHtml = `<span style="text-decoration: line-through; color: #94a3b8;">${rateStr}</span>`;

            if (item.status === 'Already invoiced') {
              statusBadge = `<span class="badge" style="background: #e0f2fe; color: #0284c7; font-size: 10px; padding: 2px 6px; border-radius: 4px; font-weight: 600;">Already invoiced</span>`;
              actionHtml = `<span style="font-size: 10px; color: #0284c7;">${item.existing_invoice_number || 'Billed'}</span>`;
            } else if (item.status === 'Previously voided') {
              const origLabel = item.original_voided_summary || `Original: ${item.original_voided_formatted || rateStr} (Voided)`;
              const replLabel = item.replacement_summary || `Replacement: ${item.replacement_formatted || rateStr} (Current approved settings)`;
              rateHtml = `
                <div style="font-size: 10px; color: #dc2626; text-decoration: line-through;">${origLabel}</div>
                <div style="font-size: 10px; color: #15803d; font-weight: 700;">${replLabel}</div>
              `;
              if (item.can_recreate_current) {
                recreatableCount++;
                statusBadge = `
                  <span class="badge" style="background: #fef3c7; color: #d97706; font-size: 10px; padding: 2px 6px; border-radius: 4px; font-weight: 600;">Previously voided</span>
                  <div style="font-size: 9px; color: #15803d; font-weight: 600; margin-top: 2px;">Recreation Permitted</div>
                `;
              } else {
                statusBadge = `
                  <span class="badge" style="background: #fef3c7; color: #d97706; font-size: 10px; padding: 2px 6px; border-radius: 4px; font-weight: 600;">Previously voided</span>
                  <div style="font-size: 9px; color: #dc2626; font-weight: 600; margin-top: 2px;">Recreation Blocked</div>
                `;
              }
              if (window.IQRA_IS_ADMIN && item.voided_item_id) {
                const recreateBtn = item.can_recreate_current
                  ? `<button type="button" class="btn-recreate-void-current" data-item-id="${item.voided_item_id}" style="background: #28c76f; color: #fff; border: none; border-radius: 3px; padding: 3px 8px; font-size: 10px; font-weight: 600; cursor: pointer; margin-bottom: 3px; display: inline-block;">Recreate Using Current Settings</button><br>`
                  : '';
                actionHtml = `${recreateBtn}<button type="button" class="btn-restore-void" data-item-id="${item.voided_item_id}" style="background: #f59e0b; color: #fff; border: none; border-radius: 3px; padding: 2px 8px; font-size: 10px; cursor: pointer; display: inline-block;">Inspect &amp; Restore</button>`;
              } else {
                actionHtml = `<span style="font-size: 10px; color: #d97706;">Voided</span>`;
              }
            } else if (item.status === 'Historical MySkool payment exists') {
              statusBadge = `<span class="badge" style="background: #f3e8ff; color: #9333ea; font-size: 10px; padding: 2px 6px; border-radius: 4px; font-weight: 600;">Historical payment exists</span>`;
              actionHtml = `<span style="font-size: 10px; color: #9333ea;">Paid in MySkool</span>`;
            } else if (item.status === 'Historical opening balance exists') {
              statusBadge = `<span class="badge" style="background: #f0fdf4; color: #16a34a; font-size: 10px; padding: 2px 6px; border-radius: 4px; font-weight: 600;">Historical opening balance</span>`;
              actionHtml = `<span style="font-size: 10px; color: #16a34a;">Opening Balance</span>`;
            } else if (item.status === 'Requires restoration review') {
              if (item.original_voided_summary && item.replacement_summary) {
                rateHtml = `
                  <div style="font-size: 10px; color: #dc2626; text-decoration: line-through;">${item.original_voided_summary}</div>
                  <div style="font-size: 10px; color: #64748b;">${item.replacement_summary}</div>
                `;
              }
              statusBadge = `<span class="badge" style="background: #ffedd5; color: #ea580c; font-size: 10px; padding: 2px 6px; border-radius: 4px; font-weight: 600;">Restoration review</span>`;
              if (window.IQRA_IS_ADMIN && item.voided_item_id) {
                actionHtml = `<button type="button" class="btn-restore-void" data-item-id="${item.voided_item_id}" style="background: #ea580c; color: #fff; border: none; border-radius: 3px; padding: 2px 8px; font-size: 10px; cursor: pointer;">Inspect &amp; Restore</button>`;
              } else {
                actionHtml = `<span style="font-size: 10px; color: #ea580c;">Review Needed</span>`;
              }
            } else {
              statusBadge = `<span class="badge" style="background: #fee2e2; color: #dc2626; font-size: 10px; padding: 2px 6px; border-radius: 4px; font-weight: 600;">Financial conflict</span>`;
              actionHtml = `<span style="font-size: 10px; color: #dc2626;">Blocked</span>`;
            }
          }

          tr.style.cssText = rowStyle + '; border-bottom: 1px solid #f1f5f9;';
          tr.innerHTML = `
            <td style="padding: 4px 6px;"><strong>${item.student_name}</strong><br><span style="color:#94a3b8;font-size:9px;">GR: ${item.student_gr} (${item.grade_label})</span></td>
            <td style="padding: 4px 6px;"><strong>${item.fee_type_name}</strong><br><span style="color:#64748b;font-size:9px;">${item.rate_source}</span></td>
            <td style="padding: 4px 6px;"><span class="ms-pill-lavender" style="font-size:9px;">${item.period_label}</span></td>
            <td style="padding: 4px 6px; text-align: right;">${rateHtml}</td>
            <td style="padding: 4px 6px; text-align: center;">${statusBadge}<br><span style="font-size:8px; color:#64748b;" title="${item.reason}">${item.reason}</span></td>
            <td style="padding: 4px 6px; text-align: right;">${actionHtml}</td>
          `;
          previewTbody.appendChild(tr);
        });

        // Attach direct recreate-using-current-settings button handlers in preview
        document.querySelectorAll('.btn-recreate-void-current').forEach(btn => {
          btn.addEventListener('click', async function(e) {
            e.preventDefault();
            e.stopPropagation();
            const itemId = this.dataset.itemId;
            await executeRecreateUsingCurrentSettings(itemId, 'Recreated voided fee obligation using current approved settings from Create Fee preview.', this);
          });
        });

        // Attach restoration button handlers
        document.querySelectorAll('.btn-restore-void').forEach(btn => {
          btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const itemId = this.dataset.itemId;
            openRestorationDrawer(itemId);
          });
        });

        const summary = data.summary || {};
        if (previewCount) {
          previewCount.textContent = `${summary.total_eligible || 0} charge(s) planned, ${recreatableCount} recreatable (${summary.total_blocked || 0} blocked/voided)`;
        }

        if ((summary.total_eligible || 0) === 0 && recreatableCount === 0) {
          if (previewWarning) {
            previewWarning.style.display = 'block';
            previewWarning.innerHTML = `<strong>No eligible fees to create.</strong> Selected obligations are already invoiced, voided, or historically settled.`;
          }
          if (btnSubmitCreateFee) {
            btnSubmitCreateFee.disabled = true;
            btnSubmitCreateFee.style.opacity = '0.5';
            btnSubmitCreateFee.style.cursor = 'not-allowed';
          }
        } else {
          if (previewWarning) {
            if ((summary.total_eligible || 0) === 0 && recreatableCount > 0) {
              previewWarning.style.display = 'block';
              previewWarning.innerHTML = `<strong>Previously voided obligation(s) eligible for recreation.</strong> Click <em>Recreate Using Current Settings</em> or <em>Save</em> to create a new obligation using current approved student settings.`;
            } else {
              previewWarning.style.display = 'none';
            }
          }
          if (btnSubmitCreateFee) {
            btnSubmitCreateFee.disabled = false;
            btnSubmitCreateFee.style.opacity = '1';
            btnSubmitCreateFee.style.cursor = 'pointer';
          }
        }
      } catch (err) {
        console.error('Error fetching preview:', err);
        previewTbody.innerHTML = '<tr><td colspan="6" style="padding: 10px; text-align: center; color: #dc2626;">Error calculating fee eligibility. Check network connection.</td></tr>';
      }
    }, 150);
  }

  // RESTORATION DRAWER HANDLERS
  const restorationDrawer = document.getElementById('myskoolRestorationDrawer');
  const restorationCloseBtn = document.getElementById('restorationCloseBtn');
  const restorationForm = document.getElementById('form-restore-voided-fee');
  const restoreItemIdInput = document.getElementById('restore_item_id');
  const restorationSpinner = document.getElementById('restoration_loading_spinner');
  const restorationContent = document.getElementById('restoration_content_block');
  const btnSubmitRestoration = document.getElementById('btn-submit-restoration');
  const btnSubmitRecreateCurrent = document.getElementById('btn-submit-recreate-current');

  async function executeRecreateUsingCurrentSettings(itemId, reasonText, triggerBtn) {
    if (!itemId) return;
    const origText = triggerBtn ? triggerBtn.textContent : '';
    if (triggerBtn) {
      triggerBtn.disabled = true;
      triggerBtn.textContent = 'Recreating...';
    }
    try {
      const resp = await fetch('/challan/recreate-voided-fee', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': window.IQRA_CSRF_TOKEN || document.querySelector('meta[name="csrf-token"]')?.content
        },
        body: JSON.stringify({
          invoice_item_id: Number(itemId),
          reason: reasonText || 'Recreated voided fee obligation using current approved settings.'
        })
      });
      const data = await resp.json();
      if (resp.ok && data.success) {
        alertNotice(data.message || 'Fee recreated using current approved settings.');
        if (restorationDrawer) restorationDrawer.hidden = true;
        setTimeout(() => location.reload(), 700);
      } else {
        const errMsg = data.message || (data.errors && Object.values(data.errors).flat()[0]) || 'Recreation failed';
        alertNotice(errMsg, 'error');
        if (triggerBtn) {
          triggerBtn.disabled = false;
          triggerBtn.textContent = origText || 'Recreate Using Current Settings';
        }
      }
    } catch (err) {
      alertNotice('Network error while recreating fee obligation.', 'error');
      if (triggerBtn) {
        triggerBtn.disabled = false;
        triggerBtn.textContent = origText || 'Recreate Using Current Settings';
      }
    }
  }

  if (restorationCloseBtn && restorationDrawer) {
    restorationCloseBtn.addEventListener('click', () => {
      restorationDrawer.hidden = true;
    });
  }

  async function openRestorationDrawer(itemId) {
    if (!restorationDrawer) return;
    restorationDrawer.hidden = false;
    if (restoreItemIdInput) restoreItemIdInput.value = itemId;
    if (restorationSpinner) restorationSpinner.style.display = 'block';
    if (restorationContent) restorationContent.style.display = 'none';

    try {
      const resp = await fetch(`/challan/inspect-voided-fee/${itemId}`, {
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      });
      const data = await resp.json();

      if (restorationSpinner) restorationSpinner.style.display = 'none';
      if (restorationContent) restorationContent.style.display = 'block';

      if (!data.exists) {
        alertNotice(data.error || 'Invoice item not found', 'error');
        restorationDrawer.hidden = true;
        return;
      }

      const item = data.item;
      const values = data.original_approved_values || {};
      const currentSettings = data.current_approved_settings || {};
      const proposed = data.proposed_restoration || {};
      const histEv = data.historical_evidence || {};

      document.getElementById('restore_student_info').textContent = `Student: ${item.student_name} (GR: ${item.gr_number})`;
      document.getElementById('restore_invoice_info').textContent = `Parent Invoice: ${item.invoice_number} (Status: ${item.invoice_status})`;
      document.getElementById('restore_period_info').textContent = `Particular: ${item.description} (${item.period_key || item.period_start})`;
      document.getElementById('restore_original_net').textContent = `${item.currency} ${values.net_formatted} (Voided)`;

      const restoreCurrentEl = document.getElementById('restore_current_net');
      if (restoreCurrentEl) {
        const currStr = currentSettings.currency || item.currency;
        const currFmt = currentSettings.net_formatted || values.net_formatted;
        restoreCurrentEl.textContent = `${currStr} ${currFmt}`;
      }

      const recreateStatusRow = document.getElementById('restore_recreate_status_row');
      if (recreateStatusRow) {
        if (data.is_safe_to_recreate_current) {
          recreateStatusRow.innerHTML = `<span style="color: #15803d; font-weight: 600;">✓ Recreation Using Current Settings Permitted</span>`;
        } else {
          recreateStatusRow.innerHTML = `<span style="color: #dc2626; font-weight: 600;">✕ Recreation Blocked: ${data.recreate_block_reason || data.error || 'Not eligible'}</span>`;
        }
      }

      if (btnSubmitRecreateCurrent) {
        btnSubmitRecreateCurrent.disabled = !data.is_safe_to_recreate_current;
        btnSubmitRecreateCurrent.style.opacity = data.is_safe_to_recreate_current ? '1' : '0.5';
        btnSubmitRecreateCurrent.textContent = currentSettings.currency && currentSettings.net_formatted
          ? `Recreate Using Current Settings (${currentSettings.currency} ${currentSettings.net_formatted})`
          : 'Recreate Using Current Settings';
      }

      const settlementBox = document.getElementById('restore_settlement_box');
      if (histEv.post_cutoff_paid_minor > 0 || proposed.settled_paid_minor > 0) {
        settlementBox.style.display = 'block';
        const paidFmt = (proposed.settled_paid_minor / 100).toFixed(2);
        document.getElementById('restore_settlement_details').textContent =
          `Verified historical payment of ${item.currency} ${paidFmt} (Slips: ${histEv.slips || 'Verified record'}). This fee will be restored directly with historical settlement applied atomically.`;
      } else {
        settlementBox.style.display = 'none';
      }

      const badge = document.getElementById('restore_safety_badge');
      badge.textContent = data.safety_status;
      if (data.is_safe_to_restore) {
        badge.style.background = '#eafbf1';
        badge.style.color = '#28c76f';
        if (btnSubmitRestoration) {
          btnSubmitRestoration.disabled = false;
          btnSubmitRestoration.textContent = `Restore Original (${item.currency} ${values.net_formatted})`;
        }
      } else {
        badge.style.background = '#fee2e2';
        badge.style.color = '#dc2626';
        if (btnSubmitRestoration) {
          btnSubmitRestoration.disabled = true;
          btnSubmitRestoration.textContent = 'Restore Original (Historical Values)';
        }
      }

      document.getElementById('restore_action_desc').textContent = data.action_description;
    } catch (e) {
      alertNotice('Failed to inspect voided fee forensics.', 'error');
      restorationDrawer.hidden = true;
    }
  }

  if (btnSubmitRecreateCurrent) {
    btnSubmitRecreateCurrent.addEventListener('click', async function(e) {
      e.preventDefault();
      const itemId = restoreItemIdInput ? restoreItemIdInput.value : null;
      const reasonInput = document.getElementById('restore_reason');
      const reasonText = (reasonInput && reasonInput.value ? reasonInput.value.trim() : '')
        || 'Recreated voided fee obligation using current approved student settings.';
      await executeRecreateUsingCurrentSettings(itemId, reasonText, btnSubmitRecreateCurrent);
    });
  }

  if (restorationForm) {
    restorationForm.addEventListener('submit', async function(e) {
      e.preventDefault();
      if (btnSubmitRestoration) {
        btnSubmitRestoration.disabled = true;
        btnSubmitRestoration.textContent = 'Restoring Original...';
      }

      const fd = new FormData(restorationForm);
      try {
        const resp = await fetch(restorationForm.action, {
          method: 'POST',
          body: fd,
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
          }
        });

        const data = await resp.json();
        if (resp.ok && data.success) {
          alertNotice(data.message || 'Fee successfully restored');
          restorationDrawer.hidden = true;
          setTimeout(() => location.reload(), 700);
        } else {
          alertNotice(data.message || 'Restoration failed', 'error');
          if (btnSubmitRestoration) {
            btnSubmitRestoration.disabled = false;
            btnSubmitRestoration.textContent = 'Restore Original (Historical Values)';
          }
        }
      } catch (err) {
        alertNotice('Network error during fee restoration', 'error');
        if (btnSubmitRestoration) {
          btnSubmitRestoration.disabled = false;
          btnSubmitRestoration.textContent = 'Restore Original (Historical Values)';
        }
      }
    });
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
<dialog id="receipt-details-dialog" style="border:none;border-radius:8px;box-shadow:0 12px 40px rgba(0,0,0,0.3);padding:0;max-width:840px;width:96%;margin:auto;font-family:inherit;">
 <div style="background:#7367f0;color:#fff;padding:16px 20px;display:flex;align-items:center;justify-content:space-between;border-top-left-radius:8px;border-top-right-radius:8px;">
  <strong style="font-size:16px;display:flex;align-items:center;gap:8px;">
    <span>Receipt Slip #<span id="rd-slip"></span></span>
    <span id="rd-source-badge" style="font-size:10px;padding:2px 8px;border-radius:12px;font-weight:700;"></span>
  </strong>
  <button type="button" onclick="document.getElementById('receipt-details-dialog').close()" style="background:none;border:none;color:#fff;font-size:24px;line-height:1;cursor:pointer;">&times;</button>
 </div>
 <div style="padding:20px;font-size:13px;color:#5e5873;max-height:84vh;overflow-y:auto;">
  
  {{-- META GRID --}}
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:10px;background:#f8f7fa;border:1px solid #ebe9f1;border-radius:6px;padding:12px;margin-bottom:14px;">
    <div><small style="color:#64748b;display:block;">Payment Date</small><strong id="rd-date">—</strong></div>
    <div><small style="color:#64748b;display:block;">Original Receipt Total</small><strong id="rd-total" style="color:#7367f0;">—</strong></div>
    <div><small style="color:#64748b;display:block;">Total Amount Reversed</small><strong id="rd-reversed-total" style="color:#b45309;">—</strong></div>
    <div><small style="color:#64748b;display:block;">Remaining Effective Paid</small><strong id="rd-effective-total" style="color:#166534;">—</strong></div>
    <div><small style="color:#64748b;display:block;">Discount</small><strong id="rd-discount">0</strong></div>
    <div><small style="color:#64748b;display:block;">Method</small><strong id="rd-method">Cash</strong></div>
    <div><small style="color:#64748b;display:block;">Received By</small><strong id="rd-received-by">—</strong></div>
    <div><small style="color:#64748b;display:block;">Status</small><strong id="rd-status">Active</strong></div>
  </div>

  <div id="rd-notice-container"></div>

  {{-- ITEMS TABLE --}}
  <div style="font-weight:600;margin:12px 0 6px;color:#334155;">Underlying Item Allocations:</div>
  <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:12px;border:1px solid #ebe9f1;border-radius:4px;overflow:hidden;">
      <thead>
        <tr style="background:#f1f5f9;color:#475569;text-align:left;">
          <th style="padding:8px 10px;">Student</th>
          <th style="padding:8px 10px;">Fee Particular</th>
          <th style="padding:8px 10px;">Month</th>
          <th style="padding:8px 10px;text-align:right;">Original Paid</th>
          <th style="padding:8px 10px;text-align:right;">Reversed</th>
          <th style="padding:8px 10px;text-align:right;">Effective Paid</th>
          <th style="padding:8px 10px;">Status</th>
          <th style="padding:8px 10px;text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody id="rd-items-tbody">
      </tbody>
    </table>
  </div>

  {{-- INDIVIDUAL PAYMENT RESTORATION CONFIRMATION PANEL (PHASE 3 TASK 2) --}}
  @if(in_array(auth()->user()?->role, ['super_admin', 'finance_admin', 'admin'], true))
  <div id="rd-restore-panel" style="display:none;margin-top:16px;background:#faf9ff;border:1px solid #c4b5fd;border-radius:8px;padding:16px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;padding-bottom:8px;border-bottom:1px solid #e2e8f0;">
      <div>
        <strong style="font-size:14px;color:#4b3f72;">Individual MySkool Payment Allocation Restoration</strong>
        <div style="font-size:11px;color:#64748b;">Reverses the selected payment credit back to outstanding dues. Does not issue a cash refund or delete historical records.</div>
      </div>
      <button type="button" onclick="closeMySkoolRestorePanel()" style="background:#f1f5f9;border:1px solid #cbd5e1;border-radius:4px;padding:3px 9px;font-size:11px;font-weight:600;color:#475569;cursor:pointer;">&times; Close Panel</button>
    </div>

    <div id="rdr-loading" style="display:none;padding:16px;text-align:center;color:#64748b;font-size:12px;">
      Inspecting ledger linkage and reversible allocation balance...
    </div>

    <div id="rdr-content" style="display:none;">
      {{-- Allocation Identity & Reversible Balance Grid --}}
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px;background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:10px 12px;margin-bottom:12px;font-size:11px;">
        <div><small style="color:#64748b;display:block;">Student Name &amp; GR</small><strong id="rdr-student">—</strong></div>
        <div><small style="color:#64748b;display:block;">Original MySkool Slip #</small><strong id="rdr-slip">—</strong></div>
        <div><small style="color:#64748b;display:block;">Fee Type</small><strong id="rdr-fee-type">—</strong></div>
        <div><small style="color:#64748b;display:block;">Billing Month</small><strong id="rdr-month">—</strong></div>
        <div><small style="color:#64748b;display:block;">Original Currency</small><strong id="rdr-currency">—</strong></div>
        <div><small style="color:#64748b;display:block;">Original Paid Amount</small><strong id="rdr-orig-paid" style="color:#7367f0;">—</strong></div>
        <div><small style="color:#64748b;display:block;">Amount Previously Reversed</small><strong id="rdr-prev-reversed" style="color:#b45309;">—</strong></div>
        <div><small style="color:#64748b;display:block;">Remaining Reversible Amount</small><strong id="rdr-rem-reversible" style="color:#166534;">—</strong></div>
      </div>

      {{-- Unreconciled / Blocked State Banner --}}
      <div id="rdr-reconcile-warning" style="display:none;background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;padding:12px;margin-bottom:12px;font-size:11px;color:#9a3412;">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">
          <strong id="rdr-reconcile-title" style="font-size:12px;">Requires Reconciliation</strong>
          <span id="rdr-reconcile-badge" style="background:#ffedd5;color:#9a3412;border:1px solid #fdba74;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;">REQUIRES RECONCILIATION</span>
        </div>
        <div id="rdr-reconcile-msg" style="margin-top:4px;line-height:1.45;">—</div>
        <div id="rdr-reconcile-actions" style="display:none;margin-top:10px;padding-top:8px;border-top:1px dashed #fdba74;">
          <div style="margin-bottom:6px;color:#7c2d12;font-weight:600;">To restore this payment safely, first reconcile its historical obligation and settlement lineage in the original receipt currency (creates 0.00 unpaid debt):</div>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <input type="text" id="rdr-reconcile-reason" class="myskool-input" placeholder="Enter reconciliation reason (min 5 chars)..." style="flex:1;min-width:220px;font-size:11px;">
            <button type="button" id="rdr-btn-reconcile" onclick="submitMySkoolLineageReconciliation()" style="background:#0284c7;color:#fff;border:none;border-radius:4px;padding:6px 12px;font-size:11px;font-weight:700;cursor:pointer;">
              Reconcile Lineage First
            </button>
          </div>
        </div>
      </div>

      {{-- Restoration Execution Form --}}
      <form id="rdr-form" onsubmit="submitMySkoolPaymentRestoration(event)" style="display:none;">
        @csrf
        <input type="hidden" id="rdr-col-id" name="qa_staged_collection_id">
        <input type="hidden" id="rdr-idempotency-key" name="idempotency_key">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:12px;">
          <div>
            <label style="display:block;font-weight:700;font-size:11px;color:#334155;margin-bottom:6px;">Restoration Mode *</label>
            <label style="display:flex;align-items:center;gap:6px;font-size:12px;margin-bottom:6px;cursor:pointer;">
              <input type="radio" name="restoration_mode" id="rdr-mode-full" value="full" checked onchange="updateMySkoolRestorePreview()">
              <span><strong>Full Restoration</strong> (Restore full remaining reversible amount)</span>
            </label>
            <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;">
              <input type="radio" name="restoration_mode" id="rdr-mode-partial" value="partial" onchange="updateMySkoolRestorePreview()">
              <span><strong>Partial Restoration</strong> (Restore chosen partial amount)</span>
            </label>
          </div>

          <div>
            <label style="display:block;font-weight:700;font-size:11px;color:#334155;margin-bottom:4px;">
              Amount to Restore (<span id="rdr-amount-curr">SAR</span>) *
            </label>
            <input type="number" id="rdr-amount" name="restore_amount" class="myskool-input" step="0.01" min="0.01" required oninput="updateMySkoolRestorePreview()" style="width:100%;font-weight:700;font-size:13px;">
            <small id="rdr-amount-help" style="display:block;color:#64748b;font-size:10px;margin-top:3px;">Must be greater than 0.00 and at most the remaining reversible allocation.</small>
          </div>
        </div>

        {{-- Live Financial Impact Preview --}}
        <div style="font-weight:700;font-size:11px;color:#334155;margin-bottom:5px;">Preview of Financial Impact:</div>
        <table style="width:100%;border-collapse:collapse;font-size:11px;border:1px solid #e2e8f0;background:#fff;margin-bottom:12px;">
          <thead>
            <tr style="background:#f1f5f9;color:#334155;text-align:left;">
              <th style="padding:6px 10px;border-bottom:1px solid #e2e8f0;">Accounting Dimension</th>
              <th style="padding:6px 10px;border-bottom:1px solid #e2e8f0;">Before Restoration</th>
              <th style="padding:6px 10px;border-bottom:1px solid #e2e8f0;">After Restoration</th>
            </tr>
          </thead>
          <tbody id="rdr-preview-tbody"></tbody>
        </table>

        <div style="margin-bottom:12px;">
          <label style="display:block;font-weight:700;font-size:11px;color:#334155;margin-bottom:4px;">Mandatory Restoration Reason *</label>
          <textarea id="rdr-reason" name="reason" class="myskool-input" rows="2" required minlength="5" placeholder="Enter mandatory restoration reason and verification reference (min 5 characters)..." style="width:100%;height:54px;resize:vertical;"></textarea>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:8px;">
          <button type="button" onclick="closeMySkoolRestorePanel()" style="background:#f1f5f9;border:1px solid #cbd5e1;padding:7px 14px;border-radius:4px;font-size:12px;font-weight:600;color:#475569;cursor:pointer;">
            Cancel
          </button>
          <button type="submit" id="rdr-btn-submit" style="background:#dc2626;color:#fff;border:none;padding:7px 16px;border-radius:4px;font-size:12px;font-weight:700;cursor:pointer;">
            Confirm &amp; Restore to Outstanding Dues
          </button>
        </div>
      </form>
    </div>
  </div>

  {{-- INDIVIDUAL PAYMENT / FEE DISPUTE PANEL (PHASE 3 TASK 3) --}}
  <div id="rd-dispute-panel" style="display:none;margin-top:16px;background:#fffdf5;border:1px solid #fde68a;border-radius:8px;padding:16px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;padding-bottom:8px;border-bottom:1px solid #fef3c7;">
      <div>
        <strong style="font-size:14px;color:#92400e;">Dispute Historical MySkool Fee / Payment Entry</strong>
        <div style="font-size:11px;color:#b45309;">Creates a tracked case in the Disputes section without altering ledger balances or deleting historical records.</div>
      </div>
      <button type="button" onclick="closeMySkoolDisputePanel()" style="background:#f1f5f9;border:1px solid #cbd5e1;border-radius:4px;padding:3px 9px;font-size:11px;font-weight:600;color:#475569;cursor:pointer;">&times; Close Panel</button>
    </div>

    <div id="rdd-loading" style="display:none;padding:16px;text-align:center;color:#64748b;font-size:12px;">
      Inspecting record details for dispute...
    </div>

    <div id="rdd-content" style="display:none;">
      {{-- Dispute Item Details Grid --}}
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px;background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:10px 12px;margin-bottom:12px;font-size:11px;">
        <div><small style="color:#64748b;display:block;">Student Name &amp; GR</small><strong id="rdd-student">—</strong></div>
        <div><small style="color:#64748b;display:block;">Record Reference</small><strong id="rdd-record">—</strong></div>
        <div><small style="color:#64748b;display:block;">Fee Type</small><strong id="rdd-fee-type">—</strong></div>
        <div><small style="color:#64748b;display:block;">Billing Month</small><strong id="rdd-month">—</strong></div>
        <div><small style="color:#64748b;display:block;">Currency &amp; Amount</small><strong id="rdd-amount" style="color:#92400e;">—</strong></div>
      </div>

      <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:10px 12px;margin-bottom:12px;font-size:11px;color:#92400e;line-height:1.45;">
        <strong>Safety Guarantee:</strong> Submitting a dispute preserves all original imported receipts, other students' fees, and historical records. It creates a formal case in the Disputes section for administrative review. Financial balances are not automatically modified.
      </div>

      {{-- Dispute Execution Form --}}
      <form id="rdd-form" onsubmit="submitMySkoolDispute(event)">
        @csrf
        <input type="hidden" id="rdd-col-id" name="qa_staged_collection_id">
        <input type="hidden" id="rdd-item-id" name="invoice_item_id">

        <div style="margin-bottom:12px;">
          <label style="display:block;font-weight:700;font-size:11px;color:#334155;margin-bottom:4px;">Mandatory Dispute Reason &amp; Explanation *</label>
          <textarea id="rdd-reason" name="reason" class="myskool-input" rows="3" required minlength="5" placeholder="Enter explanation for disputing this entry (min 5 characters)..." style="width:100%;height:65px;resize:vertical;"></textarea>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:8px;">
          <button type="button" onclick="closeMySkoolDisputePanel()" style="background:#f1f5f9;border:1px solid #cbd5e1;padding:7px 14px;border-radius:4px;font-size:12px;font-weight:600;color:#475569;cursor:pointer;">
            Cancel
          </button>
          <button type="submit" id="rdd-btn-submit" style="background:#d97706;color:#fff;border:none;padding:7px 16px;border-radius:4px;font-size:12px;font-weight:700;cursor:pointer;">
            Submit Dispute Case
          </button>
        </div>
      </form>
    </div>
  </div>
  @endif

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
(() => {
    let activeReceiptPayload = null;
    let currentRestoreInspection = null;

    function formatMinorMoney(minor) {
        const val = Math.max(0, Number(minor || 0)) / 100;
        return val.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function renderAllocationStatusBadge(status, label) {
        const st = String(status || 'paid').toLowerCase();
        const text = label || (st === 'partially_reversed' ? 'Partially Reversed' : (st === 'fully_reversed' || st === 'reversed' ? 'Fully Reversed' : (st === 'requires_reconciliation' ? 'Requires Reconciliation' : (st === 'requires_review' ? 'Requires Review' : 'Paid'))));
        let style = 'background:#dcfce7;color:#166534;border:1px solid #bbf7d0;';
        if (st === 'partially_reversed') {
            style = 'background:#fef3c7;color:#92400e;border:1px solid #fde68a;';
        } else if (st === 'fully_reversed' || st === 'reversed') {
            style = 'background:#fee2e2;color:#991b1b;border:1px solid #fecaca;';
        } else if (st === 'requires_reconciliation' || st === 'requires_review' || st === 'disputed' || st === 'under_review') {
            style = 'background:#ffedd5;color:#9a3412;border:1px solid #fed7aa;';
        }
        return '<span style="display:inline-block;padding:2px 7px;border-radius:10px;font-size:10px;font-weight:700;white-space:nowrap;' + style + '">' + text + '</span>';
    }

    window.showReceiptDetails = function(payload) {
        if (!payload) return;
        activeReceiptPayload = payload;
        if (typeof window.closeMySkoolRestorePanel === 'function') {
            window.closeMySkoolRestorePanel();
        }
        if (typeof window.closeMySkoolDisputePanel === 'function') {
            window.closeMySkoolDisputePanel();
        }
        document.getElementById('rd-slip').textContent = payload.slip_number || '—';
        document.getElementById('rd-date').textContent = payload.received_on || '—';
        document.getElementById('rd-total').textContent = payload.original_total_amount || payload.total_amount || '—';
        document.getElementById('rd-reversed-total').textContent = payload.total_reversed_amount || ((payload.currency ? payload.currency + ' ' : '') + '0.00');
        document.getElementById('rd-effective-total').textContent = payload.remaining_effective_amount || payload.total_amount || '—';
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
            noticeBox.innerHTML = '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:4px;padding:8px 12px;margin:10px 0;font-size:11px;color:#92400e;line-height:1.45;"><strong>Historical MySkool Payment Record — Individual Allocation Restoration Enabled</strong><br>Original imported MySkool source records are permanently preserved. Reconciled allocations credited in the ledger can be individually restored (in full or in part) to outstanding dues without affecting sibling allocations or issuing an automatic cash refund.</div>';
        } else {
            noticeBox.innerHTML = '';
        }

        var tbody = document.getElementById('rd-items-tbody');
        tbody.innerHTML = '';
        if (payload.items && payload.items.length > 0) {
            payload.items.forEach(function(it) {
                var tr = document.createElement('tr');
                var currPrefix = it.currency ? it.currency + ' ' : '';
                var origPaid = it.original_amount || it.amount || '0.00';
                var revAmt = it.reversed_amount || '0.00';
                var effPaid = it.effective_paid_amount !== undefined ? it.effective_paid_amount : (it.amount || '0.00');
                var remMinor = it.remaining_reversible_minor !== undefined ? Number(it.remaining_reversible_minor) : Number(it.amount_minor || 0);
                var statusBadge = renderAllocationStatusBadge(it.allocation_status, it.allocation_status_label);

                var actionHtml = '<span style="color:#94a3b8;font-size:11px;">—</span>';
                @if(in_array(auth()->user()?->role, ['super_admin', 'finance_admin', 'admin'], true))
                if (payload.is_historical && window.IQRA_IS_ADMIN && it.id) {
                    var disputeBtn = '<button type="button" class="btn-ms-disc js-myskool-dispute-btn" onclick="openMySkoolDisputePanel(' + Number(it.id) + ', ' + (it.invoice_item_id ? Number(it.invoice_item_id) : 'null') + ')" style="background:#fffbeb;color:#b45309;border:1px solid #fcd34d;border-radius:4px;padding:3px 8px;font-size:10px;font-weight:700;cursor:pointer;" title="Dispute this MySkool payment allocation">DISPUTE</button>';
                    var restoreBtn = '<button type="button" class="btn-ms-disc js-myskool-restore-btn" onclick="openMySkoolRestorePanel(' + Number(it.id) + ')" style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:4px;padding:3px 8px;font-size:10px;font-weight:700;cursor:pointer;" title="Restore this MySkool payment allocation">RESTORE</button>';
                    actionHtml = '<div style="display:flex;gap:4px;justify-content:flex-end;flex-wrap:wrap;">' + disputeBtn + ' ' + restoreBtn + '</div>';
                }
                @endif

                tr.innerHTML = '<td style="padding:8px 10px;border-bottom:1px solid #ebe9f1;"><strong>' + (it.student_name || '—') + '</strong><br><small style="color:#64748b;">GR: ' + (it.gr_number || '—') + '</small></td>' +
                    '<td style="padding:8px 10px;border-bottom:1px solid #ebe9f1;">' + (it.fee_particular || 'Monthly Fee') + '</td>' +
                    '<td style="padding:8px 10px;border-bottom:1px solid #ebe9f1;"><span class="ms-pill-lavender">' + (it.period || '—') + '</span></td>' +
                    '<td style="padding:8px 10px;border-bottom:1px solid #ebe9f1;text-align:right;font-weight:600;">' + currPrefix + origPaid + '</td>' +
                    '<td style="padding:8px 10px;border-bottom:1px solid #ebe9f1;text-align:right;color:#b45309;font-weight:600;">' + (Number(it.reversed_amount_minor || 0) > 0 ? currPrefix + revAmt : '—') + '</td>' +
                    '<td style="padding:8px 10px;border-bottom:1px solid #ebe9f1;text-align:right;font-weight:700;color:#166534;">' + currPrefix + effPaid + '</td>' +
                    '<td style="padding:8px 10px;border-bottom:1px solid #ebe9f1;">' + statusBadge + '</td>' +
                    '<td style="padding:8px 10px;border-bottom:1px solid #ebe9f1;text-align:right;">' + actionHtml + '</td>';
                tbody.appendChild(tr);
            });
        } else {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:12px;color:#94a3b8;">No itemized details recorded.</td></tr>';
        }

        var printBtn = document.getElementById('rd-print-btn');
        if (payload.print_url) {
            printBtn.href = payload.print_url;
            printBtn.style.display = 'inline-flex';
        } else {
            printBtn.style.display = 'none';
        }

        var dlg = document.getElementById('receipt-details-dialog');
        if (dlg && !dlg.open) {
            dlg.showModal();
        }
    };

    window.closeMySkoolRestorePanel = function() {
        const panel = document.getElementById('rd-restore-panel');
        if (panel) panel.style.display = 'none';
        currentRestoreInspection = null;
    };

    window.openMySkoolRestorePanel = async function(stagedCollectionId) {
        if (typeof window.closeMySkoolDisputePanel === 'function') {
            window.closeMySkoolDisputePanel();
        }
        var dlg = document.getElementById('receipt-details-dialog');
        if (dlg && !dlg.open) {
            dlg.showModal();
        }
        const panel = document.getElementById('rd-restore-panel');
        const loading = document.getElementById('rdr-loading');
        const content = document.getElementById('rdr-content');
        if (!panel) return;

        panel.style.display = 'block';
        loading.style.display = 'block';
        content.style.display = 'none';
        panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

        try {
            const resp = await fetch('/challan/inspect-myskool-payment/' + encodeURIComponent(stagedCollectionId), {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            const data = await resp.json();
            if (!resp.ok || !data.exists) {
                window.alertNotice(data.error || 'Unable to inspect MySkool allocation.', 'error');
                panel.style.display = 'none';
                return;
            }
            currentRestoreInspection = data;
            populateMySkoolRestorePanel(data);
            loading.style.display = 'none';
            content.style.display = 'block';
        } catch (err) {
            window.alertNotice('Network error while inspecting MySkool allocation.', 'error');
            panel.style.display = 'none';
        }
    };

    function populateMySkoolRestorePanel(data) {
        const curr = data.currency || 'SAR';
        document.getElementById('rdr-student').textContent = (data.student_name || '—') + ' (GR ' + (data.student_gr || '—') + ')';
        document.getElementById('rdr-slip').textContent = '#' + (data.slip_number || '—') + ' (Row #' + data.qa_staged_collection_id + ')';
        document.getElementById('rdr-fee-type').textContent = data.fee_type || data.fee_particular || 'Monthly Fee';
        document.getElementById('rdr-month').textContent = data.billing_month || data.period_key || '—';
        document.getElementById('rdr-currency').innerHTML = data.has_currency_conflict
            ? '<span style="color:#dc2626;font-weight:700;">' + curr + ' (Conflict vs ' + (data.student_expected_currency || '—') + ')</span>'
            : curr;
        document.getElementById('rdr-orig-paid').textContent = curr + ' ' + (data.original_paid_formatted || data.amount_formatted || '0.00');
        document.getElementById('rdr-prev-reversed').textContent = curr + ' ' + (data.previously_reversed_formatted || '0.00');
        document.getElementById('rdr-rem-reversible').textContent = curr + ' ' + (data.remaining_reversible_formatted || '0.00');

        const warnBox = document.getElementById('rdr-reconcile-warning');
        const warnTitle = document.getElementById('rdr-reconcile-title');
        const warnBadge = document.getElementById('rdr-reconcile-badge');
        const warnMsg = document.getElementById('rdr-reconcile-msg');
        const warnActions = document.getElementById('rdr-reconcile-actions');
        const formEl = document.getElementById('rdr-form');

        if (!data.is_credited_in_ledger || !data.is_safe_to_reverse || Number(data.remaining_reversible_minor || 0) <= 0) {
            warnBox.style.display = 'block';
            formEl.style.display = 'none';
            const isFullyRev = data.allocation_status === 'fully_reversed'
                || (Number(data.previously_reversed_minor || 0) >= Number(data.original_paid_minor || 0) && Number(data.original_paid_minor || 0) > 0);
            if (isFullyRev) {
                warnTitle.textContent = 'Allocation Already Fully Reversed';
                warnBadge.textContent = 'FULLY REVERSED';
                warnMsg.textContent = 'This historical payment allocation has already been fully restored to outstanding dues. Remaining reversible balance is ' + curr + ' 0.00.';
                warnActions.style.display = 'none';
            } else if (data.has_currency_conflict) {
                warnTitle.textContent = 'Requires Review — Currency Conflict Detected';
                warnBadge.textContent = 'REQUIRES REVIEW';
                warnMsg.textContent = data.safety_explanation || 'Historical receipt currency conflicts with student registration currency. Never mix or offset different currencies.';
                warnActions.style.display = 'none';
            } else {
                warnTitle.textContent = 'Requires Reconciliation — Historical Payment Not Yet Credited in Active Ledger';
                warnBadge.textContent = 'REQUIRES RECONCILIATION';
                warnMsg.textContent = data.safety_explanation || 'This historical payment has not been posted as a paid credit on an active ledger obligation. Restoration is blocked until its original fee obligation and source lineage are reconciled so artificial debt is never created.';
                warnActions.style.display = data.can_reconcile_lineage ? 'block' : 'none';
            }
            return;
        }

        warnBox.style.display = 'none';
        formEl.style.display = 'block';

        const remMajor = (Number(data.remaining_reversible_minor || 0) / 100).toFixed(2);
        document.getElementById('rdr-col-id').value = data.qa_staged_collection_id;
        document.getElementById('rdr-idempotency-key').value = 'ms-restore-' + data.qa_staged_collection_id + '-' + Date.now();
        document.getElementById('rdr-amount-curr').textContent = curr;
        document.getElementById('rdr-mode-full').checked = true;
        document.getElementById('rdr-mode-partial').checked = false;

        const amountInput = document.getElementById('rdr-amount');
        amountInput.max = remMajor;
        amountInput.value = remMajor;
        amountInput.readOnly = true;

        document.getElementById('rdr-reason').value = '';
        updateMySkoolRestorePreview();
    }

    window.updateMySkoolRestorePreview = function() {
        if (!currentRestoreInspection) return;
        const data = currentRestoreInspection;
        const curr = data.currency || 'SAR';
        const remMinor = Number(data.remaining_reversible_minor || 0);
        const remMajor = (remMinor / 100).toFixed(2);

        const isFull = document.getElementById('rdr-mode-full').checked;
        const amountInput = document.getElementById('rdr-amount');
        if (isFull) {
            amountInput.value = remMajor;
            amountInput.readOnly = true;
        } else {
            if (amountInput.readOnly) {
                amountInput.readOnly = false;
                if (Number(amountInput.value) >= Number(remMajor) && remMinor > 100) {
                    amountInput.value = '';
                }
                amountInput.focus();
            }
        }

        const rawVal = parseFloat(amountInput.value || '0');
        const restoreMinor = Number.isFinite(rawVal) && rawVal > 0 ? Math.round(rawVal * 100) : 0;
        const validAmount = restoreMinor > 0 && restoreMinor <= remMinor;

        const helpEl = document.getElementById('rdr-amount-help');
        const submitBtn = document.getElementById('rdr-btn-submit');
        if (!validAmount) {
            helpEl.style.color = '#dc2626';
            helpEl.textContent = 'Enter an amount between ' + curr + ' 0.01 and ' + curr + ' ' + remMajor + '.';
            if (submitBtn) submitBtn.disabled = true;
        } else {
            helpEl.style.color = '#64748b';
            helpEl.textContent = (restoreMinor === remMinor)
                ? 'Restores 100% of remaining reversible allocation (' + curr + ' ' + remMajor + ').'
                : 'Partial restoration of ' + curr + ' ' + formatMinorMoney(restoreMinor) + '; leaves ' + curr + ' ' + formatMinorMoney(remMinor - restoreMinor) + ' paid.';
            if (submitBtn) submitBtn.disabled = false;
        }

        const clampedMinor = validAmount ? restoreMinor : 0;
        const newEffectivePaidMinor = Math.max(0, remMinor - clampedMinor);
        const newAllocStatus = (clampedMinor === 0)
            ? (data.allocation_status_label || 'Paid')
            : (newEffectivePaidMinor === 0 ? 'Fully Reversed' : 'Partially Reversed');

        const itemPaidBefore = Number(data.item_paid_minor || 0);
        const itemNet = Number(data.item_net_minor || 0);
        const itemBalBefore = Number(data.item_balance_minor || 0);
        const itemPaidAfter = Math.max(0, itemPaidBefore - clampedMinor);
        const itemBalAfter = Math.max(0, itemNet - itemPaidAfter);

        const invPaidBefore = Number(data.invoice_paid_minor || 0);
        const invTotal = Number(data.invoice_total_minor || 0);
        const invBalBefore = Number(data.invoice_balance_minor || 0);
        const invPaidAfter = Math.max(0, invPaidBefore - clampedMinor);
        const invBalAfter = Math.max(0, invTotal - invPaidAfter);

        const previewTbody = document.getElementById('rdr-preview-tbody');
        previewTbody.innerHTML = [
            ['Selected Allocation Effective Paid', curr + ' ' + formatMinorMoney(remMinor), '<b>' + curr + ' ' + formatMinorMoney(newEffectivePaidMinor) + '</b> (' + newAllocStatus + ')'],
            ['Invoice Item (' + (data.invoice_item_id ? '#' + data.invoice_item_id : 'Linked Obligation') + ') Outstanding Due', curr + ' ' + formatMinorMoney(itemBalBefore), '<b style="color:#dc2626;">' + curr + ' ' + formatMinorMoney(itemBalAfter) + ' (+' + curr + ' ' + formatMinorMoney(clampedMinor) + ')</b>'],
            ['Parent Invoice (' + (data.invoice_number || 'N/A') + ') Balance', curr + ' ' + formatMinorMoney(invBalBefore), '<b style="color:#dc2626;">' + curr + ' ' + formatMinorMoney(invBalAfter) + '</b>'],
            ['Other Sibling &amp; Month Allocations on Slip #' + (data.slip_number || '—'), 'Unchanged', '<span style="color:#166534;font-weight:600;">Unchanged (Isolated)</span>'],
            ['Original MySkool Source Record', 'Row #' + data.qa_staged_collection_id + ' (' + curr + ' ' + (data.original_paid_formatted || data.amount_formatted) + ')', '<span style="color:#166534;font-weight:600;">Preserved Intact</span>']
        ].map(row => '<tr><td style="padding:5px 10px;border-bottom:1px solid #f1f5f9;">' + row[0] + '</td><td style="padding:5px 10px;border-bottom:1px solid #f1f5f9;">' + row[1] + '</td><td style="padding:5px 10px;border-bottom:1px solid #f1f5f9;">' + row[2] + '</td></tr>').join('');
    };

    window.submitMySkoolPaymentRestoration = async function(e) {
        e.preventDefault();
        if (!currentRestoreInspection) return;

        const formEl = document.getElementById('rdr-form');
        const submitBtn = document.getElementById('rdr-btn-submit');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Restoring...';
        }

        try {
            const fd = new FormData(formEl);
            const resp = await fetch('{{ route("challan.restore_myskool_payment") }}', {
                method: 'POST',
                body: fd,
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '{{ csrf_token() }}'
                }
            });
            const res = await resp.json();
            if (resp.ok && res.success) {
                window.alertNotice(res.notice || 'MySkool payment allocation restored to outstanding dues.');
                document.getElementById('receipt-details-dialog').close();
                setTimeout(() => location.reload(), 650);
            } else {
                let msg = res.message || 'Restoration blocked by financial safety rules.';
                if (res.errors) {
                    const firstErr = Object.values(res.errors)[0];
                    if (Array.isArray(firstErr) && firstErr[0]) {
                        msg = firstErr[0];
                    }
                }
                window.alertNotice(msg, 'error');
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Confirm & Restore to Outstanding Dues';
                }
            }
        } catch (err) {
            window.alertNotice('Network error while restoring MySkool payment.', 'error');
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Confirm & Restore to Outstanding Dues';
            }
        }
    };

    window.submitMySkoolLineageReconciliation = async function() {
        if (!currentRestoreInspection) return;
        const reasonInput = document.getElementById('rdr-reconcile-reason');
        const reason = (reasonInput ? reasonInput.value : '').trim();
        if (reason.length < 5) {
            window.alertNotice('Please enter a reconciliation reason of at least 5 characters.', 'error');
            if (reasonInput) reasonInput.focus();
            return;
        }
        const btn = document.getElementById('rdr-btn-reconcile');
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Reconciling...';
        }
        try {
            const fd = new FormData();
            fd.append('_token', '{{ csrf_token() }}');
            fd.append('qa_staged_collection_id', String(currentRestoreInspection.qa_staged_collection_id));
            fd.append('action_type', 'reconcile_lineage');
            fd.append('reason', reason);
            fd.append('idempotency_key', 'ms-reconcile-' + currentRestoreInspection.qa_staged_collection_id + '-' + Date.now());

            const resp = await fetch('{{ route("challan.correct_myskool_payment") }}', {
                method: 'POST',
                body: fd,
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '{{ csrf_token() }}'
                }
            });
            const res = await resp.json();
            if (resp.ok && res.success) {
                window.alertNotice(res.notice || 'Historical obligation reconciled. You can now restore this payment.');
                await window.openMySkoolRestorePanel(currentRestoreInspection.qa_staged_collection_id);
            } else {
                window.alertNotice(res.message || 'Lineage reconciliation blocked.', 'error');
            }
        } catch (err) {
            window.alertNotice('Network error while reconciling historical obligation.', 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.textContent = 'Reconcile Lineage First';
            }
        }
    };

    window.closeMySkoolDisputePanel = function() {
        const panel = document.getElementById('rd-dispute-panel');
        if (panel) panel.style.display = 'none';
    };

    window.openMySkoolDisputePanel = async function(stagedCollectionId, invoiceItemId) {
        if (typeof window.closeMySkoolRestorePanel === 'function') {
            window.closeMySkoolRestorePanel();
        }
        var dlg = document.getElementById('receipt-details-dialog');
        if (dlg && !dlg.open) {
            dlg.showModal();
        }
        const panel = document.getElementById('rd-dispute-panel');
        const loading = document.getElementById('rdd-loading');
        const content = document.getElementById('rdd-content');
        if (!panel) return;

        panel.style.display = 'block';
        loading.style.display = 'block';
        content.style.display = 'none';
        panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

        try {
            let inspectUrl = stagedCollectionId
                ? ('/challan/inspect-myskool-payment/' + encodeURIComponent(stagedCollectionId))
                : ('/challan/inspect-myskool-item/' + encodeURIComponent(invoiceItemId));
            const resp = await fetch(inspectUrl, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            const data = await resp.json();
            if (!resp.ok || !data.exists) {
                window.alertNotice(data.error || 'Unable to inspect entry for dispute.', 'error');
                panel.style.display = 'none';
                return;
            }

            const curr = data.currency || 'SAR';
            document.getElementById('rdd-student').textContent = (data.student_name || '—') + ' (GR ' + (data.student_gr || '—') + ')';
            document.getElementById('rdd-record').textContent = data.qa_staged_collection_id
                ? ('Slip #' + (data.slip_number || '—') + ' (Row #' + data.qa_staged_collection_id + ')')
                : ('Invoice #' + (data.invoice_number || '—') + ' (Item #' + (data.invoice_item_id || invoiceItemId) + ')');
            document.getElementById('rdd-fee-type').textContent = data.fee_type || data.fee_particular || 'Monthly Fee';
            document.getElementById('rdd-month').textContent = data.billing_month || data.period_key || '—';
            document.getElementById('rdd-amount').textContent = curr + ' ' + (data.original_paid_formatted || data.amount_formatted || '0.00');

            document.getElementById('rdd-col-id').value = data.qa_staged_collection_id || (stagedCollectionId || '');
            document.getElementById('rdd-item-id').value = data.invoice_item_id || (invoiceItemId || '');
            document.getElementById('rdd-reason').value = '';

            loading.style.display = 'none';
            content.style.display = 'block';
        } catch (err) {
            window.alertNotice('Network error while inspecting entry for dispute.', 'error');
            panel.style.display = 'none';
        }
    };

    window.submitMySkoolDispute = async function(e) {
        e.preventDefault();
        const formEl = document.getElementById('rdd-form');
        const submitBtn = document.getElementById('rdd-btn-submit');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Submitting Dispute...';
        }
        try {
            const fd = new FormData(formEl);
            const resp = await fetch('{{ route("challan.dispute_myskool_payment") }}', {
                method: 'POST',
                body: fd,
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '{{ csrf_token() }}'
                }
            });
            const res = await resp.json();
            if (resp.ok && res.success) {
                window.alertNotice(res.notice || 'Dispute case created successfully.');
                const dlg = document.getElementById('receipt-details-dialog');
                if (dlg && dlg.open) dlg.close();
                setTimeout(() => location.reload(), 650);
            } else {
                let msg = res.message || 'Dispute submission failed.';
                if (res.errors) {
                    const firstErr = Object.values(res.errors)[0];
                    if (Array.isArray(firstErr) && firstErr[0]) msg = firstErr[0];
                }
                window.alertNotice(msg, 'error');
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Submit Dispute Case';
                }
            }
        } catch (err) {
            window.alertNotice('Network error while submitting dispute.', 'error');
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Submit Dispute Case';
            }
        }
    };

    window.updateMySkoolDisputeStatus = async function(disputeId, newStatus) {
        let notes = '';
        if (newStatus === 'rejected') {
            notes = prompt('Please enter rejection notes / reason (optional):');
            if (notes === null) return;
        } else if (newStatus === 'approved') {
            if (!confirm('Approve this dispute case? This preserves records and makes no automatic balance changes.')) return;
        }

        try {
            const resp = await fetch('/challan/disputes/' + encodeURIComponent(disputeId) + '/status', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '{{ csrf_token() }}'
                },
                body: JSON.stringify({
                    _token: '{{ csrf_token() }}',
                    status: newStatus,
                    review_notes: notes
                })
            });
            const res = await resp.json();
            if (resp.ok && res.success) {
                window.alertNotice(res.notice || 'Dispute status updated successfully.');
                setTimeout(() => location.reload(), 650);
            } else {
                window.alertNotice(res.message || 'Unable to update dispute status.', 'error');
            }
        } catch (err) {
            window.alertNotice('Network error while updating dispute status.', 'error');
        }
    };

    window.openMySkoolDisputeFromInvoiceItem = function(invoiceItemId, stagedCollectionId) {
        window.openMySkoolDisputePanel(stagedCollectionId || null, invoiceItemId);
    };

    window.openMySkoolRestoreFromInvoiceItem = async function(invoiceItemId, stagedCollectionId) {
        if (stagedCollectionId && Number(stagedCollectionId) > 0) {
            return window.openMySkoolRestorePanel(stagedCollectionId);
        }
        try {
            const resp = await fetch('/challan/inspect-myskool-item/' + encodeURIComponent(invoiceItemId), {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            const data = await resp.json();
            if (!resp.ok || !data.exists) {
                window.alertNotice(data.error || 'Unable to inspect invoice item for restoration.', 'error');
                return;
            }
            if (data.qa_staged_collection_id) {
                return window.openMySkoolRestorePanel(data.qa_staged_collection_id);
            }
            if (data.requires_finance_review) {
                window.alertNotice(data.finance_review_message || 'Finance review required. Use Dispute to open a review case.', 'error');
                return window.openMySkoolDisputePanel(null, invoiceItemId);
            }
            window.alertNotice('This fee entry is already marked unpaid. No payment restoration needed.', 'info');
        } catch (err) {
            window.alertNotice('Network error inspecting invoice item.', 'error');
        }
    };
})();
</script>

@if(in_array(auth()->user()?->role, ['super_admin', 'finance_admin', 'admin'], true))
{{-- ADMINISTRATOR-ONLY MYSKOOL PAYMENT INSPECTION & CORRECTION MODAL (TASK 2 - 5) --}}
<dialog id="myskool-payment-correction-dialog" style="border:none;border-radius:8px;box-shadow:0 16px 48px rgba(0,0,0,0.35);padding:0;max-width:780px;width:96%;margin:auto;font-family:inherit;">
    <div style="background:#4b3f72;color:#fff;padding:14px 20px;display:flex;align-items:center;justify-content:space-between;border-top-left-radius:8px;border-top-right-radius:8px;">
        <div style="display:flex;align-items:center;gap:10px;">
            <span style="font-size:18px;">&#8634;</span>
            <div>
                <div style="font-weight:700;font-size:15px;">Review / Correct MySkool Payment — Slip #<span id="mpc-header-slip">—</span></div>
                <div style="font-size:11px;color:#e2e8f0;">Read-Only Forensic Inspection &amp; Authorized Accounting Correction Facility</div>
            </div>
        </div>
        <button type="button" onclick="document.getElementById('myskool-payment-correction-dialog').close()" style="background:none;border:none;color:#fff;font-size:24px;line-height:1;cursor:pointer;">&times;</button>
    </div>

    <div style="padding:18px 20px;font-size:12px;color:#334155;max-height:82vh;overflow-y:auto;">
        <div id="mpc-loading" style="display:none;padding:30px;text-align:center;color:#64748b;font-size:13px;">
            Loading MySkool source collection forensics and reconciliation lineage...
        </div>

        <div id="mpc-body">
            {{-- Shared-Slip Sibling Allocation Selector (Case C) --}}
            <div id="mpc-shared-slip-box" style="display:none;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:10px 12px;margin-bottom:14px;">
                <div style="font-weight:700;color:#92400e;font-size:12px;margin-bottom:6px;">
                    Shared MySkool Slip Detected — Select Individual Source Collection Allocation:
                </div>
                <div style="font-size:11px;color:#78350f;margin-bottom:8px;">
                    Correcting or reversing a selected student row affects <strong>only</strong> that specific source collection row and preserves all sibling allocations on this slip intact.
                </div>
                <div id="mpc-shared-slip-list" style="display:flex;flex-wrap:wrap;gap:6px;"></div>
            </div>

            {{-- Safety & Reversibility Banner --}}
            <div id="mpc-safety-banner" style="border-radius:6px;padding:10px 12px;margin-bottom:14px;border:1px solid #cbd5e1;background:#f8fafc;">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">
                    <strong id="mpc-safety-title" style="font-size:12px;">Reversal Safety Check</strong>
                    <span id="mpc-case-badge" style="font-size:10px;padding:2px 8px;border-radius:10px;background:#e2e8f0;color:#334155;font-weight:700;">—</span>
                </div>
                <div id="mpc-safety-text" style="margin-top:4px;font-size:11px;line-height:1.4;">—</div>
            </div>

            {{-- 14 Required Forensic Inspection Fields Grid --}}
            <div style="font-weight:700;color:#1e293b;margin-bottom:6px;font-size:12px;">1. Source Collection &amp; Reconciliation Inspection (Read-Only)</div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:8px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:12px;margin-bottom:14px;">
                <div>
                    <small style="color:#64748b;display:block;font-size:10px;">Original MySkool Slip #</small>
                    <strong id="mpc-f-slip">—</strong>
                </div>
                <div>
                    <small style="color:#64748b;display:block;font-size:10px;">Imported Collection Row ID</small>
                    <strong id="mpc-f-col-id">—</strong>
                </div>
                <div>
                    <small style="color:#64748b;display:block;font-size:10px;">Student Name &amp; GR</small>
                    <strong id="mpc-f-student">—</strong>
                </div>
                <div>
                    <small style="color:#64748b;display:block;font-size:10px;">Family Code</small>
                    <strong id="mpc-f-family">—</strong>
                </div>
                <div>
                    <small style="color:#64748b;display:block;font-size:10px;">Fee Type &amp; Billing Period</small>
                    <strong id="mpc-f-fee-period">—</strong>
                </div>
                <div>
                    <small style="color:#64748b;display:block;font-size:10px;">Currency</small>
                    <strong id="mpc-f-currency">—</strong>
                </div>
                <div>
                    <small style="color:#64748b;display:block;font-size:10px;">Original Receipt Amount</small>
                    <strong id="mpc-f-amount" style="color:#7367f0;">—</strong>
                </div>
                <div>
                    <small style="color:#64748b;display:block;font-size:10px;">Historical Payment Date</small>
                    <strong id="mpc-f-date">—</strong>
                </div>
                <div>
                    <small style="color:#64748b;display:block;font-size:10px;">Reconciled to IQRA Invoice?</small>
                    <strong id="mpc-f-reconciled">—</strong>
                </div>
                <div>
                    <small style="color:#64748b;display:block;font-size:10px;">Invoice &amp; Item Reference</small>
                    <strong id="mpc-f-invoice">—</strong>
                </div>
                <div>
                    <small style="color:#64748b;display:block;font-size:10px;">Current Item / Invoice Balance</small>
                    <strong id="mpc-f-balance">—</strong>
                </div>
                <div>
                    <small style="color:#64748b;display:block;font-size:10px;">Current Receipt Status</small>
                    <strong id="mpc-f-status">—</strong>
                </div>
            </div>

            {{-- Existing Adjustments & Correction History --}}
            <div style="font-weight:700;color:#1e293b;margin-bottom:6px;font-size:12px;">2. Existing Adjustments, Settlements &amp; Correction Audit Trail</div>
            <div id="mpc-history-box" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px 12px;margin-bottom:14px;font-size:11px;max-height:130px;overflow-y:auto;">
                No prior manual corrections recorded.
            </div>

            {{-- Proposed Before / After Financial Impact Preview --}}
            <div style="font-weight:700;color:#1e293b;margin-bottom:6px;font-size:12px;">
                3. Proposed Financial Impact Preview (<span id="mpc-preview-action-name">Default Action</span>)
            </div>
            <table style="width:100%;border-collapse:collapse;font-size:11px;border:1px solid #e2e8f0;margin-bottom:14px;">
                <thead>
                    <tr style="background:#f1f5f9;color:#334155;text-align:left;">
                        <th style="padding:7px 10px;border-bottom:1px solid #e2e8f0;">Accounting Dimension</th>
                        <th style="padding:7px 10px;border-bottom:1px solid #e2e8f0;">Before Correction</th>
                        <th style="padding:7px 10px;border-bottom:1px solid #e2e8f0;">After Proposed Correction</th>
                    </tr>
                </thead>
                <tbody id="mpc-impact-tbody"></tbody>
            </table>

            {{-- Stage 1 -> Stage 2 Gate Button (Inspection stage is strictly read-only by default) --}}
            <div id="mpc-stage1-controls" style="display:flex;justify-content:space-between;align-items:center;padding-top:10px;border-top:1px solid #e2e8f0;">
                <span style="font-size:11px;color:#64748b;">Inspection Mode is strictly read-only. Source <code>qa_staged_collections</code> row is permanently preserved.</span>
                <div style="display:flex;gap:8px;">
                    <button type="button" id="mpc-btn-unlock-form" onclick="unlockMySkoolCorrectionForm()" style="background:#7367f0;color:#fff;border:none;padding:7px 14px;border-radius:4px;font-size:12px;font-weight:600;cursor:pointer;">
                        Proceed to Authorized Correction / Action &rarr;
                    </button>
                    <button type="button" onclick="document.getElementById('myskool-payment-correction-dialog').close()" style="background:#f1f5f9;border:1px solid #cbd5e1;padding:7px 14px;border-radius:4px;font-size:12px;font-weight:600;color:#475569;cursor:pointer;">
                        Close
                    </button>
                </div>
            </div>

            {{-- Stage 2: Authorized Correction Execution Form --}}
            <form id="mpc-correction-form" method="post" action="{{ route('challan.correct_myskool_payment') }}" style="display:none;background:#faf9ff;border:1px solid #dcd7fa;border-radius:6px;padding:14px;margin-top:10px;">
                @csrf
                <input type="hidden" name="qa_staged_collection_id" id="mpc-input-col-id">

                <div style="font-weight:700;color:#4b3f72;margin-bottom:10px;font-size:13px;">
                    Authorized Financial Administrator Correction Form
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:11px;">Correction / Review Action *</label>
                        <select name="action_type" id="mpc-input-action" class="myskool-input" style="width:100%;" onchange="updateMySkoolImpactTable()"></select>
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:11px;">Target Source Collection Row</label>
                        <input type="text" id="mpc-input-row-readonly" class="myskool-input disabled" readonly style="width:100%;">
                    </div>
                </div>

                {{-- Optional Reassignment Fields (Case D) --}}
                <div id="mpc-reassignment-fields" style="display:none;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:10px;background:#fff;padding:10px;border:1px solid #e2e8f0;border-radius:4px;">
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:3px;font-size:10px;">Proposed Student GR #</label>
                        <input type="text" name="proposed_student_gr" id="mpc-input-prop-gr" class="myskool-input" placeholder="e.g. 5930" style="width:100%;">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:3px;font-size:10px;">Proposed Period (YYYY-MM)</label>
                        <input type="text" name="proposed_period_key" id="mpc-input-prop-period" class="myskool-input" placeholder="e.g. 2026-10" style="width:100%;">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:3px;font-size:10px;">Proposed Fee Particular</label>
                        <input type="text" name="proposed_fee_type" id="mpc-input-prop-fee" class="myskool-input" placeholder="e.g. Monthly Fee" style="width:100%;">
                    </div>
                </div>

                {{-- Optional Pre-Cutoff Compensation Proposal Field (Case B) --}}
                <div id="mpc-compensation-fields" style="display:none;margin-bottom:10px;background:#fff;padding:10px;border:1px solid #e2e8f0;border-radius:4px;">
                    <label style="display:block;font-weight:600;margin-bottom:3px;font-size:10px;">Proposed Pre-Cutoff Compensating Obligation Adjustment (Minor Units)</label>
                    <input type="number" name="proposed_compensation_minor" id="mpc-input-prop-comp" class="myskool-input" min="0" step="1" placeholder="e.g. 28000" style="width:240px;">
                    <small style="display:block;color:#64748b;font-size:10px;margin-top:3px;">Logs the proposed compensating adjustment for pre-cutoff snapshot verification without blindly mutating <code>invoice_items.paid_minor</code>.</small>
                </div>

                <div style="margin-bottom:10px;">
                    <label style="display:block;font-weight:600;margin-bottom:4px;font-size:11px;">Mandatory Audit Reason &amp; Evidence Justification *</label>
                    <textarea name="reason" id="mpc-input-reason" class="myskool-input" rows="3" required minlength="5" placeholder="Enter detailed accounting reason and verification reference (minimum 5 characters)..." style="width:100%;height:65px;resize:vertical;"></textarea>
                </div>

                <label style="display:flex;align-items:flex-start;gap:6px;font-size:11px;color:#334155;margin-bottom:12px;cursor:pointer;">
                    <input type="checkbox" id="mpc-input-confirm" required style="margin-top:2px;">
                    <span>I confirm that I have verified the before/after accounting impact and understand that this action will be permanently recorded in the immutable audit trail while preserving <code>qa_staged_collections</code> unchanged.</span>
                </label>

                <div style="display:flex;justify-content:flex-end;gap:8px;">
                    <button type="button" onclick="lockMySkoolCorrectionForm()" style="background:#f1f5f9;border:1px solid #cbd5e1;padding:7px 14px;border-radius:4px;font-size:12px;font-weight:600;color:#475569;cursor:pointer;">
                        &larr; Back to Read-Only Inspection
                    </button>
                    <button type="submit" id="mpc-btn-submit" style="background:#dc2626;color:#fff;border:none;padding:7px 16px;border-radius:4px;font-size:12px;font-weight:700;cursor:pointer;">
                        Execute Authorized Correction
                    </button>
                </div>
            </form>
        </div>
    </div>
</dialog>

<script>
(() => {
    let currentMySkoolInspection = null;

    const actionLabels = {
        restore_payment: 'Restore Selected Payment Allocation (Full or Partial)',
        reverse_settlement: 'Reverse Reconciled MySkool Settlement (Restore Unpaid Invoice Balance)',
        reapply_settlement: 'Reapply Reversed Historical Settlement',
        reconcile_lineage: 'Reconcile Historical Obligation & Lineage (Zero New Debt)',
        mark_under_review: 'Place Under Finance Review (Audit Hold)',
        mark_disputed: 'Mark Receipt Disputed (Block Auto-Application)',
        mark_corrected: 'Mark Receipt Corrected (Audit Notation)',
        propose_reassignment: 'Propose Reassignment (Student / Period / Fee Type)',
        propose_pre_cutoff_compensation: 'Log Pre-Cutoff Snapshot Compensation Review'
    };

    window.openMySkoolCorrectionModal = async function(stagedCollectionId) {
        const dialog = document.getElementById('myskool-payment-correction-dialog');
        const loading = document.getElementById('mpc-loading');
        const body = document.getElementById('mpc-body');
        if (!dialog) return;

        lockMySkoolCorrectionForm();
        loading.style.display = 'block';
        body.style.display = 'none';
        if (!dialog.open) {
            dialog.showModal();
        }

        try {
            const resp = await fetch('/challan/inspect-myskool-payment/' + encodeURIComponent(stagedCollectionId), {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            const data = await resp.json();
            if (!resp.ok || !data.exists) {
                window.alertNotice(data.error || 'Unable to inspect MySkool payment record.', 'error');
                dialog.close();
                return;
            }
            currentMySkoolInspection = data;
            renderMySkoolInspection(data);
            loading.style.display = 'none';
            body.style.display = 'block';
        } catch (err) {
            window.alertNotice('Network error while inspecting MySkool payment.', 'error');
            dialog.close();
        }
    };

    function renderMySkoolInspection(data) {
        const curr = data.currency || 'SAR';
        document.getElementById('mpc-header-slip').textContent = data.slip_number || '—';
        document.getElementById('mpc-f-slip').textContent = data.slip_number || '—';
        document.getElementById('mpc-f-col-id').textContent = '#' + data.qa_staged_collection_id + (data.source_row ? ' (Excel Row #' + data.source_row + ')' : '');
        document.getElementById('mpc-f-student').textContent = (data.student_name || '—') + ' (GR ' + (data.student_gr || '—') + ')';
        document.getElementById('mpc-f-family').textContent = (data.family_code || '—') + (data.guardian_name ? ' (' + data.guardian_name + ')' : '');
        document.getElementById('mpc-f-fee-period').textContent = (data.fee_particular || '—') + ' [' + (data.period_key || 'N/A') + ']';
        document.getElementById('mpc-f-currency').innerHTML = data.has_currency_conflict
            ? '<span style="color:#dc2626;font-weight:700;">' + curr + ' (CONFLICT vs ' + (data.student_expected_currency || '—') + ')</span>'
            : curr;
        document.getElementById('mpc-f-amount').textContent = curr + ' ' + (data.amount_formatted || '0.00');
        document.getElementById('mpc-f-date').textContent = (data.payment_date_formatted || data.payment_date || '—') + (data.is_pre_cutoff ? ' (Pre-Cutoff <= ' + data.cutoff_date + ')' : ' (Post-Cutoff)');
        document.getElementById('mpc-f-reconciled').innerHTML = data.is_reconciled
            ? '<span style="color:#166534;font-weight:700;">Yes — ' + (data.reconciliation_id || 'Applied') + '</span>'
            : '<span style="color:#9a3412;">No (' + (data.settlement_status || 'Unreconciled') + ')</span>';
        document.getElementById('mpc-f-invoice').textContent = data.invoice_number
            ? (data.invoice_number + ' (Item #' + data.invoice_item_id + ')')
            : 'No Linked Invoice Item';
        document.getElementById('mpc-f-balance').textContent = data.invoice_item_id
            ? ('Item: ' + curr + ' ' + data.item_balance_formatted + ' | Inv: ' + curr + ' ' + data.invoice_balance_formatted)
            : ('Student Due: ' + (data.student_expected_currency || curr) + ' ' + data.student_total_outstanding_formatted);
        document.getElementById('mpc-f-status').textContent = data.current_status_label || '—';

        // Shared slip allocations (Case C)
        const sharedBox = document.getElementById('mpc-shared-slip-box');
        const sharedList = document.getElementById('mpc-shared-slip-list');
        sharedList.innerHTML = '';
        if (data.is_shared_slip && Array.isArray(data.shared_slip_allocations) && data.shared_slip_allocations.length > 1) {
            sharedBox.style.display = 'block';
            data.shared_slip_allocations.forEach(alloc => {
                const btn = document.createElement('button');
                btn.type = 'button';
                const active = Boolean(alloc.is_current_row);
                btn.style.cssText = active
                    ? 'background:#4b3f72;color:#fff;border:1px solid #4b3f72;border-radius:4px;padding:5px 10px;font-size:11px;font-weight:700;cursor:default;'
                    : 'background:#fff;color:#334155;border:1px solid #cbd5e1;border-radius:4px;padding:5px 10px;font-size:11px;cursor:pointer;';
                const stTag = alloc.correction_status
                    ? alloc.correction_status.toUpperCase()
                    : (alloc.settlement_status ? alloc.settlement_status.toUpperCase() : 'HISTORICAL');
                btn.textContent = (active ? '✓ ' : '') + 'Row #' + alloc.qa_staged_collection_id + ': ' + alloc.student_name + ' (GR ' + alloc.student_gr + ') — ' + alloc.currency + ' ' + alloc.amount_formatted + ' [' + stTag + ']';
                if (!active) {
                    btn.onclick = () => window.openMySkoolCorrectionModal(alloc.qa_staged_collection_id);
                }
                sharedList.appendChild(btn);
            });
        } else {
            sharedBox.style.display = 'none';
        }

        // Safety banner
        const banner = document.getElementById('mpc-safety-banner');
        const safetyTitle = document.getElementById('mpc-safety-title');
        const safetyText = document.getElementById('mpc-safety-text');
        const caseBadge = document.getElementById('mpc-case-badge');
        caseBadge.textContent = data.case_classification || '—';

        if (data.is_safe_to_reverse) {
            banner.style.background = '#f0fdf4';
            banner.style.borderColor = '#bbf7d0';
            safetyTitle.style.color = '#166534';
            safetyTitle.textContent = '✓ SAFE TO REVERSE RECONCILED SETTLEMENT';
        } else if (data.is_safe_to_reapply) {
            banner.style.background = '#eff6ff';
            banner.style.borderColor = '#bfdbfe';
            safetyTitle.style.color = '#1e40af';
            safetyTitle.textContent = '↺ REVERSED SETTLEMENT — SAFE TO REAPPLY OR UPDATE AUDIT STATUS';
        } else {
            banner.style.background = '#fff7ed';
            banner.style.borderColor = '#fed7aa';
            safetyTitle.style.color = '#9a3412';
            safetyTitle.textContent = '⚠ DIRECT SETTLEMENT REVERSAL BLOCKED (' + (data.safety_status || 'MANUAL REVIEW') + ')';
        }
        safetyText.textContent = data.safety_explanation || '';

        // Existing adjustments & correction audit trail
        const histBox = document.getElementById('mpc-history-box');
        const lines = [];
        if (Array.isArray(data.settlement_history) && data.settlement_history.length > 0) {
            data.settlement_history.forEach(sh => {
                lines.push('<div><strong>Settlement ' + sh.reconciliation_id + '</strong>: ' + curr + ' ' + sh.settled_amount_formatted + ' on Item #' + sh.invoice_item_id + ' — Status: <b>' + String(sh.status).toUpperCase() + '</b> (' + sh.guard_class + ')</div>');
            });
        }
        if (Array.isArray(data.fee_adjustments) && data.fee_adjustments.length > 0) {
            data.fee_adjustments.forEach(fa => {
                lines.push('<div><strong>Fee Adjustment #' + fa.id + ' (' + fa.kind + ')</strong>: ' + curr + ' ' + fa.amount_formatted + ' — ' + (fa.reason || '') + '</div>');
            });
        }
        if (Array.isArray(data.correction_history) && data.correction_history.length > 0) {
            data.correction_history.forEach(ch => {
                lines.push('<div style="margin-top:4px;padding-top:4px;border-top:1px dashed #cbd5e1;"><strong>Audit #' + ch.sequence_no + ' (' + ch.correction_id + ')</strong> [' + ch.action_type + ' &rarr; <b>' + String(ch.resulting_status).toUpperCase() + '</b>] by ' + ch.actor_name + ' on ' + ch.acted_at + '<br><span style="color:#475569;">Reason: ' + ch.reason + '</span></div>');
            });
        }
        histBox.innerHTML = lines.length > 0 ? lines.join('') : 'No prior settlements, adjustments, or manual corrections recorded for this row.';

        // Populate form action select
        const actionSelect = document.getElementById('mpc-input-action');
        actionSelect.innerHTML = '';
        const avail = Array.isArray(data.available_actions) && data.available_actions.length > 0
            ? data.available_actions
            : ['mark_under_review'];
        avail.forEach(act => {
            const opt = document.createElement('option');
            opt.value = act;
            opt.textContent = actionLabels[act] || act;
            actionSelect.appendChild(opt);
        });

        document.getElementById('mpc-input-col-id').value = data.qa_staged_collection_id;
        document.getElementById('mpc-input-row-readonly').value = 'Row #' + data.qa_staged_collection_id + ' — GR ' + data.student_gr + ' (' + curr + ' ' + data.amount_formatted + ')';
        document.getElementById('mpc-input-reason').value = '';
        document.getElementById('mpc-input-confirm').checked = false;

        updateMySkoolImpactTable();
    }

    window.updateMySkoolImpactTable = function() {
        if (!currentMySkoolInspection) return;
        const data = currentMySkoolInspection;
        const curr = data.currency || 'SAR';
        const actionSelect = document.getElementById('mpc-input-action');
        const selectedAction = (actionSelect && actionSelect.value)
            ? actionSelect.value
            : (data.is_safe_to_reverse ? 'reverse_settlement' : (data.is_safe_to_reapply ? 'reapply_settlement' : (data.available_actions?.[0] || 'mark_under_review')));

        const effects = data.proposed_effects || {};
        const eff = effects[selectedAction] || effects.reverse_settlement || {};
        const before = eff.before || {};
        const after = eff.after || {};

        document.getElementById('mpc-preview-action-name').textContent = eff.action_label || selectedAction;

        const reassignBox = document.getElementById('mpc-reassignment-fields');
        const compBox = document.getElementById('mpc-compensation-fields');
        if (reassignBox) {
            reassignBox.style.display = (selectedAction === 'propose_reassignment') ? 'grid' : 'none';
        }
        if (compBox) {
            compBox.style.display = (selectedAction === 'propose_pre_cutoff_compensation') ? 'block' : 'none';
        }

        const tbody = document.getElementById('mpc-impact-tbody');
        tbody.innerHTML = [
            ['Receipt / Allocation Status', before.receipt_status || '—', '<b>' + (after.receipt_status || '—') + '</b>'],
            ['Invoice Item Paid (' + (data.invoice_item_id ? '#' + data.invoice_item_id : 'N/A') + ')', curr + ' ' + (before.item_paid_formatted || '0.00'), '<b>' + curr + ' ' + (after.item_paid_formatted || '0.00') + '</b>'],
            ['Invoice Item Outstanding Balance', curr + ' ' + (before.item_balance_formatted || '0.00'), '<b style="color:#b45309;">' + curr + ' ' + (after.item_balance_formatted || '0.00') + '</b>'],
            ['Invoice Item Status', before.item_status || 'N/A', '<b>' + (after.item_status || 'N/A') + '</b>'],
            ['Parent Invoice Paid (' + (data.invoice_number || 'N/A') + ')', curr + ' ' + (before.invoice_paid_formatted || '0.00'), '<b>' + curr + ' ' + (after.invoice_paid_formatted || '0.00') + '</b>'],
            ['Parent Invoice Outstanding Balance', curr + ' ' + (before.invoice_balance_formatted || '0.00'), '<b style="color:#b45309;">' + curr + ' ' + (after.invoice_balance_formatted || '0.00') + '</b>'],
            ['Parent Invoice Status', before.invoice_status || 'N/A', '<b>' + (after.invoice_status || 'N/A') + '</b>'],
            ['Imported Source Row (qa_staged_collections)', 'Row #' + data.qa_staged_collection_id + ' (' + curr + ' ' + data.amount_formatted + ')', '<span style="color:#166534;font-weight:600;">Preserved 100% Unchanged</span>'],
            ['Sibling Allocations &amp; Portal Cashier Totals', 'Protected', '<span style="color:#166534;font-weight:600;">0.00 Mutation (Protected)</span>']
        ].map(r => '<tr><td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;">' + r[0] + '</td><td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;">' + r[1] + '</td><td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;">' + r[2] + '</td></tr>').join('');
    };

    window.unlockMySkoolCorrectionForm = function() {
        const s1 = document.getElementById('mpc-stage1-controls');
        const form = document.getElementById('mpc-correction-form');
        if (s1) s1.style.display = 'none';
        if (form) form.style.display = 'block';
        updateMySkoolImpactTable();
    };

    window.lockMySkoolCorrectionForm = function() {
        const s1 = document.getElementById('mpc-stage1-controls');
        const form = document.getElementById('mpc-correction-form');
        if (s1) s1.style.display = 'flex';
        if (form) form.style.display = 'none';
    };

    const formEl = document.getElementById('mpc-correction-form');
    if (formEl) {
        formEl.addEventListener('submit', async function(e) {
            e.preventDefault();
            const submitBtn = document.getElementById('mpc-btn-submit');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Processing...';
            }

            try {
                const fd = new FormData(formEl);
                const resp = await fetch(formEl.action, {
                    method: 'POST',
                    body: fd,
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                const res = await resp.json();
                if (resp.ok && res.success) {
                    window.alertNotice(res.notice || 'MySkool payment correction recorded.');
                    document.getElementById('myskool-payment-correction-dialog').close();
                    setTimeout(() => location.reload(), 700);
                } else {
                    let msg = res.message || 'Correction blocked by financial safety rules.';
                    if (res.errors) {
                        const firstErr = Object.values(res.errors)[0];
                        if (Array.isArray(firstErr) && firstErr[0]) {
                            msg = firstErr[0];
                        }
                    }
                    window.alertNotice(msg, 'error');
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Execute Authorized Correction';
                    }
                }
            } catch (err) {
                window.alertNotice('Network error while submitting MySkool payment correction.', 'error');
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Execute Authorized Correction';
                }
            }
        });
    }
})();
</script>
@endif

@endsection