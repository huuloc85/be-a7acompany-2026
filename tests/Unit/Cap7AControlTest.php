<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\Admin\Cap7AController;
use App\Http\Controllers\Api\Admin\Cap7AScreenController;
use App\Http\Middleware\API\CheckSuperAdmin;
use App\Services\Cap7AScreenSessions;
use App\Services\Cap7AThingsBoard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class Cap7AControlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'cap7a.commands_enabled' => true,
            'cap7a.device_id' => (string) Str::uuid()]);
        Cache::flush();
        Log::spy();
    }

    private function request(string $session): Request
    {
        $request = Request::create('/', 'POST', ['request_id' => (string) Str::uuid(),
            'session_id' => $session, 'action' => 'stop', 'confirmed' => true]);
        $request->setUserResolver(fn () => new class
        {
            public function getAuthIdentifier()
            {
                return 'superadmin-test';
            }
        });

        return $request;
    }

    public function test_rejects_non_superadmin(): void
    {
        foreach (['admin', 'co admin', 'employee'] as $role) {
            Auth::shouldReceive('user')->once()->andReturn((object) ['role' => (object) ['role_name' => $role]]);
            $response = (new CheckSuperAdmin)->handle(Request::create('/'), function () {
                self::fail('Non-superadmin reached controller');
            });
            self::assertSame(403, $response->getStatusCode());
        }
    }

    public function test_does_not_dispatch_offline_command(): void
    {
        $service = Mockery::mock(Cap7AThingsBoard::class);
        $service->shouldReceive('status')->once()->andReturn(['online' => false]);
        $service->shouldNotReceive('command');
        $this->expectException(HttpException::class);
        (new Cap7AController)->command($this->request((string) Str::uuid()), $service);
    }

    public function test_requires_local_permission(): void
    {
        $session = (string) Str::uuid();
        $service = Mockery::mock(Cap7AThingsBoard::class);
        $service->shouldReceive('status')->andReturn(['online' => true,
            'state' => ['session_id' => $session, 'remote_allowed' => false]]);
        $service->shouldNotReceive('command');
        $this->expectException(HttpException::class);
        (new Cap7AController)->command($this->request($session), $service);
    }

    public function test_deduplicates_command_before_dispatch(): void
    {
        $session = (string) Str::uuid();
        $request = $this->request($session);
        $service = Mockery::mock(Cap7AThingsBoard::class);
        $service->shouldReceive('status')->twice()->andReturn(['online' => true,
            'state' => ['session_id' => $session, 'remote_allowed' => true]]);
        $service->shouldReceive('command')->once()->andReturnUsing(fn ($data) => [
            'ok' => true, 'request_id' => $data['request_id'], 'message' => 'STOP requested',
        ]);
        self::assertSame(200, (new Cap7AController)->command($request, $service)->getStatusCode());
        $this->expectException(HttpException::class);
        (new Cap7AController)->command($request, $service);
    }

    public function test_screen_is_disabled_by_default(): void
    {
        config(['cap7a.screen_enabled' => false]);
        self::assertFalse((new Cap7AScreenSessions)->enabled());
    }

    public function test_mode_changes_and_reset_do_not_require_stop(): void
    {
        foreach (['RUN', 'TEST', 'STOP'] as $mode) {
            foreach (['run', 'test', 'stop', 'reset_counters'] as $action) {
                $session = (string) Str::uuid();
                $request = $this->request($session);
                $request->merge(['action' => $action]);
                $service = Mockery::mock(Cap7AThingsBoard::class);
                $service->shouldReceive('status')->once()->andReturn(['online' => true,
                    'state' => ['session_id' => $session, 'remote_allowed' => true, 'mode' => $mode]]);
                $service->shouldReceive('command')->once()->withArgs(fn ($data) => $data['action'] === $action)
                    ->andReturnUsing(fn ($data) => ['ok' => true, 'request_id' => $data['request_id']]);
                self::assertSame(200, (new Cap7AController)->command($request, $service)->getStatusCode());
            }
        }
    }

    public function test_settings_still_require_stop(): void
    {
        foreach (['RUN', 'TEST'] as $mode) {
            foreach (['set_threshold', 'set_roi', 'clear_roi'] as $action) {
                $session = (string) Str::uuid();
                $request = $this->request($session);
                $request->merge(['action' => $action, 'values' => ['threshold' => 50, 'roi' => [0, 0, 100, 100]]]);
                $service = Mockery::mock(Cap7AThingsBoard::class);
                $service->shouldReceive('status')->once()->andReturn(['online' => true,
                    'state' => ['session_id' => $session, 'remote_allowed' => true, 'mode' => $mode]]);
                $service->shouldNotReceive('command');
                try {
                    (new Cap7AController)->command($request, $service);
                    self::fail('Settings dispatched while '.$mode);
                } catch (HttpException $error) {
                    self::assertSame(409, $error->getStatusCode());
                }
            }
        }
    }

    public function test_screen_gateway_rejects_missing_secret(): void
    {
        config(['cap7a.screen_gateway_key' => str_repeat('x', 32)]);
        $sessions = Mockery::mock(Cap7AScreenSessions::class);
        $sessions->shouldNotReceive('authorize');
        $this->expectException(HttpException::class);
        (new Cap7AScreenController)->checkAccess(Request::create('/'), $sessions);
    }
}
