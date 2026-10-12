<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Support\VvpPayrollProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VvpPayrollHolidayController extends Controller
{
    public function show(Request $request, string $period)
    {
        abort_unless(VvpPayrollProfile::canRead($request->user()), 403);
        abort_unless(preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/D', $period), 422);

        return $this->respond(DB::table('payroll_holidays')->where('period', $period)->first());
    }

    public function update(Request $request, string $period)
    {
        abort_unless(VvpPayrollProfile::canEdit($request->user()), 403);
        abort_unless(preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/D', $period), 422);
        $data = $request->validate([
            'revision' => 'required|integer|min:0',
            'defaultDays' => 'required|numeric|min:0|max:31|decimal:0,2',
            'overrides' => 'present|array',
            'overrides.*' => 'required|numeric|min:0|max:31|decimal:0,2',
        ]);
        $row = DB::transaction(function () use ($data, $request, $period) {
            // Same ordered catalogue lock as profile updates; serializes first-period creation too.
            $employees = DB::table('payroll_profiles')->orderBy('employee_code')->lockForUpdate()->pluck('employee_code')->map(fn ($id) => (string) $id)->all();
            abort_unless(count($employees), 422, 'Chưa có danh mục nhân viên.');
            foreach ($data['overrides'] as $id => $days) {
                if (! in_array((string) $id, $employees, true)) {
                    throw ValidationException::withMessages(['overrides' => ['Mã nhân viên không có trong danh mục.']]);
                }
            }
            $row = DB::table('payroll_holidays')->where('period', $period)->lockForUpdate()->first();
            abort_if((int) ($row->revision ?? 0) !== (int) $data['revision'], 409, 'Công lễ đã được người khác sửa. Hãy tải lại trước khi lưu.');
            $values = [
                'default_days' => $data['defaultDays'],
                'overrides' => json_encode((object) $data['overrides'], JSON_THROW_ON_ERROR),
                'revision' => $data['revision'] + 1,
                'updated_by' => (string) $request->user()->getAuthIdentifier(),
                'updated_at' => now(),
            ];
            if ($row) {
                DB::table('payroll_holidays')->where('period', $period)->update($values);
            } else {
                DB::table('payroll_holidays')->insert([...$values, 'period' => $period, 'created_at' => now()]);
            }

            return DB::table('payroll_holidays')->where('period', $period)->first();
        });

        return $this->respond($row);
    }

    private function respond(?object $row)
    {
        return response()->json(['data' => [
            'defaultDays' => $row ? (float) $row->default_days : null,
            'overrides' => $row ? json_decode($row->overrides) : (object) [],
            'revision' => $row ? (int) $row->revision : 0,
        ]])->header('Cache-Control', 'private, no-store');
    }
}
