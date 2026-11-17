<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class AttendanceEventWriter
{
    private ?EmployeeCodeTransferService $codes = null;

    // Both scheduled and manual imports lock the same employee before checking duplicates.
    // null: unknown employee; true: inserted; false: already present (never updated).
    public function store(string $employeeCode, CarbonInterface $time): ?bool
    {
        return DB::transaction(function () use ($employeeCode, $time) {
            $this->codes ??= app(EmployeeCodeTransferService::class);
            $employeeCode = $this->codes->resolve($employeeCode);
            if (! Employee::withTrashed()->whereKey($employeeCode)->lockForUpdate()->first()) {
                // A code change may have committed while this import waited on the employee lock.
                $employeeCode = $this->codes->resolve($employeeCode);
                if (! Employee::withTrashed()->whereKey($employeeCode)->lockForUpdate()->first()) {
                    return null;
                }
            }

            return AttendanceRecord::firstOrCreate([
                'employee_code' => $employeeCode,
                'datetime' => $time->format('Y-m-d H:i:s'),
            ])->wasRecentlyCreated;
        }, 3);
    }
}
