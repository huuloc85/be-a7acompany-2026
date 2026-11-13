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
        $leaderRoles = [
            'tổ trưởng sản xuất',
            'tổ phó sản xuất',
            'tổ trưởng ngoại quan',
            'tổ trưởng qa',
            'tổ trưởng qc',
            'tổ trưởng kho',
            'tổ trưởng khuôn',
        ];
        $isLeader = $user?->role && (
            in_array($roleName, $leaderRoles, true) ||
            in_array((string) $user->role_id, ['14', '18', '19', '23', '24', '25'], true)
        );

        $isIndividualViewer = (string) ($user?->id ?? '') === '23080130';

        if ($isLeader || ($isIndividualViewer && $request->isMethodSafe())) {
            // Quyền xem bổ sung vẫn giữ quyền chặn riêng của tài khoản.
            if ($request->isMethodSafe() && ! $user->deniedPermissions()->where('key', $permission)->exists()) {
                return $next($request);
            }

            return response()->json(['message' => 'Bạn không có quyền truy cập chức năng này.'], 403);
        }

        return app(CheckPermissionKey::class)->handle($request, $next, $permission);
    }
}
