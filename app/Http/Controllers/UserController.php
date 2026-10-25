<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserController
{
    /**
     * Ensure only super_admin or finance_admin can manage users.
     */
    private function authorizeAdmin(): void
    {
        $role = auth()->user()?->role;
        abort_unless($role === 'super_admin', 403, 'Unauthorized access: Only Super Administrator can access user management.');
    }

    /**
     * Display a listing of system users with filter options.
     */
    public function index(Request $request)
    {
        $this->authorizeAdmin();

        $search = trim((string) $request->input('search', ''));
        $roleFilter = trim((string) $request->input('role', ''));
        $campusFilter = $request->input('campus_id');
        $statusFilter = $request->input('status');

        $query = DB::table('users as u')
            ->leftJoin('campuses as c', 'c.id', '=', 'u.campus_id')
            ->leftJoin('departments as d', 'd.id', '=', 'u.department_id')
            ->select([
                'u.id',
                'u.name',
                'u.email',
                'u.role',
                'u.campus_id',
                'u.department_id',
                'u.is_active',
                'u.created_at',
                'u.updated_at',
                'c.name as campus_name',
                'c.code as campus_code',
                'd.name as department_name',
                'd.code as department_code',
            ]);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('u.name', 'like', "%{$search}%")
                  ->orWhere('u.email', 'like', "%{$search}%");
            });
        }

        if ($roleFilter !== '') {
            $query->where('u.role', $roleFilter);
        }

        if (!empty($campusFilter)) {
            $query->where('u.campus_id', (int) $campusFilter);
        }

        if ($statusFilter !== null && $statusFilter !== '') {
            $query->where('u.is_active', (bool) (int) $statusFilter);
        }

        $users = $query->orderBy('u.role')
            ->orderBy('u.name')
            ->paginate(25)
            ->withQueryString();

        // Metrics for summary KPI bar
        $allStats = DB::table('users')->selectRaw("
            COUNT(*) as total_users,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_users,
            SUM(CASE WHEN role IN ('super_admin', 'finance_admin') THEN 1 ELSE 0 END) as admin_users,
            SUM(CASE WHEN role = 'cashier' THEN 1 ELSE 0 END) as cashier_users
        ")->first();

        $campuses = DB::table('campuses')->orderBy('name')->get();
        $departments = DB::table('departments')->orderBy('name')->get();

        $roles = [
            'super_admin' => [
                'title' => 'Super Administrator',
                'description' => 'Unrestricted global access to all branches, fees, settings, and migration.',
                'badge' => 'badge-super-admin',
            ],
            'finance_admin' => [
                'title' => 'Finance Administrator',
                'description' => 'Full access to financial ledgers, fee structures, collections, and reporting.',
                'badge' => 'badge-finance-admin',
            ],
            'cashier' => [
                'title' => 'Campus Cashier',
                'description' => 'Branch-level fee collection, challan receiving, and receipt printing.',
                'badge' => 'badge-cashier',
            ],
            'department_manager' => [
                'title' => 'Department Manager',
                'description' => 'Campus and department-level fee management (School, Quran, or Tuition).',
                'badge' => 'badge-dept-manager',
            ],
            'auditor' => [
                'title' => 'Auditor',
                'description' => 'Read-only access for financial review, reconciliation, and audit logs.',
                'badge' => 'badge-auditor',
            ],
        ];

        return view('settings.users', compact(
            'users',
            'allStats',
            'campuses',
            'departments',
            'roles',
            'search',
            'roleFilter',
            'campusFilter',
            'statusFilter'
        ));
    }

    /**
     * Store a newly created user in the database.
     */
    public function store(Request $request)
    {
        $this->authorizeAdmin();

        $validated = $request->validate([
            'name' => 'required|string|max:190',
            'email' => 'required|email|max:190|unique:users,email',
            'password' => 'required|string|min:8|max:100',
            'role' => 'required|in:super_admin,finance_admin,cashier,department_manager,auditor',
            'campus_id' => 'nullable|integer|exists:campuses,id',
            'department_id' => 'nullable|integer|exists:departments,id',
            'is_active' => 'nullable|boolean',
        ]);

        // Non-global roles require a campus anchor
        if (in_array($validated['role'], ['cashier', 'department_manager', 'auditor'], true) && empty($validated['campus_id'])) {
            return back()->withInput()->withErrors([
                'campus_id' => 'Campus selection is required for branch-scoped roles (Cashier, Department Manager, Auditor).'
            ]);
        }

        // Global roles are unrestricted across campuses
        if (in_array($validated['role'], ['super_admin', 'finance_admin'], true)) {
            $validated['campus_id'] = $validated['campus_id'] ?: null;
            $validated['department_id'] = null;
        }

        $campusId = !empty($validated['campus_id']) ? (int) $validated['campus_id'] : null;
        $departmentId = !empty($validated['department_id']) ? (int) $validated['department_id'] : null;

        $user = User::create([
            'name' => trim($validated['name']),
            'email' => strtolower(trim($validated['email'])),
            'password' => $validated['password'],
            'role' => $validated['role'],
            'campus_id' => $campusId,
            'department_id' => $departmentId,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()->route('settings.users.index')
            ->with('notice', "User account '{$user->name}' ({$user->email}) created successfully with role '{$user->role}'.");
    }

    /**
     * Update an existing user's details.
     */
    public function update(Request $request, int $id)
    {
        $this->authorizeAdmin();

        $user = User::findOrFail($id);

        if ($user->role === 'super_admin' && auth()->user()->role !== 'super_admin') {
            abort(403, 'Only Super Administrators can modify Super Administrator accounts.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:190',
            'email' => ['required', 'email', 'max:190', Rule::unique('users')->ignore($user->id)],
            'role' => 'required|in:super_admin,finance_admin,cashier,department_manager,auditor',
            'campus_id' => 'nullable|integer|exists:campuses,id',
            'department_id' => 'nullable|integer|exists:departments,id',
            'is_active' => 'nullable|boolean',
            'password' => 'nullable|string|min:8|max:100',
        ]);

        // Safety: Ensure at least one active Super Admin always remains
        if ($user->role === 'super_admin' && $validated['role'] !== 'super_admin') {
            $activeSuperAdmins = User::where('role', 'super_admin')
                ->where('is_active', true)
                ->where('id', '!=', $user->id)
                ->count();
            if ($activeSuperAdmins < 1) {
                return back()->withInput()->withErrors([
                    'role' => 'Safety lock: At least one active Super Administrator must remain in the system.'
                ]);
            }
        }

        if (in_array($validated['role'], ['cashier', 'department_manager', 'auditor'], true) && empty($validated['campus_id'])) {
            return back()->withInput()->withErrors([
                'campus_id' => 'Campus selection is required for branch-scoped roles.'
            ]);
        }

        if (in_array($validated['role'], ['super_admin', 'finance_admin'], true)) {
            $validated['campus_id'] = $validated['campus_id'] ?: null;
            $validated['department_id'] = null;
        }

        $campusId = !empty($validated['campus_id']) ? (int) $validated['campus_id'] : null;
        $departmentId = !empty($validated['department_id']) ? (int) $validated['department_id'] : null;

        $updateData = [
            'name' => trim($validated['name']),
            'email' => strtolower(trim($validated['email'])),
            'role' => $validated['role'],
            'campus_id' => $campusId,
            'department_id' => $departmentId,
            'is_active' => $request->boolean('is_active', true),
        ];

        if (!empty($validated['password'])) {
            $updateData['password'] = $validated['password'];
        }

        $user->update($updateData);

        return redirect()->route('settings.users.index')
            ->with('notice', "User account '{$user->name}' updated successfully.");
    }

    /**
     * Toggle a user's active/inactive status.
     */
    public function toggleStatus(int $id)
    {
        $this->authorizeAdmin();

        $user = User::findOrFail($id);

        if ($user->id === auth()->id()) {
            return back()->with('error', 'Safety protection: You cannot deactivate your own logged-in account.');
        }

        if ($user->role === 'super_admin' && $user->is_active) {
            $activeSuperAdmins = User::where('role', 'super_admin')
                ->where('is_active', true)
                ->where('id', '!=', $user->id)
                ->count();
            if ($activeSuperAdmins < 1) {
                return back()->with('error', 'Safety lock: Cannot deactivate the last remaining active Super Administrator.');
            }
        }

        $user->is_active = !$user->is_active;
        $user->save();

        $stateText = $user->is_active ? 'activated' : 'deactivated';
        return back()->with('notice', "User '{$user->name}' has been {$stateText}.");
    }

    /**
     * Reset a user's password.
     */
    public function resetPassword(Request $request, int $id)
    {
        $this->authorizeAdmin();

        $user = User::findOrFail($id);

        if ($user->role === 'super_admin' && auth()->user()->role !== 'super_admin') {
            abort(403, 'Only Super Administrators can reset Super Administrator passwords.');
        }

        $validated = $request->validate([
            'password' => 'required|string|min:8|max:100',
        ]);

        $user->password = $validated['password'];
        $user->save();

        return back()->with('notice', "Password for '{$user->name}' has been reset successfully.");
    }
}
