<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Employee;
use App\Services\AttendanceImportQueue;
use App\Services\EmployeeCodeTransferService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AttendanceCodeTransferController extends BaseController
{
    public function show(string $id)
    {
        $this->employee($id);
        $row = DB::table('employee_code_transfers')->where('new_code', $id)->orderByDesc('id')->first();

        return response()->json(['transfer' => $row ? $this->data($row) : null]);
    }

    public function confirm(Request $request, string $id, int $transfer, EmployeeCodeTransferService $service)
    {
        $input = $request->validate([
            'old_code' => 'required|string', 'new_code' => 'required|string',
            'tested' => 'required|accepted', 'history_checked' => 'required|accepted',
        ]);

        return DB::transaction(function () use ($id, $transfer, $input, $service) {
            $employee = $this->employee($id, true);
            $row = DB::table('employee_code_transfers')->where('id', $transfer)->where('new_code', $id)->lockForUpdate()->first();
            abort_unless($row, 404, 'Không tìm thấy yêu cầu chuyển mã của nhân viên.');
            abort_if($input['old_code'] !== $row->old_code || $input['new_code'] !== $row->new_code
                || $employee->name !== $row->employee_name || $employee->trashed(), 409, 'Hồ sơ đã thay đổi. Tải lại trước khi xác nhận.');
            // Repeated submissions after a lost response must not reset or duplicate deletion.
            if (! in_array($row->status, ['delete_pending', 'deleting', 'completed'], true)) {
                abort_unless($row->status === 'awaiting_confirmation', 409, 'Chưa xác minh chuyển vân tay thành công. Không xóa mã cũ.');
                $service->confirm($row);
            }

            return response()->json(['transfer' => $this->data(DB::table('employee_code_transfers')->find($transfer))], 202);
        });
    }

    public function retry(Request $request, string $id, int $transfer, AttendanceImportQueue $imports)
    {
        $request->validate(['confirmed' => 'required|accepted']);

        return DB::transaction(function () use ($id, $transfer, $imports) {
            $employee = $this->employee($id, true);
            $row = DB::table('employee_code_transfers')->where('id', $transfer)->where('new_code', $id)->lockForUpdate()->first();
            abort_unless($row, 404, 'Không tìm thấy yêu cầu chuyển mã của nhân viên.');
            abort_if($employee->trashed() || $employee->name !== $row->employee_name, 409, 'Hồ sơ đã thay đổi. Vui lòng kiểm tra trước khi thử lại.');
            $history = DB::table('attendance_import_requests')->where('id', $row->history_import_id)->lockForUpdate()->first();
            // Never reclaim processing jobs or retry a deletion from this non-destructive control.
            abort_unless($history && ! $row->confirmed_at && ($row->status === 'failed'
                || ($row->status === 'pending' && $history->status === 'failed')), 409, 'Không thể thử lại ở trạng thái hiện tại. Tải lại hoặc liên hệ IT.');
            $imports->enqueue($history->start_date, $history->end_date, $row->new_code);
            DB::table('employee_code_transfers')->where('id', $transfer)->update([
                'status' => 'pending', 'error' => null, 'claim_token' => null, 'updated_at' => now(),
            ]);

            return response()->json(['transfer' => $this->data(DB::table('employee_code_transfers')->find($transfer))], 202);
        });
    }

    private function employee(string $id, bool $lock = false): Employee
    {
        abort_unless(Schema::hasTable('employee_code_transfers'), 503, 'BE chưa có bảng chuyển mã. Vui lòng liên hệ IT.');
        $query = Employee::withTrashed()->whereKey($id);
        $employee = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
        abort_if(in_array((int) $employee->role_id, [1, 15, 21, 22], true), 403, 'Nhóm tài khoản này không thuộc diện đồng bộ máy chấm công.');

        return $employee;
    }

    private function data(object $row): array
    {
        $history = $row->history_import_id ? DB::table('attendance_import_requests')->find($row->history_import_id) : null;
        $phase = $row->status;
        if (in_array($row->status, ['pending', 'delete_pending'], true)) {
            $phase = match ($history->status ?? null) {
                'pending' => 'history_pending', 'processing' => 'history_processing',
                'failed' => 'history_failed', 'succeeded' => $row->status,
                default => 'history_missing',
            };
        }
        $message = match ($phase) {
            'history_pending' => 'Chờ bridge lấy lịch sử chấm công.',
            'history_processing' => 'Bridge đang lấy lịch sử chấm công.',
            'history_failed' => $history->error ?: 'Lấy lịch sử chấm công thất bại.',
            'history_missing' => 'Không tìm thấy yêu cầu lấy công. Vui lòng liên hệ IT.',
            'pending' => 'Đã lấy lịch sử. Chờ bridge chuyển hồ sơ và vân tay.',
            'processing' => 'Bridge đang chuyển hồ sơ, vân tay và đọc lại để đối chiếu.',
            'awaiting_confirmation' => 'Đã chuyển và đối chiếu vân tay. Hãy chấm thử, kiểm tra máy ghi đúng mã mới trước khi xóa mã cũ.',
            'delete_pending' => 'Đã xác nhận chấm thử. Chờ bridge kiểm tra lại và xóa mã cũ.',
            'deleting' => 'Bridge đang kiểm tra mã mới và xóa hồ sơ mã cũ.',
            'completed' => 'Đã xác minh mã mới và xóa hồ sơ mã cũ trên máy chấm công.',
            'failed', 'delete_failed' => $row->error ?: 'Chưa hoàn tất. Vui lòng kiểm tra hoặc liên hệ IT.',
            'not_required' => 'Không yêu cầu chuyển mã trên máy chấm công.',
            default => 'Chưa xác định được trạng thái. Vui lòng liên hệ IT.',
        };
        $active = in_array($phase, ['history_pending', 'history_processing', 'pending', 'processing', 'delete_pending', 'deleting'], true);
        $end = $active ? CarbonImmutable::now() : CarbonImmutable::parse($row->updated_at);

        return [
            'id' => $row->id, 'old_code' => $row->old_code, 'new_code' => $row->new_code,
            'status' => $row->status, 'phase' => $phase, 'message' => $message, 'active' => $active,
            'elapsed_seconds' => max(0, CarbonImmutable::parse($row->created_at)->diffInSeconds($end, false)),
            'updated_at' => CarbonImmutable::parse($row->updated_at)->toIso8601String(),
            'can_confirm' => $row->status === 'awaiting_confirmation',
            'can_retry' => ! $row->confirmed_at && $history && ($row->status === 'failed' || ($row->status === 'pending' && $history->status === 'failed')),
            'history' => $history ? [
                'completed_days' => $history->completed_days,
                'total_days' => CarbonImmutable::parse($history->start_date)->diffInDays(CarbonImmutable::parse($history->end_date)) + 1,
            ] : null,
        ];
    }
}
