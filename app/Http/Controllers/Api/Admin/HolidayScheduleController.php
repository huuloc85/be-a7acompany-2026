<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\HolidaySchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class HolidayScheduleController extends Controller
{
    public function show(HolidaySchedule $schedule)
    {
        return $this->respond($schedule->current());
    }

    public function update(Request $request, HolidaySchedule $schedule)
    {
        $validator = Validator::make($request->all(), [
            'revision' => ['required', 'integer', 'min:0'],
            'schedules' => ['present', 'array', 'max:4'],
            'schedules.*' => ['array:holiday,enabled,startsAt,endsAt'],
            'schedules.*.holiday' => ['required', Rule::in(HolidaySchedule::HOLIDAYS), 'distinct'],
            'schedules.*.enabled' => ['required', 'boolean'],
            'schedules.*.startsAt' => ['present', 'nullable', 'date_format:Y-m-d\TH:i:s.v\Z'],
            'schedules.*.endsAt' => ['present', 'nullable', 'date_format:Y-m-d\TH:i:s.v\Z'],
        ], [
            'schedules.*.holiday.distinct' => 'Mỗi giao diện chỉ được xếp một khoảng thời gian.',
            'schedules.*.startsAt.date_format' => 'Thời gian bắt đầu không hợp lệ.',
            'schedules.*.endsAt.date_format' => 'Thời gian kết thúc không hợp lệ.',
        ]);
        $validator->after(function ($validator) use ($request) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $enabled = [];
            foreach ($request->input('schedules') as $index => $item) {
                if (! $item['enabled']) {
                    continue;
                }
                if (! $item['startsAt'] || ! $item['endsAt']) {
                    $validator->errors()->add("schedules.$index.startsAt", 'Vui lòng chọn thời gian bắt đầu và kết thúc.');
                } elseif (strtotime($item['startsAt']) >= strtotime($item['endsAt'])) {
                    $validator->errors()->add("schedules.$index.endsAt", 'Thời gian kết thúc phải sau thời gian bắt đầu.');
                } else {
                    $enabled[] = [...$item, 'index' => $index];
                }
            }
            usort($enabled, fn ($a, $b) => strcmp($a['startsAt'], $b['startsAt']));
            for ($index = 1; $index < count($enabled); $index++) {
                if ($enabled[$index]['startsAt'] < $enabled[$index - 1]['endsAt']) {
                    $field = $enabled[$index]['index'];
                    $validator->errors()->add("schedules.$field.startsAt", 'Thời gian bị trùng với một giao diện khác.');
                }
            }
        });
        $data = $validator->validate();
        $items = array_map(fn ($item) => [
            'holiday' => $item['holiday'],
            'enabled' => (bool) $item['enabled'],
            'startsAt' => $item['startsAt'],
            'endsAt' => $item['endsAt'],
        ], $data['schedules']);

        return $this->respond($schedule->save($items, (int) $data['revision']));
    }

    private function respond(array $schedule)
    {
        return response()->json([...$schedule, 'serverTime' => now()->toIso8601String()])
            ->header('Cache-Control', 'no-store');
    }
}
