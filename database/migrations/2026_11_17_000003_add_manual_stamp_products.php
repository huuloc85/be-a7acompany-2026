<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('send_stamps', function (Blueprint $table) {
            $table->integer('product_id')->nullable()->change();
            $table->json('manual_product')->nullable();
            $table->string('manual_product_key', 64)->nullable()->index();
        });

        // Quyền thao tác, không có URL/sidebar item và không tự gán cho role/user.
        if (! DB::table('permissions')->where('key', 'print_manual_label')->exists()) {
            DB::table('permissions')->insert([
                'key' => 'print_manual_label',
                'name' => 'In tem ngoài danh mục',
                'type' => 'admin',
                'module' => DB::table('permissions')->where('key', 'view_label_management')->value('module') ?? 'stamps',
                'display_area' => 'sidebar',
                'url' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (DB::table('send_stamps')->whereNull('product_id')->exists()) {
            throw new RuntimeException('Không thể rollback khi còn lịch sử tem ngoài danh mục.');
        }
        Schema::table('send_stamps', function (Blueprint $table) {
            $table->dropIndex(['manual_product_key']);
            $table->dropColumn(['manual_product', 'manual_product_key']);
            $table->integer('product_id')->nullable(false)->change();
        });
        // Giữ cấu hình RBAC để không xóa quyền đã được quản trị viên cấp.
    }
};
