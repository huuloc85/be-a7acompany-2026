<?php

namespace App\Http\Middleware\API;

use Closure;
use Illuminate\Http\Request;

class CheckScheduleAccess
{
    public function handle(Request $request, Closure $next, string $permission = 'view_schedule')
    {
        $user = auth()->user();
        $roleName = mb_strtolower(trim($user?->role?->role_name ?? ''));
        $isLeader = $user?->role && (
            in_array((string) $user->role_id, ['14', '18', '19', '23', '24', '25'], true)
            || in_array($roleName, ['tổ trưởng sản xuất', 'tổ phó sản xuất', 'tổ trưởng ngoại quan', 'tổ trưởng qa', 'tổ trưởng qc', 'tổ trưởng kho', 'tổ trưởng khuôn'], true)
        );

        // Giữ lịch chỉ đọc cho tổ trưởng, kể cả khi có view_schedule để dùng API hoạt động.
        if ($isLeader && (! $request->isMethodSafe() || $user->deniedPermissions()->where('key', $permission)->exists())) {
            return response()->json(['message' => 'Bạn không có quyền truy cập chức năng này.'], 403);
        }

        $keys = $request->isMethodSafe() ? [$permission, 'view_team_schedule'] : [$permission];

        return app(CheckPermissionKey::class)->handle($request, $next, ...$keys);
    }
}
