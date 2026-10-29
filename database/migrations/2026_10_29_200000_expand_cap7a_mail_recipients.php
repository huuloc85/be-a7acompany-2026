<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite does not enforce VARCHAR length; no widening is needed there.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }
        foreach (['cap7a_mail_settings', 'cap7a_production_reports'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->text('recipient')->change());
        }
    }

    public function down(): void
    {
        // Keep the wider columns: shrinking could truncate existing recipient lists.
    }
};
