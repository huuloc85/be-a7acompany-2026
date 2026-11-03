<?php

namespace App\Http\Controllers;

use App\Exports\Attendance\MultiSheetExport;
use App\Models\AttendanceRecord;
use App\Models\CategoryCelender;
use App\Models\Celender;
use App\Models\CelenderDetailHNHC;
use App\Models\Employee;
use App\Traits\CelenderDetailTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class AttendanceRecordController extends Controller
{
    use CelenderDetailTrait;

    private $listRecord;

    // View Lịch Sử Chấm Công (Admin)
    public function index(Request $request)
    {
        $employees = Employee::whereNotIn('role_id', [15, 1])
            ->whereNull('deleted_at')
            ->get();

        // Lấy tháng hiện tại hoặc tháng được chọn từ request
        $currentMonth = $request->input('month', Carbon::now()->format('Y-m'));

        // Lấy danh sách các danh mục làm việc
        $categories = CategoryCelender::all();

        // Truy vấn dữ liệu theo tháng được chọn và danh mục (nếu có)
        $query = AttendanceRecord::whereYear('date', Carbon::parse($currentMonth)->year)
            ->whereMonth('date', Carbon::parse($currentMonth)->month)
            ->orderBy('employee_code', 'asc')
            ->orderBy('date', 'asc');
        if ($request->filled('category')) {
            $query->whereHas('employee', function ($q) use ($request) {
                $q->where('calendar_category_id', $request->input('category'));
            });
        }

        $limit = $request->input('limit', 50);
        $records = $query->paginate($limit);

        return view('attendence.index', compact('records', 'currentMonth', 'categories', 'employees'));
    }

    // Add Record (Admin)
    public function handleAddRecords(Request $request)
    {
        DB::beginTransaction();
        try {
            // Tạo bản ghi mới
            $attendance = new AttendanceRecord;
            $attendance->employee_code = $request->input('employee_code');
            // $attendance->datetime = $request->input('datetime');
            $date = $request->input('date');
            $time = $request->input('time');
            $attendance->date = $date;
            $attendance->time = $time;
            $attendance->datetime = \Carbon\Carbon::createFromFormat('Y-m-d H:i', $date.' '.$time);
            // $attendance->direction = $request->input('shift');
            // $attendance->deviceName = $request->input('deviceName');
            // $attendance->deviceSN = $request->input('shift');
            $attendance->employee_Name = $request->input('employee_name');
            // $attendance->cardNo = $request->input('shift');
            $attendance->save();

            DB::commit();
            toast('Thêm dữ liệu chấm công thành công!', 'success', 'top-right');

            return redirect()->route('admin.attendence.index');
        } catch (\Exception $e) {
            // Rollback nếu có lỗi
            DB::rollBack();
            Log::error('errors: '.$e->getMessage().' getLine: '.$e->getLine());
            toast('Thêm dữ liệu chấm công không thành công!', 'error', 'top-right');

            return redirect()->back();
        }
    }

    public function update(Request $request, $employee_code, $datetime)
    {
        try {

            $newDatetime = $request->input('datetime');
            // Parse `datetime` thành `date`, `time`, và `datetime`
            $parsedDate = \Carbon\Carbon::parse($newDatetime)->format('Y-m-d');
            $parsedTime = \Carbon\Carbon::parse($newDatetime)->format('H:i:s.u');
            $parsedDateTime = \Carbon\Carbon::parse($newDatetime)->format('Y-m-d H:i:s.u');

            // Cập nhật dữ liệu bản ghi trong cơ sở dữ liệu
            $updateResult = DB::table('attendencerecord')
                ->where('employee_code', $employee_code)
                ->where('datetime', $datetime)
                ->update([
                    'date' => $parsedDate,
                    'time' => $parsedTime,
                    'datetime' => $parsedDateTime,
                ]);
            // Thông báo thành công
            toast('Cập nhật dữ liệu chấm công thành công!', 'success', 'top-right');

            return redirect()->route('admin.attendence.index');
        } catch (\Exception $e) {
            // Nếu có lỗi, log lỗi và thông báo thất bại
            Log::error('Lỗi: '.$e->getMessage().' tại dòng: '.$e->getLine());
            toast('Cập nhật dữ liệu chấm công không thành công!', 'error', 'top-right');

            return redirect()->back();
        }
    }

    // Delete Record (Admin)
    public function destroy($employee_code, $datetime)
    {
        try {
            $record = AttendanceRecord::where('employee_code', $employee_code)
                ->where('datetime', $datetime)
                ->first();

            if ($record) {
                AttendanceRecord::where('employee_code', $employee_code)
                    ->where('datetime', $datetime)
                    ->delete();
                Log::info("Đã xoá bản ghi chấm công: $employee_code vào lúc $datetime");
                toast('Đã xoá thành công bảng chấm công.', 'success');
            } else {
                Log::warning("Không tìm thấy bản ghi để xoá: $employee_code vào lúc $datetime");
                toast('Không tìm thấy bản ghi để xoá.', 'error');
            }
        } catch (\Exception $e) {
            Log::error("Xoá không thành công bảng chấm công: {$e->getMessage()}");
            toast('Xoá không thành công bảng chấm công.', 'error');
        }

        return redirect()->route('admin.attendence.index');
    }

    // View Bảng Tính Công (Admin)
    public function records(Request $request)
    {

        $startDate = $request->start_date;
        $endDate = $request->end_date;
        if ($startDate) {
            $startDate = Carbon::parse($startDate)->startOfDay();
        }
        if ($endDate) {
            $endDate = Carbon::parse($endDate)->endOfDay();
        }
        $currentMonth = $request->input('month', Carbon::now()->format('Y-m'));
        $timeFilter = $request->time_filter ?? 'working_hours';
        $calendarId = Celender::whereMonth('date', Carbon::parse($currentMonth)->month)->whereYear('date', Carbon::parse($currentMonth)->year)->pluck('id')->first();
        $startCalendarId = null;
        $endCalendarId = null;
        if ($startDate && $endDate) {
            $startCalendarId = Celender::whereMonth('date', $startDate->month)->whereYear('date', $startDate->year)->pluck('id')->first();
            $endCalendarId = Celender::whereMonth('date', $endDate->month)->whereYear('date', $endDate->year)->pluck('id')->first();
        }
        $query = $this->buildQuery($request, $currentMonth, $timeFilter);
        $records = $this->checkQuery($query, $timeFilter);
        $this->listRecord = $records;
        $dayOfWeekMapping = AttendanceRecord::getDayOfWeekMapping();

        foreach ($records as $key => $record) {
            if ($startDate && $endDate) {
                // if ($record->employee_code == '23081500' && $record->date == '2024-11-01') {
                //     dd($calendarId);
                // }
                if (Carbon::parse($record->date)->month == $startDate->month && Carbon::parse($record->date)->year == $startDate->year) {
                    $calendarId = $startCalendarId;
                } elseif (Carbon::parse($record->date)->month == $endDate->month && Carbon::parse($record->date)->year == $endDate->year) {
                    $calendarId = $endCalendarId;
                }
            }
            $this->processRecord($record, $timeFilter, $dayOfWeekMapping, $calendarId, $key);
            // if ($record->employee_code == '17030500	' && $record->date == '2024-12-22') {
            //     dd($record);
            // }
        }

        $calendarDetails = $this->getCelenderDetails($request, $calendarId);

        return view('attendence.records', [
            'records' => $this->listRecord,
            'currentMonth' => $currentMonth,
            'timeFilter' => $timeFilter,
            'id' => $calendarId,
            'employeesToday' => $calendarDetails['employeesToday'],
            'today' => $calendarDetails['today'],
            'day' => $calendarDetails['day'],
            'employeesTodayCount' => $calendarDetails['employeesTodayCount'],
            'currentDay' => $calendarDetails['currentDay'],
        ]);
    }

    // Query (Admin)
    private function buildQuery(Request $request, $currentMonth, $timeFilter)
    {
        // Nếu có cả `start_date` và `end_date`, truy vấn sẽ chỉ sử dụng chúng
        if ($request->has('start_date') && $request->start_date && $request->has('end_date') && $request->end_date) {
            $query = AttendanceRecord::whereDate('date', '>=', $request->start_date)
                ->whereDate('date', '<=', $request->end_date)
                ->orderBy('employee_code', 'asc')
                ->orderBy('date', 'asc');
        } else {
            // Nếu không có `start_date` và `end_date`, sử dụng `currentMonth`
            $query = AttendanceRecord::whereYear('date', Carbon::parse($currentMonth)->year)
                ->whereMonth('date', Carbon::parse($currentMonth)->month)
                ->orderBy('employee_code', 'asc')
                ->orderBy('date', 'asc');
        }

        // Điều kiện tìm kiếm theo `employee_name`
        if ($request->has('employee_name') && $request->employee_name) {
            $employeeIds = Employee::where('name', 'like', '%'.$request->employee_name.'%')->pluck('code');
            $query->whereIn('employee_code', $employeeIds);
        }

        // Điều kiện theo `timeFilter`
        if (config('a7a.list_category')[$timeFilter]) {
            $categoryId = CategoryCelender::listCate[$timeFilter];
            $query->whereHas('employee', function ($query) use ($categoryId) {
                $query->where('calendar_category_id', $categoryId);
            });

            // Điều kiện thời gian cho `Ca 1` nếu `timeFilter` thuộc `list_category_ca1`
            if (in_array($timeFilter, config('a7a.list_category_ca1'))) {
                $query->whereTime('time', '>=', config('a7a.ca1_start_time'))
                    ->whereTime('time', '<=', config('a7a.ca1_end_time'));
            }
        }

        return $query;
    }

    // Query theo ca (Admin)
    private function checkQuery($query, $timeFilter)
    {
        $records = $query->select(
            'employee_code',
            'date',
            DB::raw('COUNT(*) as record_count'),
            DB::raw('MIN(time) as time_in'),
            DB::raw('MAX(time) as time_out'),
            DB::raw("GROUP_CONCAT(time ORDER BY time ASC SEPARATOR ', ') as all_times")
        );

        return $records->groupBy('employee_code', 'date')
            ->orderBy('employee_code', 'asc')
            ->has('employee')
            ->with('employee')
            ->get();
    }

    // Code chức năng tính công (Admin)
    private function processRecord($record, $timeFilter, $dayOfWeekMapping, $calendarId, $key)
    {
        if ($timeFilter == null && $record->employee->calendar_category_id != null) {
            $timeFilter = CategoryCelender::listCateforEmployee[$record->employee->calendar_category_id];
        }
        $date = Carbon::parse($record->date);
        $record->day_of_week = $dayOfWeekMapping[$date->format('l')];

        $shift = CelenderDetailHNHC::where('celender_id', $calendarId)->where('employee_id', $record->employee->id)->pluck('day'.$date->day)->first();
        if (in_array($timeFilter, config('a7a.list_category_ca1'))) {
            if ($shift === config('a7a.shift_1') || $shift === config('a7a.shift_1_extra_day')) {
                $record->shift = 'Ca 1';
            } elseif ($shift === config('a7a.shift_2') || $shift === config('a7a.shift_2_extra_night')) {
                $record->shift = 'Ca 2';
            } else {
                $record->shift = 'Đổi lịch đi làm';
            }
            $this->processRecordCa1($record, $timeFilter, $dayOfWeekMapping);
        } elseif (in_array($timeFilter, config('a7a.list_category_ca2'))) {
            if ($shift === config('a7a.shift_1') || $shift === config('a7a.shift_1_extra_day')) {
                $record->shift = 'Ca 1';
            } elseif ($shift === config('a7a.shift_2') || $shift === config('a7a.shift_2_extra_night')) {
                $record->shift = 'Ca 2';
            } else {
                $record->shift = 'Đổi lịch đi làm';
            }
            if ($shift == config('a7a.shift_1') || $shift == config('a7a.shift_1_extra_day')) {
                $this->processRecordCa1($record, $timeFilter, $dayOfWeekMapping);
            } elseif ($shift == config('a7a.shift_2') || $shift == config('a7a.shift_2_extra_night')) {
                if (Carbon::now()->format('Y-m-d') == $record->date) {
                    unset($this->listRecord[$key]);
                } else {
                    $times = explode(', ', $record->all_times);
                    $timesBefore12AM = array_filter($times, function ($time) {
                        return strtotime($time) < strtotime(config('a7a.ca2_check_start_time'));
                    });

                    $timesAfter12AM = array_filter($times, function ($time) {
                        return strtotime($time) > strtotime(config('a7a.ca2_check_start_time'));
                    });

                    if (empty($timesBefore12AM) || ! empty($timesAfter12AM)) {
                        $this->processRecordCa2($record, $timeFilter, $dayOfWeekMapping);
                    } else {
                        unset($this->listRecord[$key]);
                    }
                }
            } else {
                // /lịch nghĩ nhưng đi làm
                $currentDate = Carbon::parse($record->date);
                $calendarIdToUse = $calendarId;
                if ($currentDate->day == 1) {
                    $prevMonth = $currentDate->copy()->subMonth();
                    $calendarIdToUse = Celender::whereMonth('date', $prevMonth->month)
                        ->whereYear('date', $prevMonth->year)
                        ->pluck('id')->first();
                }
                $dateToCheck = $currentDate->copy()->subDay(); // Ngày trước đó
                $shiftBefore = CelenderDetailHNHC::where('celender_id', $calendarIdToUse)
                    ->where('employee_id', $record->employee->id)
                    ->pluck('day'.$dateToCheck->day)
                    ->first();
                if ($shiftBefore == config('a7a.shift_1') || $shiftBefore == config('a7a.shift_1_extra_day')) {
                    $this->processRecordCa1($record, $timeFilter, $dayOfWeekMapping);
                } elseif ($shiftBefore == config('a7a.shift_2') || $shiftBefore == config('a7a.shift_2_extra_night')) {
                    if (Carbon::now()->format('Y-m-d') == $record->date) {
                        unset($this->listRecord[$key]);
                    } else {
                        // check in before 12h AM?
                        $times = explode(', ', $record->all_times);
                        $timesBefore12AM = array_filter($times, function ($time) {
                            return strtotime($time) < strtotime(config('a7a.ca2_check_start_time'));
                        });
                        $timesAfter12AM = array_filter($times, function ($time) {
                            return strtotime($time) > strtotime(config('a7a.ca2_check_start_time'));
                        });

                        if (empty($timesBefore12AM) || ! empty($timesAfter12AM)) {
                            $this->processRecordCa2($record, $timeFilter, $dayOfWeekMapping);
                        } else {
                            unset($this->listRecord[$key]);
                        }
                    }
                }
            }
        }
    }

    // Tính Tổng BreakTime
    private function calculateBreakTime($record, $timeFilter, $timeIn, $timeOut)
    {
        // if ($record->employee_code == "16100400" && $record->date == "2024-11-06") {
        //     $timeIn = "00:30:00.000000";
        // }
        $breakTimesConfig = config('a7a.break_times');
        $timeIn = Carbon::parse($timeIn);
        $timeOut = Carbon::parse($timeOut);
        $breakTime = 0;
        switch ($timeFilter) {
            case 'working_hours':
                $breakSchedule = $breakTimesConfig['ca_hanh_chinh']['schedule'];
                break;
            case 'qc_day':
                $breakSchedule = $breakTimesConfig['ca_ngay']['schedule'];
                break;
            case 'rotating_shift_mk':
            case 'rotating_shift_jp':
            case 'technical':
                // Chọn lịch nghỉ dựa trên giờ vào
                $breakSchedule = $record->shift == 'Ca 2' ?
                    $breakTimesConfig['sx_ca_2']['schedule'] :
                    $breakTimesConfig['sx_ca_1']['schedule'];
                break;
            default:
                return 0;
        }

        // foreach ($breakSchedule as $break) {
        //     $breakStart = Carbon::parse($break['time']);
        //     $breakEnd = $breakStart->copy()->addMinutes($break['duration']);

        //     // Kiểm tra xem khoảng nghỉ có nằm trong khoảng thời gian làm việc không
        //     if ($breakStart < $timeOut && $breakEnd > $timeIn) {
        //         // Tính thời gian nghỉ thực tế
        //         $actualBreakStart = max($breakStart, $timeIn);
        //         $actualBreakEnd = min($breakEnd, $timeOut);
        //         $breakTime += $actualBreakEnd->diffInMinutes($actualBreakStart);
        //     }
        // }

        // Nếu ca là qc_day và giờ về sớm hơn 17:00, vẫn trừ thêm 10 phút
        // if ($timeFilter === 'qc_day' && $timeOut < Carbon::parse('17:00')) {
        //     $breakTime += 10;
        // }

        // return $breakTime;
        return $this->handleBreakTime($record, $breakSchedule, $timeIn, $timeOut, $timeFilter);
    }

    private function handleBreakTime($record, $breakSchedule, $timeIn, $timeOut, $timeFilter)
    {
        $breakTime = 0;
        if (in_array($timeFilter, config('a7a.list_category_ca1')) || $record->shift == 'Ca 1') {
            // ca 1
            foreach ($breakSchedule as $break) {
                $breakStart = Carbon::parse($break['time']);
                $breakEnd = $breakStart->copy()->addMinutes($break['duration']);

                // Kiểm tra xem khoảng nghỉ có nằm trong khoảng thời gian làm việc không
                if ($breakStart < $timeOut && $breakEnd > $timeIn) {
                    // Tính thời gian nghỉ thực tế
                    $actualBreakStart = max($breakStart, $timeIn);
                    $actualBreakEnd = min($breakEnd, $timeOut);
                    $breakTime += $actualBreakEnd->diffInMinutes($actualBreakStart);
                }
            }
        } elseif (in_array($timeFilter, config('a7a.list_category_ca2'))) {
            // ca 2
            foreach ($breakSchedule as $key => $break) {
                $breakStart = Carbon::parse($break['time']);
                $breakEnd = $breakStart->copy()->addMinutes($break['duration']);
                if ($timeIn->hour >= 18) {
                    // đi làm từ chiều đến tối
                    if ($timeOut->hour >= 18 && $breakStart->hour >= 18) {
                        // về trong ngày
                        if ($timeIn < $breakStart && $timeOut > $breakEnd) {
                            $breakTime += $break['duration'];
                        }
                    } elseif ($timeOut->hour < 9) {
                        // về ngày hôm sau
                        if ($breakStart->hour >= 18) {
                            if ($timeIn < $breakStart) {
                                $breakTime += $break['duration'];
                            }
                        } elseif ($breakStart->hour < 9) {
                            if ($timeOut > $breakEnd) {
                                $breakTime += $break['duration'];
                            }
                        }
                    }
                } else {
                    // đi làm khi qua ngày hôm sau
                    if ($breakStart->hour < 9) {
                        if ($timeIn < $breakStart && $timeOut > $breakEnd) {
                            $breakTime += $break['duration'];
                        }
                    }
                }
            }
        }

        // Nếu ca là qc_day và giờ về sớm hơn 17:00, vẫn trừ thêm 10 phút
        if ($timeFilter === 'qc_day' && $timeOut < Carbon::parse('17:00')) {
            $breakTime += 10;
        }

        return $breakTime;
    }

    // Code chức năng tính công ca 1 (Admin)
    private function processRecordCa1($record, $timeFilter, $dayOfWeekMapping)
    {
        $workStartTime = config('a7a.ca1_work_start_time');
        $workEndTime = $timeFilter === 'working_hours' ? config('a7a.ca1_work_end_time_wh') : config('a7a.ca1_work_end_time_qd');

        // if ($record->employee_code === '23030100') {
        //     $dayOfWeek = Carbon::parse($record->date)->dayOfWeek; // 1 là Thứ Hai, 3 là Thứ Tư, 5 là Thứ Sáu
        //     if (in_array($dayOfWeek, [1, 3, 5]) && $record->shift === 'Ca 1') {
        //         $workStartTime = '07:00'; // Đặt giờ bắt đầu làm việc là 7:00 sáng
        //         $workEndTime = Carbon::parse($workStartTime)->addHours(8)->format('H:i'); // Đặt giờ kết thúc để làm đủ 8 tiếng

        //         // Đảm bảo giờ làm việc không vượt quá 8 tiếng
        //         $record->total_hours = min($record->total_hours, 8);
        //         $record->overtime_hours = 0; // Không có giờ tăng ca
        //     }
        // }

        $breakTime = $this->calculateBreakTime($record, $timeFilter, $record->time_in, $record->time_out);

        if ($record->record_count == 1) {
            $time = Carbon::parse($record->time_in);
            $diffToStart = $time->diffInSeconds(Carbon::parse($workStartTime));
            $diffToEnd = $time->diffInSeconds(Carbon::parse($workEndTime));

            if ($diffToStart < $diffToEnd) {
                $record->time_in = $time;
                $record->time_out = null;
            } else {
                $record->time_out = $time;
                $record->time_in = null;
            }
        }

        if ($record->record_count > 1) {
            $timeIn = Carbon::parse($record->time_in);
            $timeOut = Carbon::parse($record->time_out);

            // Khoảng cách giữa time_out và time_out > 10p thì chạy bình thường
            if ($timeIn && $timeOut && $timeIn->diffInMinutes($timeOut) <= 10) {
                $diffToStart = $timeIn->diffInSeconds(Carbon::parse($workStartTime));
                $diffToEnd = $timeIn->diffInSeconds(Carbon::parse($workEndTime));

                if ($diffToStart < $diffToEnd) {
                    $record->time_in = $timeIn;
                    $record->time_out = null;
                } else {
                    $record->time_out = $timeIn;
                    $record->time_in = null;
                }
            }
        }

        if ($record->time_in && $record->time_out) {
            $timeIn = Carbon::parse($record->time_in);
            $timeOut = Carbon::parse($record->time_out);

            // Nếu time_in và time_out khác nhau ít nhất 10p
            if ($timeIn->diffInMinutes($timeOut) >= 10) {
                $record->total_hours = $this->calculateTotalHours($record, $workStartTime, $workEndTime, $breakTime);
                $record->overtime_hours = $this->calculateOvertime($record);
            } else {
                // Nếu không, đặt time_out là null
                $record->time_out = null;
                $record->total_hours = 0;
                $record->overtime_hours = 0;
            }
        }
        // gán giờ hành chính thấp nhất từ total_hours
        $administrativeHours = min($record->total_hours, 8);
        $record->administrative_hours = $administrativeHours;
        // check đi làm trễ nhưng vẫn tính tăng ca
        // $timeIn = Carbon::parse($record->time_in); // Thời gian vào làm
        // if ($timeIn->gt($workStartTime)) {
        //     // Nếu vào trễ, tính lại giờ tăng ca
        //     $actualWorkHours = $record->total_hours;
        //     $administrativeHours = $record->administrative_hours;
        //     $workedHours = $actualWorkHours - $administrativeHours;
        //     // Nếu giờ thực làm > hành chính, phần dư sẽ được tính tăng ca
        //     $record->overtime_hours = $workedHours > 0 ? $workedHours : 0;
        // } else {
        //     $record->overtime_hours;
        // }

        if ($record->employee->calendar_category_id == [2, 4]) {
            $record->overtime_hours = min($record->total_hours - $administrativeHours);
        }

        if ($record->overtime_hours > 0) {
            $record->total_hours = $record->administrative_hours + $record->overtime_hours;
        }

        if ($record->time_in && $record->time_out) {
            if ($record->total_hours == 0) {
                $record->total_hours = 'Cho Về Sớm';
            }
        }
        // if ($record->employee_code == '20020700	' && $record->date == '2025-03-25') {
        //     dd($record->total_hours);
        // }
    }

    // Code chức năng tính công ca 2 (Admin)
    private function processRecordCa2($record, $timeFilter, $dayOfWeekMapping)
    {
        // set time in
        if ($record->record_count < 1) {
            $record->time_in = null;
        } else {
            $times = explode(', ', $record->all_times);
            $timesBefore8AM = array_filter($times, function ($time) {
                return strtotime($time) > strtotime(config('a7a.ca2_min_start_time'));
            });
            $record->time_in = ! empty($timesBefore8AM) ? min($timesBefore8AM) : null;
        }

        // set time out
        $checkTimeOut = AttendanceRecord::where('employee_code', $record->employee_code)
            ->where('date', Carbon::parse($record->date)->addDay())
            ->where('time', '<', config('a7a.ca2_max_end_time'))
            ->orderBy('time', 'desc')
            ->select('time', 'date')
            ->first();

        $record->time_out = isset($checkTimeOut->time) ? $checkTimeOut->time : null;
        $record->date_out = isset($checkTimeOut->date) ? $checkTimeOut->date : null;

        if ($record->time_out == null && $record->time_in != null) {
            $times = explode(', ', $record->all_times);
            if (count($times) > 1) {
                $listTimeout = array_filter($times, function ($time) use ($record) {
                    return strtotime($time) > strtotime($record->time_in);
                });
                $record->time_out = ! empty($listTimeout) ? min($listTimeout) : null;
            }
        }

        $breakTime = $this->calculateBreakTime($record, $timeFilter, $record->time_in, $record->time_out);
        $workStartTime = config('a7a.ca2_work_start_time');
        $workEndTime = config('a7a.ca2_work_end_time');
        if ($record->record_count > 1) {
            $timeIn = Carbon::parse($record->time_in);
            $timeOut = Carbon::parse($record->time_out);

            // Khoảng cách giữa time_out và time_out > 1 thì chạy bình thường
            if ($timeIn && $timeOut && $timeIn->diffInMinutes($timeOut) <= 10) {
                $diffToStart = $timeIn->diffInSeconds(Carbon::parse($workStartTime));
                $diffToEnd = $timeIn->diffInSeconds(Carbon::parse($workEndTime));

                if ($diffToStart < $diffToEnd) {
                    $record->time_in = $timeIn;
                    $record->time_out = null;
                } else {
                    $record->time_out = $timeIn;
                    $record->time_in = null;
                }
            }
        }
        $record->total_hours = $this->calculateTotalHours($record, $workStartTime, $workEndTime, $breakTime, true);
        $record->overtime_hours = $this->calculateOvertime($record, true);

        $administrativeHours = min($record->total_hours, 8);
        $record->administrative_hours = $administrativeHours;
        $timeIn = Carbon::parse($record->time_in); // Thời gian vào làm
        if ($timeIn->gt($workStartTime)) {
            // Nếu vào trễ, tính lại giờ tăng ca
            $actualWorkHours = $record->total_hours;
            $workedHours = $actualWorkHours - $administrativeHours;
            // Nếu giờ thực làm > hành chính, phần dư sẽ được tính tăng ca
            $record->overtime_hours = $workedHours > 0 ? $workedHours : 0;
        } else {
            $record->overtime_hours;
        }

        if ($record->overtime_hours > 0) {
            $record->total_hours = $record->administrative_hours + $record->overtime_hours;
        }
        if ($record->time_in && $record->time_out) {
            if ($record->total_hours == 0) {
                $record->total_hours = 'Cho Về Sớm';
            }
        }
    }

    // Tính Tổng Giờ Làm Việc
    private function calculateTotalHours($record, $workStartTime, $workEndTime, $breakTime, $shift2 = false)
    {
        if (! $record->time_in || ! $record->time_out) {
            return 0;
        }

        $timeInDate = Carbon::parse($record->time_in);
        $timeOutDate = Carbon::parse($record->time_out);
        $workStartDate = Carbon::parse($workStartTime);
        $workEndDate = Carbon::parse($workEndTime);

        // Xử lý thời gian bắt đầu
        $effectiveStart = $timeInDate < $workStartDate ? $workStartDate : $timeInDate;

        // Xử lý thời gian kết thúc
        // Nếu đi trễ nhưng về trễ hơn cả giờ làm → cho phép lấy giờ thực tế để bù
        if ($timeInDate > $workStartDate && $timeOutDate > $workEndDate) {
            $effectiveEnd = $timeOutDate;
        } else {
            $effectiveEnd = $timeOutDate > $workEndDate ? $workEndDate : $timeOutDate;
        }

        // Xử lý ca 2 nếu có ngày khác
        if ($shift2 && $record->date_out == null) {
            $effectiveEnd = $timeOutDate;
        }

        if ($shift2 && $timeOutDate->hour < 24 && $record->date == $record->date_out) {
            $effectiveEnd = $timeOutDate;
        }

        if ($shift2 && $effectiveEnd < $effectiveStart && $record->date_out != null && $record->date != $record->date_out) {
            $effectiveEnd->addDay();
        }

        // Tính thời gian làm việc
        $workingSeconds = max(0, $effectiveEnd->diffInSeconds($effectiveStart));
        $workingHours = $workingSeconds / 3600 - ($breakTime / 60);

        // Tính thêm giờ nếu làm chưa đủ
        $dailyWorkHours = 8;
        if ($workingHours < $dailyWorkHours) {
            $requiredHours = $dailyWorkHours - $workingHours;
            $adjustedTimeOut = $shift2 ? $timeOutDate->copy()->subDay() : $timeOutDate;
            $billedHours = $adjustedTimeOut <= $workEndDate ? 0 : min($adjustedTimeOut->diffInHours($workEndDate), $requiredHours);
            $workingHours += $billedHours;
        }

        return round($workingHours * 4) / 4; // Làm tròn 15 phút
    }

    // Tính Giờ Tăng Ca
    private function calculateOvertime($record, $shift2 = false)
    {
        $totalHours = $record->total_hours;
        $categoryId = $record->employee->calendar_category_id;
        // Xác định thời gian bắt đầu dựa vào ca
        $timeStartWork = $shift2 ? Carbon::parse(config('a7a.ca2_work_start_time')) : Carbon::parse(config('a7a.ca1_work_start_time'));
        $overtimeStart = Carbon::parse(config('a7a.over_time_start_qd'));

        // Set overtime start time based on category
        if ($categoryId == CategoryCelender::listCate['working_hours']) {
            $overtimeStart = Carbon::parse(config('a7a.over_time_start_wh'));
        }
        if ($shift2) {
            $overtimeStart = Carbon::parse(config('a7a.over_time_start_ca2'));
        }
        if (! $overtimeStart) {
            return 0;
        }

        $overtimeEnd = $shift2 ? Carbon::parse(config('a7a.over_time_end_ca2')) : Carbon::parse(config('a7a.over_time_end_ca1'));
        $timeIn = Carbon::parse($record->time_in);
        $timeOut = Carbon::parse($record->time_out);

        if ($totalHours >= config('a7a.time_work')) {
            if ($timeOut->lt($overtimeStart)) {
                return 0;
            }
            if ($timeOut->gt($overtimeEnd)) {
                $timeOut = $overtimeEnd;
            }

            // Tính thời gian đi trễ dựa trên ca làm việc tương ứng
            $lateMinutes = 0;
            if ($timeIn->gt($timeStartWork)) {
                $lateMinutes = $timeIn->diffInMinutes($timeStartWork);
            }

            // Tính thời gian tăng ca và trừ đi thời gian đi trễ
            $overtimeMinutes = $timeOut->diffInMinutes($overtimeStart);
            $overtimeMinutes = max(0, $overtimeMinutes - $lateMinutes); // Đảm bảo không âm

            // Làm tròn theo block 15 phút
            $overtimeHours = $overtimeMinutes > 0 ? floor($overtimeMinutes / 15) * 0.25 : 0;

            return round($overtimeHours, 2);
        }

        return 0;
    }

    // View Lịch Sử Chấm Công (Nhân Viên)
    public function employeeViewRecords(Request $request)
    {
        $employeeCode = auth()->user()->code;
        // Lấy tháng hiện tại hoặc tháng được chọn từ request
        $currentMonth = $request->input('month', Carbon::now()->format('Y-m'));

        // Lấy danh sách các danh mục làm việc
        $categories = CategoryCelender::all();

        // Truy vấn dữ liệu theo tháng được chọn và danh mục (nếu có)
        $query = AttendanceRecord::whereYear('date', Carbon::parse($currentMonth)->year)
            ->whereMonth('date', Carbon::parse($currentMonth)->month)
            ->where('employee_code', $employeeCode)
            ->orderBy('date', 'asc');
        $records = $query->get();

        return view('attendence.employee_records', compact('records', 'currentMonth', 'categories'));
    }

    // View Tính Toán Chấm Công (Nhân Viên)
    public function employeeViewCaculateRecords(Request $request)
    {
        $employeeCode = auth()->user()->code;
        $categoryId = auth()->user()->calendar_category_id;
        $currentMonth = $request->input('month', Carbon::now()->format('Y-m'));
        $calendarId = Celender::whereMonth('date', Carbon::parse($currentMonth)->month)->whereYear('date', Carbon::parse($currentMonth)->year)->pluck('id')->first();
        $dayOfWeekMapping = AttendanceRecord::getDayOfWeekMapping();
        $query = AttendanceRecord::whereYear('date', Carbon::parse($currentMonth)->year)
            ->whereMonth('date', Carbon::parse($currentMonth)->month)
            ->where('employee_code', $employeeCode)
            ->orderBy('employee_code', 'asc')
            ->orderBy('date', 'asc');
        $timeFilter = CategoryCelender::listCateforEmployee[$categoryId];
        $records = $this->checkQuery($query, $timeFilter);
        $this->listRecord = $records;
        foreach ($records as $key => $record) {
            $this->processRecord($record, $timeFilter, $dayOfWeekMapping, $calendarId, $key);
        }

        return view('attendence.employee_caculate_records', [
            'records' => $this->listRecord,
            'currentMonth' => $currentMonth,
        ]);
    }

    // Kéo Data Từ Mcc
    public function updateDataCC(Request $request)
    {
        $data = $request->validate([
            'data' => 'required|array|min:1|max:1000',
            'data.*.employee_code' => 'required|string|exists:employees,id',
            'data.*.datetime' => 'required|date_format:Y-m-d H:i:s',
        ])['data'];

        try {
            DB::transaction(function () use ($data) {
                $writer = new \App\Services\AttendanceEventWriter;
                foreach (collect($data)->sortBy('employee_code') as $record) {
                    $writer->store($record['employee_code'], Carbon::parse($record['datetime']));
                }
            });

            return response()->json(['message' => 'Data inserted successfully']);
        } catch (\Throwable $e) {
            return \App\Helpers\HandleError::handle($e);
        }
    }

    // Export
    public function export(Request $request)
    {
        ini_set('max_execution_time', 5000);
        $currentMonth = Carbon::now()->format('m-Y');
        $startDate = Carbon::parse($request->input('start_date'))->startOfDay();
        $endDate = Carbon::parse($request->input('end_date'))->endOfDay();
        $employeeCodes = Employee::whereNotIn('role_id', [15, 1])->pluck('code');

        $startMonthCalendarId = Celender::whereMonth('date', $startDate->month)
            ->whereYear('date', $startDate->year)
            ->pluck('id')
            ->first();
        $endMonthCalendarId = Celender::whereMonth('date', $endDate->month)
            ->whereYear('date', $endDate->year)
            ->pluck('id')
            ->first();

        // $calendarIds = collect([$startMonthCalendarId, $endMonthCalendarId])->filter();
        // dd($calendarIds);
        $query = AttendanceRecord::whereBetween('date', [$startDate, $endDate])
            ->whereIn('employee_code', $employeeCodes)
            ->orderBy('employee_code', 'asc')
            ->orderBy('date', 'asc');

        $records = $this->checkQuery($query, null);

        // $employeeCodes = ['23052600', '20050400', '23030100', '16100400', '22072300'];
        $allEmployee = Employee::select('id', 'code', 'name', 'company')
            ->whereNull('deleted_at')
            ->whereNotNull('company')
            ->whereNotIn('role_id', [15, 17])
            ->get();

        // $allEmployee = Employee::select('id', 'code', 'name', 'company')
        //     ->where('role_id', '!=', 15)
        //     ->where('role_id', '!=', 17)
        //     ->where('deleted_at', null)->where('company', '!=', null)->get();

        $this->listRecord = $records;
        $a7aRecords = [];
        $vinhVinhPhatRecords = [];

        foreach ($allEmployee as $employee) {
            $attendance = [];
            $employeeTotalHours = [
                'totalHourMonth' => 0,
                'totalHourTC' => 0,
                'totalHourDay' => 0,
                'totalHourNight' => 0,
            ];
            $employeeforPC = [
                'PCTCNgay' => 0,
                'PCTCDem' => 0,
                'PCTCTC' => 0,
            ];
            foreach ($records as $key => $record) {
                if ($employee->id == $record->employee_code) {
                    $attendance[] = $record;
                }
                if (Carbon::parse($record->date)->month == $startDate->month && Carbon::parse($record->date)->year == $startDate->year) {
                    $calendarId = $startMonthCalendarId;
                } elseif (Carbon::parse($record->date)->month == $endDate->month && Carbon::parse($record->date)->year == $endDate->year) {
                    $calendarId = $endMonthCalendarId;
                }

                $this->processRecord($record, null, AttendanceRecord::getDayOfWeekMapping(), $calendarId, $key);

                // Tính tổng giờ cho nhân viên này
                if ($employee->id == $record->employee_code) {
                    $employeeTotalHours['totalHourMonth'] += (float) $record->total_hours;
                    $employeeTotalHours['totalHourTC'] += (float) $record->overtime_hours;
                    if ($record->shift === 'Ca 1') {
                        $employeeTotalHours['totalHourDay'] += (float) $record->administrative_hours;
                        // Giờ hành chính
                        if ($record->administrative_hours > 5) {
                            $employeeforPC['PCTCNgay'] += 1; // Tăng PCTCNgay
                        }
                    } elseif ($record->shift === 'Ca 2') {
                        $employeeTotalHours['totalHourNight'] += (float) $record->administrative_hours;
                        // Giờ đêm
                        if ($record->administrative_hours > 5) {
                            $employeeforPC['PCTCDem'] += 1; // Tăng PCTCDem
                        }
                    }
                    if ($record->administrative_hours >= 8) {
                        $employeeforPC['PCTCTC'] += 1; // Tăng PCTCTC nếu giờ >= 8
                    }
                }
            }

            // Gán dữ liệu chấm công và tổng giờ làm việc cho nhân viên
            $employee->setDataAttribute($attendance);
            $employee->setEmployeeTotalHoursAttribute($employeeTotalHours); // tổng giờ làm việc
            $employee->setEmployeeForPCAttribute($employeeforPC);  // phụ cấp

            // Phân loại nhân viên theo công ty
            if ($employee->company === 'A7A') {
                $a7aRecords[] = $employee;
            } elseif ($employee->company === 'Vinh Vinh Phát') {
                $vinhVinhPhatRecords[] = $employee;
            }
        }
        $listDate = $this->createDateRangeArray($startDate, $endDate);

        return Excel::download(
            new MultiSheetExport([
                'A7A' => $a7aRecords,
                'Vinh Vinh Phát' => $vinhVinhPhatRecords,
            ], $startDate, $endDate, $currentMonth, $listDate),
            'Bảng Tính Công Tháng'.'.xlsx',
            \Maatwebsite\Excel\Excel::XLSX,
            [
                'Content-Type' => 'text/xlsx',
            ]
        );
    }

    public function createDateRangeArray($startDate, $endDate)
    {
        $dates = [];
        $currentDate = clone $startDate;

        while ($currentDate <= $endDate) {
            $dates[] = $currentDate->format('Y-m-d');
            $currentDate->modify('+1 day');
        }

        return $dates;
    }
}
