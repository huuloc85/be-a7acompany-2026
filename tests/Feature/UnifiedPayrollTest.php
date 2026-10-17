<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Admin\PayrollPublicationController;
use App\Http\Controllers\Api\Admin\SalaryController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UnifiedPayrollTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('employees', function ($t) {
            $t->string('id')->primary();
            $t->string('name');
            $t->string('company');
        });
        Schema::create('salaries_manager', function ($t) {
            $t->id();
            $t->string('calculator_key')->unique();
            $t->string('title');
            $t->string('start_date');
            $t->string('end_date');
            $t->string('date_show');
            $t->string('status');
            $t->integer('revision');
            $t->double('total')->default(0);
            $t->timestamps();
        });
        Schema::create('payroll_snapshots', function ($t) {
            $t->integer('salary_id')->primary();
            $t->text('draft');
        });
        Schema::create('payroll_snapshot_members', function ($t) {
            $t->integer('salary_id');
            $t->string('employee_id');
        });
        DB::table('employees')->insert([
            ['id' => '1', 'name' => 'VVP test', 'company' => 'Vinh Vinh Phát'],
            ['id' => '2', 'name' => 'A7A test', 'company' => 'A7A'],
        ]);
    }

    private function request(array $data): Request
    {
        $request = Request::create('/test', 'PUT', $data);
        $request->setUserResolver(fn () => (object) ['role' => (object) ['role_name' => 'Admin']]);

        return $request;
    }

    private function draft(): array
    {
        $rows = [];
        foreach (['1', '2'] as $id) {
            $row = array_fill_keys(explode(' ', 'dayDays nightDays daySalary nightSalary dayOvertimeSalary nightOvertimeSalary reinforcementSalary housing travel meal holidaySalary paidLeaveSalary downtimeSalary menstrualSalary diligence gross union social health unemployment personalDeduction dependentDeduction taxable tax net'), 0);
            $row['profile'] = ['employeeId' => $id];
            $row['adjustments'] = array_fill_keys(['bonus', 'referral', 'otherIncome', 'advance', 'otherDeduction'], 0);
            $row['hours'] = ['day' => 8];
            $row['daySalary'] = $row['gross'] = $row['net'] = (int) $id * 1000;
            $rows[] = $row;
        }

        return ['startDate' => '2026-09-16', 'endDate' => '2026-10-15', 'rules' => ['standardDays' => 25], 'rows' => $rows];
    }

    public function test_one_period_contains_both_companies_and_filters_without_changing_id(): void
    {
        $controller = new PayrollPublicationController;
        $saved = $controller->save($this->request(['draft' => $this->draft(), 'revision' => 0]), 'all', '2026-10')->getData(true)['data'];
        $this->assertSame('all:2026-10', $saved['calculator_key']);
        $this->assertEquals(3000, $saved['total']);
        $this->assertEquals(1, DB::table('salaries_manager')->count());
        foreach (['vvp' => '1', 'a7a' => '2'] as $company => $employee) {
            $response = (new SalaryController)->getSalary($this->request(['company' => $company]), $saved['id'])->getData(true);
            $this->assertCount(1, $response['snapshot']['rows']);
            $this->assertSame($employee, $response['snapshot']['rows'][0]['profile']['employeeId']);
            $this->assertEquals($saved['id'], $response['manager']['id']);
        }
        $all = (new SalaryController)->getSalary($this->request(['company' => 'all']), $saved['id'])->getData(true);
        $this->assertCount(2, $all['snapshot']['rows']);
        $controller->save($this->request(['draft' => $this->draft(), 'revision' => 1]), 'all', '2026-10');
        $this->assertEquals(1, DB::table('salaries_manager')->count());
    }

    public function test_stale_revision_is_rejected(): void
    {
        $controller = new PayrollPublicationController;
        $controller->save($this->request(['draft' => $this->draft(), 'revision' => 0]), 'all', '2026-10');
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $controller->save($this->request(['draft' => $this->draft(), 'revision' => 0]), 'all', '2026-10');
    }
}
