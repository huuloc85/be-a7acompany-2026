<?php

namespace App\Services;

use App\Models\RequestForm;
use Carbon\Carbon;

class AttendanceRequestService
{
    /** Attach current request statuses without changing attendance or payroll. */
    public function annotate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        // Do not filter by submitted_at: a request may be submitted in an earlier month.
        $requests = RequestForm::query()
            ->whereIn('employee_id', array_unique(array_column($rows, 'employee_id')))
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
