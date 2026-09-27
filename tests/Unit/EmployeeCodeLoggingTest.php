<?php

namespace Tests\Unit;

use App\Helpers\LogActivity;
use Carbon\Carbon;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class EmployeeCodeLoggingTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    public static function loggingCases(): iterable
    {
        yield 'view uses current ID' => ['logViewActivity', '25060400', '2026-09-28'];
        yield 'special employee keeps current date' => ['logRoleSpecificLoginActivity', '25060400', '2026-09-28'];
        yield 'ordinary shift keeps previous-day rule' => ['logRoleSpecificLoginActivity', '22120700', '2026-09-27'];
    }

    #[DataProvider('loggingCases')]
    public function test_logs_use_current_employee_id(string $method, string $id, string $date): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-28 07:00:00'));
        $user = (object) ['id' => $id, 'name' => 'Test employee', 'role_id' => 15, 'calendar_category_id' => 1];
        $history = Mockery::mock('alias:App\\Models\\LoginHistory');
        $history->shouldReceive('where')->with('employee_id', $id)->once()->andReturnSelf();
        $history->shouldReceive('where')->with('month_history', '2026-09')->once()->andReturnSelf();
        $history->shouldReceive('where')->with('activity_type', 'test')->once()->andReturnSelf();
        $history->shouldReceive('where')->with('date', $date)->once()->andReturnSelf();
        $history->shouldReceive('first')->once()->andReturn(null);
        $history->shouldReceive('create')->once()->with(Mockery::on(function ($record) use ($id, $date) {
            self::assertSame($id, $record['employee_id']);
            self::assertSame($id, $record['employee_code']);
            self::assertSame($date, $record['date']);

            return true;
        }));

        LogActivity::$method($user, 'test', 'Test description');
    }
}
