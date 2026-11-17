<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('permissions')->select('key')
            ->groupBy('key')->havingRaw('COUNT(*) > 1')->pluck('key');

        // Không tự gộp ID: việc đó có thể thay đổi grant/deny và các menu con.
        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Cần xử lý quyền trùng trước khi tạo unique index: '.$duplicates->implode(', '));
        }

        Schema::table('permissions', function (Blueprint $table) {
            $table->unique('key', 'permissions_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->dropUnique('permissions_key_unique');
        });
    }
};
