<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vvp_payroll_holidays', function (Blueprint $table) {
            $table->string('period', 7)->primary();
            $table->decimal('default_days', 5, 2);
            $table->json('overrides');
            $table->unsignedInteger('revision');
            $table->string('updated_by', 50)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vvp_payroll_holidays');
    }
};
