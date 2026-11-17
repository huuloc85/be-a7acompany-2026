<?php

namespace App\Services;

use App\Models\Employee;
use App\Support\AttendanceEmployeeCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmployeeCodeTransferService
{
    private ?bool $hasTransfers = null;

    public function assertUnused(string $code): void
    {
        if (AttendanceEmployeeCode::resolve($code) !== $code || (Schema::hasTable('employee_code_transfers')
            && DB::table('employee_code_transfers')->where('old_code', $code)->orWhere('new_code', $code)->exists())) {
            throw ValidationException::withMessages(['id' => 'Mã này đã có lịch sử chuyển mã. Không dùng lại cho nhân viên khác.']);
        }
    }

    public function assertIdle(array $codes): void
    {
        if (Schema::hasTable('employee_code_transfers') && DB::table('employee_code_transfers')
            ->where(fn ($q) => $q->whereIn('old_code', $codes)->orWhereIn('new_code', $codes))
            ->whereNotIn('status', ['completed', 'not_required'])->exists()) {
            throw ValidationException::withMessages(['id' => 'Đang chuyển mã trên máy chấm công. Hoàn tất chuyển mã trước khi đồng bộ, xóa hoặc đổi mã tiếp.']);
        }
    }

    public function record(string $old, Employee $employee, ?string $from = null): object
    {
        $new = (string) $employee->id;
        if ($old === $new || ! preg_match('/^[0-9]{1,20}$/', $old)
            || AttendanceEmployeeCode::resolve($old) !== $old || DB::table('employees')->where('id', $old)->exists()) {
            throw ValidationException::withMessages(['id' => 'Mã cũ không hợp lệ hoặc vẫn có hồ sơ trên web.']);
        }
        $this->assertIdle([$old, $new]);
        if (AttendanceEmployeeCode::resolve($new) !== $new || $this->resolve($new) !== $new
            || DB::table('attendance_device_requests')->whereIn('employee_id', [$old, $new])
                ->whereIn('status', ['pending', 'processing', 'delete_pending', 'delete_processing'])->exists()) {
            throw ValidationException::withMessages(['id' => 'Mã mới đã có ánh xạ hoặc đang có yêu cầu thiết bị. Kiểm tra trước khi chuyển.']);
        }
        if (DB::table('employee_code_transfers')->where('old_code', $old)->exists()) {
            throw ValidationException::withMessages(['id' => 'Mã cũ đã có ánh xạ. Không ghi đè lịch sử chuyển mã.']);
        }
        $required = ! in_array((int) $employee->role_id, [1, 15, 21, 22], true);
        $history = $required ? app(AttendanceImportQueue::class)->enqueue(
            $from ?? now('Asia/Ho_Chi_Minh')->subDay()->toDateString(),
            now('Asia/Ho_Chi_Minh')->toDateString(), $new
        ) : null;
        $id = DB::table('employee_code_transfers')->insertGetId([
            'old_code' => $old, 'new_code' => $new, 'employee_name' => $employee->name,
            'status' => $required ? 'pending' : 'not_required', 'history_import_id' => $history?->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('employee_code_transfers')->find($id);
    }

    public function resolve(string $code): string
    {
        if (! ($this->hasTransfers ??= Schema::hasTable('employee_code_transfers'))) {
            return $code;
        }
        $seen = [];
        while ($row = DB::table('employee_code_transfers')->where('old_code', $code)->lockForUpdate()->first()) {
            if (isset($seen[$code])) {
                throw new \RuntimeException('Ánh xạ mã nhân viên bị lặp. Dừng nhập công để tránh sai người.');
            }
            $seen[$code] = true;
            $code = $row->new_code;
        }

        return $code;
    }

    public function confirm(object $row): void
    {
        if ($row->status !== 'awaiting_confirmation') {
            throw new \RuntimeException('Chưa xác minh chuyển vân tay thành công. Không xóa mã cũ.');
        }
        $history = app(AttendanceImportQueue::class)->enqueue(
            substr($row->created_at, 0, 10), now('Asia/Ho_Chi_Minh')->toDateString(), $row->new_code
        );
        DB::table('employee_code_transfers')->where('id', $row->id)->update([
            'status' => 'delete_pending', 'confirmed_at' => now(), 'history_import_id' => $history->id, 'updated_at' => now(),
        ]);
    }

    public function processNext(): bool
    {
        if (! Schema::hasTable('employee_code_transfers')) {
            return false;
        }
        $job = DB::transaction(function () {
            $row = DB::table('employee_code_transfers')->whereIn('status', ['pending', 'delete_pending'])
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('attendance_import_requests')
                    ->whereColumn('attendance_import_requests.id', 'employee_code_transfers.history_import_id')
                    ->where('attendance_import_requests.status', 'succeeded'))
                ->orderBy('id')->lockForUpdate()->first();
            if (! $row) {
                return null;
            }
            $row->claim_token = (string) Str::uuid();
            $row->status = $row->status === 'delete_pending' ? 'deleting' : 'processing';
            DB::table('employee_code_transfers')->where('id', $row->id)->update([
                'status' => $row->status, 'claim_token' => $row->claim_token,
                'claimed_at' => now(), 'error' => null, 'updated_at' => now(),
            ]);

            return $row;
        });
        if (! $job) {
            return false;
        }
        // Never automatically reclaim an in-flight device mutation. An interrupted worker needs IT review.
        try {
            $employee = Employee::find($job->new_code);
            if (! $employee || $employee->name !== $job->employee_name
                || in_array((int) $employee->role_id, [1, 15, 21, 22], true)
                || DB::table('employees')->where('id', $job->old_code)->exists()) {
                throw new \RuntimeException('Hồ sơ web đã thay đổi. Dừng chuyển mã để tránh nhầm người.');
            }
            $device = app(AttendanceDeviceCodeTransfer::class);
            if ($job->status === 'deleting') {
                if (! $job->confirmed_at) {
                    throw new \RuntimeException('Chưa xác nhận chấm thử. Không xóa mã cũ.');
                }
                $device->verify($job, true);
                app(AttendanceDeviceDeleteService::class)->delete($job->old_code, $job->employee_name);
                $status = 'completed';
            } else {
                $device->copy($job);
                $status = 'awaiting_confirmation';
            }
            $error = null;
        } catch (\Throwable $e) {
            $status = $job->status === 'deleting' ? 'delete_failed' : 'failed';
            // Do not persist HTTP response bodies, credentials or fingerprint templates.
            $error = $e instanceof \UnexpectedValueException ? $e->getMessage()
                : 'Không hoàn tất chuyển mã. Giữ hồ sơ cũ; kiểm tra kết nối, hồ sơ và khả năng chuyển vân tay của máy trước khi thử lại.';
        }
        DB::table('employee_code_transfers')->where('id', $job->id)->where('claim_token', $job->claim_token)->update([
            'status' => $status, 'error' => $error, 'claim_token' => null, 'updated_at' => now(),
        ]);

        return true;
    }
}
