<?php

namespace App\Support;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;

final class RequestFormAccess
{
    public static function supervisors(): Builder
    {
        $key = RbacPermissionCatalog::SUPERVISOR_PERMISSION;

        return Employee::query()
            ->whereHas('role', fn ($q) => $q->whereNotIn('role_name', ['admin', 'co admin', 'super admin']))
            ->where(fn ($q) => $q->whereHas('role.permissions', fn ($p) => $p->where('key', $key))
                ->orWhereHas('grantedPermissions', fn ($p) => $p->where('key', $key)))
            ->whereDoesntHave('deniedPermissions', fn ($p) => $p->where('key', $key));
    }
}
