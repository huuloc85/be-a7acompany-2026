<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('vvp_payroll_profiles', 'payroll_profiles');
        Schema::rename('vvp_payroll_holidays', 'payroll_holidays');
        Schema::rename('vvp_payroll_adjustments', 'payroll_adjustments');
    }

    public function down(): void
    {
        Schema::rename('payroll_adjustments', 'vvp_payroll_adjustments');
        Schema::rename('payroll_holidays', 'vvp_payroll_holidays');
        Schema::rename('payroll_profiles', 'vvp_payroll_profiles');
    }
};
