<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\Admin\AttendanceRecordController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class AttendanceDeviceImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $app = new \Illuminate\Container\Container;
        \Illuminate\Container\Container::setInstance($app);
        \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
        $app->instance('config', new \Illuminate\Config\Repository);
        Http::swap(new \Illuminate\Http\Client\Factory);
        $factory = Mockery::mock(\Illuminate\Contracts\Routing\ResponseFactory::class);
        $factory->shouldReceive('json')->andReturnUsing(fn ($data, $status = 200) => new \Illuminate\Http\JsonResponse($data, $status));
        $app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class, $factory);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        \Illuminate\Support\Facades\Facade::setFacadeApplication(null);
        \Illuminate\Container\Container::setInstance(null);
        parent::tearDown();
    }

    public function test_import_maps_legacy_codes_preserves_time_and_reports_unknown_codes(): void
    {
        config(['acs.device_ip' => 'device.test', 'acs.username' => 'test', 'acs.password' => 'test']);
        Http::preventStrayRequests();
        Http::fake(['device.test/*' => Http::response(['AcsEvent' => [
            'numOfMatches' => 3,
            'totalMatches' => 3,
            'InfoList' => [
                ['employeeNoString' => '24021900', 'time' => '2026-09-23T07:15:00+07:00'],
                ['employeeNoString' => '24101900', 'time' => '2026-09-23T17:30:00+07:00'],
                ['employeeNoString' => '26092300', 'time' => '2026-09-23T07:00:00+07:00'],
            ],
        ]])]);
        $employees = Mockery::mock('alias:App\\Models\\Employee');
        $employees->shouldReceive('withTrashed')->once()->andReturnSelf();
        $employees->shouldReceive('pluck')->with('id')->once()->andReturn(collect(['24061901', '24101900']));
        $records = Mockery::mock('overload:App\\Services\\AttendanceEventWriter');
        $records->shouldReceive('store')->once()->with('24061901', Mockery::on(fn ($time) => $time->toIso8601String() === '2026-09-23T07:15:00+07:00'
        ))->andReturn(true);
        $records->shouldReceive('store')->once()->with('24101900', Mockery::on(fn ($time) => $time->toIso8601String() === '2026-09-23T17:30:00+07:00'
        ))->andReturn(true);
        $response = (new AttendanceRecordController)->fetchTodayEvents(Request::create('/', 'GET', [
            'start_time' => '2026-09-23T00:00:00+07:00',
            'end_time' => '2026-09-23T23:59:59+07:00',
        ]));
        self::assertSame(200, $response->getStatusCode());
        $data = $response->getData(true);
        self::assertSame(2, $data['total']);
        self::assertSame(1, $data['unmatched_total']);
        self::assertSame('26092300', $data['unmatched_events'][0]['EmployeeNo']);
    }
}
