<?php

namespace Tests\Unit;

use App\Services\Cap7AThingsBoard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class Cap7AThingsBoardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'cap7a.enabled' => true, 'cap7a.api_key' => null,
            'cap7a.device_id' => (string) Str::uuid(), 'cap7a.username' => 'test@example.invalid',
            'cap7a.password' => 'secret-test', 'cap7a.thingsboard_url' => 'https://tb.invalid']);
        Http::preventStrayRequests();
        Cache::flush();
    }

    private function telemetry(int $age = 0, bool $online = true): array
    {
        $ts = now()->getTimestampMs() - $age;

        return ['cap7a_online' => [['ts' => $ts, 'value' => $online ? 'true' : 'false']],
            'cap7a_state' => [['ts' => $ts, 'value' => json_encode([
                'schema' => 1, 'session_id' => (string) Str::uuid(), 'sampled_at_ms' => $ts,
                'mode' => 'STOP', 'total' => 5,
            ])]]];
    }

    public function test_disabled_configuration_does_not_contact_thingsboard(): void
    {
        config(['cap7a.enabled' => false]);
        $result = (new Cap7AThingsBoard)->status();
        self::assertFalse($result['configured']);
        Http::assertNothingSent();
    }

    public function test_receives_fresh_data_without_exposing_credentials(): void
    {
        Http::fake(['*/api/auth/login' => Http::response(['token' => 'jwt-test']),
            '*/values/timeseries*' => Http::response($this->telemetry())]);
        $result = (new Cap7AThingsBoard)->status();
        self::assertTrue($result['online']);
        self::assertSame(5, $result['state']['total']);
        self::assertStringNotContainsString('jwt-test', json_encode($result));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/values/timeseries') && $r->hasHeader('X-Authorization', 'Bearer jwt-test'));
    }

    public function test_api_key_receives_telemetry_without_login_credentials(): void
    {
        config(['cap7a.api_key' => '  tb-test-key  ', 'cap7a.username' => null, 'cap7a.password' => null]);
        Http::fake(['*/values/timeseries*' => Http::response($this->telemetry())]);
        $result = (new Cap7AThingsBoard)->status();
        self::assertTrue($result['configured']);
        self::assertTrue($result['online']);
        self::assertStringNotContainsString('tb-test-key', json_encode($result));
        Http::assertSent(fn ($r) => $r->hasHeader('X-Authorization', 'ApiKey tb-test-key'));
        Http::assertSentCount(1);
    }

    public function test_api_key_takes_priority_for_rpc(): void
    {
        config(['cap7a.api_key' => 'tb-test-key']);
        Http::fake(['*/api/rpc/twoway/*' => Http::response(['ok' => true])]);
        (new Cap7AThingsBoard)->command(['expires_at_ms' => 123, 'action' => 'stop']);
        Http::assertSent(fn ($r) => $r->hasHeader('X-Authorization', 'ApiKey tb-test-key')
            && $r['persistent'] === false && $r['method'] === 'cap7a.command');
        Http::assertSentCount(1);
    }

    public function test_rejected_api_key_does_not_retry_or_fall_back_to_login(): void
    {
        config(['cap7a.api_key' => 'tb-test-key']);
        Http::fake(['*/values/timeseries*' => Http::response(['message' => 'tb-test-key'], 401)]);
        try {
            (new Cap7AThingsBoard)->status();
            self::fail('Expected rejected API key to fail.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            self::assertSame(502, $error->getStatusCode());
            self::assertStringNotContainsString('tb-test-key', $error->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_whitespace_api_key_without_credentials_is_unconfigured(): void
    {
        config(['cap7a.api_key' => '   ', 'cap7a.username' => null, 'cap7a.password' => null]);
        self::assertFalse((new Cap7AThingsBoard)->status()['configured']);
        Http::assertNothingSent();
    }

    public function test_stale_data_and_last_will_are_offline(): void
    {
        foreach ([$this->telemetry(60000), $this->telemetry(0, false)] as $data) {
            Http::fake(['*/api/auth/login' => Http::response(['token' => 'jwt-test']),
                '*/values/timeseries*' => Http::response($data)]);
            self::assertFalse((new Cap7AThingsBoard)->status()['online']);
        }
    }

    public function test_rejects_insecure_url(): void
    {
        config(['cap7a.thingsboard_url' => 'http://tb.invalid']);
        self::assertFalse((new Cap7AThingsBoard)->configured());
        Http::assertNothingSent();
    }

    public function test_rpc_is_nonpersistent_and_does_not_retry(): void
    {
        Http::fake(['*/api/auth/login' => Http::response(['token' => 'jwt-test']),
            '*/api/rpc/twoway/*' => Http::response(['ok' => true])]);
        (new Cap7AThingsBoard)->command(['expires_at_ms' => 123, 'action' => 'stop']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/rpc/twoway/')
            && $r['persistent'] === false && $r['timeout'] === 5000 && $r['method'] === 'cap7a.command');
        Http::assertSentCount(2);
    }

    public function test_cap7a_routes_have_authentication_and_superadmin_guard(): void
    {
        $routes = collect(app('router')->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/cap7a/'));
        self::assertCount(4, $routes);
        self::assertTrue($routes->contains(fn ($route) => $route->uri() === 'api/cap7a/daily'));
        foreach ($routes as $route) {
            self::assertContains('auth:sanctum', $route->middleware());
            self::assertContains('check.token.expiration', $route->middleware());
            self::assertContains('api.permission', $route->middleware());
        }
    }
}
