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
        $hasRollupColumn = DB::getDriverName() === 'sqlite'
            ? DB::selectOne("SELECT name FROM pragma_table_xinfo('login_history') WHERE name = 'activity_rollup'") !== null
            : Schema::hasColumn('login_history', 'activity_rollup');

        if (! $hasRollupColumn) {
            $previewActivity = DB::getPdo()->quote(LoginHistoryDeduplication::PREVIEW_ACTIVITY);
            Schema::table('login_history', function (Blueprint $table) use ($previewActivity) {
                $table->unsignedTinyInteger('activity_rollup')
                    ->nullable()
                    ->virtualAs("CASE WHEN activity_type = {$previewActivity} THEN NULL ELSE 1 END");
            });
        }

        app(LoginHistoryDeduplication::class)->merge();

        if (! Schema::hasIndex('login_history', 'login_history_rollup_unique')) {
            if (DB::getDriverName() === 'mysql') {
                DB::statement('ALTER TABLE login_history ADD UNIQUE INDEX login_history_rollup_unique (employee_id, date, activity_type, month_history, activity_rollup), ALGORITHM=INPLACE, LOCK=NONE');
            } else {
                Schema::table('login_history', function (Blueprint $table) {
                    $table->unique([...LoginHistoryDeduplication::KEY_COLUMNS, 'activity_rollup'], 'login_history_rollup_unique');
                });
            }
        }

        if (Schema::hasIndex('login_history', 'login_history_rollup_lookup')) {
            Schema::table('login_history', function (Blueprint $table) {
                $table->dropIndex('login_history_rollup_lookup');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasIndex('login_history', 'login_history_rollup_lookup')) {
            Schema::table('login_history', function (Blueprint $table) {
                $table->index(LoginHistoryDeduplication::KEY_COLUMNS, 'login_history_rollup_lookup');
            });
        }

        Schema::table('login_history', function (Blueprint $table) {
            $table->dropUnique('login_history_rollup_unique');
            $table->dropColumn('activity_rollup');
        });
    }
};
