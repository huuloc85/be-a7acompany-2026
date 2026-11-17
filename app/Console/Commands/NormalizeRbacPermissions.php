<?php

namespace App\Console\Commands;

use App\Support\RbacPermissionCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class NormalizeRbacPermissions extends Command
{
    protected $signature = 'rbac:normalize {--apply : Sao lưu rồi áp dụng trong transaction}';

    protected $description = 'Gộp quyền hiển thị trùng và chuyển ngoại lệ tổ trưởng sang RBAC (mặc định chỉ xem trước)';

    public function handle(): int
    {
        $tables = ['permissions', 'role_permission', 'user_granted_permissions', 'user_denied_permissions', 'sidebar_items'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        }
        $duplicates = collect($before['permissions'])->groupBy('key')->filter(fn ($rows) => $rows->count() > 1);
        if ($duplicates->isNotEmpty()) {
            $this->error('Cần xử lý key trùng trước: '.$duplicates->keys()->implode(', '));

            return self::FAILURE;
        }

        $permissions = collect($before['permissions'])->keyBy('key');
        $rows = [];
        foreach (RbacPermissionCatalog::ALIASES as $old => $canonical) {
            if ($permissions->has($old)) {
                if (! $permissions->has($canonical)) {
                    $this->error("Thiếu quyền đích {$canonical}; chưa thay đổi dữ liệu.");

                    return self::FAILURE;
                }
                $rows[] = [$permissions[$old]['id'], $old, $permissions[$canonical]['id'], $canonical];
            }
        }
        $this->table(['ID cũ', 'Key cũ', 'ID giữ', 'Key giữ'], $rows);
        $this->line('Chỉ gộp 4 alias nhân viên. Quyền thống kê, hoạt động, quản lý, lịch cá nhân và lịch tổng giữ riêng.');
        if (! $this->option('apply')) {
            $this->info('Chỉ xem trước; chưa ghi database. --apply chỉ dùng khi triển khai cùng code FE/BE mới.');

            return self::SUCCESS;
        }

        if (! $rows) {
            $this->info('Không còn alias cần chuyển; không cấp lại các quyền ngoại lệ.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($tables) {
            $snapshot = [];
            foreach ($tables as $table) {
                $snapshot[$table] = DB::table($table)->lockForUpdate()->get()->map(fn ($row) => (array) $row)->all();
            }
            $employees = DB::table('employees')->select('id', 'role_id')->lockForUpdate()->get();
            $roles = DB::table('roles')->select('id', 'role_name')->lockForUpdate()->get();
            $lockedPermissions = collect($snapshot['permissions'])->keyBy('key');
            if ($lockedPermissions->count() !== count($snapshot['permissions'])) {
                throw new \RuntimeException('Có key trùng; chưa thay đổi dữ liệu.');
            }
            foreach (RbacPermissionCatalog::ALIASES as $old => $canonical) {
                if ($lockedPermissions->has($old) && ! $lockedPermissions->has($canonical)) {
                    throw new \RuntimeException("Thiếu quyền đích {$canonical}; chưa thay đổi dữ liệu.");
                }
            }
            foreach ([RbacPermissionCatalog::SUPERVISOR_PERMISSION, 'manage_daily_activity'] as $key) {
                if ($lockedPermissions->has($key)) {
                    throw new \RuntimeException("Quyền {$key} đã tồn tại; cần đối chiếu chuyển đổi dở dang trước khi tiếp tục.");
                }
            }
            $backup = 'rbac-backups/'.now()->format('Ymd-His').'-'.Str::uuid().'.json';
            if (! Storage::disk('local')->put($backup, json_encode([
                'database' => DB::connection()->getDatabaseName(),
                'created_at' => now()->toIso8601String(),
                'tables' => $snapshot,
                'employees' => $employees,
                'roles' => $roles,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR))) {
                throw new \RuntimeException('Không thể sao lưu RBAC; chưa thay đổi dữ liệu.');
            }
            $this->info('Bản sao lưu: '.Storage::disk('local')->path($backup));

            foreach (array_unique(RbacPermissionCatalog::ALIASES) as $canonical) {
                $keys = array_keys(RbacPermissionCatalog::ALIASES, $canonical);
                $target = DB::table('permissions')->where('key', $canonical)->first();
                $sources = DB::table('permissions')->whereIn('key', $keys)->pluck('id')->all();
                if (! $target || ! $sources) {
                    continue;
                }
                $ids = array_merge([$target->id], $sources);
                $roleLinks = DB::table('role_permission')->whereIn('permission_id', $ids)->get();
                $grants = DB::table('user_granted_permissions')->whereIn('permission_id', $ids)->get();
                $denies = DB::table('user_denied_permissions')->whereIn('permission_id', $ids)->get();
                $overrideUsers = $grants->pluck('user_id')->merge($denies->pluck('user_id'))->unique();

                foreach ($roleLinks->pluck('role_id')->unique() as $roleId) {
                    DB::table('role_permission')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $target->id]);
                }
                foreach ($employees->whereIn('id', $overrideUsers) as $employee) {
                    $granted = $grants->where('user_id', $employee->id)->pluck('permission_id')->all();
                    $denied = $denies->where('user_id', $employee->id)->pluck('permission_id')->all();
                    $inherited = $roleLinks->where('role_id', $employee->role_id)->pluck('permission_id')->all();
                    $allowed = count(array_diff(array_unique(array_merge($inherited, $granted)), $denied)) > 0;
                    foreach (['user_granted_permissions', 'user_denied_permissions'] as $table) {
                        DB::table($table)->where('user_id', $employee->id)->whereIn('permission_id', $ids)->delete();
                    }
                    // Preserve current effective access, including deny on one alias + grant on another.
                    if ($allowed && $granted) {
                        $this->linkUser('user_granted_permissions', $employee->id, $target->id);
                    } elseif (! $allowed && $denied) {
                        $this->linkUser('user_denied_permissions', $employee->id, $target->id);
                    }
                }
                DB::table('sidebar_items')->whereIn('permission_id', $sources)->update(['permission_id' => $target->id]);
                foreach (['role_permission', 'user_granted_permissions', 'user_denied_permissions'] as $table) {
                    DB::table($table)->whereIn('permission_id', $sources)->delete();
                }
                DB::table('permissions')->whereIn('id', $sources)->delete();
                DB::table('permissions')->where('id', $target->id)->update([
                    'display_area' => 'both',
                    'url' => $target->url ? '/'.ltrim($target->url, '/') : null,
                    'updated_at' => now(),
                ]);
            }
            $supervisor = $this->permission(RbacPermissionCatalog::SUPERVISOR_PERMISSION, 'Duyệt đơn được giao', 'request_forms', '/admin/request-forms');
            $activityManage = $this->permission('manage_daily_activity', 'Quản lý hoạt động trong ngày', 'activity', null);
            $schedule = DB::table('permissions')->where('key', 'view_schedule')->value('id');
            $scheduleRead = DB::table('permissions')->where('key', 'view_team_schedule')->value('id');
            if (! $schedule || ! $scheduleRead) {
                throw new \RuntimeException('Thiếu quyền lịch/hoạt động; toàn bộ thay đổi đã được rollback.');
            }
            // Daily activity writes previously used view_schedule. Preserve those assignments before splitting.
            foreach (DB::table('role_permission')->where('permission_id', $schedule)->get() as $link) {
                DB::table('role_permission')->insertOrIgnore(['role_id' => $link->role_id, 'permission_id' => $activityManage]);
            }
            foreach (['user_granted_permissions', 'user_denied_permissions'] as $table) {
                foreach (DB::table($table)->where('permission_id', $schedule)->get() as $link) {
                    $this->linkUser($table, $link->user_id, $activityManage);
                }
            }
            $leaderNames = ['tổ trưởng sản xuất', 'tổ phó sản xuất', 'tổ trưởng ngoại quan', 'tổ trưởng qa', 'tổ trưởng qc', 'tổ trưởng kho', 'tổ trưởng khuôn'];
            $leaderIds = $roles->filter(fn ($r) => in_array((string) $r->id, ['14', '18', '19', '23', '24', '25'], true)
                || in_array(mb_strtolower(trim($r->role_name)), $leaderNames, true))->pluck('id')->all();
            foreach ($leaderIds as $roleId) {
                DB::table('role_permission')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $scheduleRead]);
                // The previous schedule middleware always made leaders read-only, even with view_schedule.
                DB::table('role_permission')->where('role_id', $roleId)->where('permission_id', $schedule)->delete();
            }
            foreach ($employees->whereIn('role_id', $leaderIds) as $employee) {
                foreach (['user_granted_permissions', 'user_denied_permissions'] as $table) {
                    if (DB::table($table)->where('user_id', $employee->id)->where('permission_id', $schedule)->exists()) {
                        $this->linkUser($table, $employee->id, $scheduleRead);
                        DB::table($table)->where('user_id', $employee->id)->where('permission_id', $schedule)->delete();
                    }
                }
            }
            if ($employees->contains('id', '23080130')) {
                $this->linkUser('user_granted_permissions', '23080130', $scheduleRead);
                if (DB::table('user_denied_permissions')->where('user_id', '23080130')->where('permission_id', $schedule)->exists()) {
                    $this->linkUser('user_denied_permissions', '23080130', $scheduleRead);
                }
            }
            foreach (['25060406', '22120703', '22120905', '22120304', '22122809', '22121822'] as $userId) {
                if ($employees->contains('id', $userId)) {
                    $this->linkUser('user_granted_permissions', $userId, $supervisor);
                }
            }
            // Do not grant entire leader roles: the old API only authorized the six accounts above.
            $after = [];
            foreach ($tables as $table) {
                $after[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
            }
            foreach ($employees as $employee) {
                $expected = $this->effectiveKeys($snapshot, $employee);
                $actual = $this->effectiveKeys($after, $employee);
                // Schedule authorization previously included explicit read-only exceptions.
                $isLeader = in_array($employee->role_id, $leaderIds);
                $scheduleDenied = collect($snapshot['user_denied_permissions'])->contains(fn ($link) => (string) $link['user_id'] === (string) $employee->id && $link['permission_id'] == $schedule);
                $canReadSchedule = $isLeader || (string) $employee->id === '23080130'
                    ? ! $scheduleDenied : (bool) array_intersect($expected, ['view_schedule', 'view_team_schedule']);
                $canWriteSchedule = ! $isLeader && in_array('view_schedule', $expected, true);
                if ($canReadSchedule !== (bool) array_intersect($actual, ['view_schedule', 'view_team_schedule'])
                    || $canWriteSchedule !== in_array('view_schedule', $actual, true)) {
                    throw new \RuntimeException("Quyền lịch của tài khoản {$employee->id} thay đổi; toàn bộ chuyển đổi đã được rollback.");
                }
                if (in_array('view_schedule', $expected, true)) {
                    $expected[] = 'manage_daily_activity';
                }
                if (in_array((string) $employee->id, ['25060406', '22120703', '22120905', '22120304', '22122809', '22121822'], true)) {
                    $expected[] = RbacPermissionCatalog::SUPERVISOR_PERMISSION;
                }
                $expected = array_values(array_diff($expected, ['view_schedule', 'view_team_schedule']));
                $actual = array_values(array_diff($actual, ['view_schedule', 'view_team_schedule']));
                sort($expected);
                sort($actual);
                if ($expected !== $actual) {
                    throw new \RuntimeException("Quyền của tài khoản {$employee->id} thay đổi; toàn bộ chuyển đổi đã được rollback.");
                }
            }
            $this->info('Đã đối chiếu quyền trước/sau của '.$employees->count().' tài khoản.');
        });
        $this->info('Đã chuẩn hóa RBAC. Cần làm mới phiên đăng nhập để FE nhận quyền mới.');

        return self::SUCCESS;
    }

    private function effectiveKeys(array $snapshot, object $employee): array
    {
        $inherited = collect($snapshot['role_permission'])->where('role_id', $employee->role_id)->pluck('permission_id')->all();
        $granted = collect($snapshot['user_granted_permissions'])->where('user_id', $employee->id)->pluck('permission_id')->all();
        $denied = collect($snapshot['user_denied_permissions'])->where('user_id', $employee->id)->pluck('permission_id')->all();
        $ids = array_diff(array_unique(array_merge($inherited, $granted)), $denied);

        return collect($snapshot['permissions'])->whereIn('id', $ids)->pluck('key')
            ->map(fn ($key) => RbacPermissionCatalog::ALIASES[$key] ?? $key)->unique()->values()->all();
    }

    private function linkUser(string $table, string $userId, int $permissionId): void
    {
        DB::table($table)->insertOrIgnore(['user_id' => $userId, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function permission(string $key, string $name, string $module, ?string $url): int
    {
        $id = DB::table('permissions')->where('key', $key)->value('id');

        return $id ?: DB::table('permissions')->insertGetId([
            'key' => $key, 'name' => $name, 'type' => 'both', 'module' => $module,
            'url' => $url, 'display_area' => 'both', 'sort_order' => 8,
            'icon' => $url ? 'fas fa-file-signature' : null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
