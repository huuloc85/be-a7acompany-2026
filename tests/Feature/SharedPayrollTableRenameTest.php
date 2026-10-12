<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SharedPayrollTableRenameTest extends TestCase
{
    public function test_rename_and_rollback_preserve_both_companies_and_inputs(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        DB::statement('PRAGMA foreign_keys = ON');
        Schema::create('employees', fn ($table) => $table->string('id')->primary());
        DB::table('employees')->insert([['id' => '001'], ['id' => '002']]);
        foreach (['2026_10_06_000001_create_vvp_payroll_profiles_table.php', '2026_10_06_000002_create_vvp_payroll_holidays_table.php', '2026_10_08_000001_create_vvp_payroll_adjustments_table.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        foreach (['001' => 'VVP', '002' => 'A7A'] as $id => $company) {
            DB::table('vvp_payroll_profiles')->insert([
                'employee_code' => $id, 'profile' => json_encode(['company' => $company, 'bankAccount' => '000123']),
                'revision' => 3, 'source' => 'test', 'source_row' => 8, 'effective_from' => '2026-09-01',
            ]);
            DB::table('vvp_payroll_adjustments')->insert(['employee_id' => $id, 'period' => '2026-09', 'adjustment' => '{"bonus":100}']);
        }
        $before = [];
        foreach (['profiles', 'holidays', 'adjustments'] as $suffix) {
            $before[$suffix] = DB::table('vvp_payroll_'.$suffix)->get()->toJson();
        }
        $migration = require database_path('migrations/2026_10_13_000002_rename_shared_payroll_tables.php');
        $migration->up();
        foreach ($before as $suffix => $data) {
            $this->assertFalse(Schema::hasTable('vvp_payroll_'.$suffix));
            $this->assertSame($data, DB::table('payroll_'.$suffix)->get()->toJson());
        }
        $migration->down();
        foreach ($before as $suffix => $data) {
            $this->assertSame($data, DB::table('vvp_payroll_'.$suffix)->get()->toJson());
        }
    }
}
