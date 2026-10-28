<?php

namespace App\Http\Middleware;

use App\Services\EmployeePreviewService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EmployeePreview
{
    public function handle(Request $request, Closure $next)
    {
        $secret = $request->bearerToken() ?? '';
        $service = app(EmployeePreviewService::class);
        $context = $service->resolve($secret);
        if (! $context) {
            return response()->json(['code' => 'EMPLOYEE_PREVIEW_EXPIRED', 'message' => 'Phiên xem đã hết hạn hoặc bị thu hồi.'], 401);
        }
        if ($request->isMethod('POST') && $request->path() === 'api/employee-preview/stop') {
            $service->stop($secret, $context);

            return response()->json(['message' => 'Đã kết thúc phiên xem.']);
        }
        if ($request->isMethod('POST') && $request->path() === 'api/auth/check') {
            return response()->json($service->payload($context['employee'], $context['session']));
        }
        if (! $this->allowsRead($request)) {
            return response()->json(['code' => 'EMPLOYEE_PREVIEW_READ_ONLY', 'message' => 'Phiên xem chỉ cho phép xem lịch làm việc, chấm công, bảng lương và đơn yêu cầu. Không được thay đổi dữ liệu.'], 403);
        }
        if (preg_match('~^api/employee/request-forms/([0-9]+)$~D', $request->path(), $matches)) {
            $employeeId = (string) $context['employee']->id;
            $ownsRequest = \App\Models\RequestForm::whereKey($matches[1])
                ->where(function ($query) use ($employeeId) {
                    $query->where('employee_id', $employeeId)->orWhere('authorized_employee_id', $employeeId);
                })->exists();
            if (! $ownsRequest) {
                return response()->json(['message' => 'Bạn không có quyền xem đơn này.'], 403);
            }
        }
        $guard = Auth::guard('sanctum');
        $defaultGuard = Auth::getDefaultDriver();
        $resolver = $request->getUserResolver();
        $employee = $context['employee']->withAccessToken($context['parent']);
        $guard->setUser($employee);
        Auth::shouldUse('sanctum');
        $request->setUserResolver(fn () => $employee);
        $request->attributes->set('employee_preview', true);
        Log::info('Employee preview read', ['actor_id' => $context['actor']->id, 'employee_id' => $employee->id, 'path' => $request->path()]);
        try {
            return $next($request);
        } finally {
            $guard->forgetUser();
            Auth::shouldUse($defaultGuard);
            $request->setUserResolver($resolver);
            $request->attributes->remove('employee_preview');
        }
    }

    public function allowsRead(Request $request): bool
    {
        if (! $request->isMethod('GET')) {
            return false;
        }

        // Explicit allowlist: even GET routes outside these reviewed screens are denied.
        return (bool) preg_match('~^api/employee/(attendances(?:/history)?|salaries(?:/[0-9]+)?|schedules(?:/(?:details|[0-9]+))?|request-forms(?:/(?:types|signature-fields|authorizable-employees|authorized-to-me|as-supervisor(?:/[0-9]+)?|[0-9]+))?)$~D', $request->path());
    }
}
