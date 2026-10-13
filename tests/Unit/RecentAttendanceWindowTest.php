<?php

namespace Tests\Unit;

use App\Http\Middleware\RecentAttendanceWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RecentAttendanceWindowTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function cases(): iterable
    {
        yield 'October default' => ['2026-10-13T10:00:00+07:00', [], 200, '2026-10-01,2026-10-31'];
        yield 'all three months' => ['2026-10-13T10:00:00+07:00', ['date_between' => '2026-08-01,2026-10-31'], 200, '2026-08-01,2026-10-31'];
        yield 'old July rejected' => ['2026-10-13T10:00:00+07:00', ['date_between' => '2026-07-01,2026-07-31'], 422, null];
        yield 'overlapping old month rejected' => ['2026-10-13T10:00:00+07:00', ['date_between' => '2026-07-31,2026-10-01'], 422, null];
        yield 'future rejected' => ['2026-10-13T10:00:00+07:00', ['date_between' => '2026-10-16,2026-11-15'], 422, null];
        yield 'November in Vietnam' => ['2026-10-31T17:00:00Z', [], 200, '2026-11-01,2026-11-30'];
        yield 'August expires in November' => ['2026-10-31T17:00:00Z', ['date_between' => '2026-08-01,2026-08-31'], 422, null];
        yield 'January crosses year' => ['2027-01-01T00:00:00+07:00', ['date_between' => '2026-11-01,2027-01-31'], 200, '2026-11-01,2027-01-31'];
        yield 'reverse dates' => ['2026-10-13T10:00:00+07:00', ['date_between' => '2026-10-31,2026-10-01'], 422, null];
        yield 'invalid day' => ['2026-10-13T10:00:00+07:00', ['date_between' => '2026-09-31,2026-10-31'], 422, null];
        yield 'array bypass' => ['2026-10-13T10:00:00+07:00', ['date_between' => ['2026-08-01', '2026-10-31']], 422, null];
        yield 'single date bypass' => ['2026-10-13T10:00:00+07:00', ['date' => '2025-01-01'], 422, null];
        yield 'preserve other filters' => ['2026-10-13T10:00:00+07:00', ['employee_id' => '001'], 200, '2026-10-01,2026-10-31'];
    }

    #[DataProvider('cases')]
    public function test_window(string $now, array $filters, int $status, ?string $expected): void
    {
        Carbon::setTestNow(Carbon::parse($now));
        $request = Request::create('/', 'GET', ['filter' => $filters]);
        $response = (new RecentAttendanceWindow)->handle($request, function ($request) use ($filters, $expected) {
            self::assertSame($expected, $request->input('filter.date_between'));
            if (isset($filters['employee_id'])) {
                self::assertSame('001', $request->input('filter.employee_id'));
            }

            return new JsonResponse(['ok' => true]);
        });
        self::assertSame($status, $response->getStatusCode());
    }
}
