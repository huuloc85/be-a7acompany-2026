<?php

namespace Tests\Unit;

use App\Services\AttendanceImportQueue;
use App\Services\AutomaticAttendanceImport;
use Carbon\Carbon;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

class AutomaticAttendanceImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $app = new Container;
        Container::setInstance($app);
        Facade::setFacadeApplication($app);
        $capsule = new Manager($app);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::swap($capsule->getDatabaseManager());
        Schema::swap($capsule->getConnection()->getSchemaBuilder());
        (require __DIR__.'/../../database/migrations/2026_10_02_000001_create_attendance_import_requests_table.php')->up();
        Carbon::setTestNow('2026-10-02T08:00:00+07:00');
    }

    protected function tearDown(): void
    {
        DB::disconnect();
        Carbon::setTestNow();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_enqueues_vietnam_yesterday_and_today_only_once_while_pending(): void
    {
        $auto = new AutomaticAttendanceImport;
        $queue = new AttendanceImportQueue;
        self::assertTrue($auto->enqueueIfDue($queue));
        self::assertFalse($auto->enqueueIfDue($queue));
        $row = DB::table('attendance_import_requests')->first();
        self::assertSame('2026-10-01', $row->start_date);
        self::assertSame('2026-10-02', $row->end_date);
        self::assertSame('bridge-auto', $row->requested_by);
        self::assertSame(1, DB::table('attendance_import_requests')->count());
    }

    public function test_failed_job_waits_five_minutes_then_reuses_same_queue_row(): void
    {
        $auto = new AutomaticAttendanceImport;
        $queue = new AttendanceImportQueue;
        $auto->enqueueIfDue($queue);
        $claimed = $queue->claim();
        $queue->fail($claimed, 'Device offline');
        Carbon::setTestNow('2026-10-02T08:04:59+07:00');
        self::assertFalse($auto->enqueueIfDue($queue));
        Carbon::setTestNow('2026-10-02T08:05:00+07:00');
        self::assertTrue($auto->enqueueIfDue($queue));
        self::assertSame($claimed->id, DB::table('attendance_import_requests')->first()->id);
        self::assertSame('pending', DB::table('attendance_import_requests')->first()->status);
    }

    public function test_active_manual_job_is_not_reset_even_when_lease_is_old(): void
    {
        $queue = new AttendanceImportQueue;
        $queue->enqueue('2026-10-01', '2026-10-02', 'admin');
        $claimed = $queue->claim();
        Carbon::setTestNow('2026-10-02T08:10:00+07:00');
        self::assertFalse((new AutomaticAttendanceImport)->enqueueIfDue($queue));
        $row = DB::table('attendance_import_requests')->first();
        self::assertSame($claimed->claim_token, $row->claim_token);
        self::assertSame('admin', $row->requested_by);
    }

    public function test_midnight_uses_new_vietnam_dates_even_if_utc_is_previous_day(): void
    {
        $auto = new AutomaticAttendanceImport;
        $queue = new AttendanceImportQueue;
        $auto->enqueueIfDue($queue);
        Carbon::setTestNow('2026-10-02T17:01:00Z');
        self::assertTrue($auto->enqueueIfDue($queue));
        $row = DB::table('attendance_import_requests')->orderByDesc('id')->first();
        self::assertSame('2026-10-02', $row->start_date);
        self::assertSame('2026-10-03', $row->end_date);
    }
}
