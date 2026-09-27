<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\HandleError;
use App\Helpers\SeptemberAttendanceHours;
use App\Models\AttendanceRecord;
use App\Models\ScheduleDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class AttendanceHistoryController extends BaseController
{
    public function index(Request $request)
    {
        try {
            $records = QueryBuilder::for(AttendanceRecord::class)
                ->allowedFilters([
                    'datetime',
                    'date',
                    'time',
                    'created_at',
                    'updated_at',
                    'employees.id',
                    'employees.name',
                    'employees.calendar_category_id',
                    AllowedFilter::scope('date_between'),
                    AllowedFilter::scope('time_between'),
                    AllowedFilter::scope('datetime_between'),
                ])
                ->defaultSort('-datetime')
                ->allowedSorts([
                    'datetime',
                    'date',
                    'time',
                    'created_at',
                    'updated_at',
                    'employees.id',
                    'employees.name',
                    'employees.calendar_category_id',
                ])
                ->allowedIncludes([
                    'employees',
                ])
                ->paginate($request->input('limit'));

            return response()->json($records);
        } catch (\Throwable $e) {
            return HandleError::handle($e);
        }
    }

    public function show($id)
    {
        try {
            $record = AttendanceRecord::with(['employees'])->findOrFail($id);

            return response()->json($record);
        } catch (\Throwable $e) {
            return HandleError::handle($e);
        }
    }

    public function store(Request $request)
    {
        DB::beginTransaction();
        try {
            $this->validate($request, [
                'employee_code' => 'required|exists:employees,id',
                'datetime' => 'required|date_format:Y-m-d H:i:s',
            ]);

            $request->merge([
                'date' => Carbon::parse($request->input('datetime'))->format('Y-m-d'),
                'time' => Carbon::parse($request->input('datetime'))->format('H:i:s'),
            ]);

            $record = AttendanceRecord::create([
                'employee_code' => $request->input('employee_code'),
                'datetime' => $request->input('datetime'),
                'date' => $request->input('date'),
                'time' => $request->input('time'),
            ]);

            DB::commit();

            return response()->json([
                'message' => 'created successfully',
                'data' => $record,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return HandleError::handle($e);
        }
    }

    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $record = AttendanceRecord::findOrFail($id);
            $this->validate($request, [
                'employee_code' => 'sometimes|exists:employees,id',
                'datetime' => 'required|date_format:Y-m-d H:i:s',
                'date' => 'sometimes|date',
                'time' => 'sometimes|date_format:H:i:s',
            ]);
            $record->update($request->all());

            DB::commit();

            return response()->json([
                'message' => 'updated successfully',
                'data' => $record,
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            return HandleError::handle($e);
        }
    }

    public function delete($id)
    {
        DB::beginTransaction();
        try {
            $record = AttendanceRecord::findOrFail($id);
            $record->delete();

            DB::commit();

            return response()->json([
                'message' => 'Deleted successfully',
                'data' => $record,
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            return HandleError::handle($e);
        }
    }

    public function detail(Request $request)
    {
        try {
            $includeCalculation = $request->boolean('include_calculation', false);

            $query = ScheduleDetail::query()
                ->leftJoin('attendance_records', function ($join) {
                    $join->on('schedule_details.date', '=', 'attendance_records.date')
                        ->on('schedule_details.employee_id', '=', 'attendance_records.employee_code');
                })
                ->rightJoin('employees', function ($join) {
                    $join->on('schedule_details.employee_id', '=', 'employees.id')
                        ->whereNull('employees.deleted_at');
                })
                ->leftJoin('calendar_categories', function ($join) {
                    $join->on('employees.calendar_category_id', '=', 'calendar_categories.id');
                })
                ->select([
                    'schedule_details.employee_id',
                    'employees.name',
                    'schedule_details.schedule_id',
                    'employees.calendar_category_id',
                    'calendar_categories.name as calendar_category_name',
                    'schedule_details.is_wc_clean_men',
                    'schedule_details.is_wc_clean_women',
                    'schedule_details.is_wc_trash',
                    'schedule_details.is_eat_room',
                    'schedule_details.hnhc',
                    'schedule_details.date',
                    'attendance_records.datetime',
                    'attendance_records.time',
                    'employees.company',
                ]);

            $arrayDate = explode(',', $request->input('filter.date_between'));
            $originalStartDate = null;
            $originalEndDate = null;

            if (count($arrayDate) !== 2) {
                $query->whereBetween('schedule_details.date', [
                    Carbon::now()->startOfMonth(),
                    Carbon::now(),
                ]);
            } elseif ($includeCalculation) {
                // Store original date range for final filtering when calculating
                $originalStartDate = Carbon::parse($arrayDate[0]);
                $originalEndDate = Carbon::parse($arrayDate[1]);
            }

            $records = QueryBuilder::for($query)
                ->allowedFilters([
                    'employee_id',
                    'date',
                    'employees.name',
                    'employees.company',
                    AllowedFilter::callback('date_between', function ($query, $value) use ($includeCalculation) {
                        if (is_array($value) && count($value) === 2) {
                            if ($includeCalculation) {
                                // Extend range by 2 days on each side for shift detection
                                $start = Carbon::parse($value[0])->subDays(2);
                                $end = Carbon::parse($value[1])->addDays(2);
                            } else {
                                $start = Carbon::parse($value[0])->subDay();
                                $end = Carbon::parse($value[1]) > Carbon::now() ? Carbon::now()->addDay() : Carbon::parse($value[1])->addDay();
                            }
                            $query->whereBetween('schedule_details.date', [
                                $start,
                                $end,
                            ]);
                        }
                    }),
                    'employees.calendar_category_id',
                ])
                ->defaultSort('date')
                ->allowedSorts([
                    'employee_id',
                    'name',
                    'date',
                    'calendar_category_id',
                    'calendar_category_name',
                ])
                ->get();

            $grouped = $records->groupBy(['employee_id', 'name', 'date']);
            $result = [];
            foreach ($grouped as $employee_id => $byName) {
                foreach ($byName as $name => $byDate) {
                    $datesList = $byDate->keys()->sort()->values();
                    foreach ($byDate as $date => $items) {
                        $dates = $items->filter(function ($item) {
                            return ! empty($item->datetime);
                        })->map(function ($item) {
                            return [
                                'datetime' => $item->datetime,
                                'date' => $item->date,
                                'time' => $item->time,
                            ];
                        })->values();

                        $currentIndex = $datesList->search($date);
                        $yesterday = $currentIndex !== false && $currentIndex > 0 ? $byDate[$datesList[$currentIndex - 1]] : [];
                        $tomorrow = $currentIndex !== false && $currentIndex < $datesList->count() - 1 ? $byDate[$datesList[$currentIndex + 1]] : [];

                        $result[] = [
                            'employee_id' => $employee_id,
                            'name' => $name,
                            'date' => $date,
                            'calendar_category_id' => $items->first()->calendar_category_id,
                            'calendar_category_name' => $items->first()->calendar_category_name,
                            'schedule_id' => $items->first()->schedule_id,
                            'is_wc_clean_men' => $items->first()->is_wc_clean_men,
                            'is_wc_clean_women' => $items->first()->is_wc_clean_women,
                            'is_wc_trash' => $items->first()->is_wc_trash,
                            'is_eat_room' => $items->first()->is_eat_room,
                            'hnhc' => $items->first()->hnhc,
                            'company' => $items->first()->company,
                            'dates' => (function () use ($yesterday, $dates, $tomorrow, $date) {
                                $result = $dates->toArray();

                                if ($yesterday && $yesterday->isNotEmpty()) {
                                    $prevDate = Carbon::parse($date)->copy()->subDay()->format('Y-m-d');
                                    if ($yesterday->first()->date === $prevDate) {
                                        $result = array_merge(
                                            $yesterday->filter(function ($item) {
                                                return ! empty($item->datetime);
                                            })->map(function ($item) {
                                                return [
                                                    'datetime' => $item->datetime,
                                                    'date' => $item->date,
                                                    'time' => $item->time,
                                                ];
                                            })->toArray(),
                                            $result
                                        );
                                    }
                                }

                                if ($tomorrow && $tomorrow->isNotEmpty()) {
                                    $nextDate = Carbon::parse($date)->copy()->addDay()->format('Y-m-d');
                                    if ($tomorrow->first()->date === $nextDate) {
                                        $result = array_merge(
                                            $result,
                                            $tomorrow->filter(function ($item) {
                                                return ! empty($item->datetime);
                                            })->map(function ($item) {
                                                return [
                                                    'datetime' => $item->datetime,
                                                    'date' => $item->date,
                                                    'time' => $item->time,
                                                ];
                                            })->toArray()
                                        );
                                    }
                                }

                                return $result;
                            })(),
                        ];
                    }
                }
            }

            // When include_calculation=1, run calculation logic and filter to original date range
            if ($includeCalculation) {
                // Filter by original date range if provided (return only requested dates)
                if ($originalStartDate && $originalEndDate) {
                    $result = array_filter($result, function ($item) use ($originalStartDate, $originalEndDate) {
                        $date = Carbon::parse($item['date']);

                        return $date->between($originalStartDate, $originalEndDate, true);
                    });
                } elseif ($arrayDate && count($arrayDate) === 2) {
                    $start = Carbon::parse($arrayDate[0])->startOfDay();
                    $end = Carbon::parse($arrayDate[1])->endOfDay();
                    $result = array_filter($result, function ($item) use ($start, $end) {
                        $date = Carbon::parse($item['date']);

                        return $date->between($start, $end) || $date->equalTo($start) || $date->equalTo($end);
                    });
                }

                // Calculate attendance results (time_in, time_out, total_hours, shift, day_type, etc.)
                $result = $this->calculateAttendances($result);
            } else {
                // Original filtering for non-calculation mode
                if ($arrayDate && count($arrayDate) === 2) {
                    $start = Carbon::parse($arrayDate[0])->startOfDay();
                    $end = Carbon::parse($arrayDate[1])->endOfDay();
                    $result = array_filter($result, function ($item) use ($start, $end) {
                        $date = Carbon::parse($item['date']);

                        return $date->between($start, $end) || $date->equalTo($start) || $date->equalTo($end);
                    });
                }
            }

            $page = request()->input('page', 1);
            $perPage = request()->input('limit', 15);
            if ($perPage == 0) {
                $perPage = max(1, count($result));
            }
            $offset = ($page - 1) * $perPage;
            $paginated = array_slice(array_values($result), $offset, $perPage);
            $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
                $paginated,
                count($result),
                $perPage,
                $page,
                ['path' => request()->url(), 'query' => request()->query()]
            );

            return response()->json($paginator);
        } catch (\Throwable $th) {
            return HandleError::handle($th);
        }
    }

    /**
     * Calculate attendance details (time_in, time_out, total_hours, overtime, etc.)
     * Same logic as AttendanceCalculationController::calculateAttendances()
     */
    private function calculateAttendances($data)
    {
        $result = [];
        $attendanceByEmployeeAndDate = [];

        foreach ($data as $attendance) {
            $attendanceByEmployeeAndDate[$attendance['employee_id']][$attendance['date']] = $attendance;
        }

        foreach ($data as $attendance) {
            $employee_id = $attendance['employee_id'];
            $name = $attendance['name'];
            $date = $attendance['date'];
            $hnhc = $attendance['hnhc'];
            $dates = $attendance['dates'];
            $calendar_category_id = $attendance['calendar_category_id'];
            $company = $attendance['company'];

            // Check if there are attendance records for this date
            $todayRecords = array_filter($dates, function ($d) use ($date) {
                return $d['date'] === $date;
            });

            // Skip if no attendance records for this date
            if (count($todayRecords) === 0) {
                continue;
            }

            // Get yesterday's schedule and attendance info
            $yesterday = Carbon::parse($date)->subDay()->format('Y-m-d');
            $yesterdayEntry = $attendanceByEmployeeAndDate[$employee_id][$yesterday] ?? null;

            $shift = 0;
            $isScheduleChange = false;

            // Case 1: Holiday/off day but has attendance records - Schedule change
            if (($hnhc === 'X' || empty($hnhc) || $hnhc === null) && count($todayRecords) > 0) {
                // Check if yesterday was night shift (shift 2)
                $yesterdayWasNightShift = false;
                if ($yesterdayEntry) {
                    $yesterdayHnhc = $yesterdayEntry['hnhc'];
                    $yesterdayWasNightShift = ($yesterdayHnhc === 'D' || $yesterdayHnhc === 'TC');
                }

                // Check if today's records are only early morning records (likely time_out of night shift)
                $onlyEarlyMorningRecords = true;
                $hasAfternoonOrEveningRecords = false;

                foreach ($todayRecords as $record) {
                    $hour = Carbon::parse($record['datetime'])->hour;

                    // If there are any records after 10:00 AM, it's likely a real work day
                    if ($hour >= 10) {
                        $onlyEarlyMorningRecords = false;
                        break;
                    }

                    // Check for afternoon/evening records (indicating real attendance)
                    if ($hour >= 14) {
                        $hasAfternoonOrEveningRecords = true;
                    }
                }

                // If it's an off day (X) and only has early morning records,
                // these are likely time_out from previous night shift
                // Skip this date unless there are clear afternoon/evening records
                if ($onlyEarlyMorningRecords && ! $hasAfternoonOrEveningRecords) {
                    continue;
                }

                // Otherwise, it's a real schedule change
                $isScheduleChange = true;
                // Determine shift based on yesterday's work pattern or time of attendance
                if ($yesterdayEntry) {
                    $yesterdayHnhc = $yesterdayEntry['hnhc'];
                    if ($yesterdayHnhc === 'N' || $yesterdayHnhc === 'LN') {
                        $shift = 1; // Yesterday was day shift, likely day shift today
                    } elseif ($yesterdayHnhc === 'D' || $yesterdayHnhc === 'TC') {
                        $shift = 2; // Yesterday was night shift, likely night shift today
                    } else {
                        // Guess from attendance time
                        $firstRecord = array_values($todayRecords)[0];
                        $recordTime = Carbon::parse($firstRecord['datetime'])->hour;
                        $shift = ($recordTime >= 6 && $recordTime < 18) ? 1 : 2;
                    }
                } else {
                    // No yesterday data, guess from attendance time
                    $firstRecord = array_values($todayRecords)[0];
                    $recordTime = Carbon::parse($firstRecord['datetime'])->hour;
                    $shift = ($recordTime >= 6 && $recordTime < 18) ? 1 : 2;
                }
            }
            // Case 2: Normal work day with attendance
            else {
                if ($hnhc === 'N' || $hnhc === 'LN') {
                    $shift = 1;
                } elseif ($hnhc === 'D' || $hnhc === 'TC') {
                    $shift = 2;
                } else {
                    // If no clear schedule, guess from attendance time
                    $firstRecord = array_values($todayRecords)[0];
                    $recordTime = Carbon::parse($firstRecord['datetime'])->hour;
                    $shift = ($recordTime >= 6 && $recordTime < 18) ? 1 : 2;
                }
            }

            $time_in = '';
            $time_out = '';

            // Calculate time since we have attendance records and a shift
            if ($shift > 0) {
                if ($shift === 1) {
                    // Day shift: use only records from current date
                    $dateEntries = array_filter($dates, function ($d) use ($date) {
                        return $d['date'] === $date;
                    });
                    if (count($dateEntries) > 0) {
                        $time_in = array_reduce($dateEntries, function ($min, $d) {
                            return $min === null || $d['datetime'] < $min ? $d['datetime'] : $min;
                        });

                        // Only set time_out if there are multiple records (complete attendance)
                        if (count($dateEntries) > 1) {
                            $time_out = array_reduce($dateEntries, function ($max, $d) {
                                return $max === null || $d['datetime'] > $max ? $d['datetime'] : $max;
                            });

                            // If time_in and time_out are the same, it means only one record
                            if ($time_in === $time_out) {
                                $time_out = '';
                            }
                        }
                    }
                } elseif ($shift === 2) {
                    // Night shift: time_in from current date (evening), time_out from next date (morning)
                    $tomorrow = Carbon::parse($date)->addDay()->format('Y-m-d');

                    // Get time_in from current date (evening start) - only records after 18:00
                    $todayEntries = array_filter($dates, function ($d) use ($date) {
                        if ($d['date'] !== $date) {
                            return false;
                        }
                        // For night shift, only consider records after 18:00 as time_in
                        $hour = Carbon::parse($d['datetime'])->hour;

                        return $hour >= 18;
                    });
                    if (count($todayEntries) > 0) {
                        $time_in = array_reduce($todayEntries, function ($min, $d) {
                            return $min === null || $d['datetime'] < $min ? $d['datetime'] : $min;
                        });
                    }

                    // Get time_out from next date (morning end) - only records before 12:00
                    $tomorrowEntries = array_filter($dates, function ($d) use ($tomorrow) {
                        if ($d['date'] !== $tomorrow) {
                            return false;
                        }
                        // For night shift, only consider records before 12:00 as time_out
                        $hour = Carbon::parse($d['datetime'])->hour;

                        return $hour < 12;
                    });
                    if (count($tomorrowEntries) > 0) {
                        $time_out = array_reduce($tomorrowEntries, function ($max, $d) {
                            return $max === null || $d['datetime'] > $max ? $d['datetime'] : $max;
                        });
                    }

                    // If no time_out from tomorrow, check if there are multiple records today that could be time_out
                    if (! $time_out && count($todayEntries) > 1) {
                        $latestToday = array_reduce($todayEntries, function ($max, $d) {
                            return $max === null || $d['datetime'] > $max ? $d['datetime'] : $max;
                        });
                        // If latest record today is after midnight, it could be time_out
                        $latestTime = Carbon::parse($latestToday);
                        if ($latestTime->hour >= 22 || $latestTime->hour <= 6) {
                            $time_out = $latestToday;
                        }

                        // If time_in and time_out are the same, it means only one record
                        if ($time_in === $time_out) {
                            $time_out = '';
                        }
                    }
                }
            }

            $total_hours = 0;
            $break_time = 0;

            // Only calculate total hours if both time_in and time_out are available
            if ($time_in && $time_out && $date >= SeptemberAttendanceHours::EFFECTIVE_DATE) {
                $total_hours = SeptemberAttendanceHours::calculate(
                    $date, $shift, (string) $calendar_category_id, $time_in, $time_out
                );
            } elseif ($time_in && $time_out) {
                $start = Carbon::parse($time_in);
                $end = Carbon::parse($time_out);

                if ($shift === 1) {
                    $shiftStart = $start->copy()->setTime(7, 30, 0);
                    $start = $start->gt($shiftStart) ? $start : $shiftStart;
                } elseif ($shift === 2) {
                    $shiftStart = $start->copy()->setTime(19, 30, 0);
                    $start = $start->gt($shiftStart) ? $start : $shiftStart;
                }

                $startMinutes = $start->hour * 60 + $start->minute;
                $endMinutes = $shift == 2
                    ? ($end->timestamp - $start->timestamp) / 60 + $startMinutes
                    : $end->hour * 60 + $end->minute;

                // Calculate break time based on calendar_category_id
                if ($calendar_category_id == '4') {
                    if ($endMinutes > 9 * 60 + 30 && $startMinutes < 9 * 60 + 45 && $startMinutes < 9 * 60 + 30) {
                        $break_time += 15;
                    }
                    if ($endMinutes >= 13 * 60 && $startMinutes < 12 * 60) {
                        $break_time += 60;
                    }
                    if ($endMinutes > 14 * 60 + 30 && $startMinutes < 14 * 60 + 45 && $startMinutes < 14 * 60 + 30) {
                        $break_time += 15;
                    }
                } elseif ($calendar_category_id == '2') {
                    if ($endMinutes > 9 * 60 + 30 && $startMinutes < 9 * 60 + 35 && $startMinutes < 9 * 60 + 30) {
                        $break_time += 5;
                    }
                    if ($endMinutes >= 12 * 60 && $startMinutes < 11 * 60 + 20) {
                        $break_time += 40;
                    }
                    if ($endMinutes > 14 * 60 + 30 && $startMinutes < 14 * 60 + 35 && $startMinutes < 14 * 60 + 30) {
                        $break_time += 5;
                    }
                    if ($endMinutes > 16 * 60 && $startMinutes < 17 * 60 + 10 && $startMinutes < 16 * 60) {
                        $break_time += 10;
                    }
                    if ($endMinutes < 17 * 60 && $startMinutes < 17 * 60) {
                        $break_time += 10;
                    }
                } elseif ($shift === 1) {
                    if ($endMinutes > 9 * 60 + 30 && $startMinutes < 9 * 60 + 40 && $startMinutes < 9 * 60 + 30) {
                        $break_time += 10;
                    }
                    if ($endMinutes >= 11 * 60 + 50 && $startMinutes < 11 * 60 + 20) {
                        $break_time += 30;
                    }
                    if ($endMinutes > 14 * 60 + 30 && $startMinutes < 14 * 60 + 40 && $startMinutes < 14 * 60 + 30) {
                        $break_time += 10;
                    }
                    if ($endMinutes > 17 * 60 && $startMinutes < 17 * 60 + 10 && $startMinutes < 17 * 60) {
                        $break_time += 10;
                    }
                } elseif ($shift === 2) {
                    if ($endMinutes > 21 * 60 + 30 && $startMinutes < 21 * 60 + 40 && $startMinutes < 21 * 60 + 30) {
                        $break_time += 10;
                    }
                    if ($endMinutes >= 24 * 60 && $startMinutes < 23 * 60 + 30) {
                        $break_time += 30;
                    }
                    if ($endMinutes > 26 * 60 + 30 && $startMinutes < 26 * 60 + 40 && $startMinutes < 26 * 60 + 30) {
                        $break_time += 10;
                    }
                    if ($endMinutes > 29 * 60 && $startMinutes < 29 * 60 + 10 && $startMinutes < 29 * 60) {
                        $break_time += 10;
                    }
                }

                $total_hours = ($end->timestamp - $start->timestamp - $break_time * 60) / 3600;
                $total_hours = max(0, $total_hours);
                $total_hours = floor($total_hours * 4) / 4;
            }

            // Determine day type for display
            $dayType = 'Nghỉ'; // Default: Off day
            if ($isScheduleChange) {
                $dayType = 'Đổi lịch làm';
            } elseif ($shift > 0) {
                if ($hnhc === 'N' || $hnhc === 'LN') {
                    $dayType = 'Ca ngày';
                } elseif ($hnhc === 'D' || $hnhc === 'TC') {
                    $dayType = 'Ca đêm';
                }
            }

            // Skip records where user has work schedule but no time_in and time_out
            if (($hnhc === 'N' || $hnhc === 'LN' || $hnhc === 'D' || $hnhc === 'TC') && empty($time_in) && empty($time_out)) {
                continue;
            }

            $result[] = [
                'employee_id' => $employee_id,
                'name' => $name,
                'company' => $company,
                'calendar_category_id' => $calendar_category_id,
                'date' => $date,
                'shift' => $shift,
                'hnhc' => $hnhc,
                'day_type' => $dayType,
                'is_schedule_change' => $isScheduleChange,
                'time_in' => $time_in,
                'time_out' => $time_out,
                'total_hours' => $total_hours,
                'overtime_hours' => $total_hours > 8 ? $total_hours - 8 : 0,
                'administrative_hours' => $total_hours > 8 ? 8 : $total_hours,
            ];
        }

        return $result;
    }
}
