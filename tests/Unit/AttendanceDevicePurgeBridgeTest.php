<?php

namespace Tests\Unit;

use App\Console\Commands\AttendanceDeviceBridge;
use App\Services\AttendanceDeviceDeleteService;
use App\Services\AttendanceDeviceEmployeeService;
use App\Services\AttendanceDeviceRequestService;
use Illuminate\Container\Container;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class AttendanceDevicePurgeBridgeTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        Container::setInstance(null);
        parent::tearDown();
    }

    public static function cases(): iterable
    {
        yield 'purged snapshot' => [true, null, 'Example', true];
        yield 'missing without authorization' => [false, null, 'Example', false];
        yield 'reused code even same name' => [true, (object) ['role_id' => 2, 'name' => 'Example'], 'Example', false];
        yield 'missing snapshot name' => [true, null, null, false];
        yield 'ordinary explicit delete' => [false, (object) ['role_id' => 2, 'name' => 'Example'], 'Example', true];
        yield 'name mismatch' => [false, (object) ['role_id' => 2, 'name' => 'Other'], 'Example', false];
        yield 'excluded role' => [false, (object) ['role_id' => 15, 'name' => 'Example'], 'Example', false];
    }

    #[DataProvider('cases')]
    public function test_only_authorized_snapshot_is_deleted(bool $purged, ?object $employee, ?string $name, bool $allowed): void
    {
        Container::setInstance(new Container);
        $job = (object) ['id' => 7, 'employee_id' => '00123456', 'employee_name' => $name, 'action' => 'delete', 'delete_after_purge' => $purged];
        $model = Mockery::mock('alias:App\\Models\\Employee');
        $model->shouldReceive('withTrashed')->once()->andReturnSelf();
        $model->shouldReceive('find')->with('00123456')->once()->andReturn($employee);
        $queue = Mockery::mock(AttendanceDeviceRequestService::class);
        $queue->shouldReceive('claim')->once()->andReturn($job);
        $delete = Mockery::mock(AttendanceDeviceDeleteService::class);
        Container::getInstance()->instance(AttendanceDeviceDeleteService::class, $delete);
        $sync = Mockery::mock(AttendanceDeviceEmployeeService::class);
        $sync->shouldNotReceive('sync');
        $command = Mockery::mock(AttendanceDeviceBridge::class)->makePartial();
        if ($allowed) {
            $delete->shouldReceive('delete')->with('00123456', 'Example')->once();
            $queue->shouldReceive('finish')->with($job)->once();
            $command->shouldReceive('info')->once();
        } else {
            $delete->shouldNotReceive('delete');
            $queue->shouldReceive('finish')->with($job, Mockery::type('string'))->once();
        }
        $method = new \ReflectionMethod(AttendanceDeviceBridge::class, 'processEmployeeRequest');
        self::assertTrue($method->invoke($command, $queue, $sync));
    }
}
