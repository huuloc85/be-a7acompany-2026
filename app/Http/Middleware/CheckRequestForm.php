<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckRequestForm
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();

        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Danh sách employee_id được phép duyệt đơn
        $allowedEmployeeIds = ['25060406', '22120703', '22120905', '22120304', '22122809', '22121822'];

        // Kiểm tra employee_id
        if (in_array($user->id, $allowedEmployeeIds)) {
            return $next($request);
        }        // Kiểm tra role admin
        if ($user->role) {
            $roleName = strtolower(trim($user->role->role_name));

            if ($roleName == 'admin' || $roleName == 'super admin' || $roleName == 'co admin') {
                return $next($request);
            }
        }

        // Nếu không có quyền
        return response()->json([
            'error' => 'Forbidden',
            'message' => 'Bạn không có quyền duyệt đơn',
        ], 403);
    }
}
