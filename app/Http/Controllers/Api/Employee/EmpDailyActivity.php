<?php

namespace App\Http\Controllers\Api\Employee;

use App\Helpers\HandleError;
use App\Helpers\LogActivity;
use App\Http\Controllers\Controller;
use App\Models\CheckEmployee;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\QueryBuilder;

class EmpDailyActivity extends Controller
{
    public function index(Request $request)
    {
        try {
            $allowedStatuses = [1, 2, 6];
            $filterDate = $request->input('filter.date', Carbon::today()->format('Y-m-d'));
            $user = Auth::user();

            $employeeQuery = QueryBuilder::for(CheckEmployee::with('product'))
                ->where('employee_id', $user->id)
                ->where('date', $filterDate)
                ->when(! empty($allowedStatuses), function ($query) use ($allowedStatuses) {
                    return $query->whereIn('status', $allowedStatuses);
                })
                ->allowedFilters(['employee_id', 'date'])
                ->allowedSorts(['employee_id', 'date']);

            $limit = $request->input('limit', 10);
            if (! is_null($limit) && $limit == 0) {
                $limit = $employeeQuery->count();
            }
            $employees = $employeeQuery->paginate($limit);

            CheckEmployee::loadDailyQuantities($employees->getCollection(), $allowedStatuses);

            LogActivity::logViewActivity(auth()->user(), 'Xem Hoạt Động Hàng Ngày', 'Nhân viên xem danh sách hoạt động hàng ngày');

            return response()->json($employees, 200);
        } catch (\Throwable $th) {
            return HandleError::handle($th);
        }
    }

    public function show($id)
    {
        try {
            $checkEmployee = CheckEmployee::with(['employee', 'product'])->where('employee_id', Auth::id())->findOrFail($id);

            LogActivity::logViewActivity(auth()->user(), 'Xem Chi Tiết Hoạt Động', 'Nhân viên xem chi tiết hoạt động theo ID');

            return response()->json($checkEmployee, 200);
        } catch (\Throwable $th) {
            return HandleError::handle($th);
        }
    }

    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $request->validate([
                'product_id' => 'required|exists:products,id',
                'status' => 'nullable|in:1,2,6',
            ]);

            $checkEmployee = CheckEmployee::where('employee_id', Auth::id())->findOrFail($id);
            $checkEmployee->update($request->only('product_id', 'status'));

            DB::commit();

            LogActivity::logViewActivity(auth()->user(), 'Cập Nhật Hoạt Động', 'Nhân viên cập nhật thông tin hoạt động');

            return response()->json(['message' => 'Updated successfully', 'data' => $checkEmployee], 200);
        } catch (\Throwable $th) {
            DB::rollBack();

            return HandleError::handle($th);
        }
    }

    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $checkEmployee = CheckEmployee::where('employee_id', Auth::id())->findOrFail($id);
            $checkEmployee->delete();

            DB::commit();

            LogActivity::logViewActivity(auth()->user(), 'Xóa Hoạt Động', 'Nhân viên xóa hoạt động');

            return response()->json([
                'message' => 'Deleted successfully',
                'data' => $checkEmployee,
            ], 200);
        } catch (\Throwable $th) {
            DB::rollBack();

            return HandleError::handle($th);
        }
    }
}
