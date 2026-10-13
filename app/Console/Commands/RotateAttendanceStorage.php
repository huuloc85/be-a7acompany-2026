<?php

namespace App\Console\Commands;

use App\Services\AttendanceRetentionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RotateAttendanceStorage extends Command
{
    protected $signature = 'attendance:rotate-storage {--apply : Archive, verify, then remove expired live records}';

    protected $description = 'Keep three calendar months of live attendance; preserve full archived results';

    public function handle(AttendanceRetentionService $retention): int
    {
        if (! $this->option('apply')) {
            $cutoff = Carbon::now('Asia/Ho_Chi_Minh')->startOfMonth()->subMonths(2)->toDateString();
            $this->info(json_encode([
                'dry_run' => true, 'database' => DB::connection()->getDatabaseName(), 'cutoff' => $cutoff,
                'eligible' => DB::table('attendance_records')->where('date', '<', $cutoff)->count(),
            ]));

            return self::SUCCESS;
        }
        try {
            $this->info(json_encode($retention->rotate()));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            report($error);
            $this->error('Attendance rotation failed and was rolled back. Check the application log.');

            return self::FAILURE;
        }
    }
}
