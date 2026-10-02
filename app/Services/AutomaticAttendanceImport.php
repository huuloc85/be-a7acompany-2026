<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AutomaticAttendanceImport
{
    public function enqueueIfDue(AttendanceImportQueue $queue): bool
    {
        $today = Carbon::now('Asia/Ho_Chi_Minh');
        $start = $today->copy()->subDay()->toDateString();
        $end = $today->toDateString();

        return DB::transaction(function () use ($queue, $start, $end) {
            $row = DB::table('attendance_import_requests')->where('start_date', $start)
                ->where('end_date', $end)->lockForUpdate()->first();
            // Never reset active work, including a stale job that claim() will recover.
            // Use the last update as cooldown so failures cannot hammer the device.
            if ($row && (in_array($row->status, ['pending', 'processing'], true)
                || Carbon::parse($row->updated_at)->gt(now()->subMinutes(5)))) {
                return false;
            }

            $queue->enqueue($start, $end, 'bridge-auto');

            return true;
        }, 3);
    }
}
