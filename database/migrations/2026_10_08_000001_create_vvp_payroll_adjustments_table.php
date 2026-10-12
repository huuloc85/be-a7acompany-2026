<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vvp_payroll_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('period', 7);
            $table->string('employee_id', 20);
            $table->json('adjustment');
            $table->unsignedInteger('revision')->default(1);
            $table->string('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['period', 'employee_id']);
            $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vvp_payroll_adjustments');
    }
};
