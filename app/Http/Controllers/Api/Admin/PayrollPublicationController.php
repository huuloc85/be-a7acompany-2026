<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Support\VvpPayrollProfile;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayrollPublicationController extends Controller
{
    public function show(Request $request, string $company, string $period)
    {
        abort_unless(VvpPayrollProfile::canRead($request->user()), 403);
        $this->validateKey($company, $period);

        return response()->json(['data' => DB::table('salaries_manager')->where('calculator_key', "$company:$period")->first()]);
    }

    private function validateKey(string $company, string $period): void
    {
        validator(compact('company', 'period'), ['company' => 'required|in:vvp,a7a', 'period' => 'required|date_format:Y-m'])->validate();
    }

    public function save(Request $request, string $company, string $period)
    {
        abort_unless(VvpPayrollProfile::canEdit($request->user()), 403);
        $this->validateKey($company, $period);
        $rules = ['revision' => 'required|integer|min:0', 'draft' => 'required|array',
            'draft.startDate' => 'required|date_format:Y-m-d', 'draft.endDate' => 'required|date_format:Y-m-d',
            'draft.rows' => 'required|array|min:1|max:2000', 'draft.rules' => 'required|array',
            'draft.rows.*.profile' => 'required|array', 'draft.rows.*.profile.employeeId' => 'required|string',
            'draft.rows.*.adjustments' => 'required|array', 'draft.rows.*.hours' => 'required|array'];
        $amounts = ['dayDays', 'nightDays', 'daySalary', 'nightSalary', 'dayOvertimeSalary', 'nightOvertimeSalary', 'reinforcementSalary', 'housing', 'travel', 'meal', 'holidaySalary', 'paidLeaveSalary', 'downtimeSalary', 'menstrualSalary', 'diligence', 'gross', 'union', 'social', 'health', 'unemployment', 'personalDeduction', 'dependentDeduction', 'taxable', 'tax', 'net'];
        foreach ($amounts as $field) {
            $rules["draft.rows.*.$field"] = 'required|numeric|between:-1000000000000,1000000000000';
        }
        foreach (['bonus', 'referral', 'otherIncome', 'advance', 'otherDeduction'] as $field) {
            $rules["draft.rows.*.adjustments.$field"] = 'required|numeric|between:0,1000000000000';
        }
        $data = $request->validate($rules);
        $draft = $data['draft'];
        $month = Carbon::createFromFormat('!Y-m', $period);
        abort_unless($draft['startDate'] === $month->copy()->subMonth()->day(16)->toDateString()
            && $draft['endDate'] === $month->copy()->day(15)->toDateString(), 422, 'Kỳ công không khớp tháng lương.');
        $seen = [];
        foreach ($draft['rows'] as &$row) {
            $id = (string) ($row['profile']['attendanceEmployeeId'] ?? $row['profile']['employeeId']);
            $employee = DB::table('employees')->where('id', $id)->first(['id', 'name', 'company']);
            $employeeCompany = mb_strtolower(trim($employee->company ?? ''));
            if ($employeeCompany === 'vinh vinh phát') {
                $employeeCompany = 'vvp';
            }
            abort_unless($employee && $employeeCompany === $company && ! isset($seen[$id]), 422, 'Nhân viên không khớp công ty hoặc bị trùng.');
            $seen[$id] = true;
            $row['profile']['employeeId'] = $id;
            $row['profile']['attendanceEmployeeId'] = $id;
            $row['profile']['name'] = $employee->name;
            $row['profile']['company'] = $employee->company;
            // Verify accounting identities; admin reviews the calculation snapshot before publication.
            $gross = array_sum(array_map(fn ($key) => $row[$key], ['daySalary', 'nightSalary', 'dayOvertimeSalary', 'nightOvertimeSalary', 'reinforcementSalary', 'housing', 'travel', 'meal', 'holidaySalary', 'paidLeaveSalary', 'downtimeSalary', 'menstrualSalary', 'diligence']))
                + $row['adjustments']['bonus'] + $row['adjustments']['referral'] + $row['adjustments']['otherIncome'];
            $net = $gross - $row['union'] - $row['social'] - $row['health'] - $row['unemployment'] - $row['tax'] - $row['adjustments']['advance'] - $row['adjustments']['otherDeduction'];
            abort_unless(abs($gross - $row['gross']) < 1 && abs($net - $row['net']) < 1, 422, 'Tổng thu nhập hoặc thực lãnh không khớp.');
        }
        unset($row);

        return DB::transaction(function () use ($company, $period, $month, $draft, $data) {
            // Unique key + row lock prevents duplicate periods and concurrent overwrites.
            DB::table('salaries_manager')->insertOrIgnore([
                'calculator_key' => "$company:$period", 'title' => 'Lương '.strtoupper($company).' '.$period,
                'start_date' => $draft['startDate'], 'end_date' => $draft['endDate'],
                'date_show' => $month->copy()->addMonth()->startOfMonth()->toDateString(),
                'status' => 'draft', 'revision' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $salary = DB::table('salaries_manager')->where('calculator_key', "$company:$period")->lockForUpdate()->first();
            abort_unless($salary->status === 'draft', 409, 'Bảng lương đã công bố; không thể ghi đè.');
            abort_unless((int) $salary->revision === $data['revision'], 409, 'Bản nháp đã thay đổi. Tải lại trước khi lưu.');
            DB::table('payroll_snapshots')->updateOrInsert(['salary_id' => $salary->id], ['draft' => json_encode($draft, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
            DB::table('payroll_snapshot_members')->where('salary_id', $salary->id)->delete();
            DB::table('payroll_snapshot_members')->insert(array_map(fn ($row) => ['salary_id' => $salary->id, 'employee_id' => $row['profile']['employeeId']], $draft['rows']));
            DB::table('salaries_manager')->where('id', $salary->id)->update(['revision' => $salary->revision + 1, 'total' => array_sum(array_column($draft['rows'], 'net')), 'updated_at' => now()]);

            return response()->json(['data' => DB::table('salaries_manager')->find($salary->id)]);
        });
    }

    public function publish(Request $request, int $id)
    {
        abort_unless(VvpPayrollProfile::canEdit($request->user()), 403);
        $data = $request->validate(['revision' => 'required|integer|min:1']);

        return DB::transaction(function () use ($id, $request, $data) {
            $salary = DB::table('salaries_manager')->where('id', $id)->lockForUpdate()->first();
            abort_unless($salary && $salary->calculator_key, 404);
            abort_unless((int) $salary->revision === $data['revision'] && $salary->status === 'draft', 409, 'Bảng lương đã thay đổi; hãy tải lại để duyệt đúng bản.');
            abort_unless(DB::table('payroll_snapshots')->where('salary_id', $id)->exists(), 422);
            DB::table('salaries_manager')->where('id', $id)->update(['status' => 'published', 'published_at' => now(), 'published_by' => (string) $request->user()->getAuthIdentifier(), 'updated_at' => now()]);

            return response()->json(['data' => DB::table('salaries_manager')->find($id)]);
        });
    }
}
