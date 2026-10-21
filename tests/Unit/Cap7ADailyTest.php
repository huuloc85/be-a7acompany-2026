<?php

namespace Tests\Unit;

use App\Services\Cap7ASystem;
use App\Services\Cap7AThingsBoard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class Cap7ADailyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cap7a.systems.tail.enabled' => true, 'cap7a.systems.tail.api_key' => 'test-key',
            'cap7a.systems.tail.device_id' => (string) Str::uuid(), 'cap7a.systems.tail.thingsboard_url' => 'https://tb.invalid']);
        Http::preventStrayRequests();
        Http::fake(['*/values/attributes/SHARED_SCOPE*' => Http::response([])]);
    }

    private function report(): array
    {
        return ['schema' => 1, 'date' => '2026-10-15', 'timezone' => 'Asia/Ho_Chi_Minh',
            'hours' => array_map(fn ($hour) => ['hour' => $hour, 'ok' => $hour === 9 ? 8 : 0,
                'ng' => $hour === 9 ? 2 : 0, 'unconfirmed' => $hour === 10 ? 1 : 0], range(0, 23))];
    }

    private function fake(array $report): void
    {
        Http::fake(['*/values/timeseries*' => Http::response([
            'cap7a_daily_2026_10_15' => [['ts' => 1000, 'value' => json_encode($report)]],
        ])]);
    }

    public function test_daily_totals_and_quality_use_confirmed_outputs(): void
    {
        $this->fake($this->report());
        $result = (new Cap7AThingsBoard(new Cap7ASystem('tail')))->daily('2026-10-15');
        self::assertTrue($result['available']);
        self::assertSame(11, $result['report']['total']);
        self::assertSame(80.0, $result['report']['ok_percent']);
        self::assertSame(1, $result['report']['unconfirmed']);
        Http::assertSent(fn ($request) => $request['keys'] === 'cap7a_daily_2026_10_15');
    }

    public function test_missing_history_is_not_zero_production(): void
    {
        Http::fake(['*/values/timeseries*' => Http::response([])]);
        self::assertFalse((new Cap7AThingsBoard(new Cap7ASystem('tail')))->daily('2026-10-15')['available']);
    }

    public function test_no_classified_products_have_no_quality_percentage(): void
    {
        $report = $this->report();
        $report['hours'][9]['ok'] = $report['hours'][9]['ng'] = 0;
        $this->fake($report);
        self::assertNull((new Cap7AThingsBoard(new Cap7ASystem('tail')))->daily('2026-10-15')['report']['ok_percent']);
    }

    public function test_rejects_report_for_wrong_day(): void
    {
        $report = $this->report();
        $report['date'] = '2026-10-14';
        $this->fake($report);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new Cap7AThingsBoard(new Cap7ASystem('tail')))->daily('2026-10-15');
    }

    public function test_rejects_negative_counters(): void
    {
        $report = $this->report();
        $report['hours'][9]['ng'] = -1;
        $this->fake($report);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new Cap7AThingsBoard(new Cap7ASystem('tail')))->daily('2026-10-15');
    }
}
