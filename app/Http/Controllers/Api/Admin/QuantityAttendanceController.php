<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\DailyQuantity;
use App\Models\Employee;
use App\Models\ScheduleDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class QuantityAttendanceController extends BaseController
{
    private const ALLOWED_CALENDAR_CATEGORY_IDS = [2, 3, 5, 6];

    private const QUANTITY_STATUS = 2;

    public function employees()
    {
        $employees = Employee::query()
            ->join('calendar_categories', 'employees.calendar_category_id', '=', 'calendar_categories.id')
            ->join('daily_quantities', 'daily_quantities.employee_id', '=', 'employees.id')
            ->whereNull('daily_quantities.deleted_at')
            ->where('daily_quantities.status', self::QUANTITY_STATUS)
            ->whereIn('employees.calendar_category_id', self::ALLOWED_CALENDAR_CATEGORY_IDS)
            ->select([
                'employees.id',
                'employees.name',
                'employees.calendar_category_id',
                'calendar_categories.name as calendar_category_name',
            ])
            ->selectRaw('MIN(daily_quantities.date) as oldest_date')
            ->selectRaw('MAX(daily_quantities.date) as newest_date')
            ->selectRaw('COUNT(DISTINCT daily_quantities.date) as attendance_days')
            ->groupBy(
                'employees.id',
                'employees.name',
                'employees.calendar_category_id',
                'calendar_categories.name'
            )
            ->orderBy('employees.name')
            ->get();

        return response()->json(['data' => $employees]);
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|string|exists:employees,id',
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
        ]);

        $employee = Employee::query()
            ->with('calendarCategory:id,name')
            ->whereKey($validated['employee_id'])
            ->whereIn('calendar_category_id', self::ALLOWED_CALENDAR_CATEGORY_IDS)
            ->first();

        if (! $employee) {
            throw ValidationException::withMessages([
                'employee_id' => 'Nhân viên phải thuộc danh mục lịch 2, 3, 5 hoặc 6.',
            ]);
        }

        $baseQuery = DailyQuantity::query()
            ->whereNull('deleted_at')
            ->where('employee_id', $employee->id)
            ->where('status', self::QUANTITY_STATUS);

        $oldestDate = (clone $baseQuery)->min('date');
        $newestDate = (clone $baseQuery)->max('date');
        $fromDate = Carbon::parse($validated['from_date'] ?? $oldestDate ?? Carbon::today())->startOfDay();
        $toDate = Carbon::parse($validated['to_date'] ?? Carbon::today())->startOfDay();

        if ($fromDate->gt($toDate)) {
            throw ValidationException::withMessages([
                'from_date' => 'Từ ngày phải nhỏ hơn hoặc bằng đến ngày.',
            ]);
        }

        if ($toDate->gt(Carbon::today())) {
            throw ValidationException::withMessages([
                'to_date' => 'Đến ngày không được lớn hơn ngày hiện tại.',
            ]);
        }

        $quantities = (clone $baseQuery)
            ->with('product:id,code,name')
            ->whereBetween('date', [$fromDate->toDateString(), $toDate->toDateString()])
            ->orderBy('date')
            ->orderBy('created_at')
            ->get([
                'id',
                'product_id',
                'employee_id',
                'quantity',
                'shift',
                'date',
                'created_at',
            ]);

        $schedulesByDate = ScheduleDetail::query()
            ->with('schedules:id,title')
            ->where('employee_id', $employee->id)
            ->whereBetween('date', [$fromDate->toDateString(), $toDate->toDateString()])
            ->orderByDesc('schedule_id')
            ->get(['date', 'schedule_id', 'hnhc'])
            ->groupBy(fn ($schedule) => Carbon::parse($schedule->date)->toDateString())
            ->map(fn ($schedules) => $schedules->first());

        $data = $quantities
            ->groupBy(fn ($quantity) => Carbon::parse($quantity->date)->toDateString())
            ->map(function ($items, $date) use ($schedulesByDate) {
                $schedule = $schedulesByDate->get($date);

                return [
                    'date' => $date,
                    'schedule_code' => $schedule?->hnhc,
                    'schedule_label' => $this->scheduleLabel($schedule?->hnhc),
                    'schedule_id' => $schedule?->schedule_id,
                    'schedule_title' => $schedule?->schedules?->title,
                    'quantity_shifts' => $items->pluck('shift')->filter()->unique()->sort()->values(),
                    'record_count' => $items->count(),
                    'product_count' => $items->pluck('product_id')->unique()->count(),
                    'total_quantity' => $items->sum(fn ($item) => (int) $item->quantity),
                    'quantities' => $items->map(fn ($item) => [
                        'id' => $item->id,
                        'product_id' => $item->product_id,
                        'product_code' => $item->product?->code,
                        'product_name' => $item->product?->name,
                        'quantity' => (int) $item->quantity,
                        'shift' => $item->shift,
                        'created_at' => $item->created_at,
                    ])->values(),
                ];
            })
            ->values();

        return response()->json([
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'calendar_category_id' => $employee->calendar_category_id,
                'calendar_category_name' => $employee->calendarCategory?->name,
            ],
            'filters' => [
                'status' => self::QUANTITY_STATUS,
                'from_date' => $fromDate->toDateString(),
                'to_date' => $toDate->toDateString(),
            ],
            'summary' => [
                'oldest_date' => $oldestDate,
                'newest_date' => $newestDate,
                'attendance_days' => $data->count(),
                'record_count' => $quantities->count(),
                'total_quantity' => $quantities->sum(fn ($item) => (int) $item->quantity),
            ],
            'data' => $data,
        ]);
    }

    private function scheduleLabel(?string $code): string
    {
        return match ($code) {
            'N' => 'Ca ngày',
            'D' => 'Ca đêm',
            'LN' => 'Tăng cường ca ngày',
            'TC' => 'Tăng cường ca đêm',
            'NN' => 'Nghỉ nửa ngày',
            'X' => 'Nghỉ',
            default => 'Chưa có lịch',
        };
    }
}
