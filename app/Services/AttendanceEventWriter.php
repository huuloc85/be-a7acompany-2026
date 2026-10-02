<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class AttendanceEventWriter
{
    // Both scheduled and manual imports lock the same employee before checking duplicates.
    // null: unknown employee; true: inserted; false: already present (never updated).
    public function store(string $employeeCode, CarbonInterface $time): ?bool
    {
        return DB::transaction(function () use ($employeeCode, $time) {
            if (! Employee::withTrashed()->whereKey($employeeCode)->lockForUpdate()->first()) {
                return null;
            }

            return AttendanceRecord::firstOrCreate([
                'employee_code' => $employeeCode,
                'datetime' => $time->format('Y-m-d H:i:s'),
            ])->wasRecentlyCreated;
        });
    }
}
