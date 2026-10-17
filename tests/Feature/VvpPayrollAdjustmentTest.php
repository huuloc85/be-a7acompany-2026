<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Admin\VvpPayrollAdjustmentController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class VvpPayrollAdjustmentTest extends TestCase
{
    public function test_manual_attendance_roundtrip_and_restore(): void
    {
        $controller = new VvpPayrollAdjustmentController;
        $entries = [['date' => '2026-09-01', 'day' => 8, 'night' => 0, 'overtime' => 2, 'overtimeShift' => 1]];
        $controller->update($this->input(['attendanceOverrides' => $entries]), '2026-09', '001');
        $rows = $controller->index($this->input(), '2026-09')->getData(true)['data'];
        $this->assertEquals($entries, $rows[0]['adjustment']['attendanceOverrides']);
        $this->assertEquals(1, $rows[0]['revision']);
        $controller->update($this->input(['attendanceOverrides' => []], 1), '2026-09', '001');
        $rows = $controller->index($this->input(), '2026-09')->getData(true)['data'];
        $this->assertSame([], $rows[0]['adjustment']['attendanceOverrides']);
    }

    public function test_manual_attendance_rejects_more_than_24_hours(): void
    {
        $this->expectException(HttpException::class);
        (new VvpPayrollAdjustmentController)->update($this->input(['attendanceOverrides' => [
            ['date' => '2026-09-01', 'day' => 16, 'night' => 8, 'overtime' => 1, 'overtimeShift' => 1],
        ]]), '2026-09', '001');
    }

    public function test_manual_attendance_rejects_date_outside_payroll_period(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        (new VvpPayrollAdjustmentController)->update($this->input(['attendanceOverrides' => [
            ['date' => '2026-09-16', 'day' => 8, 'night' => 0, 'overtime' => 0, 'overtimeShift' => 1],
        ]]), '2026-09', '001');
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        DB::statement('PRAGMA foreign_keys = ON');
        Schema::create('employees', fn ($t) => $t->string('id')->primary());
        Schema::create('payroll_profiles', function ($t) {
            $t->string('employee_id')->primary();
        });
        foreach (['001', '002'] as $id) {
            DB::table('employees')->insert(['id' => $id]);
            DB::table('payroll_profiles')->insert(['employee_id' => $id]);
        }
        (require database_path('migrations/2026_10_08_000001_create_vvp_payroll_adjustments_table.php'))->up();
        Schema::rename('vvp_payroll_adjustments', 'payroll_adjustments');
    }

    private function input(array $adjustment = [], int $revision = 0, string $role = 'Admin'): Request
    {
        $r = Request::create('/test', 'PUT', compact('adjustment', 'revision'));
        $user = new class($role)
        {
            public object $role;

            public function __construct($role)
            {
                $this->role = (object) ['role_name' => $role];
            }

            public function hasPermission($permission)
            {
                return $permission === 'view_salary_total';
            }

            public function getAuthIdentifier()
            {
                return 'test';
            }
        };
        $r->setUserResolver(fn () => $user);

        return $r;
    }

    public function test_roundtrip_is_separate_for_employee_and_period(): void
    {
        $c = new VvpPayrollAdjustmentController;
        $values = ['downtimeDays' => 0.5, 'paidLeaveDays' => 1, 'authorizedLeaveDays' => 2, 'unauthorizedLeaveDays' => 0, 'bonus' => 500000, 'referral' => 100000, 'note' => null];
        $c->update($this->input($values), '2026-09', '001');
        $c->update($this->input(['bonus' => 0]), '2026-09', '002');
        $c->update($this->input(['bonus' => 200000]), '2026-10', '001');
        $rows = $c->index($this->input(), '2026-09')->getData(true)['data'];
        $this->assertCount(2, $rows);
        $this->assertEquals(0.5, $rows[0]['adjustment']['downtimeDays']);
        $this->assertSame('', $rows[0]['adjustment']['note']);
        $this->assertEquals(0, $rows[1]['adjustment']['bonus']);
        $this->assertSame('001', $rows[0]['employeeId']);
        $this->assertEquals(200000, $c->index($this->input(), '2026-10')->getData(true)['data'][0]['adjustment']['bonus']);
    }

    public function test_stale_save_is_rejected_without_overwriting(): void
    {
        $c = new VvpPayrollAdjustmentController;
        $c->update($this->input(['bonus' => 500]), '2026-09', '001');
        try {
            $c->update($this->input(['bonus' => 900]), '2026-09', '001');
            $this->fail('Expected conflict');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertEquals(500, $c->index($this->input(), '2026-09')->getData(true)['data'][0]['adjustment']['bonus']);
    }

    public function test_read_only_user_cannot_save(): void
    {
        $c = new VvpPayrollAdjustmentController;
        $this->assertSame([], $c->index($this->input([], 0, 'Employee'), '2026-09')->getData(true)['data']);
        $this->expectException(HttpException::class);
        $c->update($this->input(['bonus' => 100], 0, 'Employee'), '2026-09', '001');
    }

    public function test_unknown_employee_is_rejected(): void
    {
        $this->expectException(HttpException::class);
        (new VvpPayrollAdjustmentController)->update($this->input(['bonus' => 100]), '2026-09', 'missing');
    }

    public function test_invalid_days_are_rejected(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        (new VvpPayrollAdjustmentController)->update($this->input(['paidLeaveDays' => 32]), '2026-09', '001');
    }

    public function test_holiday_values_cannot_overwrite_separate_settings(): void
    {
        $row = (new VvpPayrollAdjustmentController)->update($this->input(['bonus' => 0, 'holidayDays' => 10, 'unknown' => 1]), '2026-09', '001')->getData(true)['data'];
        $this->assertSame(['bonus' => 0], $row['adjustment']);
    }
}
