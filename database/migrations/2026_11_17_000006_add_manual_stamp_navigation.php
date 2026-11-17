<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $parents = DB::table('permissions')->where('key', 'view_label_management')->get();
            $manuals = DB::table('permissions')->where('key', 'print_manual_label')->get();
            if ($parents->count() !== 1 || $manuals->count() > 1) {
                throw new RuntimeException('Cần có duy nhất một quyền Quản lý tem và không trùng quyền In tem ngoài danh mục.');
            }
            $parent = $parents->first();
            $attributes = [
                'name' => 'In tem ngoài danh mục',
                'type' => $parent->type,
                'module' => $parent->module,
                'display_area' => 'sidebar',
                // Điều hướng nằm trong nhóm Quản lý tem; quyền này kiểm soát riêng trang nhập tay.
                'url' => null,
                'icon' => 'fas fa-edit',
                'sort_order' => $parent->sort_order,
                'updated_at' => now(),
            ];
            $manualId = $manuals->first()?->id;
            if ($manualId) {
                DB::table('permissions')->where('id', $manualId)->update($attributes);
            } else {
                $manualId = DB::table('permissions')->insertGetId($attributes + [
                    'key' => 'print_manual_label', 'created_at' => now(),
                ]);
            }

            $item = DB::table('sidebar_items')->where('key', 'print_manual_label')->first();
            $navigation = [
                'permission_id' => $parent->id,
                'title' => 'In tem ngoài danh mục',
                'icon' => 'fas fa-edit',
                'url' => '/stamps/manual',
                'updated_at' => now(),
            ];
            if ($item) {
                DB::table('sidebar_items')->where('id', $item->id)->update($navigation);
            } else {
                DB::table('sidebar_items')->insert($navigation + [
                    'key' => 'print_manual_label', 'created_at' => now(),
                ]);
            }

            // Sao chép phạm vi cấp quyền hiện có, không gán mặc định cho mọi user/role.
            foreach (DB::table('role_permission')->where('permission_id', $parent->id)->get() as $grant) {
                if (! DB::table('role_permission')->where('role_id', $grant->role_id)->where('permission_id', $manualId)->exists()) {
                    DB::table('role_permission')->insert(['role_id' => $grant->role_id, 'permission_id' => $manualId]);
                }
            }
            foreach (['user_granted_permissions', 'user_denied_permissions'] as $table) {
                foreach (DB::table($table)->where('permission_id', $parent->id)->get() as $override) {
                    if (! DB::table($table)->where('user_id', $override->user_id)->where('permission_id', $manualId)->exists()) {
                        DB::table($table)->insert([
                            'user_id' => $override->user_id,
                            'permission_id' => $manualId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        // Giữ quyền đã cấp/chặn và cấu hình menu để không mất thay đổi của quản trị viên.
    }
};
