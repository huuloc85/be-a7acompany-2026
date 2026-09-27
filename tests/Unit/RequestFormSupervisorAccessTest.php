<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\Employee\EmpRequestFormController;
use App\Http\Middleware\CheckRequestForm;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class RequestFormSupervisorAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $app = new Container;
        Container::setInstance($app);
        Facade::setFacadeApplication($app);
        Auth::swap(Mockery::mock());
        $factory = Mockery::mock(ResponseFactory::class);
        $factory->shouldReceive('json')->andReturnUsing(
            fn ($data, $status = 200) => new JsonResponse($data, $status)
        );
        $app->instance(ResponseFactory::class, $factory);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    public static function recordEndpoints(): iterable
    {
        foreach (['getDetailAsSupervisor', 'approveBySupervisor', 'rejectBySupervisor'] as $method) {
            yield $method => [$method];
        }
    }

    private function invokeEndpoint(string $method): JsonResponse
    {
        $controller = new EmpRequestFormController;

        return $method === 'getDetailAsSupervisor'
            ? $controller->$method('123')
            : $controller->$method(Request::create('/'), '123');
    }

    #[DataProvider('recordEndpoints')]
    public function test_new_supervisor_passes_access_check(string $method): void
    {
        Auth::shouldReceive('id')->andReturn('22011800');
        $model = Mockery::mock('alias:App\Models\RequestForm');
        if ($method === 'getDetailAsSupervisor') {
            $model->shouldReceive('with')->once()->andReturnSelf();
        }
        $model->shouldReceive('find')->with('123')->once()->andReturn(null);

        // Missing record returns 404, not the former supervisor-access 403.
        self::assertSame(404, $this->invokeEndpoint($method)->getStatusCode());
    }

    #[DataProvider('recordEndpoints')]
    public function test_new_supervisor_cannot_access_another_supervisors_record(string $method): void
    {
        Auth::shouldReceive('id')->andReturn('22011800');
        $model = Mockery::mock('alias:App\Models\RequestForm');
        if ($method === 'getDetailAsSupervisor') {
            $model->shouldReceive('with')->once()->andReturnSelf();
        }
        $model->shouldReceive('find')->with('123')->once()->andReturn(
            (object) ['supervisor_id' => '19010400']
        );

        self::assertSame(403, $this->invokeEndpoint($method)->getStatusCode());
    }

    public function test_unlisted_employee_is_denied_on_all_supervisor_endpoints(): void
    {
        Auth::shouldReceive('id')->andReturn('99999999');
        $controller = new EmpRequestFormController;
        self::assertSame(403, $controller->getAsSupervisor(Request::create('/'))->getStatusCode());
        foreach (self::recordEndpoints() as [$method]) {
            self::assertSame(403, $this->invokeEndpoint($method)->getStatusCode());
        }
    }

    public function test_new_supervisors_list_is_scoped_to_assigned_requests(): void
    {
        Auth::shouldReceive('id')->andReturn('22011800');
        $model = Mockery::mock('alias:App\Models\RequestForm');
        $query = Mockery::mock();
        $model->shouldReceive('query')->once()->andReturn($query);
        $query->shouldReceive('where')->with('supervisor_id', '22011800')
            ->once()->ordered()->andReturnSelf();
        // Stop before database access, after verifying both ownership filters.
        $query->shouldReceive('where')->with('employee_id', '!=', '22011800')
            ->once()->ordered()->andThrow(new \RuntimeException('Scoped query verified'));

        $this->expectExceptionMessage('Scoped query verified');
        (new EmpRequestFormController)->getAsSupervisor(Request::create('/'));
    }

    public function test_request_form_middleware_accepts_new_supervisor(): void
    {
        Auth::shouldReceive('user')->andReturn((object) ['id' => '22011800']);
        $response = (new CheckRequestForm)->handle(
            Request::create('/'), fn () => new JsonResponse(['success' => true])
        );

        self::assertSame(200, $response->getStatusCode());
    }
}
