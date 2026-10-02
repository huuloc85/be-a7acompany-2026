<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\Admin\AttendanceImportController;
use App\Services\AttendanceImportQueue;
use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Validator;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AttendanceImportControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $app = new Container;
        Container::setInstance($app);
        Facade::setFacadeApplication($app);
        $factory = new Factory(new Translator(new ArrayLoader, 'en'), $app);
        Validator::swap($factory);
        Request::macro('validate', function (array $rules) use ($factory) {
            return $factory->make($this->all(), $rules)->validate();
        });
        $responses = Mockery::mock(ResponseFactory::class);
        $responses->shouldReceive('json')->andReturnUsing(fn ($data, $status = 200) => new JsonResponse($data, $status));
        $app->instance(ResponseFactory::class, $responses);
        CarbonImmutable::setTestNow('2026-10-02T08:00:00+07:00');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Request::flushMacros();
        CarbonImmutable::setTestNow();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    public static function invalidRanges(): array
    {
        return [
            'missing confirmation' => [['start_date' => '2026-09-23', 'end_date' => '2026-09-28']],
            'missing dates' => [['confirmed' => true]],
            'reversed' => [['confirmed' => true, 'start_date' => '2026-09-28', 'end_date' => '2026-09-23']],
            'future' => [['confirmed' => true, 'start_date' => '2026-10-02', 'end_date' => '2026-10-03']],
            '32 days' => [['confirmed' => true, 'start_date' => '2026-09-01', 'end_date' => '2026-10-02']],
            'invalid date' => [['confirmed' => true, 'start_date' => '2026-09-31', 'end_date' => '2026-10-02']],
        ];
    }

    #[DataProvider('invalidRanges')]
    public function test_rejects_invalid_request_before_queuing(array $data): void
    {
        $queue = Mockery::mock(AttendanceImportQueue::class);
        $queue->shouldNotReceive('enqueue');
        $this->expectException(ValidationException::class);
        (new AttendanceImportController)->store(Request::create('/', 'POST', $data), $queue);
    }

    public function test_accepts_31_days_and_only_queues_without_contacting_device(): void
    {
        $request = Request::create('/', 'POST', ['confirmed' => true, 'start_date' => '2026-09-02', 'end_date' => '2026-10-02']);
        $user = Mockery::mock(\Illuminate\Contracts\Auth\Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn('admin');
        $request->setUserResolver(fn () => $user);
        $queue = Mockery::mock(AttendanceImportQueue::class);
        $queue->shouldReceive('enqueue')->once()->with('2026-09-02', '2026-10-02', 'admin')
            ->andReturn((object) ['id' => 1, 'status' => 'pending']);
        $response = (new AttendanceImportController)->store($request, $queue);
        self::assertSame(202, $response->getStatusCode());
        self::assertSame(['id' => 1, 'status' => 'pending'], $response->getData(true));
    }
}
