<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $links = [];
        foreach (DB::table('vvp_payroll_profiles')->get() as $row) {
            $profile = json_decode($row->profile, true, 512, JSON_THROW_ON_ERROR);
            $id = (string) (($profile['attendanceEmployeeId'] ?? '') ?: $row->employee_code);
            $employee = DB::table('employees')->where('id', $id)->first();
            if (! $employee || ! in_array(mb_strtolower(trim($employee->company ?? '')), ['vvp', 'vinh vinh phát'], true) || in_array($id, $links, true)) {
                throw new RuntimeException('Không thể liên kết danh mục: mã web thiếu, trùng hoặc không thuộc VVP.');
            }
            $links[$row->employee_code] = $id;
        }
        Schema::table('vvp_payroll_profiles', function (Blueprint $table) {
            $table->string('employee_id', 20)->nullable()->unique();
            $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete()->cascadeOnUpdate();
        });
        DB::transaction(function () use ($links) {
            foreach ($links as $code => $id) {
                DB::table('vvp_payroll_profiles')->where('employee_code', $code)->update(['employee_id' => $id]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('vvp_payroll_profiles', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
            $table->dropUnique(['employee_id']);
            $table->dropColumn('employee_id');
        });
    }
};
