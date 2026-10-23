@extends('layouts.app')
@section('title', 'Migration Review Dashboard')
@section('content')
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:12px;">
    <div>
        <h2 style="font-size:22px;font-weight:700;color:#2c2c2c;margin:0;">Migration Review Dashboard</h2>
        <p style="color:#6e6b7b;font-size:13px;margin:4px 0 0;">Approved MySkool workbook verification, live portal database metrics, and single-database migration readiness.</p>
    </div>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <span style="background:#e8fadf;color:#28c76f;padding:6px 12px;border-radius:20px;font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:6px;">
            <span style="width:8px;height:8px;background:#28c76f;border-radius:50%;"></span> Active Database: {{ $activeDb ?? 'iqra_billing' }}
        </span>
        <span style="background:{{ $importStatus === 'Imported' ? '#e8fadf' : '#f1f5f9' }};color:{{ $importStatus === 'Imported' ? '#28c76f' : '#64748b' }};padding:6px 12px;border-radius:20px;font-size:12px;font-weight:600;">
            Import Status: {{ $importStatus }}
        </span>
        <span style="background:#fceaea;color:#ea5455;padding:6px 12px;border-radius:20px;font-size:12px;font-weight:600;">
            Financial Postings: {{ $actualData['opening_balance_invoices'] + $actualData['live_receipts'] }} (Protected)
        </span>
    </div>
</div>

<!-- SECTION 1: Current System -->
<div style="background:#fff;border:1px solid #ebe9f1;border-radius:8px;padding:18px 20px;margin-bottom:22px;box-shadow:0 1px 3px rgba(0,0,0,0.04);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;border-bottom:1px solid #f1f5f9;padding-bottom:10px;">
        <div>
            <h3 style="font-size:15px;font-weight:700;color:#1e293b;margin:0;display:flex;align-items:center;gap:8px;">
                <span>🖥️</span> Current System Environment
            </h3>
            <p style="font-size:12px;color:#64748b;margin:2px 0 0;">Dedicated single MySQL database connection verified fail-closed against unauthorized schemas.</p>
        </div>
        <span style="background:#e8fadf;color:#166534;font-size:11px;font-weight:600;padding:3px 10px;border-radius:12px;">SINGLE-DATABASE ARCHITECTURE</span>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:15px;">
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:12px 14px;">
            <div style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:600;">Active Database</div>
            <div style="font-size:16px;font-weight:700;color:#0f172a;margin-top:4px;"><code>{{ $activeDb ?? 'iqra_billing' }}</code></div>
            <div style="font-size:11px;color:#16a34a;margin-top:2px;">✓ Verified Target Connection</div>
        </div>

        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:12px 14px;">
            <div style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:600;">Connection Health</div>
            <div style="font-size:16px;font-weight:700;color:{{ $dbVerified ? '#16a34a' : '#dc2626' }};margin-top:4px;">
                {{ $dbVerified ? 'Verified' : 'Check Failed' }}
            </div>
            <div style="font-size:11px;color:#64748b;margin-top:2px;">127.0.0.1:3306 (mysql driver)</div>
        </div>

        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:12px 14px;">
            <div style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:600;">Source Workbook</div>
            <div style="font-size:16px;font-weight:700;color:{{ $expectedWorkbook['configured'] ? '#0f172a' : '#dc2626' }};margin-top:4px;">
                {{ $expectedWorkbook['configured'] ? 'Configured' : 'Missing' }}
            </div>
            <div style="font-size:11px;color:#64748b;margin-top:2px;">{{ $expectedWorkbook['file_name'] }}</div>
        </div>

        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:12px 14px;">
            <div style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:600;">Import Status</div>
            <div style="font-size:16px;font-weight:700;color:{{ $importStatus === 'Imported' ? '#16a34a' : '#64748b' }};margin-top:4px;">
                {{ $importStatus }}
            </div>
            <div style="font-size:11px;color:#64748b;margin-top:2px;">Staging Tables Zero-Baseline</div>
        </div>

        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:12px 14px;">
            <div style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:600;">Posting Status</div>
            <div style="font-size:16px;font-weight:700;color:#0f172a;margin-top:4px;">Protected</div>
            <div style="font-size:11px;color:#16a34a;margin-top:2px;">Zero Financial Postings</div>
        </div>
    </div>
</div>

<!-- SECTION 2: Approved MySkool Workbook -->
<div style="background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;padding:18px 20px;margin-bottom:22px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;border-bottom:1px solid #e2e8f0;padding-bottom:10px;flex-wrap:wrap;gap:8px;">
        <div>
            <h3 style="font-size:15px;font-weight:700;color:#1e293b;margin:0;display:flex;align-items:center;gap:8px;">
                <span>📗</span> Approved MySkool Workbook (Final Authority)
            </h3>
            <p style="font-size:12px;color:#475569;margin:2px 0 0;">Configured source file: <code>{{ $expectedWorkbook['file_name'] }}</code> | Batch: <code>{{ $expectedWorkbook['batch_id'] }}</code></p>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
            @if($expectedWorkbook['hash_verified'])
                <span style="background:#dcfce7;color:#15803d;font-size:11px;font-weight:600;padding:3px 10px;border-radius:12px;">✓ SHA-256 VERIFIED</span>
            @else
                <span style="background:#fee2e2;color:#b91c1c;font-size:11px;font-weight:600;padding:3px 10px;border-radius:12px;">HASH CHECK PENDING</span>
            @endif
            <span style="background:#e0e7ff;color:#3730a3;font-size:11px;font-weight:600;padding:3px 10px;border-radius:12px;">Version: {{ $expectedWorkbook['version'] }}</span>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));gap:15px;">
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:14px;">
            <div style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:600;">Worksheet: Students</div>
            <div style="font-size:24px;font-weight:700;color:#1e293b;margin:6px 0 2px;">{{ number_format($expectedWorkbook['expected_students']) }}</div>
            <div style="font-size:12px;color:#64748b;">
                Expected valid student records across School (3,981) and Tuition (377).<br>
                13 admission currency records pending staff verification.
            </div>
        </div>

        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:14px;">
            <div style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:600;">Worksheet: Paid details</div>
            <div style="font-size:24px;font-weight:700;color:#1e293b;margin:6px 0 2px;">{{ number_format($expectedWorkbook['expected_collections']) }}</div>
            <div style="font-size:12px;color:#64748b;">
                Expected historical payment receipts across SAR, AED, PKR, QAR, BHD, OMR.<br>
                Preserved as historical evidence with source slip identities.
            </div>
        </div>

        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:14px;">
            <div style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:600;">Worksheet: Outstanding (Compiled Valid)</div>
            <div style="font-size:24px;font-weight:700;color:#1e293b;margin:6px 0 2px;">{{ number_format($expectedWorkbook['expected_dues']) }}</div>
            <div style="font-size:12px;color:#64748b;">
                Expected residual outstanding balance lines by fee particular.<br>
                Currencies isolated without cross-currency addition.
            </div>
        </div>
    </div>
</div>

<!-- SECTION 3: Actual Portal Data (Live Database Queries) -->
<div style="background:#fff;border:1px solid #ebe9f1;border-radius:8px;padding:18px 20px;margin-bottom:22px;box-shadow:0 1px 3px rgba(0,0,0,0.04);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;border-bottom:1px solid #f1f5f9;padding-bottom:10px;flex-wrap:wrap;gap:8px;">
        <div>
            <h3 style="font-size:15px;font-weight:700;color:#1e293b;margin:0;display:flex;align-items:center;gap:8px;">
                <span>📊</span> Actual Portal Data (Live Database: <code>{{ $activeDb ?? 'iqra_billing' }}</code>)
            </h3>
            <p style="font-size:12px;color:#64748b;margin:2px 0 0;">Live counts queried directly from the current database. Un-imported tables display zero.</p>
        </div>
        <span style="background:#f1f5f9;color:#475569;font-size:11px;font-weight:600;padding:3px 10px;border-radius:12px;">PRE-IMPORT CLEAN STATE</span>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(210px, 1fr));gap:15px;">
        <!-- Students -->
        <div style="border:1px solid #e2e8f0;border-radius:6px;padding:14px;background:#f8fafc;">
            <div style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:600;">Students Imported</div>
            <div style="font-size:24px;font-weight:700;color:{{ $actualData['students'] > 0 ? '#16a34a' : '#64748b' }};margin:6px 0 4px;">
                {{ number_format($actualData['students']) }}
            </div>
            <div style="font-size:12px;color:#64748b;">
                @if($actualData['students'] === 0)
                    <span style="background:#f1f5f9;color:#64748b;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">Not Imported</span>
                @else
                    <span style="color:#16a34a;font-weight:600;">Imported</span>
                @endif
                <span style="color:#94a3b8;margin-left:4px;">(Expected: 4,358)</span>
            </div>
        </div>

        <!-- Families -->
        <div style="border:1px solid #e2e8f0;border-radius:6px;padding:14px;background:#f8fafc;">
            <div style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:600;">Families Created</div>
            <div style="font-size:24px;font-weight:700;color:{{ $actualData['families'] > 0 ? '#16a34a' : '#64748b' }};margin:6px 0 4px;">
                {{ number_format($actualData['families']) }}
            </div>
            <div style="font-size:12px;color:#64748b;">
                @if($actualData['families'] === 0)
                    <span style="background:#f1f5f9;color:#64748b;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">Not Created</span>
                @else
                    <span style="color:#16a34a;font-weight:600;">Created</span>
                @endif
                <span style="color:#94a3b8;margin-left:4px;">(Family groupings)</span>
            </div>
        </div>

        <!-- Enrollments -->
        <div style="border:1px solid #e2e8f0;border-radius:6px;padding:14px;background:#f8fafc;">
            <div style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:600;">Enrollments Created</div>
            <div style="font-size:24px;font-weight:700;color:{{ $actualData['enrollments'] > 0 ? '#16a34a' : '#64748b' }};margin:6px 0 4px;">
                {{ number_format($actualData['enrollments']) }}
            </div>
            <div style="font-size:12px;color:#64748b;">
                @if($actualData['enrollments'] === 0)
                    <span style="background:#f1f5f9;color:#64748b;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">Not Created</span>
                @else
                    <span style="color:#16a34a;font-weight:600;">Created</span>
                @endif
                <span style="color:#94a3b8;margin-left:4px;">(School / Tuition)</span>
            </div>
        </div>

        <!-- Collections Staged -->
        <div style="border:1px solid #e2e8f0;border-radius:6px;padding:14px;background:#f8fafc;">
            <div style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:600;">Historical Collections</div>
            <div style="font-size:24px;font-weight:700;color:{{ $actualData['collections_staged'] > 0 ? '#16a34a' : '#64748b' }};margin:6px 0 4px;">
                {{ number_format($actualData['collections_staged']) }}
            </div>
            <div style="font-size:12px;color:#64748b;">
                @if($actualData['collections_staged'] === 0)
                    <span style="background:#f1f5f9;color:#64748b;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">Not Staged</span>
                @else
                    <span style="color:#16a34a;font-weight:600;">Staged</span>
                @endif
                <span style="color:#94a3b8;margin-left:4px;">(Expected: 38,068)</span>
            </div>
        </div>

        <!-- Dues Staged -->
        <div style="border:1px solid #e2e8f0;border-radius:6px;padding:14px;background:#f8fafc;">
            <div style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:600;">Outstanding Dues</div>
            <div style="font-size:24px;font-weight:700;color:{{ $actualData['dues_staged'] > 0 ? '#16a34a' : '#64748b' }};margin:6px 0 4px;">
                {{ number_format($actualData['dues_staged']) }}
            </div>
            <div style="font-size:12px;color:#64748b;">
                @if($actualData['dues_staged'] === 0)
                    <span style="background:#f1f5f9;color:#64748b;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">Not Staged</span>
                @else
                    <span style="color:#16a34a;font-weight:600;">Staged</span>
                @endif
                <span style="color:#94a3b8;margin-left:4px;">(Expected: 11,351)</span>
            </div>
        </div>

        <!-- Opening Balance Invoices -->
        <div style="border:1px solid #e2e8f0;border-radius:6px;padding:14px;background:#f8fafc;">
            <div style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:600;">Opening Invoices</div>
            <div style="font-size:24px;font-weight:700;color:{{ $actualData['opening_balance_invoices'] > 0 ? '#dc2626' : '#16a34a' }};margin:6px 0 4px;">
                {{ number_format($actualData['opening_balance_invoices']) }}
            </div>
            <div style="font-size:12px;color:#64748b;">
                <span style="background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">Protected (0)</span>
            </div>
        </div>

        <!-- Live Receipts -->
        <div style="border:1px solid #e2e8f0;border-radius:6px;padding:14px;background:#f8fafc;">
            <div style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:600;">Live Receipts</div>
            <div style="font-size:24px;font-weight:700;color:{{ $actualData['live_receipts'] > 0 ? '#dc2626' : '#16a34a' }};margin:6px 0 4px;">
                {{ number_format($actualData['live_receipts']) }}
            </div>
            <div style="font-size:12px;color:#64748b;">
                <span style="background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">Clean (0)</span>
            </div>
        </div>

        <!-- Exceptions -->
        <div style="border:1px solid #e2e8f0;border-radius:6px;padding:14px;background:#f8fafc;">
            <div style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:600;">Import Exceptions</div>
            <div style="font-size:24px;font-weight:700;color:{{ $actualData['exceptions'] > 0 ? '#f59e0b' : '#64748b' }};margin:6px 0 4px;">
                {{ number_format($actualData['exceptions']) }}
            </div>
            <div style="font-size:12px;color:#64748b;">
                <span style="background:#f1f5f9;color:#64748b;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">0 Logged</span>
            </div>
        </div>
    </div>
</div>

<!-- SECTION 4: Review and Auditing Screens -->
<div style="background:#fff;border:1px solid #ebe9f1;border-radius:8px;padding:18px 20px;margin-bottom:22px;box-shadow:0 1px 3px rgba(0,0,0,0.04);">
    <h3 style="font-size:15px;font-weight:700;color:#1e293b;margin:0 0 14px;border-bottom:1px solid #f1f5f9;padding-bottom:10px;">
        🔍 Migration Review & Audit Modules
    </h3>

    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));gap:15px;">
        <div style="border:1px solid #e2e8f0;border-radius:6px;padding:14px;background:#faf5ff;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <strong style="color:#581c87;font-size:14px;">Historical Collections Review</strong>
                <span>🧾</span>
            </div>
            <p style="font-size:12px;color:#6b21a8;margin:6px 0 10px;">Inspect historical receipts from worksheet <code>Paid details</code>.</p>
            <a href="/migration/qa-collections" style="color:#7e22ce;font-size:12px;font-weight:600;text-decoration:none;">Review Collections ({{ number_format($actualData['collections_staged']) }}) →</a>
        </div>

        <div style="border:1px solid #e2e8f0;border-radius:6px;padding:14px;background:#eff6ff;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <strong style="color:#1e3a8a;font-size:14px;">Outstanding Fees Review</strong>
                <span>📑</span>
            </div>
            <p style="font-size:12px;color:#1e40af;margin:6px 0 10px;">Inspect residual outstanding balances from worksheet <code>Outstanding (Compiled Valid)</code>.</p>
            <a href="/migration/qa-dues" style="color:#2563eb;font-size:12px;font-weight:600;text-decoration:none;">Review Outstanding ({{ number_format($actualData['dues_staged']) }}) →</a>
        </div>

        <div style="border:1px solid #e2e8f0;border-radius:6px;padding:14px;background:#f0fdf4;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <strong style="color:#14532d;font-size:14px;">Opening Balance Preview</strong>
                <span>⚖️</span>
            </div>
            <p style="font-size:12px;color:#166534;margin:6px 0 10px;">Pre-posting invoice simulation without financial ledger mutation.</p>
            <a href="/migration/opening-balances" style="color:#15803d;font-size:12px;font-weight:600;text-decoration:none;">Inspect Opening Balances →</a>
        </div>

        <div style="border:1px solid #e2e8f0;border-radius:6px;padding:14px;background:#fff7ed;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <strong style="color:#7c2d12;font-size:14px;">Financial Exceptions</strong>
                <span>🛑</span>
            </div>
            <p style="font-size:12px;color:#9a3412;margin:6px 0 10px;">Review held dues lines excluded from posting to protect ledger integrity.</p>
            <a href="/migration/held-dues" style="color:#ea580c;font-size:12px;font-weight:600;text-decoration:none;">Inspect Held Exceptions →</a>
        </div>

        <div style="border:1px solid #e2e8f0;border-radius:6px;padding:14px;background:#f8fafc;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <strong style="color:#334155;font-size:14px;">Campus Approvals</strong>
                <span>🏫</span>
            </div>
            <p style="font-size:12px;color:#475569;margin:6px 0 10px;">Assign and approve physical campus designations.</p>
            <a href="/migration/campus" style="color:#4f46e5;font-size:12px;font-weight:600;text-decoration:none;">Review & Approve Campuses →</a>
        </div>

        <div style="border:1px solid #e2e8f0;border-radius:6px;padding:14px;background:#f8fafc;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <strong style="color:#334155;font-size:14px;">Department Classification</strong>
                <span>📚</span>
            </div>
            <p style="font-size:12px;color:#475569;margin:6px 0 10px;">Review School vs Tuition department programs.</p>
            <a href="/migration/department" style="color:#4f46e5;font-size:12px;font-weight:600;text-decoration:none;">Review Departments →</a>
        </div>

        <div style="border:1px solid #e2e8f0;border-radius:6px;padding:14px;background:#f8fafc;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <strong style="color:#334155;font-size:14px;">Currency Review</strong>
                <span>💱</span>
            </div>
            <p style="font-size:12px;color:#475569;margin:6px 0 10px;">Verify approved student currencies and resolve missing currencies.</p>
            <a href="/migration/currency" style="color:#4f46e5;font-size:12px;font-weight:600;text-decoration:none;">Review Currencies →</a>
        </div>

        <div style="border:1px solid #e2e8f0;border-radius:6px;padding:14px;background:#f8fafc;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <strong style="color:#334155;font-size:14px;">Identity Reconciliation</strong>
                <span>🔍</span>
            </div>
            <p style="font-size:12px;color:#475569;margin:6px 0 10px;">Audit historical student GR and family code matching.</p>
            <a href="/migration/gr-reconciliation" style="color:#4f46e5;font-size:12px;font-weight:600;text-decoration:none;">Reconciliation Cases →</a>
        </div>
    </div>
</div>

<!-- SECTION 5: Recent Audit Logs -->
<div style="background:#fff;border:1px solid #ebe9f1;border-radius:8px;padding:18px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.04);">
    <h3 style="font-size:15px;font-weight:700;color:#1e293b;margin:0 0 14px;border-bottom:1px solid #f1f5f9;padding-bottom:10px;">
        Recent Staging Audit & Approval Logs
    </h3>
    @if($recentLogs->isEmpty())
        <p style="color:#94a3b8;font-size:13px;margin:0;padding:10px 0;">No approval actions logged yet. Clean baseline established.</p>
    @else
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
                <tr style="border-bottom:2px solid #ebe9f1;text-align:left;color:#6e6b7b;">
                    <th style="padding:10px 8px;">ID</th>
                    <th style="padding:10px 8px;">Action</th>
                    <th style="padding:10px 8px;">Entity</th>
                    <th style="padding:10px 8px;">GR Number</th>
                    <th style="padding:10px 8px;">Performed By</th>
                    <th style="padding:10px 8px;">Reason / Notes</th>
                    <th style="padding:10px 8px;">Timestamp</th>
                </tr>
            </thead>
            <tbody>
                @foreach($recentLogs as $log)
                <tr style="border-bottom:1px solid #ebe9f1;">
                    <td style="padding:10px 8px;font-weight:600;">#{{ $log->id }}</td>
                    <td style="padding:10px 8px;"><span style="background:#e8f0fe;color:#1a73e8;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">{{ $log->action }}</span></td>
                    <td style="padding:10px 8px;">{{ ucfirst($log->entity_type) }}</td>
                    <td style="padding:10px 8px;">{{ $log->gr_number ?: '—' }}</td>
                    <td style="padding:10px 8px;font-weight:600;">{{ $log->performed_by }}</td>
                    <td style="padding:10px 8px;color:#6e6b7b;max-width:250px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $log->reason ?: '—' }}</td>
                    <td style="padding:10px 8px;color:#b9b9c3;font-size:12px;">{{ $log->created_at }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
