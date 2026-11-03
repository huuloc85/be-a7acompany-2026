<?php

namespace App\Services;

use App\Models\Role;
use Illuminate\Auth\Access\AuthorizationException;

class EmployeePrivilegeGuard
{
    public static function check($roleId): void
    {
        $role = Role::findOrFail($roleId);
        $privileged = in_array((int) $roleId, [1, 15, 21, 22], true)
            || in_array(strtolower(trim($role->role_name)), ['super admin', 'admin', 'co admin'], true);

        if ($privileged && strtolower(trim(auth()->user()?->role?->role_name ?? '')) !== 'super admin') {
            throw new AuthorizationException('Chỉ Super Admin được quản lý tài khoản quản trị.');
        }
    }
}
