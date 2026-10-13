<?php

namespace App\Services;

use App\Models\RequestForm;

class OvertimeApprovalService
{
    public const EFFECTIVE_DATE = '2026-10-16';

    public static function rules(): array
    {
        return [
            'form_data' => ['required', 'array'],
            'form_data.ngay_ap_dung' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.self::EFFECTIVE_DATE],
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
            ->where('type', RequestForm::TYPE_DON_XIN_TANG_CA)
            ->whereIn('employee_id', array_unique(array_column($rows, 'employee_id')))
            ->whereBetween('form_data->ngay_ap_dung', [min($dates), max($dates)])
            ->get(['id', 'employee_id', 'form_data', 'status', 'rejection_reason'])
            ->groupBy(fn ($form) => $form->employee_id.'|'.$form->form_data['ngay_ap_dung']);

        return array_map(function ($row) use ($requests) {
            $applicable = substr((string) $row['date'], 0, 10) >= self::EFFECTIVE_DATE;
            $forms = $requests->get($row['employee_id'].'|'.$row['date'], collect())
                ->filter(fn ($form) => (int) ($form->form_data['ca_lam'] ?? 0) === (int) $row['shift']);
            $approved = $forms->contains('status', RequestForm::STATUS_APPROVED);
            $actualHours = (float) (in_array($row['hnhc'], ['TC', 'LN'], true)
                ? $row['total_hours'] : $row['overtime_hours']);
            $defaultHours = ! in_array($row['hnhc'], ['TC', 'LN'], true)
                && in_array((int) ($row['calendar_category_id'] ?? 0), [2, 3, 6], true)
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
}
