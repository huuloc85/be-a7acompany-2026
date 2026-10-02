<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AttendanceDeviceRequestService
{
    public function enqueue(string $employeeId, string $requestedBy): object
    {
        return DB::transaction(function () use ($employeeId, $requestedBy) {
            DB::table('attendance_device_requests')->insertOrIgnore([
                'employee_id' => $employeeId, 'requested_by' => $requestedBy,
                'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = DB::table('attendance_device_requests')->where('employee_id', $employeeId)->lockForUpdate()->first();
            // A repeated click must not reset a request being processed or already verified.
            if ($row->status === 'failed') {
                DB::table('attendance_device_requests')->where('id', $row->id)->update([
                    'status' => 'pending', 'requested_by' => $requestedBy, 'error' => null,
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
                $query->where('status', 'pending')->orWhere(function ($stale) {
                    $stale->where('status', 'processing')->where('claimed_at', '<', now()->subMinutes(5));
                });
            })->orderBy('id')->lockForUpdate()->first();
            if (! $row) {
                return null;
            }
            $token = (string) Str::uuid();
            DB::table('attendance_device_requests')->where('id', $row->id)->update([
                'status' => 'processing', 'claim_token' => $token, 'claimed_at' => now(),
                'attempts' => $row->attempts + 1, 'error' => null, 'updated_at' => now(),
            ]);

            return DB::table('attendance_device_requests')->where('id', $row->id)->first();
        });
    }

    public function finish(object $job, ?string $error = null): void
    {
        DB::table('attendance_device_requests')->where('id', $job->id)
            ->where('claim_token', $job->claim_token)->where('status', 'processing')->update([
                'status' => $error === null ? 'succeeded' : 'failed',
                'error' => $error === null ? null : mb_substr($error, 0, 500),
                'completed_at' => now(), 'claim_token' => null, 'updated_at' => now(),
            ]);
    }
}
