<?php

use App\Services\LoginHistoryDeduplication;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('login_history', 'login_history_rollup_lookup')) {
            if (DB::getDriverName() === 'mysql') {
                DB::statement('ALTER TABLE login_history ADD INDEX login_history_rollup_lookup (employee_id, date, activity_type, month_history), ALGORITHM=INPLACE, LOCK=NONE');
            } else {
                Schema::table('login_history', function (Blueprint $table) {
                    $table->index(LoginHistoryDeduplication::KEY_COLUMNS, 'login_history_rollup_lookup');
                });
            }
        }

        app(LoginHistoryDeduplication::class)->merge(today()->subDay()->toDateString());
    }

    public function down(): void
    {
        Schema::table('login_history', function (Blueprint $table) {
            $table->dropIndex('login_history_rollup_lookup');
        });
    }
};
