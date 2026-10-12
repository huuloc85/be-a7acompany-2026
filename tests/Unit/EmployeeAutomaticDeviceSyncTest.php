<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\Admin\EmployeeController;
use App\Services\AttendanceDeviceRequestService;
use Illuminate\Container\Container;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class EmployeeAutomaticDeviceSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $app = new Container;
        Container::setInstance($app);
        Facade::setFacadeApplication($app);
        DB::swap(Mockery::mock());
        $hash = Mockery::mock(Hasher::class);
        $hash->shouldReceive('make')->andReturn('test-hash');
        $app->instance('hash', $hash);
        $response = Mockery::mock(ResponseFactory::class);
        $response->shouldReceive('json')->andReturnUsing(fn ($data, $status = 200) => new JsonResponse($data, $status));
        $app->instance(ResponseFactory::class, $response);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    public static function roles(): iterable
    {
        yield 'eligible' => [2, true];
        foreach ([1, 15, 21, 22] as $role) {
            yield 'excluded '.$role => [$role, false];
        }
    }

    #[DataProvider('roles')]
    public function test_creation_queues_once_before_commit_without_second_confirmation(int $role, bool $sync): void
    {
        $employee = (object) ['id' => '00123456', 'name' => 'Example', 'role_id' => $role];
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('validate')->once()->andReturn((array) $employee);
        $request->shouldReceive('hasFile')->with('card_photo')->andReturn(false);
        if ($sync) {
            $user = Mockery::mock(\Illuminate\Contracts\Auth\Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->once()->andReturn('admin');
            $request->shouldReceive('user')->once()->andReturn($user);
        } else {
            $request->shouldNotReceive('user');
        }
        $model = Mockery::mock('alias:App\\Models\\Employee');
        $queue = Mockery::mock(AttendanceDeviceRequestService::class);
        Container::getInstance()->instance(AttendanceDeviceRequestService::class, $queue);
        DB::shouldReceive('beginTransaction')->once()->globally()->ordered();
        $model->shouldReceive('create')->once()->globally()->ordered()
            ->with(Mockery::on(fn ($data) => $data['id'] === '00123456'))->andReturn($employee);
        if ($sync) {
            $queue->shouldReceive('enqueue')->once()->globally()->ordered()->with('00123456', 'admin')
                ->andReturn((object) ['status' => 'pending']);
        } else {
            $queue->shouldNotReceive('enqueue');
        }
        DB::shouldReceive('commit')->once()->globally()->ordered();
        DB::shouldReceive('rollBack')->never();
        $response = (new EmployeeController)->addEmployee($request);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('00123456', $response->getData(true)['data']['id']);
        if ($sync) {
            self::assertSame('pending', $response->getData(true)['attendance_device']['status']);
        } else {
            self::assertNull($response->getData(true)['attendance_device']);
        }
    }
}
