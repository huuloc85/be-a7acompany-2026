<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_device_requests', function (Blueprint $table) {
            $table->string('action', 10)->default('sync');
            $table->string('employee_name')->nullable();
        });
    }

    public function down(): void
    {
        // Do not roll back while requests are active.
        Schema::table('attendance_device_requests', function (Blueprint $table) {
            $table->dropColumn(['action', 'employee_name']);
        });
    }
};
