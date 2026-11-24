<?php

namespace App\Services;

use App\Models\RequestForm;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AttendanceExceptionService
{
    public static function rules(): array
    {
        return [
            'form_data' => ['required', 'array', function ($attribute, $data, $fail) {
                if (! is_array($data) || ! is_string($data['gio_ra_ngoai'] ?? null) || ! is_string($data['gio_quay_lai'] ?? null)
                    || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($data['gio_ra_ngoai'] ?? ''))
                    || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($data['gio_quay_lai'] ?? ''))) {
                    return;
                }
                $minutes = function ($value) use ($data) {
                    [$hour, $minute] = array_map('intval', explode(':', $value));

                    return $hour * 60 + $minute + ((int) ($data['ca_lam'] ?? 0) === 2 && $hour < 12 ? 1440 : 0);
                };
                if ($minutes($data['gio_quay_lai']) <= $minutes($data['gio_ra_ngoai'])) {
                    $fail('Giờ quay lại phải sau giờ ra ngoài.');
                }
            }],
            'form_data.ngay_ap_dung' => ['required', 'date_format:Y-m-d'],
            'form_data.ca_lam' => ['required', 'integer', 'in:1,2'],
            'form_data.gio_ra_ngoai' => ['required', 'date_format:H:i'],
            'form_data.gio_quay_lai' => ['required', 'date_format:H:i', 'different:form_data.gio_ra_ngoai'],
            'form_data.ly_do' => ['required', 'string', 'max:500'],
            'digital_signature_supervisor' => ['prohibited'],
            'digital_signature_manager' => ['prohibited'],
        ];
    }

    /** Live request checks, just like overtime: approval does not replace actual punches/hours. */
    public function annotate(array $rows, array $raw): array
    {
        if ($rows === []) {
            return [];
        }
        $requests = RequestForm::query()
            ->whereIn('type', [RequestForm::TYPE_DON_XIN_RA_NGOAI, RequestForm::TYPE_DON_XIN_DI_TRE_VE_SOM])
            ->whereIn('employee_id', array_unique(array_column($rows, 'employee_id')))
            ->get(['id', 'employee_id', 'type', 'form_data', 'status', 'rejection_reason'])
            ->groupBy(fn ($form) => (string) $form->employee_id);
        $punches = [];
        foreach ($raw as $entry) {
            $punches[$entry['employee_id'].'|'.$entry['date']] = $entry['dates'];
        }

        foreach ($rows as &$row) {
            $row['attendance_exceptions'] = array_map(function ($event) use ($row, $requests) {
                $forms = $this->matchingRequests($requests->get((string) $row['employee_id'], collect()), $row, $event);

                return [...$event,
                    'approved' => $forms->contains('status', RequestForm::STATUS_APPROVED),
                    'requests' => $forms->map(fn ($form) => [
                        'id' => $form->id, 'status' => $form->status, 'rejection_reason' => $form->rejection_reason,
                    ])->values()->all(),
                ];
            }, $this->events($row, $punches[$row['employee_id'].'|'.$row['date']] ?? [], $requests->get((string) $row['employee_id'], collect())));
        }

        return $rows;
    }

    public function events(array $row, array $dates, ?Collection $requests = null): array
    {
        $shift = (int) $row['shift'];
        if (! in_array($shift, [1, 2], true)) {
            return [];
        }
        $day = Carbon::parse($row['date'], 'Asia/Ho_Chi_Minh')->startOfDay();
        $start = $day->copy()->setTime($shift === 2 ? 21 : 7, 30);
        $end = $shift === 2 ? $day->copy()->addDay()->setTime(6, 30)
            : $day->copy()->setTime((string) ($row['calendar_category_id'] ?? '') === '4' ? 17 : 16, (string) ($row['calendar_category_id'] ?? '') === '4' ? 0 : 30);
        // Match the calculator's day/night scan windows; do not borrow another shift's scans.
        $windowStart = $shift === 2 ? $day->copy()->setTime(18, 0) : $day;
        $windowEnd = $shift === 2 ? $day->copy()->addDay()->setTime(12, 0) : $day->copy()->addDay();
        $scans = collect($dates)->pluck('datetime')->unique()->sort()->filter(
            fn ($value) => Carbon::parse($value, 'Asia/Ho_Chi_Minh')->gte($windowStart)
                && Carbon::parse($value, 'Asia/Ho_Chi_Minh')->lt($windowEnd)
        )->values()->all();
        $events = [];
        // Device timestamps do not identify exit/entry direction. Use submitted requests.
        foreach ($requests ?? [] as $form) {
            $data = $form->form_data;
            if ($form->type !== RequestForm::TYPE_DON_XIN_RA_NGOAI
                || (string) $form->employee_id !== (string) $row['employee_id']
                || $this->parse($data['ngay_ap_dung'] ?? null)?->toDateString() !== $row['date']
                || (int) ($data['ca_lam'] ?? 0) !== $shift) {
                continue;
            }
            $out = $this->requestTime($row, $data['gio_ra_ngoai'] ?? null);
            $back = $this->requestTime($row, $data['gio_quay_lai'] ?? null);
            if (! $out || ! $back || ! $back->gt($out)) {
                continue;
            }
            $events[] = [
                'kind' => 'ra_ngoai', 'time_out' => $out->toDateTimeString(),
                'time_in' => $back->toDateTimeString(), 'request_id' => $form->id,
            ];
        }
        if ($scans === []) {
            return $events;
        }
        if (Carbon::parse($scans[0], 'Asia/Ho_Chi_Minh')->startOfMinute()->gt($start)) {
            $events[] = ['kind' => 'di_tre', 'time_out' => null, 'time_in' => $scans[0]];
        }
        // A final checkout is only an early departure once the shift has ended.
        if (count($scans) % 2 === 0 && Carbon::now('Asia/Ho_Chi_Minh')->gte($end)) {
            $last = $scans[count($scans) - 1];
            if (Carbon::parse($last, 'Asia/Ho_Chi_Minh')->startOfMinute()->lt($end)) {
                $events[] = ['kind' => 've_som', 'time_out' => $last, 'time_in' => null];
            }
        }

        return $events;
    }

    public function matchingRequests(Collection $forms, array $row, array $event): Collection
    {
        return $forms->filter(function ($form) use ($row, $event) {
            if (isset($event['request_id']) && $form->id !== $event['request_id']) {
                return false;
            }
            $data = $form->form_data;
            $type = $event['kind'] === 'ra_ngoai' ? RequestForm::TYPE_DON_XIN_RA_NGOAI : RequestForm::TYPE_DON_XIN_DI_TRE_VE_SOM;
            $date = $this->parse($data['ngay_ap_dung'] ?? null)?->toDateString();
            if ($form->type !== $type || $date !== $row['date']
                || (isset($data['ca_lam']) && (int) $data['ca_lam'] !== (int) $row['shift'])) {
                return false;
            }
            $out = $this->requestTime($row, $data[$event['kind'] === 'ra_ngoai' ? 'gio_ra_ngoai' : 'gio_ve_som'] ?? null);
            $in = $this->requestTime($row, $data[$event['kind'] === 'ra_ngoai' ? 'gio_quay_lai' : 'gio_vao_tre'] ?? null);
            if ($event['kind'] === 'di_tre') {
                return $in && Carbon::parse($event['time_in'], 'Asia/Ho_Chi_Minh')->startOfMinute()->lte($in);
            }
            if ($event['kind'] === 've_som') {
                return $out && Carbon::parse($event['time_out'], 'Asia/Ho_Chi_Minh')->startOfMinute()->gte($out);
            }

            return $out && $in && $in->gt($out)
                && Carbon::parse($event['time_out'], 'Asia/Ho_Chi_Minh')->startOfMinute()->gte($out)
                && Carbon::parse($event['time_in'], 'Asia/Ho_Chi_Minh')->startOfMinute()->lte($in);
        });
    }

    private function requestTime(array $row, mixed $value): ?Carbon
    {
        $time = $this->parse($value);
        if (! $time) {
            return null;
        }
        $result = Carbon::parse($row['date'], 'Asia/Ho_Chi_Minh')->setTime($time->hour, $time->minute);
        if ((int) $row['shift'] === 2 && $time->hour < 12) {
            $result->addDay();
        }

        return $result;
    }

    private function parse(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value, 'Asia/Ho_Chi_Minh')->setTimezone('Asia/Ho_Chi_Minh');
        } catch (\Exception) {
            return null;
        }
    }
}
