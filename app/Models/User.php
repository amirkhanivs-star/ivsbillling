<?php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable {
    use Notifiable;
    protected $fillable = ['name', 'email', 'password', 'role', 'campus_id', 'department_id', 'is_active'];
    protected $hidden = ['password', 'remember_token'];
    protected function casts(): array { return ['password' => 'hashed', 'is_active' => 'boolean']; }

    public function canAccessDepartment(int $departmentId, int $campusId): bool {
        if (!$this->is_active) return false;
        if (in_array($this->role, ['super_admin', 'finance_admin'], true)) return true;
        // Non-global roles must be anchored to a campus. Missing scope must not
        // silently grant access to all campuses.
        if (!$this->campus_id || (int) $this->campus_id !== $campusId) return false;
        if (!in_array($this->role, ['cashier', 'department_manager', 'auditor'], true)) return false;
        if ($this->role !== 'cashier' && !$this->department_id) return false;
        return !$this->department_id || (int) $this->department_id === $departmentId;
    }
}
