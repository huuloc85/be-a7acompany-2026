<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\ScheduleDetail;
use Illuminate\Http\Request;

class ReferenceDataController extends Controller
{
    public function requestEmployee(string $id)
    {
        return response()->json(Employee::query()
            ->select(['id', 'name', 'gender', 'role_id'])
            ->with('role:id,role_name')->findOrFail($id));
    }

    public function attendanceSchedule(Request $request)
    {
        $data = $request->validate(['filter.date' => ['required', 'date_format:Y-m-d']]);
        $rows = ScheduleDetail::query()
            ->select(['employee_id', 'date', 'hnhc'])
            ->with('employees:id,name')
            ->whereDate('date', $data['filter']['date'])
            ->whereIn('hnhc', ['D', 'TC', 'N', 'LN'])
            ->get();

        return response()->json(['data' => $rows, 'total' => $rows->count()]);
    }
}
