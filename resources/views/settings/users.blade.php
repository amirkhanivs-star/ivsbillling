@extends('layouts.app')
@section('title', 'Users & Access Control')

@section('content')
<style>
/* Modern Users Management Styles */
.user-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 14px;
    margin-bottom: 20px;
}
.user-kpi-card {
    background: #ffffff;
    border: 1px solid #e9e7ef;
    border-radius: 8px;
    padding: 16px 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 2px 10px rgba(34, 41, 47, 0.04);
}
.user-kpi-title {
    font-size: 12px;
    font-weight: 600;
    color: #6e6b7b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.user-kpi-val {
    font-size: 24px;
    font-weight: 700;
    color: var(--purple, #7367f0);
    margin-top: 4px;
}
.user-kpi-icon {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: #f0edff;
    color: var(--purple, #7367f0);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
}

/* Filter bar */
.user-filter-bar {
    background: #ffffff;
    border: 1px solid #ebe9f1;
    border-radius: 8px;
    padding: 14px 18px;
    margin-bottom: 18px;
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: flex-end;
}
.filter-group {
    flex: 1;
    min-width: 150px;
}
.filter-group label {
    font-size: 11px;
    font-weight: 600;
    color: #5e5873;
    margin-bottom: 4px;
    display: block;
}

/* User badges */
.role-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    text-transform: capitalize;
    white-space: nowrap;
}
.badge-super-admin { background: #7367f0; color: #fff; }
.badge-finance-admin { background: #00cfe8; color: #fff; }
.badge-cashier { background: #28c76f; color: #fff; }
.badge-dept-manager { background: #ff9f43; color: #fff; }
.badge-auditor { background: #4b4b4b; color: #fff; }

.status-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}
.status-active { background: #dcfce7; color: #15803d; }
.status-inactive { background: #fee2e2; color: #b91c1c; }

.user-avatar-circle {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: #f3f2f7;
    color: #7367f0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 13px;
    margin-right: 8px;
}

/* Modern native Dialog styles */
dialog.modern-dialog {
    border: 0;
    border-radius: 10px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.22);
    width: min(580px, 94vw);
    padding: 0;
    background: #ffffff;
    color: #5e5873;
    overflow: hidden;
}
dialog.modern-dialog::backdrop {
    background-color: rgba(34, 41, 47, 0.55);
    backdrop-filter: blur(3px);
}
.dialog-header {
    background: #f8f7fa;
    border-bottom: 1px solid #ebe9f1;
    padding: 16px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.dialog-header h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    color: #5e5873;
}
.dialog-body {
    padding: 20px;
    max-height: calc(85vh - 130px);
    overflow-y: auto;
}
.dialog-footer {
    background: #f8f7fa;
    border-top: 1px solid #ebe9f1;
    padding: 12px 20px;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}
.close-dialog-btn {
    background: none;
    border: none;
    font-size: 20px;
    color: #b9b9c3;
    cursor: pointer;
    line-height: 1;
}
.close-dialog-btn:hover { color: #5e5873; }

.form-row-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
    margin-bottom: 14px;
}
.input-with-button {
    display: flex;
    gap: 6px;
}
.role-info-box {
    background: #f0edff;
    border-left: 3px solid #7367f0;
    padding: 9px 12px;
    border-radius: 0 4px 4px 0;
    font-size: 12px;
    color: #5e50ee;
    margin-top: 6px;
    margin-bottom: 14px;
    line-height: 1.4;
}
.action-btn-group {
    display: inline-flex;
    gap: 6px;
    align-items: center;
}
</style>

<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:18px;">
        <div>
            <h2 style="margin:0; font-size:20px; font-weight:600; color:#4c4868;">Users & Staff Management</h2>
            <p class="muted" style="margin:4px 0 0 0; font-size:13px;">
                Create and manage staff accounts, cashier branch assignments, and access roles across IQRA Campuses.
            </p>
        </div>
        <div>
            <button type="button" class="btn purple" id="btnOpenCreateUser" style="padding:9px 18px; font-weight:600; display:inline-flex; align-items:center; gap:6px;">
                <span style="font-size:16px; line-height:1;">+</span> Create New User
            </button>
        </div>
    </div>

    <!-- Summary KPI strip -->
    <div class="user-kpi-grid">
        <div class="user-kpi-card">
            <div>
                <div class="user-kpi-title">Total Users</div>
                <div class="user-kpi-val">{{ number_format($allStats->total_users ?? 0) }}</div>
            </div>
            <div class="user-kpi-icon">👥</div>
        </div>
        <div class="user-kpi-card">
            <div>
                <div class="user-kpi-title">Active Accounts</div>
                <div class="user-kpi-val" style="color:#28c76f;">{{ number_format($allStats->active_users ?? 0) }}</div>
            </div>
            <div class="user-kpi-icon" style="background:#e8faf0; color:#28c76f;">✓</div>
        </div>
        <div class="user-kpi-card">
            <div>
                <div class="user-kpi-title">Administrators</div>
                <div class="user-kpi-val" style="color:#7367f0;">{{ number_format($allStats->admin_users ?? 0) }}</div>
            </div>
            <div class="user-kpi-icon" style="background:#f0edff; color:#7367f0;">🛡️</div>
        </div>
        <div class="user-kpi-card">
            <div>
                <div class="user-kpi-title">Cashiers & Branch Staff</div>
                <div class="user-kpi-val" style="color:#00cfe8;">{{ number_format($allStats->cashier_users ?? 0) }}</div>
            </div>
            <div class="user-kpi-icon" style="background:#e0f9fc; color:#00cfe8;">🏢</div>
        </div>
    </div>

    <!-- Filter & Search Bar -->
    <form method="GET" action="{{ route('settings.users.index') }}" class="user-filter-bar">
        <div class="filter-group" style="flex:2; min-width:200px;">
            <label for="filter_search">Search Staff</label>
            <input type="text" id="filter_search" name="search" class="input" placeholder="Search by name or email..." value="{{ $search }}" autocomplete="off">
        </div>
        <div class="filter-group">
            <label for="filter_role">Role</label>
            <select id="filter_role" name="role" class="input">
                <option value="">All Roles</option>
                @foreach($roles as $rKey => $rMeta)
                    <option value="{{ $rKey }}" {{ $roleFilter === $rKey ? 'selected' : '' }}>{{ $rMeta['title'] }}</option>
                @endforeach
            </select>
        </div>
        <div class="filter-group">
            <label for="filter_campus">Campus</label>
            <select id="filter_campus" name="campus_id" class="input">
                <option value="">All Campuses</option>
                @foreach($campuses as $c)
                    <option value="{{ $c->id }}" {{ (string)$campusFilter === (string)$c->id ? 'selected' : '' }}>{{ $c->name }} ({{ $c->code }})</option>
                @endforeach
            </select>
        </div>
        <div class="filter-group" style="min-width:120px;">
            <label for="filter_status">Status</label>
            <select id="filter_status" name="status" class="input">
                <option value="">All</option>
                <option value="1" {{ $statusFilter === '1' ? 'selected' : '' }}>Active</option>
                <option value="0" {{ $statusFilter === '0' ? 'selected' : '' }}>Inactive</option>
            </select>
        </div>
        <div style="display:flex; gap:6px; align-items:flex-end;">
            <button type="submit" class="btn purple">Filter</button>
            @if($search || $roleFilter || $campusFilter !== null && $campusFilter !== '' || $statusFilter !== null && $statusFilter !== '')
                <a href="{{ route('settings.users.index') }}" class="btn gray" title="Clear Filters">Clear</a>
            @endif
        </div>
    </form>

    <!-- Users Table -->
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th style="min-width:200px;">Staff Member</th>
                    <th>Role</th>
                    <th>Campus Scope</th>
                    <th>Department Scope</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th style="text-align:right; min-width:170px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($users as $user)
                    <tr>
                        <td>
                            <div style="display:flex; align-items:center;">
                                <div class="user-avatar-circle">
                                    {{ strtoupper(substr($user->name, 0, 1)) }}
                                </div>
                                <div>
                                    <div style="font-weight:600; color:#5e5873;">{{ $user->name }}</div>
                                    <div class="muted small">{{ $user->email }}</div>
                                </div>
                            </div>
                        </td>
                        <td>
                            @php
                                $rInfo = $roles[$user->role] ?? ['title' => ucfirst(str_replace('_',' ',$user->role)), 'badge' => 'badge-cashier'];
                            @endphp
                            <span class="role-badge {{ $rInfo['badge'] }}">
                                {{ $rInfo['title'] }}
                            </span>
                        </td>
                        <td>
                            @if($user->campus_name)
                                <span style="font-weight:500; color:#5e5873;">{{ $user->campus_name }}</span>
                                <span class="muted small">({{ $user->campus_code }})</span>
                            @else
                                <span class="chip" style="background:#f0edff; color:#7367f0;">Global / All</span>
                            @endif
                        </td>
                        <td>
                            @if($user->department_name)
                                <span style="font-weight:500; color:#5e5873;">{{ $user->department_name }}</span>
                            @else
                                <span class="muted small">—</span>
                            @endif
                        </td>
                        <td>
                            @if($user->is_active)
                                <span class="status-chip status-active">● Active</span>
                            @else
                                <span class="status-chip status-inactive">● Inactive</span>
                            @endif
                        </td>
                        <td class="muted small">
                            {{ $user->created_at ? \Carbon\Carbon::parse($user->created_at)->format('d M Y') : '—' }}
                        </td>
                        <td style="text-align:right;">
                            <div class="action-btn-group">
                                <button type="button" class="btn small gray btn-edit-user"
                                    data-id="{{ $user->id }}"
                                    data-name="{{ $user->name }}"
                                    data-email="{{ $user->email }}"
                                    data-role="{{ $user->role }}"
                                    data-campus-id="{{ $user->campus_id }}"
                                    data-department-id="{{ $user->department_id }}"
                                    data-is-active="{{ $user->is_active ? 1 : 0 }}"
                                    title="Edit User">
                                    ✎ Edit
                                </button>

                                <button type="button" class="btn small gray btn-reset-password"
                                    data-id="{{ $user->id }}"
                                    data-name="{{ $user->name }}"
                                    title="Reset Password">
                                    🔑
                                </button>

                                @if($user->id !== auth()->id())
                                    <form method="POST" action="{{ route('settings.users.toggle', $user->id) }}" style="display:inline; margin:0;" onsubmit="return confirm('Are you sure you want to {{ $user->is_active ? 'deactivate' : 'activate' }} {{ addslashes($user->name) }}?');">
                                        @csrf
                                        <button type="submit" class="btn small {{ $user->is_active ? 'red' : 'green' }}" title="{{ $user->is_active ? 'Deactivate Account' : 'Activate Account' }}">
                                            {{ $user->is_active ? 'Deactivate' : 'Activate' }}
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" style="text-align:center; padding:30px;" class="muted">
                            No users found matching your search and filter criteria.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div style="margin-top:16px;">
        {{ $users->links() }}
    </div>
</div>

<!-- ================= CREATE USER MODAL ================= -->
<dialog id="createUserModal" closedby="any" class="modern-dialog" aria-labelledby="createUserTitle">
    <div class="dialog-header">
        <h3 id="createUserTitle">Create New User Account</h3>
        <button type="button" class="close-dialog-btn" onclick="document.getElementById('createUserModal').close()" aria-label="Close dialog">×</button>
    </div>
    <form method="POST" action="{{ route('settings.users.store') }}">
        @csrf
        <div class="dialog-body">
            <div class="form-row-2">
                <div class="field">
                    <label for="create_name">Full Name <span style="color:#ea5455;">*</span></label>
                    <input type="text" id="create_name" name="name" class="input" required autocomplete="name" placeholder="e.g. Brother Ahmad">
                </div>
                <div class="field">
                    <label for="create_email">Email Address <span style="color:#ea5455;">*</span></label>
                    <input type="email" id="create_email" name="email" class="input" required inputmode="email" autocomplete="email" placeholder="staff@iqra.edu.pk">
                </div>
            </div>

            <div class="field" style="margin-bottom:14px;">
                <label for="create_password">Initial Password <span style="color:#ea5455;">*</span></label>
                <div class="input-with-button">
                    <input type="text" id="create_password" name="password" class="input" required minlength="8" autocomplete="new-password" placeholder="At least 8 characters">
                    <button type="button" class="btn gray" id="btnGeneratePassword" style="white-space:nowrap;" title="Generate secure temporary password">⚡ Generate</button>
                </div>
                <small class="muted" style="display:block; margin-top:4px;">Minimum 8 characters. Share this password securely with the staff member.</small>
            </div>

            <div class="field" style="margin-bottom:6px;">
                <label for="create_role">Role & Access Level <span style="color:#ea5455;">*</span></label>
                <select id="create_role" name="role" class="input" required>
                    @foreach($roles as $rKey => $rMeta)
                        <option value="{{ $rKey }}" {{ $rKey === 'cashier' ? 'selected' : '' }}>{{ $rMeta['title'] }}</option>
                    @endforeach
                </select>
            </div>

            <div id="create_role_desc" class="role-info-box">
                {{ $roles['cashier']['description'] }}
            </div>

            <div class="form-row-2">
                <div class="field">
                    <label for="create_campus_id">Campus Scope <span id="create_campus_req" style="color:#ea5455;">*</span></label>
                    <select id="create_campus_id" name="campus_id" class="input">
                        <option value="">-- Select Campus --</option>
                        @foreach($campuses as $c)
                            <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->code }})</option>
                        @endforeach
                    </select>
                    <small id="create_campus_help" class="muted" style="display:block; margin-top:4px;">Branch anchor for cashier transactions.</small>
                </div>
                <div class="field">
                    <label for="create_department_id">Department Scope (Optional)</label>
                    <select id="create_department_id" name="department_id" class="input">
                        <option value="">All Departments / None</option>
                        @foreach($departments as $d)
                            <option value="{{ $d->id }}">{{ $d->name }} ({{ $d->code }})</option>
                        @endforeach
                    </select>
                    <small class="muted" style="display:block; margin-top:4px;">Applies to Department Managers.</small>
                </div>
            </div>

            <div class="field" style="margin-top:10px;">
                <label style="display:inline-flex; align-items:center; gap:8px; cursor:pointer; font-weight:500;">
                    <input type="checkbox" name="is_active" value="1" checked style="width:16px; height:16px;">
                    <span>Active Account (User can log in immediately)</span>
                </label>
            </div>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn gray" onclick="document.getElementById('createUserModal').close()">Cancel</button>
            <button type="submit" class="btn purple wide">+ Create Account</button>
        </div>
    </form>
</dialog>

<!-- ================= EDIT USER MODAL ================= -->
<dialog id="editUserModal" closedby="any" class="modern-dialog" aria-labelledby="editUserTitle">
    <div class="dialog-header">
        <h3 id="editUserTitle">Edit User Account</h3>
        <button type="button" class="close-dialog-btn" onclick="document.getElementById('editUserModal').close()" aria-label="Close dialog">×</button>
    </div>
    <form id="editUserForm" method="POST" action="">
        @csrf
        @method('PUT')
        <div class="dialog-body">
            <div class="form-row-2">
                <div class="field">
                    <label for="edit_name">Full Name <span style="color:#ea5455;">*</span></label>
                    <input type="text" id="edit_name" name="name" class="input" required autocomplete="name">
                </div>
                <div class="field">
                    <label for="edit_email">Email Address <span style="color:#ea5455;">*</span></label>
                    <input type="email" id="edit_email" name="email" class="input" required inputmode="email" autocomplete="email">
                </div>
            </div>

            <div class="field" style="margin-bottom:14px;">
                <label for="edit_password">New Password (Leave blank to keep existing)</label>
                <div class="input-with-button">
                    <input type="text" id="edit_password" name="password" class="input" minlength="8" autocomplete="new-password" placeholder="Leave empty to keep current password">
                    <button type="button" class="btn gray" id="btnGenerateEditPassword" style="white-space:nowrap;" title="Generate new password">⚡ Generate</button>
                </div>
            </div>

            <div class="field" style="margin-bottom:6px;">
                <label for="edit_role">Role & Access Level <span style="color:#ea5455;">*</span></label>
                <select id="edit_role" name="role" class="input" required>
                    @foreach($roles as $rKey => $rMeta)
                        <option value="{{ $rKey }}">{{ $rMeta['title'] }}</option>
                    @endforeach
                </select>
            </div>

            <div id="edit_role_desc" class="role-info-box">
                {{ $roles['cashier']['description'] }}
            </div>

            <div class="form-row-2">
                <div class="field">
                    <label for="edit_campus_id">Campus Scope</label>
                    <select id="edit_campus_id" name="campus_id" class="input">
                        <option value="">-- Global / Unrestricted --</option>
                        @foreach($campuses as $c)
                            <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->code }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="edit_department_id">Department Scope</label>
                    <select id="edit_department_id" name="department_id" class="input">
                        <option value="">All Departments / None</option>
                        @foreach($departments as $d)
                            <option value="{{ $d->id }}">{{ $d->name }} ({{ $d->code }})</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="field" style="margin-top:10px;">
                <label style="display:inline-flex; align-items:center; gap:8px; cursor:pointer; font-weight:500;">
                    <input type="checkbox" id="edit_is_active" name="is_active" value="1" style="width:16px; height:16px;">
                    <span>Active Account (User can log in)</span>
                </label>
            </div>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn gray" onclick="document.getElementById('editUserModal').close()">Cancel</button>
            <button type="submit" class="btn purple wide">Save Changes</button>
        </div>
    </form>
</dialog>

<!-- ================= RESET PASSWORD MODAL ================= -->
<dialog id="resetPasswordModal" closedby="any" class="modern-dialog" aria-labelledby="resetPasswordTitle">
    <div class="dialog-header">
        <h3 id="resetPasswordTitle">Reset Password</h3>
        <button type="button" class="close-dialog-btn" onclick="document.getElementById('resetPasswordModal').close()" aria-label="Close dialog">×</button>
    </div>
    <form id="resetPasswordForm" method="POST" action="">
        @csrf
        <div class="dialog-body">
            <p id="resetPasswordTargetName" style="margin:0 0 15px 0; font-weight:600; color:#5e5873;"></p>
            <div class="field" style="margin-bottom:14px;">
                <label for="modal_reset_password">New Password <span style="color:#ea5455;">*</span></label>
                <div class="input-with-button">
                    <input type="text" id="modal_reset_password" name="password" class="input" required minlength="8" autocomplete="new-password" placeholder="At least 8 characters">
                    <button type="button" class="btn gray" id="btnGenerateResetPassword" style="white-space:nowrap;" title="Generate strong password">⚡ Generate</button>
                </div>
            </div>
            <p class="muted small" style="margin:0;">Setting a new password will immediately take effect for the staff member's next login.</p>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn gray" onclick="document.getElementById('resetPasswordModal').close()">Cancel</button>
            <button type="submit" class="btn purple">Update Password</button>
        </div>
    </form>
</dialog>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const roleDescriptions = @json(array_map(fn($r) => $r['description'], $roles));

    // Helper: random secure password generator
    function generateSecurePassword(length = 12) {
        const charset = "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%&*";
        let password = "";
        const cryptoObj = window.crypto || window.msCrypto;
        if (cryptoObj && cryptoObj.getRandomValues) {
            const values = new Uint32Array(length);
            cryptoObj.getRandomValues(values);
            for (let i = 0; i < length; i++) {
                password += charset[values[i] % charset.length];
            }
        } else {
            for (let i = 0; i < length; i++) {
                password += charset.charAt(Math.floor(Math.random() * charset.length));
            }
        }
        return password;
    }

    // Modal fallback for light-dismiss (clicking backdrop) for browsers lacking closedBy
    document.querySelectorAll('dialog.modern-dialog').forEach(function (dialog) {
        if (!('closedBy' in HTMLDialogElement.prototype)) {
            dialog.addEventListener('click', function (event) {
                if (event.target !== dialog) return;
                const rect = dialog.getBoundingClientRect();
                const isDialogContent = (
                    rect.top <= event.clientY &&
                    event.clientY <= rect.top + rect.height &&
                    rect.left <= event.clientX &&
                    event.clientX <= rect.left + rect.width
                );
                if (!isDialogContent) {
                    dialog.close();
                }
            });
        }
    });

    // Create User Modal Trigger
    const btnOpenCreate = document.getElementById('btnOpenCreateUser');
    const createUserModal = document.getElementById('createUserModal');
    if (btnOpenCreate && createUserModal) {
        btnOpenCreate.addEventListener('click', function () {
            // Auto-populate password with a strong initial suggestion
            const passInput = document.getElementById('create_password');
            if (passInput && !passInput.value) {
                passInput.value = generateSecurePassword(12);
            }
            createUserModal.showModal();
        });
    }

    // Password generator triggers
    const btnGenPass = document.getElementById('btnGeneratePassword');
    if (btnGenPass) {
        btnGenPass.addEventListener('click', function () {
            document.getElementById('create_password').value = generateSecurePassword(12);
        });
    }

    const btnGenEditPass = document.getElementById('btnGenerateEditPassword');
    if (btnGenEditPass) {
        btnGenEditPass.addEventListener('click', function () {
            document.getElementById('edit_password').value = generateSecurePassword(12);
        });
    }

    const btnGenResetPass = document.getElementById('btnGenerateResetPassword');
    if (btnGenResetPass) {
        btnGenResetPass.addEventListener('click', function () {
            document.getElementById('modal_reset_password').value = generateSecurePassword(12);
        });
    }

    // Dynamic role description updates
    function setupRoleListener(selectId, descId, campusReqId, campusHelpId) {
        const select = document.getElementById(selectId);
        const desc = document.getElementById(descId);
        if (!select || !desc) return;

        select.addEventListener('change', function () {
            const val = this.value;
            desc.textContent = roleDescriptions[val] || '';

            if (campusReqId) {
                const reqEl = document.getElementById(campusReqId);
                const helpEl = document.getElementById(campusHelpId);
                const isBranchRole = ['cashier', 'department_manager', 'auditor'].includes(val);
                if (reqEl) reqEl.style.display = isBranchRole ? 'inline' : 'none';
                if (helpEl) {
                    helpEl.textContent = isBranchRole
                        ? 'Required branch anchor for this role.'
                        : 'Unrestricted across campuses for administrators.';
                }
            }
        });
    }
    setupRoleListener('create_role', 'create_role_desc', 'create_campus_req', 'create_campus_help');
    setupRoleListener('edit_role', 'edit_role_desc', null, null);

    // Edit user triggers
    const editModal = document.getElementById('editUserModal');
    const editForm = document.getElementById('editUserForm');
    document.querySelectorAll('.btn-edit-user').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const userId = this.getAttribute('data-id');
            const name = this.getAttribute('data-name');
            const email = this.getAttribute('data-email');
            const role = this.getAttribute('data-role');
            const campusId = this.getAttribute('data-campus-id');
            const deptId = this.getAttribute('data-department-id');
            const isActive = this.getAttribute('data-is-active') === '1';

            editForm.action = '/settings/users/' + encodeURIComponent(userId);
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_email').value = email;
            document.getElementById('edit_password').value = '';
            document.getElementById('edit_role').value = role;
            document.getElementById('edit_campus_id').value = campusId || '';
            document.getElementById('edit_department_id').value = deptId || '';
            document.getElementById('edit_is_active').checked = isActive;

            const editDesc = document.getElementById('edit_role_desc');
            if (editDesc) editDesc.textContent = roleDescriptions[role] || '';

            editModal.showModal();
        });
    });

    // Reset password triggers
    const resetModal = document.getElementById('resetPasswordModal');
    const resetForm = document.getElementById('resetPasswordForm');
    document.querySelectorAll('.btn-reset-password').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const userId = this.getAttribute('data-id');
            const name = this.getAttribute('data-name');

            resetForm.action = '/settings/users/' + encodeURIComponent(userId) + '/reset-password';
            document.getElementById('resetPasswordTargetName').textContent = 'Resetting password for: ' + name;
            document.getElementById('modal_reset_password').value = generateSecurePassword(12);

            resetModal.showModal();
        });
    });
});
</script>
@endsection
