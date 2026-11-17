<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class EmployeeCodeChangeService
{
    public function update(Employee $employee, array $attributes): void
    {
        DB::transaction(function () use ($employee, $attributes) {
            $old = (string) $employee->getKey();
            $new = (string) ($attributes['id'] ?? $old);
            if ($new === $old) {
                if ((isset($attributes['name']) && $attributes['name'] !== $employee->name)
                    || (isset($attributes['role_id']) && (int) $attributes['role_id'] !== (int) $employee->role_id)) {
                    app(EmployeeCodeTransferService::class)->assertIdle([$old]);
                }
                $employee->update($attributes);

                return;
            }

            if (! Schema::hasTable('employee_code_transfers')) {
                throw ValidationException::withMessages(['id' => 'Chưa cài đặt hàng đợi chuyển mã. Vui lòng cập nhật BE và bridge trước khi đổi mã.']);
            }
            app(EmployeeCodeTransferService::class)->assertIdle([$old, $new]);
            app(EmployeeCodeTransferService::class)->assertUnused($new);
            if (isset($attributes['name']) && $attributes['name'] !== $employee->name) {
                throw ValidationException::withMessages(['name' => 'Giữ nguyên tên khi chuyển mã để đối chiếu hồ sơ máy chấm công. Đổi tên sau khi chuyển xong.']);
            }

            if (DB::table('employees')->where('id', $new)->exists()) {
                throw ValidationException::withMessages(['id' => 'Mã nhân viên đã được sử dụng.']);
            }

            // Device jobs retain the physical device code. Never retarget a pending job.
            if (Schema::hasTable('attendance_device_requests') && DB::table('attendance_device_requests')
                ->whereIn('employee_id', [$old, $new])
                ->whereIn('status', ['pending', 'processing', 'delete_pending', 'delete_processing'])
                ->lockForUpdate()->first()) {
                throw ValidationException::withMessages(['id' => 'Đang có yêu cầu máy chấm công. Chờ hoàn tất rồi đổi mã nhân viên.']);
            }

            // FK-backed relations use ON UPDATE CASCADE. These relations have no FK.
            $references = [
                'stock_transactions' => ['employee_id'],
                'onboarding_progress' => ['employee_id'],
                'attendance_daily_results' => ['employee_id'],
                'payroll_snapshot_members' => ['employee_id'],
                'payroll_profiles' => ['employee_code', 'updated_by'],
                'payroll_adjustments' => ['updated_by'],
                'payroll_holidays' => ['updated_by'],
                'attendance_import_requests' => ['requested_by'],
                'attendance_device_requests' => ['requested_by'],
            ];

            foreach ($references as $table => $columns) {
                foreach ($columns as $column) {
                    if (Schema::hasColumn($table, $column) && DB::table($table)->where($column, $new)->exists()) {
                        throw ValidationException::withMessages(['id' => 'Mã mới đã có dữ liệu liên quan. Vui lòng kiểm tra trước khi đổi mã.']);
                    }
                }
            }

            $employee->update($attributes);
            app(EmployeeCodeTransferService::class)->record($old, $employee);
            foreach ($references as $table => $columns) {
                foreach ($columns as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        DB::table($table)->where($column, $old)->update([$column => $new]);
                    }
                }
            }
            if (Schema::hasTable('personal_access_tokens')) {
                DB::table('personal_access_tokens')
                    ->where('tokenable_type', $employee->getMorphClass())
                    ->where('tokenable_id', $old)->update(['tokenable_id' => $new]);
            }
        });
    }
}
