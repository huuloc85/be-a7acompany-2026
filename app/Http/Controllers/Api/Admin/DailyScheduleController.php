<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\HandleError;
use App\Models\CheckEmployee;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\QueryBuilder;

class DailyScheduleController extends BaseController
{
    public function index(Request $request)
    {
        try {
            $allowedStatuses = [1, 2, 6];
            $filterDate = $request->input('filter.date', Carbon::today()->format('Y-m-d'));

            $employeeQuery = QueryBuilder::for(CheckEmployee::with(['employee', 'product']))
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

            return response()->json($employees, 200);
        } catch (\Throwable $th) {
            return HandleError::handle($th);
        }
    }

    public function show($id)
    {
        try {
            $checkEmployee = CheckEmployee::with(['employee', 'product'])->findOrFail($id);

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

            $checkEmployee = CheckEmployee::findOrFail($id);
            $checkEmployee->update($request->only('product_id', 'status'));

            DB::commit();

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
            $checkEmployee = CheckEmployee::findOrFail($id);
            $checkEmployee->delete();

            DB::commit();

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
