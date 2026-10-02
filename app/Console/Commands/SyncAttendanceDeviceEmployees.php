<?php

namespace App\Console\Commands;

use App\Models\Employee;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncAttendanceDeviceEmployees extends Command
{
    protected $signature = 'attendance:sync-employees
        {--limit= : Giới hạn số nhân viên xử lý mỗi lần}';

    protected $description = 'Đồng bộ nhân viên trong DB local vào máy chấm công nội bộ';

    private const LAST_SYNC_CACHE_KEY = 'acs_employee_sync_last_created_at';

    private const LAST_DELETE_SYNC_CACHE_KEY = 'acs_employee_sync_last_deleted_at';

    public function handle(): int
    {
        if (! $this->hasDeviceConfig()) {
            $this->warn('Thiếu ACS_DEVICE_IP, ACS_USERNAME hoặc ACS_PASSWORD, bỏ qua đồng bộ nhân viên.');

            return self::SUCCESS;
        }

        $limit = (int) ($this->option('limit') ?: 0);

        $createStats = $this->syncCreatedEmployees($limit);
        $deleteStats = $this->syncDeletedEmployees($limit);

        $this->info(
            'Đồng bộ nhân viên hoàn tất. '.
            "Add synced: {$createStats['synced']}, skipped: {$createStats['skipped']}, failed: {$createStats['failed']}. ".
            "Delete synced: {$deleteStats['synced']}, skipped: {$deleteStats['skipped']}, failed: {$deleteStats['failed']}."
        );

        return self::SUCCESS;
    }

    private function syncCreatedEmployees(int $limit): array
    {
        $stats = ['synced' => 0, 'skipped' => 0, 'failed' => 0];
        $lastSyncedCreatedAt = $this->getOrInitializeSyncPoint(
            self::LAST_SYNC_CACHE_KEY,
            'created_at',
            'Khởi tạo mốc thêm nhân viên. Lần chạy sau sẽ chỉ add nhân viên mới.'
        );

        if (! $lastSyncedCreatedAt) {
            return $stats;
        }

        $query = Employee::query()
            ->select(['id', 'name', 'created_at'])
            ->whereNotIn('role_id', [15, 21, 22, 1])
            ->where('created_at', '>', $lastSyncedCreatedAt)
            ->orderBy('created_at')
            ->orderBy('id');

        $latestCreatedAt = $lastSyncedCreatedAt;

        $handleEmployees = function ($employees) use (&$latestCreatedAt, &$stats) {
            foreach ($employees as $employee) {
                $result = $this->syncEmployee($employee->toArray());

                if ($result === 'synced') {
                    $stats['synced']++;
                } elseif ($result === 'failed') {
                    $stats['failed']++;

                    return false;
                } else {
                    $stats['skipped']++;
                }

                $latestCreatedAt = $employee->created_at?->toDateTimeString() ?: $latestCreatedAt;
            }

            return true;
        };

        if ($limit > 0) {
            $employees = $query->limit($limit)->get();
            $handleEmployees($employees);
        } else {
            $query->chunk(100, function ($employees) use ($handleEmployees) {
                return $handleEmployees($employees);
            });
        }

        if ($latestCreatedAt !== $lastSyncedCreatedAt) {
            Cache::forever(self::LAST_SYNC_CACHE_KEY, $latestCreatedAt);
        }

        return $stats;
    }

    private function syncDeletedEmployees(int $limit): array
    {
        $stats = ['synced' => 0, 'skipped' => 0, 'failed' => 0];
        $lastSyncedDeletedAt = Cache::get(self::LAST_DELETE_SYNC_CACHE_KEY);

        if (! $lastSyncedDeletedAt) {
            $this->syncLatestDeletedEmployee($stats);

            return $stats;
        }

        $query = Employee::onlyTrashed()
            ->select(['id', 'name', 'deleted_at'])
            ->where('deleted_at', '>', $lastSyncedDeletedAt)
            ->orderBy('deleted_at')
            ->orderBy('id');

        $latestDeletedAt = $lastSyncedDeletedAt;
        $processed = false;

        $handleEmployees = function ($employees) use (&$latestDeletedAt, &$stats, &$processed) {
            foreach ($employees as $employee) {
                $processed = true;
                $result = $this->deleteEmployee($employee->toArray());

                if ($result === 'synced') {
                    $stats['synced']++;
                } elseif ($result === 'failed') {
                    $stats['failed']++;

                    return false;
                } else {
                    $stats['skipped']++;
                }

                $latestDeletedAt = $employee->deleted_at?->toDateTimeString() ?: $latestDeletedAt;
            }

            return true;
        };

        if ($limit > 0) {
            $employees = $query->limit($limit)->get();
            $handleEmployees($employees);
        } else {
            $query->chunk(100, function ($employees) use ($handleEmployees) {
                return $handleEmployees($employees);
            });
        }

        if (! $processed) {
            $this->syncLatestDeletedEmployee($stats, $lastSyncedDeletedAt);
        }

        if ($latestDeletedAt !== $lastSyncedDeletedAt) {
            Cache::forever(self::LAST_DELETE_SYNC_CACHE_KEY, $latestDeletedAt);
        }

        return $stats;
    }

    private function syncLatestDeletedEmployee(array &$stats, ?string $notAfter = null): void
    {
        $query = Employee::onlyTrashed()
            ->select(['id', 'name', 'deleted_at'])
            ->orderByDesc('deleted_at')
            ->orderByDesc('id');

        if ($notAfter) {
            $query->where('deleted_at', '<=', $notAfter);
        }

        $employee = $query->first();

        if (! $employee) {
            if (! $notAfter) {
                Cache::forever(self::LAST_DELETE_SYNC_CACHE_KEY, now()->toDateTimeString());
                $this->info('Khởi tạo mốc xóa nhân viên. Chưa có nhân viên nào trong thùng rác.');
            }

            return;
        }

        if ($this->isEmployeeDeleteCached($employee->toArray())) {
            if (! $notAfter) {
                Cache::forever(self::LAST_DELETE_SYNC_CACHE_KEY, $employee->deleted_at?->toDateTimeString() ?: now()->toDateTimeString());
                $this->info('Khởi tạo mốc xóa nhân viên từ nhân viên vừa bị xóa gần nhất.');
            }

            return;
        }

        $result = $this->deleteEmployee($employee->toArray());

        if ($result === 'synced') {
            $stats['synced']++;
        } elseif ($result === 'failed') {
            $stats['failed']++;

            return;
        } else {
            $stats['skipped']++;
        }

        if (! $notAfter) {
            Cache::forever(self::LAST_DELETE_SYNC_CACHE_KEY, $employee->deleted_at?->toDateTimeString() ?: now()->toDateTimeString());
            $this->info('Khởi tạo mốc xóa nhân viên từ nhân viên vừa bị xóa gần nhất.');
        }
    }

    private function getOrInitializeSyncPoint(string $cacheKey, string $column, string $message): ?string
    {
        $syncPoint = Cache::get($cacheKey);

        if ($syncPoint) {
            return $syncPoint;
        }

        $latestValue = Employee::withTrashed()->max($column) ?: now()->toDateTimeString();
        Cache::forever($cacheKey, $latestValue);
        $this->info($message);

        return null;
    }

    private function hasDeviceConfig(): bool
    {
        return (bool) config('acs.device_ip')
            && (bool) config('acs.username')
            && (bool) config('acs.password');
    }

    private function syncEmployee(array $employee): string
    {
        $employeeId = $employee['id'] ?? null;
        $employeeName = $employee['name'] ?? null;

        if (! $employeeId || ! $employeeName) {
            return 'skipped';
        }

        $payloadHash = sha1(json_encode([
            'id' => (string) $employeeId,
            'name' => (string) $employeeName,
            'created_at' => $employee['created_at'] ?? null,
        ]));

        $cacheKey = 'acs_employee_synced:'.(string) $employeeId;

        if (Cache::get($cacheKey) === $payloadHash) {
            return 'skipped';
        }

        try {
            $this->postEmployeeToDevice((string) $employeeId, (string) $employeeName);
            Cache::forever($cacheKey, $payloadHash);

            return 'synced';
        } catch (\Throwable $e) {
            Log::error('Attendance device employee sync failed', [
                'employee_id' => $employeeId,
                'message' => $e->getMessage(),
            ]);

            return 'failed';
        }
    }

    private function deleteEmployee(array $employee): string
    {
        $employeeId = $employee['id'] ?? null;

        if (! $employeeId) {
            return 'skipped';
        }

        $payloadHash = sha1(json_encode([
            'id' => (string) $employeeId,
            'deleted_at' => $employee['deleted_at'] ?? null,
        ]));

        $cacheKey = 'acs_employee_deleted:'.(string) $employeeId;

        if (Cache::get($cacheKey) === $payloadHash) {
            return 'skipped';
        }

        try {
            $this->deleteEmployeeFromDevice((string) $employeeId);
            Cache::forever($cacheKey, $payloadHash);

            return 'synced';
        } catch (\Throwable $e) {
            Log::error('Attendance device employee delete failed', [
                'employee_id' => $employeeId,
                'message' => $e->getMessage(),
            ]);

            return 'failed';
        }
    }

    private function isEmployeeDeleteCached(array $employee): bool
    {
        $employeeId = $employee['id'] ?? null;

        if (! $employeeId) {
            return false;
        }

        $payloadHash = sha1(json_encode([
            'id' => (string) $employeeId,
            'deleted_at' => $employee['deleted_at'] ?? null,
        ]));

        return Cache::get('acs_employee_deleted:'.(string) $employeeId) === $payloadHash;
    }

    private function postEmployeeToDevice(string $employeeId, string $employeeName): void
    {
        app(\App\Services\AttendanceDeviceEmployeeService::class)->sync($employeeId, $employeeName);
    }

    private function deleteEmployeeFromDevice(string $employeeId): void
    {
        $deviceIp = config('acs.device_ip');
        $username = config('acs.username');
        $password = config('acs.password');
        $url = "http://{$deviceIp}/ISAPI/AccessControl/UserInfo/Delete?format=json";

        $response = Http::withDigestAuth($username, $password)
            ->timeout(10)
            ->put($url, [
                'UserInfoDelCond' => [
                    'EmployeeNoList' => [
                        [
                            'employeeNo' => $employeeId,
                        ],
                    ],
                ],
            ]);

        if ($response->successful()) {
            return;
        }

        $body = $response->body();
        $normalizedBody = strtolower(str_replace(' ', '', $body));

        if (str_contains($normalizedBody, 'notexist')) {
            return;
        }

        throw new \RuntimeException("Device delete HTTP {$response->status()}: {$body}");
    }
}
