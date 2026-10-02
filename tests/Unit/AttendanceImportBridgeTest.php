<?php

namespace Tests\Unit;

use App\Services\AttendanceDeviceImportService;
use App\Services\AttendanceEventWriter;
use App\Services\AttendanceImportQueue;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

class AttendanceImportBridgeTest extends TestCase
{
    private AttendanceImportQueue $queue;

    private AttendanceDeviceImportService $importer;

    protected function setUp(): void
    {
        parent::setUp();
        $app = new Container;
        Container::setInstance($app);
        Facade::setFacadeApplication($app);
        $app->instance('config', new Repository(['acs' => ['device_ip' => 'device.test', 'username' => 'test', 'password' => 'test']]));
        $capsule = new Manager($app);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->bootEloquent();
        DB::swap($capsule->getDatabaseManager());
        Schema::swap($capsule->getConnection()->getSchemaBuilder());
        (require __DIR__.'/../../database/migrations/2026_10_02_000001_create_attendance_import_requests_table.php')->up();
        Schema::create('employees', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->softDeletes();
        });
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->string('employee_code');
            $table->dateTime('datetime');
            $table->timestamps();
        });
        DB::table('employees')->insert(['id' => '24061901']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Carbon::setTestNow('2026-10-02 08:00:00');
        $this->queue = new AttendanceImportQueue;
        $this->importer = new AttendanceDeviceImportService;
    }

    protected function tearDown(): void
    {
        DB::disconnect();
        Carbon::setTestNow();
        \Illuminate\Database\Eloquent\Model::unsetConnectionResolver();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    private function event(string $code = '24021900', string $time = '2026-09-23T07:15:00+07:00'): array
    {
        return ['employeeNoString' => $code, 'time' => $time];
    }

    private function page(array $events, ?int $total = null): array
    {
        return ['AcsEvent' => ['InfoList' => $events, 'numOfMatches' => count($events), 'totalMatches' => $total ?? count($events)]];
    }

    public function test_enqueue_is_idempotent_while_waiting_but_completed_ranges_can_refresh(): void
    {
        $a = $this->queue->enqueue('2026-09-23', '2026-09-23', 'admin');
        self::assertSame($a->id, $this->queue->enqueue('2026-09-23', '2026-09-23', 'admin')->id);
        $job = $this->queue->claim();
        self::assertNull($this->queue->claim());
        self::assertSame($job->claim_token, $this->queue->enqueue('2026-09-23', '2026-09-23', 'other')->claim_token);
        Http::fakeSequence()->push($this->page([]));
        $this->importer->run($job, $this->queue, new AttendanceEventWriter);
        self::assertSame('succeeded', DB::table('attendance_import_requests')->first()->status);
        self::assertSame('pending', $this->queue->enqueue('2026-09-23', '2026-09-23', 'admin')->status);
        self::assertSame(1, DB::table('attendance_import_requests')->count());
    }

    public function test_import_maps_codes_deduplicates_preserves_existing_and_reports_unknown(): void
    {
        $this->queue->enqueue('2026-09-23', '2026-09-23', 'admin');
        $events = [$this->event(), $this->event('24061901'), $this->event('unknown'),
            ['employeeNoString' => '24061901'], $this->event('', '2026-09-23T09:00:00+07:00'),
            $this->event('24061901', '2026-09-24T07:00:00+07:00')];
        Http::fakeSequence()->push($this->page($events))->push($this->page($events));
        $this->importer->run($this->queue->claim(), $this->queue, new AttendanceEventWriter);
        $result = DB::table('attendance_import_requests')->first();
        self::assertSame('succeeded', $result->status);
        self::assertSame(1, $result->inserted);
        self::assertSame(1, $result->existing);
        self::assertSame(1, $result->unmatched);
        self::assertSame(3, $result->skipped);
        self::assertSame(['unknown'], json_decode($result->unmatched_codes, true));
        $saved = (array) DB::table('attendance_records')->first();
        self::assertSame('24061901', $saved['employee_code']);
        self::assertSame('2026-09-23 07:15:00', $saved['datetime']);
        Carbon::setTestNow('2026-10-02 09:00:00');
        $this->queue->enqueue('2026-09-23', '2026-09-23', 'admin');
        $this->importer->run($this->queue->claim(), $this->queue, new AttendanceEventWriter);
        self::assertSame(1, DB::table('attendance_records')->count());
        self::assertSame($saved, (array) DB::table('attendance_records')->first());
        self::assertSame(0, DB::table('attendance_import_requests')->first()->inserted);
    }

    public function test_pagination_uses_same_search_id_and_vietnam_day_boundaries(): void
    {
        Http::fakeSequence()->push($this->page([$this->event()], 2))
            ->push($this->page([$this->event('24061901', '2026-09-23T17:00:00+07:00')], 2));
        $heartbeats = 0;
        $events = $this->importer->fetchDay(CarbonImmutable::parse('2026-09-23', 'Asia/Ho_Chi_Minh'), function () use (&$heartbeats) {
            $heartbeats++;
        });
        self::assertCount(2, $events);
        self::assertSame(2, $heartbeats);
        $requests = Http::recorded()->map(fn ($pair) => $pair[0]['AcsEventCond']);
        self::assertSame($requests[0]['searchID'], $requests[1]['searchID']);
        self::assertSame(1, $requests[1]['searchResultPosition']);
        self::assertSame('2026-09-23T00:00:00+07:00', $requests[0]['startTime']);
        self::assertSame('2026-09-23T23:59:59+07:00', $requests[0]['endTime']);
    }

    public function test_incomplete_day_is_not_written_and_prior_days_survive_failure(): void
    {
        $this->queue->enqueue('2026-09-23', '2026-09-24', 'admin');
        $job = $this->queue->claim();
        Http::fakeSequence()->push($this->page([$this->event()]))
            ->push($this->page([$this->event('24061901', '2026-09-24T07:00:00+07:00')], 2))
            ->push($this->page([], 2));
        try {
            $this->importer->run($job, $this->queue, new AttendanceEventWriter);
            self::fail('Must reject incomplete page');
        } catch (\RuntimeException $e) {
            $this->queue->fail($job, $e->getMessage());
        }
        self::assertSame(1, DB::table('attendance_records')->count());
        self::assertSame(1, DB::table('attendance_import_requests')->first()->completed_days);
        self::assertSame('failed', DB::table('attendance_import_requests')->first()->status);
    }

    public function test_reclaimed_worker_resumes_checkpoint_and_stale_worker_cannot_write(): void
    {
        $this->queue->enqueue('2026-09-23', '2026-09-24', 'admin');
        $old = $this->queue->claim();
        DB::table('attendance_import_requests')->where('id', $old->id)->update(['completed_days' => 1]);
        Carbon::setTestNow('2026-10-02 08:06:00');
        $new = $this->queue->claim();
        self::assertNotSame($old->claim_token, $new->claim_token);
        $this->queue->fail($old, 'stale error');
        self::assertSame('processing', DB::table('attendance_import_requests')->first()->status);
        try {
            $this->queue->heartbeat($old);
            self::fail('Stale heartbeat must fail');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('tiến trình khác', $e->getMessage());
        }
        Http::fakeSequence()->push($this->page([]));
        $this->importer->run($new, $this->queue, new AttendanceEventWriter);
        Http::assertSent(fn ($request) => $request['AcsEventCond']['startTime'] === '2026-09-24T00:00:00+07:00');
        self::assertSame(2, DB::table('attendance_import_requests')->first()->completed_days);
        self::assertSame('succeeded', DB::table('attendance_import_requests')->first()->status);
    }

    public function test_device_http_200_error_is_not_empty_success(): void
    {
        Http::fakeSequence()->push(['statusCode' => 4, 'statusString' => 'Invalid Operation']);
        $this->expectException(\RuntimeException::class);
        $this->importer->fetchDay(CarbonImmutable::parse('2026-09-23'), fn () => null);
    }

    public function test_repeated_pages_are_rejected(): void
    {
        Http::fakeSequence()->push($this->page([$this->event()], 3))->push($this->page([$this->event()], 3));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('lặp trang');
        $this->importer->fetchDay(CarbonImmutable::parse('2026-09-23'), fn () => null);
    }
}
