<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AttendanceImportQueue
{
    public function enqueue(string $start, string $end, string $requestedBy): object
    {
        return DB::transaction(function () use ($start, $end, $requestedBy) {
            DB::table('attendance_import_requests')->insertOrIgnore([
                'start_date' => $start, 'end_date' => $end, 'requested_by' => $requestedBy,
                'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = DB::table('attendance_import_requests')->where('start_date', $start)
                ->where('end_date', $end)->lockForUpdate()->first();
            if (in_array($row->status, ['succeeded', 'failed'], true)) {
                // A later run must re-read the device: today's events may have changed.
                DB::table('attendance_import_requests')->where('id', $row->id)->update([
                    'status' => 'pending', 'requested_by' => $requestedBy, 'error' => null,
                    'claim_token' => null, 'claimed_at' => null, 'completed_days' => 0,
                    'inserted' => 0, 'existing' => 0, 'unmatched' => 0, 'skipped' => 0,
                    'unmatched_codes' => null, 'updated_at' => now(),
                ]);
            }

            return DB::table('attendance_import_requests')->find($row->id);
        });
    }

    public function claim(): ?object
    {
        return DB::transaction(function () {
            $row = DB::table('attendance_import_requests')->where(function ($query) {
                $query->where('status', 'pending')->orWhere(function ($stale) {
                    $stale->where('status', 'processing')->where('claimed_at', '<', now()->subMinutes(5));
                });
            })->orderBy('updated_at')->orderBy('id')->lockForUpdate()->first();
            if (! $row) {
                return null;
            }
            DB::table('attendance_import_requests')->where('id', $row->id)->update([
                'status' => 'processing', 'claim_token' => (string) Str::uuid(),
                'claimed_at' => now(), 'updated_at' => now(), 'error' => null,
            ]);

            return DB::table('attendance_import_requests')->find($row->id);
        });
    }

    public function heartbeat(object $job): void
    {
        $changed = DB::table('attendance_import_requests')->where('id', $job->id)
            ->where('claim_token', $job->claim_token)->where('status', 'processing')
            ->update(['claimed_at' => now(), 'updated_at' => now()]);
        if (! $changed && ! DB::table('attendance_import_requests')->where('id', $job->id)
            ->where('claim_token', $job->claim_token)->where('status', 'processing')->exists()) {
            throw new \UnexpectedValueException('Yêu cầu đã được chuyển cho tiến trình khác.');
        }
    }

    public function fail(object $job, string $message): void
    {
        DB::table('attendance_import_requests')->where('id', $job->id)
            ->where('claim_token', $job->claim_token)->where('status', 'processing')->update([
                'status' => 'failed', 'error' => mb_substr($message, 0, 500),
                'claim_token' => null, 'updated_at' => now(),
            ]);
    }
}
