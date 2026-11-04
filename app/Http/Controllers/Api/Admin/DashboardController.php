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
        $today = Carbon::today();
        $startOfMonth = $today->copy()->startOfMonth();
        $endOfMonth = $today->copy()->endOfMonth();

        $totalEmployee = Employee::whereNotIn('role_id', [15, 16, 17])
            ->whereNull('deleted_at')
            ->count();
        $totalRole = Role::whereNotIn('id', [15, 16, 17])->count();

        $topUsers = [];
        if ($request->boolean('include_top_users', true)) {
            $topUsers = LoginHistory::select('employee_name')
                ->selectRaw('COUNT(*) as login_count')
                ->groupBy('employee_name')
                ->orderByDesc('login_count')
                ->limit(10)
                ->get();
        }

        $totalSalary = SalaryManager::count();
        $totalCelender = Celender::count();
        $totalProduct = Product::count();
        $totalHistory = LoginHistory::where('date', $today->toDateString())->count();
        $totalPlan = ProductionPlan::count();
        $totalRecord = AttendanceRecord::count();
        $totalCheckEmployee = CheckEmployee::where('date', $today->toDateString())->count();
        $totalRequestForms = RequestForm::count();
        $totalFeedback = (int) Feedback::count();

        $salaryManagers = SalaryManager::orderBy('id', 'DESC')->limit(10)->get();
        $celenders = Celender::orderBy('id', 'DESC')->limit(10)->get();

        return response()->json([
            'totalEmployee' => $totalEmployee,
            'totalRole' => $totalRole,
            'totalSalary' => $totalSalary,
            'totalCelender' => $totalCelender,
            'totalProduct' => $totalProduct,
            'totalHistory' => $totalHistory,
            'totalPlan' => $totalPlan,
            'totalRecord' => $totalRecord,
            'totalCheckEmployee' => $totalCheckEmployee,
            'totalRequestForms' => $totalRequestForms, // ✅ Thêm tổng số Request Forms
            // Keep both camelCase and snake_case for frontend compatibility.
            'totalFeedback' => $totalFeedback,
            'total_feedback' => $totalFeedback,
            'topUsers' => $topUsers,
            'salaryManagers' => $salaryManagers,
            'celenders' => $celenders,
            'dateRange' => [
                'today' => $today->toDateString(),
                'startOfMonth' => $startOfMonth->toDateString(),
                'endOfMonth' => $endOfMonth->toDateString(),
            ],
        ]);
    }
}
