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
        $allowedEmployeeIds = ['25060400', '22120700', '22120900', '22120300', '22122800', '22121800'];

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
