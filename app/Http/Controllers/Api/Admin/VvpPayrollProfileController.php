<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Support\VvpPayrollProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VvpPayrollProfileController extends Controller
{
    public function store(Request $request)
    {
        abort_unless(VvpPayrollProfile::canEdit($request->user()), 403);
        $validated = $request->validate(['profile' => 'required|array', 'effectiveFrom' => 'required|date_format:Y-m-d']);
        $profile = VvpPayrollProfile::validate($validated['profile']);
        $employeeCode = $profile['employeeId'];
        $target = $employeeCode;
        $profile['attendanceEmployeeId'] = $target;

        return DB::transaction(function () use ($request, $validated, $profile, $employeeCode, $target) {
            $employee = DB::table('employees')->where('id', $target)->lockForUpdate()->first(['id', 'name', 'company', 'deleted_at']);
            if (! $employee || $employee->deleted_at !== null || ! in_array(mb_strtolower(trim($employee->company ?? '')), ['vvp', 'vinh vinh phát', 'a7a'], true)) {
                throw ValidationException::withMessages(['profile.attendanceEmployeeId' => ['Không tìm thấy nhân viên VVP/A7A theo mã web.']]);
            }
            abort_if(DB::table('vvp_payroll_profiles')->where('employee_code', $employeeCode)->exists(), 409, 'Mã nhân viên đã có trong danh mục.');
            abort_if(DB::table('vvp_payroll_profiles')->where('employee_id', $target)->exists(), 409, 'Nhân viên web đã được liên kết trong danh mục.');
            $profile['name'] = $employee->name;
            DB::table('vvp_payroll_profiles')->insert([
                'employee_code' => $employeeCode,
                'employee_id' => $target,
                'profile' => json_encode($profile, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'revision' => 1,
                'source' => 'Web catalogue',
                'source_row' => ((int) DB::table('vvp_payroll_profiles')->max('source_row')) + 1,
                'effective_from' => $validated['effectiveFrom'],
                'updated_by' => (string) $request->user()->getAuthIdentifier(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $saved = DB::table('vvp_payroll_profiles')->where('employee_code', $employeeCode)->first();

            return response()->json(['data' => $this->present($saved, $employee)], 201);
        });
    }

    public function destroy(Request $request, string $employeeCode)
    {
        abort_unless(VvpPayrollProfile::canEdit($request->user()), 403);
        $validated = $request->validate(['revision' => 'required|integer|min:1']);
        DB::transaction(function () use ($employeeCode, $validated) {
            $row = DB::table('vvp_payroll_profiles')->where('employee_code', $employeeCode)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_if((int) $row->revision !== (int) $validated['revision'], 409, 'Danh mục đã thay đổi. Vui lòng tải lại trước khi xoá.');
            DB::table('vvp_payroll_profiles')->where('employee_code', $employeeCode)->delete();
        });

        return response()->json(['data' => ['employeeCode' => $employeeCode]]);
    }

    public function index(Request $request)
    {
        abort_unless(VvpPayrollProfile::canRead($request->user()), 403);
        $rows = DB::table('vvp_payroll_profiles')->orderBy('source_row')->get();
        $codes = $rows->pluck('employee_id')->filter();
        $employees = DB::table('employees')->whereIn('id', $codes)->get(['id', 'name', 'company', 'deleted_at'])->keyBy('id');

        return response()->json([
            'data' => $rows->map(function ($row) use ($employees) {
                return $this->present($row, $employees->get($row->employee_id));
            }),
            'canEdit' => VvpPayrollProfile::canEdit($request->user()),
            'availableEmployees' => VvpPayrollProfile::canEdit($request->user())
                ? DB::table('employees')->whereNull('deleted_at')->whereNotIn('id', $codes)
                    ->orderBy('name')->get(['id', 'name', 'company'])
                    ->filter(fn ($employee) => in_array(mb_strtolower(trim($employee->company ?? '')), ['vvp', 'vinh vinh phát', 'a7a'], true))
                    ->map(fn ($employee) => ['id' => (string) $employee->id, 'name' => $employee->name, 'company' => $employee->company])->values()
                : [],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, string $employeeCode)
    {
        abort_unless(VvpPayrollProfile::canEdit($request->user()), 403);
        $validated = $request->validate(['revision' => 'required|integer|min:1', 'profile' => 'required|array']);
        $profile = VvpPayrollProfile::validate($validated['profile']);
        if ($profile['employeeId'] !== $employeeCode) {
            throw ValidationException::withMessages(['profile.employeeId' => ['Không được đổi mã nhân viên.']]);
        }

        $row = DB::transaction(function () use ($employeeCode, $validated, $profile, $request) {
            // Lock in a consistent order so simultaneous mappings cannot claim the same web ID.
            $rows = DB::table('vvp_payroll_profiles')->orderBy('employee_code')->lockForUpdate()->get();
            $row = $rows->firstWhere('employee_code', $employeeCode);
            abort_unless($row, 404);
            abort_if((int) $row->revision !== (int) $validated['revision'], 409, 'Danh mục đã được người khác sửa. Vui lòng tải lại trước khi lưu.');
            $previous = json_decode($row->profile, true, 512, JSON_THROW_ON_ERROR);
            if (! array_key_exists('attendanceEmployeeId', $profile) && isset($previous['attendanceEmployeeId'])) {
                $profile['attendanceEmployeeId'] = $row->employee_id ?: $previous['attendanceEmployeeId'];
            }
            $target = ($profile['attendanceEmployeeId'] ?? '') ?: $employeeCode;
            $employee = DB::table('employees')->where('id', $target)->first(['id', 'name', 'company', 'deleted_at']);
            if (! $employee || ! in_array(mb_strtolower(trim($employee->company ?? '')), ['vvp', 'vinh vinh phát', 'a7a'], true)) {
                throw ValidationException::withMessages(['profile.attendanceEmployeeId' => ['Không tìm thấy nhân viên VVP/A7A theo mã web.']]);
            }
            // Employees owns identity; catalogue owns salary inputs only.
            $profile['name'] = $employee->name;
            foreach ($rows as $other) {
                if ((string) $other->employee_code === $employeeCode) {
                    continue;
                }
                $otherProfile = json_decode($other->profile, true, 512, JSON_THROW_ON_ERROR);
                $claimed = $other->employee_id ?: (($otherProfile['attendanceEmployeeId'] ?? '') ?: $other->employee_code);
                if ((string) $claimed === $target) {
                    throw ValidationException::withMessages(['profile.attendanceEmployeeId' => ['Mã chấm công đã liên kết với hồ sơ lương khác.']]);
                }
            }
            DB::table('vvp_payroll_profiles')->where('employee_code', $employeeCode)->update([
                'employee_id' => $target,
                'profile' => json_encode($profile, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'revision' => $row->revision + 1,
                'updated_by' => (string) $request->user()->getAuthIdentifier(),
                'updated_at' => now(),
            ]);

            return DB::table('vvp_payroll_profiles')->where('employee_code', $employeeCode)->first();
        });

        $employee = DB::table('employees')->where('id', $row->employee_id)->first(['id', 'name', 'company', 'deleted_at']);

        return response()->json(['data' => $this->present($row, $employee)])->header('Cache-Control', 'private, no-store');
    }

    private function present(object $row, ?object $employee): array
    {
        $profile = json_decode($row->profile, true, 512, JSON_THROW_ON_ERROR);
        $linked = $employee && in_array(mb_strtolower(trim($employee->company ?? '')), ['vvp', 'vinh vinh phát', 'a7a'], true);
        if ($linked) {
            $profile['name'] = $employee->name;
            $profile['company'] = $employee->company;
            $profile['attendanceEmployeeId'] = (string) $row->employee_id;
        }

        return [
            'employee_id' => $row->employee_id,
            'profile' => $profile,
            'employeeLinked' => (bool) $linked,
            'employeeInactive' => $linked && $employee->deleted_at !== null,
            'revision' => (int) $row->revision,
            'source' => $row->source,
            'sourceRow' => (int) $row->source_row,
            'effectiveFrom' => $row->effective_from,
            'updatedAt' => $row->updated_at,
        ];
    }
}
