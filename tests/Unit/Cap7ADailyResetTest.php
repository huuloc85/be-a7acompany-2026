<?php

namespace Tests\Unit;

use App\Services\Cap7AThingsBoard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class Cap7ADailyResetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'cap7a.enabled' => true, 'cap7a.api_key' => 'test-key',
            'cap7a.device_id' => (string) Str::uuid(), 'cap7a.thingsboard_url' => 'https://tb.invalid']);
        Cache::flush();
        Log::spy();
        Http::preventStrayRequests();
    }

    public function test_reset_persists_server_timestamp_and_reopened_report_waits_for_matching_data(): void
    {
        $date = now('Asia/Ho_Chi_Minh')->toDateString();
        $attributeKey = 'cap7a_reset_'.str_replace('-', '_', $date);
        $saved = null;
        Http::fake(function ($request) use (&$saved, $attributeKey) {
            if ($request->method() === 'POST') {
                $saved = $request[$attributeKey];

                return Http::response('', 200);
            }
            if (str_contains($request->url(), '/values/attributes/')) {
                return Http::response($saved ? [['key' => $attributeKey, 'value' => $saved]] : []);
            }
            self::assertSame('cap7a_daily_'.str_replace('-', '_', now('Asia/Ho_Chi_Minh')->toDateString()).'_since_'.$saved, $request['keys']);

            return Http::response([]);
        });
        $result = (new Cap7AThingsBoard)->resetDaily($date, 'admin');
        self::assertTrue($result['reset_pending']);
        self::assertIsInt($saved);
        $reopened = (new Cap7AThingsBoard)->daily($date);
        self::assertSame($saved, $reopened['reset_at_ms']);
        self::assertNull($reopened['report']);
        self::assertTrue($reopened['reset_pending']);
        $again = (new Cap7AThingsBoard)->resetDaily($date, 'admin');
        self::assertGreaterThan($result['reset_at_ms'], $again['reset_at_ms']);
    }

    public function test_cannot_reset_past_date(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        try {
            (new Cap7AThingsBoard)->resetDaily(now('Asia/Ho_Chi_Minh')->subDay()->toDateString(), 'admin');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_filtered_report_returns_only_the_new_reset_version(): void
    {
        $date = '2026-10-15';
        $key = 'cap7a_daily_2026_10_15_since_1000';
        Http::fake([
            '*/values/attributes/*' => Http::response([['key' => 'cap7a_reset_2026_10_15', 'value' => 1000]]),
            '*/values/timeseries*' => Http::response([$key => [['ts' => 2000, 'value' => [
                'schema' => 1, 'date' => $date, 'timezone' => 'Asia/Ho_Chi_Minh', 'reset_at_ms' => 1000,
                'hours' => array_map(fn ($hour) => ['hour' => $hour, 'ok' => $hour === 9 ? 2 : 0,
                    'ng' => 0, 'unconfirmed' => 0], range(0, 23)),
            ]]]]),
        ]);
        $result = (new Cap7AThingsBoard)->daily($date);
        self::assertFalse($result['reset_pending']);
        self::assertSame(2, $result['report']['ok']);
    }

    public function test_reset_write_failure_is_not_reported_as_success(): void
    {
        Http::fake(['*/values/attributes/*' => Http::response([]),
            '*/SHARED_SCOPE' => Http::response([], 403)]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new Cap7AThingsBoard)->resetDaily(now('Asia/Ho_Chi_Minh')->toDateString(), 'admin');
    }
}
