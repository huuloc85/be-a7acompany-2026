<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Support\VvpPayrollProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VvpPayrollAdjustmentController extends Controller
{
    public function index(Request $request, string $period)
    {
        abort_unless(VvpPayrollProfile::canRead($request->user()), 403);
        abort_unless(preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/D', $period), 422);
        $rows = DB::table('vvp_payroll_adjustments')->where('period', $period)->get();

        return response()->json(['data' => $rows->map(fn ($row) => $this->present($row))])
            ->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, string $period, string $employeeId)
    {
        abort_unless(VvpPayrollProfile::canEdit($request->user()), 403);
        abort_unless(preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/D', $period), 422);
        $rules = ['revision' => 'required|integer|min:0', 'adjustment' => 'required|array'];
        foreach (['paidLeaveDays', 'downtimeDays', 'authorizedLeaveDays', 'unauthorizedLeaveDays'] as $field) {
            $rules["adjustment.$field"] = 'sometimes|required|numeric|min:0|max:31|decimal:0,2';
        }
        foreach (['menstrualHours', 'diligence', 'bonus', 'referral', 'otherIncome', 'taxExemptIncome', 'advance', 'otherDeduction'] as $field) {
            $rules["adjustment.$field"] = 'sometimes|required|numeric|min:0|max:1000000000000';
        }
        $rules['adjustment.noAttendanceConfirmed'] = 'sometimes|boolean';
        $rules['adjustment.note'] = 'sometimes|nullable|string|max:2000';
        $data = $request->validate($rules);
        // Store only explicitly supported fields; holiday days have their own API.
        $allowed = array_map(fn ($key) => substr($key, 11), array_filter(array_keys($rules), fn ($key) => str_starts_with($key, 'adjustment.')));
        $values = array_intersect_key($data['adjustment'], array_flip($allowed));
        if (array_key_exists('note', $values)) {
            $values['note'] = $values['note'] ?? '';
        }
        foreach ($values as $key => $value) {
            if ($key === 'note') {
                continue;
            }
            $values[$key] = $key === 'noAttendanceConfirmed' ? (bool) $value : (float) $value;
        }
        $row = DB::transaction(function () use ($request, $period, $employeeId, $data, $values) {
            // Lock the catalogue row to serialize initial creation as well as updates.
            $profile = DB::table('vvp_payroll_profiles')->where('employee_id', $employeeId)->lockForUpdate()->first();
            abort_unless($profile, 422, 'Nhân viên chưa có hồ sơ lương liên kết.');
            $query = DB::table('vvp_payroll_adjustments')->where('period', $period)->where('employee_id', $employeeId);
            $existing = (clone $query)->lockForUpdate()->first();
            abort_if((int) ($existing->revision ?? 0) !== (int) $data['revision'], 409, 'Khoản phát sinh đã được người khác sửa. Hãy tải lại trước khi lưu.');
            $payload = [
                'adjustment' => json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'revision' => $data['revision'] + 1,
                'updated_by' => (string) $request->user()->getAuthIdentifier(),
                'updated_at' => now(),
            ];
            if ($existing) {
                $query->update($payload);
            } else {
                DB::table('vvp_payroll_adjustments')->insert([...$payload, 'period' => $period, 'employee_id' => $employeeId, 'created_at' => now()]);
            }

            return $query->first();
        });

        return response()->json(['data' => $this->present($row)])->header('Cache-Control', 'private, no-store');
    }

    private function present(object $row): array
    {
        return ['employeeId' => (string) $row->employee_id, 'adjustment' => json_decode($row->adjustment), 'revision' => (int) $row->revision];
    }
}
