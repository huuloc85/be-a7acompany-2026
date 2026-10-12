<?php

namespace Tests\Unit;

use App\Services\AttendanceDeviceRequestService;
use Carbon\Carbon;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

class AttendanceDeviceRequestServiceTest extends TestCase
{
    private AttendanceDeviceRequestService $queue;

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
        $migration = require __DIR__.'/../../database/migrations/2026_09_30_000001_create_attendance_device_requests_table.php';
        $migration->up();
        (require __DIR__.'/../../database/migrations/2026_10_13_000001_add_device_request_action.php')->up();
        $this->queue = new AttendanceDeviceRequestService;
        Carbon::setTestNow('2026-09-30 08:00:00');
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

    public function test_duplicate_confirm_does_not_duplicate_or_interrupt_work(): void
    {
        $first = $this->queue->enqueue('123', 'admin');
        self::assertSame('pending', $first->status);
        self::assertSame($first->id, $this->queue->enqueue('123', 'admin')->id);
        $claimed = $this->queue->claim();
        self::assertSame('processing', $claimed->status);
        self::assertNull($this->queue->claim());
        self::assertSame($claimed->claim_token, $this->queue->enqueue('123', 'admin')->claim_token);
        $this->queue->finish($claimed);
        self::assertSame('succeeded', $this->queue->enqueue('123', 'admin')->status);
        self::assertSame(1, DB::table('attendance_device_requests')->count());
    }

    public function test_failed_request_can_be_retried_without_new_job(): void
    {
        $first = $this->queue->enqueue('123', 'admin');
        $this->queue->finish($this->queue->claim(), 'Device offline');
        self::assertSame('failed', DB::table('attendance_device_requests')->first()->status);
        $retry = $this->queue->enqueue('123', 'admin');
        self::assertSame($first->id, $retry->id);
        self::assertSame('pending', $retry->status);
        self::assertNull($retry->error);
        self::assertSame(2, $this->queue->claim()->attempts);
    }

    public function test_expired_worker_is_recovered_and_cannot_overwrite_new_result(): void
    {
        $this->queue->enqueue('123', 'admin');
        $old = $this->queue->claim();
        Carbon::setTestNow('2026-09-30 08:06:00');
        $new = $this->queue->claim();
        self::assertNotSame($old->claim_token, $new->claim_token);
        $this->queue->finish($old, 'Old worker failure');
        self::assertSame('processing', DB::table('attendance_device_requests')->first()->status);
        $this->queue->finish($new);
        self::assertSame('succeeded', DB::table('attendance_device_requests')->first()->status);
    }

    public function test_pending_request_survives_until_worker_starts(): void
    {
        $this->queue->enqueue('123', 'admin');
        Carbon::setTestNow('2026-10-01 08:00:00');
        self::assertSame('pending', DB::table('attendance_device_requests')->first()->status);
        self::assertSame('123', $this->queue->claim()->employee_id);
    }

    public function test_delete_then_readd_reuses_row_and_legacy_workers_cannot_claim_delete(): void
    {
        $this->queue->enqueue('123', 'admin');
        $this->queue->finish($this->queue->claim());
        $deleted = $this->queue->enqueue('123', 'admin', 'delete', 'Example');
        self::assertSame('delete_pending', $deleted->status);
        self::assertFalse(DB::table('attendance_device_requests')->whereIn('status', ['pending', 'processing'])->exists());
        $job = $this->queue->claim();
        self::assertSame('delete_processing', $job->status);
        self::assertSame('Example', $job->employee_name);
        self::assertSame($job->claim_token, $this->queue->enqueue('123', 'admin', 'delete', 'Example')->claim_token);
        $this->queue->finish($job);
        $readd = $this->queue->enqueue('123', 'admin');
        self::assertSame('sync', $readd->action);
        self::assertSame('pending', $readd->status);
        self::assertSame(1, DB::table('attendance_device_requests')->count());
    }

    public function test_opposite_action_cannot_replace_active_request(): void
    {
        $this->queue->enqueue('123', 'admin', 'delete', 'Example');
        $this->expectException(\Symfony\Component\HttpKernel\Exception\ConflictHttpException::class);
        $this->queue->enqueue('123', 'admin');
    }

    public function test_stale_delete_claim_is_recovered_without_allowing_old_worker_to_finish(): void
    {
        $this->queue->enqueue('123', 'admin', 'delete', 'Example');
        $old = $this->queue->claim();
        Carbon::setTestNow('2026-09-30 08:06:00');
        $new = $this->queue->claim();
        self::assertSame('delete_processing', $new->status);
        self::assertNotSame($old->claim_token, $new->claim_token);
        $this->queue->finish($old);
        self::assertSame('delete_processing', DB::table('attendance_device_requests')->first()->status);
        $this->queue->finish($new);
        self::assertSame('succeeded', DB::table('attendance_device_requests')->first()->status);
    }
}
