<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Employee;
use App\Services\AttendanceDeviceRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceDeviceEmployeeController extends BaseController
{
    public function store(Request $request, string $id, AttendanceDeviceRequestService $queue)
    {
        $request->validate(['confirmed' => 'required|accepted']);
        $employee = Employee::findOrFail($id);
        if (in_array((int) $employee->role_id, [1, 15, 21, 22], true)) {
            return response()->json(['message' => 'Nhóm tài khoản này không thuộc diện đồng bộ máy chấm công.'], 422);
        }
        $job = $queue->enqueue((string) $employee->id, (string) $request->user()->getAuthIdentifier());

        return response()->json($this->statusData($job), $job->status === 'succeeded' ? 200 : 202);
    }

    public function show(string $id)
    {
        Employee::findOrFail($id);
        $job = DB::table('attendance_device_requests')->where('employee_id', $id)->first();

        return response()->json($this->statusData($job));
    }

    private function statusData(?object $job): array
    {
        $status = $job->status ?? 'not_requested';
        $message = match ($status) {
            'pending' => 'Đã lưu yêu cầu. Chờ máy trung gian trong LAN xử lý.',
            'processing' => 'Máy trung gian đang thêm hồ sơ và kiểm tra quyền.',
            'succeeded' => 'Đã xác minh quyền trên máy chấm công. Bạn có thể đăng ký vân tay.',
            'failed' => $job->error ?? 'Đồng bộ thất bại. Vui lòng thử lại.',
            default => 'Chưa gửi yêu cầu đồng bộ.',
        };

        return ['status' => $status, 'message' => $message, 'updated_at' => $job->updated_at ?? null];
    }
}
