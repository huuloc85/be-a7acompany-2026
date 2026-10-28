<?php

namespace App\Services;

use App\Models\RequestForm;
use Carbon\Carbon;

class AttendanceRequestService
{
    /** Attach current request statuses without changing attendance or payroll. */
    public function annotate(array $rows, string $employeeId, string $employeeName, string $startDate, string $endDate): array
    {
        // Do not filter by submitted_at: a request may be submitted in an earlier month.
        $requests = RequestForm::query()
            ->where('employee_id', $employeeId)
            ->whereIn('type', [RequestForm::TYPE_DON_XIN_NGHI_PHEP, RequestForm::TYPE_DON_XIN_DI_TRE_VE_SOM])
            ->orderBy('id')
            ->get(['id', 'employee_id', 'type', 'form_data', 'status', 'rejection_reason'])
            ->map(function ($request) {
                $data = $request->form_data;
                $leave = $request->type === RequestForm::TYPE_DON_XIN_NGHI_PHEP;
                $single = $data['ngay_nghi_phep'] ?? $data['ngay_nghi'] ?? null;
                $from = $leave ? ($single ?? $data['ngay_nghi_phep_tu'] ?? $data['ngay_nghi_tu'] ?? null) : ($data['ngay_ap_dung'] ?? null);
                $to = $leave ? ($single ?? $data['ngay_nghi_phep_den'] ?? $data['ngay_nghi_den'] ?? null) : $from;

                return [
                    'employee_id' => (string) $request->employee_id,
                    'from' => $this->formatDateTime($from, 'Y-m-d'),
                    'to' => $this->formatDateTime($to, 'Y-m-d'),
                    'request' => [
                        'id' => $request->id,
                        'type' => $request->type,
                        'status' => $request->status,
                        'rejection_reason' => $request->rejection_reason,
                        'time_in' => $leave ? null : $this->formatDateTime($data['gio_vao_tre'] ?? null, 'H:i'),
                        'time_out' => $leave ? null : $this->formatDateTime($data['gio_ve_som'] ?? null, 'H:i'),
                    ],
                ];
            })
            ->filter(fn ($item) => $item['from'] && $item['to'] && $item['from'] <= $item['to'])
            ->groupBy('employee_id');

        // Display-only rows: never pass these through attendance/payroll calculations.
        $existingDates = array_fill_keys(array_map(fn ($row) => substr((string) $row['date'], 0, 10), $rows), true);
        foreach ($requests->get($employeeId, collect()) as $item) {
            if ($item['request']['type'] !== RequestForm::TYPE_DON_XIN_NGHI_PHEP) {
                continue;
            }
            $from = max($startDate, $item['from']);
            $to = min($endDate, $item['to']);
            for ($day = Carbon::parse($from); $day->format('Y-m-d') <= $to; $day->addDay()) {
                $date = $day->format('Y-m-d');
                if (isset($existingDates[$date])) {
                    continue;
                }
                $rows[] = [
                    'employee_id' => $employeeId,
                    'name' => $employeeName,
                    'company' => null,
                    'calendar_category_id' => null,
                    'date' => $date,
                    'shift' => 0,
                    'hnhc' => null,
                    'day_type' => '',
                    'is_schedule_change' => false,
                    'time_in' => null,
                    'time_out' => null,
                    'total_hours' => 0,
                    'overtime_hours' => 0,
                    'administrative_hours' => 0,
                ];
                $existingDates[$date] = true;
            }
        }
        usort($rows, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return array_map(function ($row) use ($requests) {
            $date = substr((string) $row['date'], 0, 10);
            $row['attendance_requests'] = $requests->get((string) $row['employee_id'], collect())
                ->filter(fn ($item) => $item['from'] <= $date && $item['to'] >= $date)
                ->pluck('request')->values()->all();

            return $row;
        }, $rows);
    }

    private function formatDateTime(mixed $value, string $format): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value, 'Asia/Ho_Chi_Minh')->setTimezone('Asia/Ho_Chi_Minh')->format($format);
        } catch (\Exception) {
            return null;
        }
    }
}
