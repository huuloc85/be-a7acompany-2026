<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salaries_manager', function (Blueprint $table) {
            // Existing imported salaries remain visible.
            $table->string('status')->default('published')->index();
            $table->string('calculator_key')->nullable()->unique();
            $table->unsignedInteger('revision')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->string('published_by')->nullable();
        });
        Schema::create('payroll_snapshots', function (Blueprint $table) {
            $table->foreignId('salary_id')->primary()->constrained('salaries_manager')->cascadeOnDelete();
            $table->json('draft');
        });
        Schema::create('payroll_snapshot_members', function (Blueprint $table) {
            $table->foreignId('salary_id')->constrained('salaries_manager')->cascadeOnDelete();
            $table->string('employee_id', 50)->index();
            $table->primary(['salary_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_snapshot_members');
        Schema::dropIfExists('payroll_snapshots');
        Schema::table('salaries_manager', function (Blueprint $table) {
            $table->dropUnique(['calculator_key']);
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'calculator_key', 'revision', 'published_at', 'published_by']);
        });
    }
};
