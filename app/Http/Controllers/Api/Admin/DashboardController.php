<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\AttendanceRecord;
use App\Models\Celender;
use App\Models\CheckEmployee;
use App\Models\Employee;
use App\Models\Feedback;
use App\Models\LoginHistory;
use App\Models\Product;
use App\Models\ProductionPlan;
use App\Models\RequestForm;
use App\Models\Role;
use App\Models\SalaryManager;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DashboardController extends BaseController
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user?->role) {
            return response()->json(['message' => 'Bạn chưa được gán vai trò.'], 403);
        }
        $hasFullBusinessAccess = $user->hasFullBusinessAccess();
        $keys = $user->getEffectivePermissions()->pluck('key')->all();
        $can = fn (array $required) => $hasFullBusinessAccess || count(array_intersect($keys, $required)) > 0;
        $today = Carbon::today();
        $startOfMonth = $today->copy()->startOfMonth();
        $endOfMonth = $today->copy()->endOfMonth();
        // Query only data the user may see, not a full dashboard later hidden by FE.
        $fields = [
            'totalEmployee' => [['view_total_employees'], fn () => Employee::whereNotIn('role_id', [15, 16, 17])->count()],
            'totalRole' => [['view_total_positions'], fn () => Role::whereNotIn('id', [15, 16, 17])->count()],
            'totalSalary' => [['view_total_salary'], fn () => SalaryManager::count()],
            'totalCelender' => [['view_total_schedule'], fn () => Celender::count()],
            'totalProduct' => [['view_total_products'], fn () => Product::count()],
            'totalHistory' => [['view_total_history'], fn () => LoginHistory::where('date', $today->toDateString())->count()],
            'totalPlan' => [['view_production_plan'], fn () => ProductionPlan::count()],
            'totalRecord' => [['view_attendance_history', 'view_attendance_calculation'], fn () => AttendanceRecord::count()],
            'totalCheckEmployee' => [['view_today_employees'], fn () => CheckEmployee::where('date', $today->toDateString())->count()],
            'totalRequestForms' => [['view_request_forms'], fn () => RequestForm::count()],
            'totalFeedback' => [['feed_back', 'view_feedbacks'], fn () => Feedback::count()],
            'salaryManagers' => [['view_chart_salary'], fn () => SalaryManager::orderByDesc('id')->limit(10)->get()],
            'celenders' => [['view_total_schedule'], fn () => Celender::orderByDesc('id')->limit(10)->get()],
        ];
        $data = [];
        foreach ($fields as $field => [$required, $query]) {
            if ($can($required)) {
                $data[$field] = $query();
            }
        }
        if (! $data) {
            return response()->json(['message' => 'Bạn không có quyền xem số liệu này.'], 403);
        }
        if (array_key_exists('totalFeedback', $data)) {
            $data['total_feedback'] = $data['totalFeedback'];
        }
        if ($can(['view_total_history']) && $request->boolean('include_top_users', true)) {
            $data['topUsers'] = LoginHistory::select('employee_name')->selectRaw('COUNT(*) as login_count')
                ->groupBy('employee_name')->orderByDesc('login_count')->limit(10)->get();
        }
        $data['dateRange'] = ['today' => $today->toDateString(), 'startOfMonth' => $startOfMonth->toDateString(), 'endOfMonth' => $endOfMonth->toDateString()];

        return response()->json($data);
    }
}
