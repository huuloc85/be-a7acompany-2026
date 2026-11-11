<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['trg_insert_wc_trash_to_schedule_details', 'insert_wc_trash_to_schedule_details'] as $procedure) {
            DB::unprepared(File::get(database_path("scripts/procedures/{$procedure}.sql")));
        }
    }

    public function down(): void
    {
        foreach (['trg_insert_wc_trash_to_schedule_details', 'insert_wc_trash_to_schedule_details'] as $procedure) {
            $sql = File::get(database_path("scripts/procedures/{$procedure}.sql"));
            $sql = str_replace('(7 - DAYOFWEEK(c.date)) % 7', '(7 - DAYOFWEEK(c.date) + 1) % 7', $sql);
            $sql = str_replace('* 7) DAY', '* 7) - 1 DAY', $sql);
            DB::unprepared($sql);
        }
    }
};
