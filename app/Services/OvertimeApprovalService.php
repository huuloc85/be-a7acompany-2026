<?php

namespace App\Services;

use App\Helpers\SeptemberAttendanceHours;
use App\Models\RequestForm;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class OvertimeApprovalService
{
    public const EFFECTIVE_DATE = '2026-10-16';

    public static function rules(): array
    {
        $today = now('Asia/Ho_Chi_Minh');
        $earliest = $today->copy()->startOfMonth()->subDays(15)->toDateString();

        return [
            'form_data' => ['required', 'array'],
            'form_data.ngay_ap_dung' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$earliest, 'before_or_equal:'.$today->copy()->endOfMonth()->toDateString()],
            'form_data.ca_lam' => ['required', 'integer', 'in:1,2'],
            'form_data.ly_do' => ['required', 'string', 'max:500'],
            // Applicants must not provide approver signatures themselves.
            'digital_signature_supervisor' => ['prohibited'],
            'digital_signature_manager' => ['prohibited'],
        ];
    }

    /** Add live approval metadata without changing or deleting actual attendance. */
    public function annotate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $dates = array_column($rows, 'date');
        $requests = RequestForm::query()
            ->whereIn('type', [RequestForm::TYPE_DON_XIN_TANG_CA, RequestForm::TYPE_DON_XIN_DI_TRE_VE_SOM])
            ->whereIn('employee_id', array_unique(array_column($rows, 'employee_id')))
            ->whereBetween('form_data->ngay_ap_dung', [min($dates), max($dates)])
            ->get(['id', 'employee_id', 'type', 'form_data', 'status', 'rejection_reason'])
            ->groupBy(fn ($form) => $form->employee_id.'|'.$form->form_data['ngay_ap_dung']);

        return array_map(function ($row) use ($requests) {
            $applicable = substr((string) $row['date'], 0, 10) >= self::EFFECTIVE_DATE;
            $dailyForms = $requests->get($row['employee_id'].'|'.$row['date'], collect());
            $forms = $dailyForms->where('type', RequestForm::TYPE_DON_XIN_TANG_CA)
                ->filter(fn ($form) => (int) ($form->form_data['ca_lam'] ?? 0) === (int) $row['shift']);
            $approved = $forms->contains('status', RequestForm::STATUS_APPROVED);
            $actualHours = (float) (in_array($row['hnhc'], ['TC', 'LN'], true)
                ? $row['total_hours'] : $row['overtime_hours']);
            if ($applicable && ! in_array($row['hnhc'], ['TC', 'LN'], true)) {
                $actualHours = max($actualHours, $this->halfDayOvertimeHours($row, $dailyForms));
            }
            $defaultHours = ! in_array($row['hnhc'], ['TC', 'LN'], true)
                && in_array((int) ($row['calendar_category_id'] ?? 0), [2, 3, 5, 6], true)
                ? min(1.0, max(0.0, $actualHours)) : 0.0;
            $requiresApprovalHours = $applicable ? max(0.0, $actualHours - $defaultHours) : 0.0;

            $row['overtime_approval'] = [
                'applicable' => $applicable,
                'approved' => $approved,
                'default_hours' => $defaultHours,
                'approved_hours' => $approved ? $requiresApprovalHours : 0,
                'unapproved_hours' => $approved ? 0 : $requiresApprovalHours,
                'requests' => $forms->map(fn ($form) => [
                    'id' => $form->id,
                    'status' => $form->status,
                    'rejection_reason' => $form->rejection_reason,
                ])->values()->all(),
            ];

            return $row;
        }, $rows);
    }

    private function halfDayOvertimeHours(array $row, Collection $forms): float
    {
        if ((string) ($row['calendar_category_id'] ?? '') !== '4' || (int) $row['shift'] !== 1
            || empty($row['time_in']) || empty($row['time_out'])) {
            return 0.0;
        }

        $day = Carbon::parse($row['date'], 'Asia/Ho_Chi_Minh')->startOfDay();
        $in = Carbon::parse($row['time_in'], 'Asia/Ho_Chi_Minh');
        $out = Carbon::parse($row['time_out'], 'Asia/Ho_Chi_Minh');
        $noon = $day->copy()->setTime(12, 0);
        $afternoon = $day->copy()->setTime(13, 0);
        $cutoff = null;
        $permissions = $forms->where('type', RequestForm::TYPE_DON_XIN_DI_TRE_VE_SOM)
            ->where('status', RequestForm::STATUS_APPROVED)
            ->filter(fn ($form) => ! isset($form->form_data['ca_lam']) || (int) $form->form_data['ca_lam'] === 1);

        if ($in->lte($day->copy()->setTime(7, 30))) {
            // A later checkout alone cannot distinguish morning overtime from early departure.
            $morningOnly = $permissions->contains(function ($form) use ($noon, $afternoon) {
                $value = $form->form_data['gio_ve_som'] ?? null;
                if (! $value) {
                    return false;
                }
                $time = Carbon::parse($value, 'Asia/Ho_Chi_Minh')->format('H:i');

                return $time >= $noon->format('H:i') && $time <= $afternoon->format('H:i');
            });
            if ($out->lte($afternoon) || $morningOnly) {
                $cutoff = $noon;
            }
        } elseif ($in->lte($afternoon)) {
            $afternoonOnly = $permissions->contains(function ($form) {
                $value = $form->form_data['gio_vao_tre'] ?? null;

                return $value && Carbon::parse($value, 'Asia/Ho_Chi_Minh')->format('H:i') === '13:00';
            });
            if ($in->gte($noon) || $afternoonOnly) {
                $cutoff = $day->copy()->setTime(17, 0);
            }
        }

        return $cutoff && $out->gt($cutoff)
            ? SeptemberAttendanceHours::calculate($row['date'], 1, '4', $cutoff->toDateTimeString(), $out->toDateTimeString())
            : 0.0;
    }
}
