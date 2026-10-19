@extends('layouts.app')
@section('title', 'Migration Review Dashboard')
@section('content')
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
    <div>
        <h2 style="font-size:22px;font-weight:700;color:#2c2c2c;margin:0;">Phase 3A: Migration & Reconciliation Review</h2>
        <p style="color:#6e6b7b;font-size:13px;margin:4px 0 0;">Review student identity, sibling groups, campus/department classifications, and historical GRs.</p>
    </div>
    <div style="display:flex;gap:10px;">
        <span style="background:#e8fadf;color:#28c76f;padding:6px 12px;border-radius:20px;font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:6px;">
            <span style="width:8px;height:8px;background:#28c76f;border-radius:50%;"></span> Isolated Database: iqra_billing_clean
        </span>
        <span style="background:#fceaea;color:#ea5455;padding:6px 12px;border-radius:20px;font-size:12px;font-weight:600;">
            Ledger Postings: 0 (Protected)
        </span>
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
