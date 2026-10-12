<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_daily_results', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id');
            $table->string('name');
            $table->string('company')->nullable();
            $table->string('calendar_category_id')->nullable();
            $table->date('date');
            $table->unsignedTinyInteger('shift');
            $table->string('hnhc', 2)->nullable();
            $table->string('day_type');
            $table->boolean('is_schedule_change');
            $table->dateTime('time_in')->nullable();
            $table->dateTime('time_out')->nullable();
            $table->decimal('total_hours', 7, 2);
            $table->decimal('administrative_hours', 7, 2);
            $table->decimal('overtime_hours', 7, 2);
            $table->uuid('sync_token');
            $table->timestamp('calculated_at');
            $table->timestamps();
            $table->unique(['employee_id', 'date'], 'attendance_daily_employee_date_unique');
            $table->index(['date', 'employee_id']);
            // Keep the saved identity independent of employee deletion/renaming.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_daily_results');
    }
};
