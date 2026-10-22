@extends('layouts.app')
@section('title', 'Migration Review Dashboard')
@section('content')
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
    <div>
        <h2 style="font-size:22px;font-weight:700;color:#2c2c2c;margin:0;">Migration Review Dashboard</h2>
        <p style="color:#6e6b7b;font-size:13px;margin:4px 0 0;">Historical baseline verification, student identity, campus/department assignments, and staging approval audit.</p>
    </div>
    <div style="display:flex;gap:10px;">
        <span style="background:#e8fadf;color:#28c76f;padding:6px 12px;border-radius:20px;font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:6px;">
            <span style="width:8px;height:8px;background:#28c76f;border-radius:50%;"></span> Historical Baseline: iqra_billing_clean
        </span>
        <span style="background:#fceaea;color:#ea5455;padding:6px 12px;border-radius:20px;font-size:12px;font-weight:600;">
            Ledger Postings: 0 (Protected)
        </span>
    </div>
</div>

<div style="background:#eef2ff;border:1px solid #c7d2fe;border-radius:8px;padding:14px 18px;margin-bottom:22px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
    <div>
        <div style="display:flex;align-items:center;gap:8px;">
            <span style="font-weight:700;color:#3730a3;font-size:14px;">📁 Historical Staging Baseline: October 19, 2026 (4,340 Students)</span>
            <span style="background:#c7d2fe;color:#312e81;font-size:11px;font-weight:600;padding:2px 8px;border-radius:12px;">READ-ONLY BASELINE</span>
        </div>
        <p style="margin:4px 0 0;font-size:12px;color:#4338ca;">
            The metrics below represent the approved October 19 staging export (<code>iqra_billing_clean</code>). All legacy CSV files are archived in <code>storage/app/private/archive_20261022_legacy_csvs/</code>.
        </p>
    </div>
    <div style="font-size:12px;color:#4338ca;background:#fff;padding:8px 12px;border-radius:6px;border:1px solid #c7d2fe;">
        <div><strong style="color:#1e1b4b;">Active QA Pipeline Version:</strong> <code>{{ $activeImportKey ?? 'v2_reviewed_20261022' }}</code></div>
        <div style="font-size:11px;color:#4b5563;margin-top:2px;">Source: <code>{{ $activeImport['relative_path'] ?? 'storage/app/imports/UPdated school record for portal.xlsx' }}</code></div>
        <div style="font-size:11px;color:#059669;margin-top:2px;">Target: <strong>iqra_billing_qa</strong> (Strictly Isolated)</div>
    </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:15px;margin-bottom:25px;">
    <!-- Student Identity Card -->
    <div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:16px;">
        <div style="display:flex;justify-content:space-between;align-items:center;">
            <span style="color:#b9b9c3;font-size:12px;font-weight:600;text-transform:uppercase;">Students & Families</span>
            <span style="font-size:18px;">👥</span>
        </div>
        <div style="font-size:24px;font-weight:700;color:#5e5873;margin:8px 0 4px;">{{ number_format($metrics['students']['total']) }}</div>
        <div style="font-size:12px;color:#6e6b7b;">
            Verified Families: <strong>{{ number_format($metrics['students']['total'] - $metrics['students']['provisional_families']) }}</strong><br>
            Provisional Families: <strong style="color:#ff9f43;">{{ $metrics['students']['provisional_families'] }}</strong><br>
            Sibling Students: <strong>{{ number_format($metrics['students']['sibling_students']) }}</strong> ({{ $metrics['students']['multi_currency_families'] }} multi-curr)
        </div>
    </div>

    <!-- Campus Approval Card -->
    <div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:16px;">
        <div style="display:flex;justify-content:space-between;align-items:center;">
            <span style="color:#b9b9c3;font-size:12px;font-weight:600;text-transform:uppercase;">Campus Approvals</span>
            <span style="font-size:18px;">🏫</span>
        </div>
        <div style="font-size:24px;font-weight:700;color:#5e5873;margin:8px 0 4px;">{{ number_format($metrics['campus']['approved']) }} / {{ number_format($metrics['campus']['total']) }}</div>
        <div style="font-size:12px;color:#6e6b7b;">
            Pending Approval: <strong style="color:#ff9f43;">{{ number_format($metrics['campus']['pending']) }}</strong><br>
            Status: <span style="color:{{ $metrics['campus']['approved'] > 0 ? '#28c76f' : '#ff9f43' }};font-weight:600;">{{ $metrics['campus']['approved'] > 0 ? 'In Progress' : 'Pending Review' }}</span>
        </div>
        <a href="/migration/campus" style="display:inline-block;margin-top:8px;font-size:12px;color:var(--ms-purple,#7367f0);font-weight:600;text-decoration:none;">Review & Approve →</a>
    </div>

    <!-- Department Approval Card -->
    <div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:16px;">
        <div style="display:flex;justify-content:space-between;align-items:center;">
            <span style="color:#b9b9c3;font-size:12px;font-weight:600;text-transform:uppercase;">Department Class</span>
            <span style="font-size:18px;">📚</span>
        </div>
        <div style="font-size:24px;font-weight:700;color:#5e5873;margin:8px 0 4px;">{{ number_format($metrics['department']['approved']) }} / {{ number_format($metrics['department']['total']) }}</div>
        <div style="font-size:12px;color:#6e6b7b;">
            School: <strong>{{ number_format($metrics['department']['school']) }}</strong> | Tuition: <strong>{{ number_format($metrics['department']['tuition']) }}</strong><br>
            Pending Approval: <strong style="color:#ff9f43;">{{ number_format($metrics['department']['pending']) }}</strong>
        </div>
        <a href="/migration/department" style="display:inline-block;margin-top:8px;font-size:12px;color:var(--ms-purple,#7367f0);font-weight:600;text-decoration:none;">Review & Approve →</a>
    </div>

    <!-- Currency Review Card -->
    <div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:16px;">
        <div style="display:flex;justify-content:space-between;align-items:center;">
            <span style="color:#b9b9c3;font-size:12px;font-weight:600;text-transform:uppercase;">Currency Review</span>
            <span style="font-size:18px;">💱</span>
        </div>
        <div style="font-size:24px;font-weight:700;color:#28c76f;margin:8px 0 4px;">{{ number_format($metrics['currency']['approved']) }} <span style="font-size:14px;color:#6e6b7b;">Approved</span></div>
        <div style="font-size:12px;color:#6e6b7b;">
            Pending Review: <strong style="color:#ea5455;">{{ number_format($metrics['currency']['pending']) }}</strong> (112 no fin, 26 unrec)<br>
            Auto-assigned SAR: <strong style="color:#28c76f;">0 (Prohibited)</strong>
        </div>
        <a href="/migration/currency" style="display:inline-block;margin-top:8px;font-size:12px;color:var(--ms-purple,#7367f0);font-weight:600;text-decoration:none;">Review Currencies →</a>
    </div>

    <!-- Historical GR Card -->
    <div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:16px;">
        <div style="display:flex;justify-content:space-between;align-items:center;">
            <span style="color:#b9b9c3;font-size:12px;font-weight:600;text-transform:uppercase;">Historical GR Cases</span>
            <span style="font-size:18px;">🔍</span>
        </div>
        <div style="font-size:24px;font-weight:700;color:#5e5873;margin:8px 0 4px;">{{ number_format($metrics['reconciliation']['total']) }}</div>
        <div style="font-size:12px;color:#6e6b7b;">
            Unknown Dues: <strong>{{ number_format($metrics['reconciliation']['dues_unknown']) }}</strong><br>
            Unknown Payments: <strong>{{ number_format($metrics['reconciliation']['payments_unknown']) }}</strong> ({{ number_format($metrics['reconciliation']['unlinked_payments']) }} unlinked)<br>
            Exact Name Matches: <strong style="color:#28c76f;">{{ number_format($metrics['reconciliation']['exact_name_matches']) }}</strong>
        </div>
        <a href="/migration/gr-reconciliation" style="display:inline-block;margin-top:8px;font-size:12px;color:var(--ms-purple,#7367f0);font-weight:600;text-decoration:none;">Review Historical GRs →</a>
    </div>

    <!-- Exceptions Card -->
    <div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:16px;">
        <div style="display:flex;justify-content:space-between;align-items:center;">
            <span style="color:#b9b9c3;font-size:12px;font-weight:600;text-transform:uppercase;">Staging Exceptions</span>
            <span style="font-size:18px;">⚠️</span>
        </div>
        <div style="font-size:24px;font-weight:700;color:#ff9f43;margin:8px 0 4px;">{{ number_format($metrics['exceptions']['total']) }}</div>
        <div style="font-size:12px;color:#6e6b7b;">
            Captured Findings: <strong>{{ number_format($metrics['exceptions']['total']) }}</strong><br>
            Audited Logs: <strong>{{ number_format($metrics['audit_logs']['total']) }}</strong>
        </div>
        <div style="display:flex;gap:10px;margin-top:8px;">
            <a href="/migration/exceptions" style="font-size:12px;color:var(--ms-purple,#7367f0);font-weight:600;text-decoration:none;">Inspect →</a>
            <a href="/migration/exceptions/export" style="font-size:12px;color:#28c76f;font-weight:600;text-decoration:none;">Export CSV ⤓</a>
        </div>
    </div>
</div>

<!-- October 22 Reviewed QA Financial Staging (Read-Only) -->
<div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:20px;margin-bottom:25px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
        <div>
            <h3 style="font-size:16px;font-weight:700;color:#2c2c2c;margin:0;">October 22 Reviewed QA Financial Staging (Read-Only)</h3>
            <p style="color:#6e6b7b;font-size:12px;margin:2px 0 0;">Isolated staging tables in <code>iqra_billing_qa</code>. Zero financial ledger postings created.</p>
        </div>
        <span style="background:#e8fadf;color:#28c76f;padding:4px 10px;border-radius:12px;font-size:11px;font-weight:600;">Phase 3C.1 Staged</span>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:15px;">
        <div style="border:1px solid #e2e8f0;border-radius:6px;padding:14px;background:#f8fafc;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <strong style="font-size:14px;color:#1e293b;">03_COLLECTION_PRESENT_ONLY</strong>
                <span style="font-size:16px;">🧾</span>
            </div>
            <div style="font-size:20px;font-weight:700;color:#334155;margin:8px 0 4px;">37,976 <span style="font-size:12px;color:#64748b;font-weight:normal;">rows</span></div>
            <div style="font-size:12px;color:#64748b;margin-bottom:10px;">
                Historical receipt details with PDF page/slip references.<br>
                Unresolved Currencies: <strong style="color:#f59e0b;">8 rows</strong> (flagged).
            </div>
            <a href="/migration/qa-collections" style="color:#7367f0;font-size:12px;font-weight:600;text-decoration:none;">Search & Review Collections →</a>
        </div>

        <div style="border:1px solid #e2e8f0;border-radius:6px;padding:14px;background:#f8fafc;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <strong style="font-size:14px;color:#1e293b;">05_OUTSTANDING_PRESENT_ONLY</strong>
                <span style="font-size:16px;">📑</span>
            </div>
            <div style="font-size:20px;font-weight:700;color:#334155;margin:8px 0 4px;">11,473 <span style="font-size:12px;color:#64748b;font-weight:normal;">rows</span></div>
            <div style="font-size:12px;color:#64748b;margin-bottom:10px;">
                Historical residual dues balances by fee particular.<br>
                Unresolved Currencies: <strong style="color:#f59e0b;">70 rows</strong> (flagged).
            </div>
            <a href="/migration/qa-dues" style="color:#7367f0;font-size:12px;font-weight:600;text-decoration:none;">Search & Review Dues →</a>
        </div>

        <div style="border:1px solid #c7d2fe;border-radius:6px;padding:14px;background:#eef2ff;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <strong style="font-size:14px;color:#3730a3;">Opening Balances Preview</strong>
                <span style="font-size:16px;">⚖️</span>
            </div>
            <div style="font-size:20px;font-weight:700;color:#312e81;margin:8px 0 4px;">7,692 <span style="font-size:12px;color:#4338ca;font-weight:normal;">invoices preview</span></div>
            <div style="font-size:12px;color:#4338ca;margin-bottom:10px;">
                Aggregated from 10,683 eligible dues (790 held).<br>
                Strict ledger posting lock: <strong style="color:#dc2626;">STOPPED (Awaiting Approval)</strong>.
            </div>
            <a href="/migration/opening-balances" style="display:inline-block;background:#4f46e5;color:#fff;padding:4px 10px;border-radius:4px;font-size:12px;font-weight:600;text-decoration:none;">Inspect Opening Balances →</a>
        </div>

        <div style="border:1px solid #fed7aa;border-radius:6px;padding:14px;background:#fff7ed;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <strong style="font-size:14px;color:#c2410c;">Held Dues Exceptions</strong>
                <span style="font-size:16px;">🛑</span>
            </div>
            <div style="font-size:20px;font-weight:700;color:#9a3412;margin:8px 0 4px;">790 <span style="font-size:12px;color:#c2410c;font-weight:normal;">held dues lines</span></div>
            <div style="font-size:12px;color:#9a3412;margin-bottom:10px;">
                Excluded from posting: 391 student hold, 329 unknown period, 70 unresolved currency.<br>
                Requires staff review prior to manual resolution.
            </div>
            <div style="display:flex;gap:10px;">
                <a href="/migration/held-dues" style="display:inline-block;background:#ea580c;color:#fff;padding:4px 10px;border-radius:4px;font-size:12px;font-weight:600;text-decoration:none;">Review 790 Lines →</a>
                <a href="/migration/held-dues/export" style="font-size:12px;color:#ea580c;font-weight:600;text-decoration:none;align-self:center;">Export CSV ⤓</a>
            </div>
        </div>
    </div>
</div>

<!-- Recent Approval History -->
<div style="background:#fff;border:1px solid #ebe9f1;border-radius:6px;padding:20px;">
    <h3 style="font-size:16px;font-weight:700;color:#2c2c2c;margin:0 0 15px;">Recent Staging Audit & Approval Logs</h3>
    @if($recentLogs->isEmpty())
        <p style="color:#b9b9c3;font-size:13px;margin:0;">No approval actions logged yet.</p>
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
