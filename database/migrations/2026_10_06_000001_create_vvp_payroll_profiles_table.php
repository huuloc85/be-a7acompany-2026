<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vvp_payroll_profiles', function (Blueprint $table) {
            // Preserve spreadsheet employee codes, including leading zeros.
            $table->string('employee_code', 50)->primary();
            $table->json('profile');
            $table->unsignedInteger('revision')->default(1);
            $table->string('source', 255);
            $table->unsignedInteger('source_row');
            $table->date('effective_from');
            $table->string('updated_by', 50)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vvp_payroll_profiles');
    }
};
