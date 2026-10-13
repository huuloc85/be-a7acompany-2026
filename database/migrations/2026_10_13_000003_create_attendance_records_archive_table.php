<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records_archive', function (Blueprint $table) {
            // Original record ID and precision; no cascading employee foreign key.
            $table->unsignedBigInteger('id')->primary();
            $table->string('employee_code', 20)->nullable();
            $table->dateTime('datetime', 6)->nullable();
            $table->date('date')->nullable();
            $table->time('time', 6)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('archived_at');
            $table->index(['date', 'employee_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records_archive');
    }
};
