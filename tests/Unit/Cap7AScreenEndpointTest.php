<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\Admin\Cap7AScreenController;
use App\Services\Cap7AScreenSessions;
use App\Services\Cap7ASystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class Cap7AScreenEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'cap7a.systems.tail.screen_enabled' => true,
            'cap7a.systems.tail.screen_dynamic_url' => true, 'cap7a.systems.tail.screen_gateway_key' => str_repeat('x', 32)]);
        Cache::flush();
    }

    private function register(string $url, string $key = '')
    {
        $request = Request::create('/', 'POST', ['websocket_url' => $url]);
        $request->headers->set('X-Cap7A-Gateway-Key', $key);

        return (new Cap7AScreenController)->registerEndpoint($request, 'tail');
    }

    public function test_registration_requires_secret(): void
    {
        $this->expectException(HttpException::class);
        $this->register('wss://test-name.trycloudflare.com/screen');
    }

    public function test_rejects_other_hosts_paths_and_credentials(): void
    {
        foreach (['wss://evil.test/screen', 'wss://a.trycloudflare.com.evil.test/screen',
            'wss://a.trycloudflare.com/screen?ticket=123', 'ws://a.trycloudflare.com/screen',
            'wss://user@a.trycloudflare.com/screen', 'wss://127.0.0.1/screen'] as $url) {
            try {
                $this->register($url, str_repeat('x', 32));
                self::fail('Invalid URL accepted');
            } catch (ValidationException $error) {
                self::assertArrayHasKey('websocket_url', $error->errors());
            }
        }
    }

    public function test_registration_is_temporary_and_rotated_key_has_no_old_endpoint(): void
    {
        $url = 'wss://test-name.trycloudflare.com/screen';
        self::assertSame(200, $this->register($url, str_repeat('x', 32))->getStatusCode());
        $sessions = new Cap7AScreenSessions(new Cap7ASystem('tail'));
        self::assertSame($url, Cache::get($sessions->endpointCacheKey()));
        config(['cap7a.systems.tail.screen_gateway_key' => str_repeat('y', 32)]);
        self::assertNull(Cache::get($sessions->endpointCacheKey()));
        config(['cap7a.systems.tail.screen_gateway_key' => str_repeat('x', 32)]);
        $this->travel(91)->seconds();
        self::assertNull(Cache::get($sessions->endpointCacheKey()));
    }

    public function test_dynamic_mode_requires_fresh_registration_not_static_fallback(): void
    {
        config(['cap7a.systems.tail.screen_ws_url' => 'wss://old.invalid/screen']);
        $this->expectException(HttpException::class);
        (new Cap7AScreenSessions(new Cap7ASystem('tail')))->issue(null);
    }

    public function test_ticket_uses_registered_endpoint(): void
    {
        $url = 'wss://new-name.trycloudflare.com/screen';
        $this->register($url, str_repeat('x', 32));
        $user = new class
        {
            public function currentAccessToken()
            {
                $token = new PersonalAccessToken;
                $token->id = 123;

                return $token;
            }

            public function getAuthIdentifier()
            {
                return 456;
            }
        };
        $response = (new Cap7AScreenSessions(new Cap7ASystem('tail')))->issue($user);
        self::assertSame($url, $response['websocket_url']);
        self::assertTrue($response['view_only']);
        self::assertSame(64, strlen($response['ticket']));
    }

    public function test_static_mode_and_disabled_screen_do_not_accept_registration(): void
    {
        config(['cap7a.systems.tail.screen_dynamic_url' => false]);
        $this->expectException(HttpException::class);
        $this->register('wss://test-name.trycloudflare.com/screen', str_repeat('x', 32));
    }

    public function test_named_tunnel_uses_fixed_hostname_instead_of_cached_quick_url(): void
    {
        $sessions = new Cap7AScreenSessions(new Cap7ASystem('tail'));
        Cache::put($sessions->endpointCacheKey(), 'wss://old-name.trycloudflare.com/screen', 90);
        config(['cap7a.systems.tail.screen_dynamic_url' => false,
            'cap7a.systems.tail.screen_ws_url' => 'wss://screen.a7atest.id.vn/screen']);
        $user = new class
        {
            public function currentAccessToken()
            {
                $token = new PersonalAccessToken;
                $token->id = 123;

                return $token;
            }

            public function getAuthIdentifier()
            {
                return 456;
            }
        };
        $response = $sessions->issue($user);
        self::assertSame('wss://screen.a7atest.id.vn/screen', $response['websocket_url']);
        self::assertTrue($response['view_only']);
        self::assertSame(64, strlen($response['ticket']));
    }
}
