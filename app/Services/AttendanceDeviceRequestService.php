<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AttendanceDeviceRequestService
{
    public function enqueue(string $employeeId, string $requestedBy, string $action = 'sync', ?string $employeeName = null): object
    {
        if (! in_array($action, ['sync', 'delete'], true)) {
            throw new \InvalidArgumentException('Unsupported device action');
        }

        return DB::transaction(function () use ($employeeId, $requestedBy, $action, $employeeName) {
            $pending = $action === 'delete' ? 'delete_pending' : 'pending';
            DB::table('attendance_device_requests')->insertOrIgnore([
                'employee_id' => $employeeId, 'requested_by' => $requestedBy,
                'action' => $action, 'employee_name' => $employeeName,
                'status' => $pending, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = DB::table('attendance_device_requests')->where('employee_id', $employeeId)->lockForUpdate()->first();
            $active = in_array($row->status, ['pending', 'processing', 'delete_pending', 'delete_processing'], true);
            if ($active && $row->action !== $action) {
                throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('Nhân viên đang có yêu cầu khác. Chờ hoàn tất rồi thực hiện lại.');
            }
            // A repeated click must not reset a request being processed or already verified.
            if (! $active && ($row->status === 'failed' || $row->action !== $action)) {
                DB::table('attendance_device_requests')->where('id', $row->id)->update([
                    'status' => $pending, 'action' => $action, 'employee_name' => $employeeName,
                    'requested_by' => $requestedBy, 'error' => null,
                    'claim_token' => null, 'claimed_at' => null, 'completed_at' => null, 'updated_at' => now(),
                ]);
            }

            return DB::table('attendance_device_requests')->where('id', $row->id)->first();
        });
    }

    public function claim(): ?object
    {
        return DB::transaction(function () {
            // Recover work interrupted by sleep, reboot or a crashed process.
            $row = DB::table('attendance_device_requests')->where(function ($query) {
                $query->whereIn('status', ['pending', 'delete_pending'])->orWhere(function ($stale) {
                    $stale->whereIn('status', ['processing', 'delete_processing'])->where('claimed_at', '<', now()->subMinutes(5));
                });
            })->orderBy('id')->lockForUpdate()->first();
            if (! $row) {
                return null;
            }
            $token = (string) Str::uuid();
            DB::table('attendance_device_requests')->where('id', $row->id)->update([
                'status' => ($row->action ?? 'sync') === 'delete' ? 'delete_processing' : 'processing',
                'claim_token' => $token, 'claimed_at' => now(),
                'attempts' => $row->attempts + 1, 'error' => null, 'updated_at' => now(),
            ]);

            return DB::table('attendance_device_requests')->where('id', $row->id)->first();
        });
    }

    public function finish(object $job, ?string $error = null): void
    {
        DB::table('attendance_device_requests')->where('id', $job->id)
            ->where('claim_token', $job->claim_token)->whereIn('status', ['processing', 'delete_processing'])->update([
                'status' => $error === null ? 'succeeded' : 'failed',
                'error' => $error === null ? null : mb_substr($error, 0, 500),
                'completed_at' => now(), 'claim_token' => null, 'updated_at' => now(),
            ]);
    }
}
