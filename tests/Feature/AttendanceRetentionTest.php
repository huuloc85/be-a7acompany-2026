<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Admin\AttendanceHistoryController;
use App\Services\AttendanceDailyResultStore;
use App\Services\AttendanceRetentionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AttendanceRetentionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Carbon::setTestNow('2026-10-13T10:00:00+07:00');
        Schema::create('employees', function ($table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('company');
            $table->string('calendar_category_id');
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('calendar_categories', function ($table) {
            $table->string('id')->primary();
            $table->string('name');
        });
        Schema::create('schedule_details', function ($table) {
            $table->string('employee_id');
            $table->integer('schedule_id');
            $table->date('date');
            $table->string('hnhc');
            foreach (['is_wc_clean_men', 'is_wc_clean_women', 'is_wc_trash', 'is_eat_room'] as $column) {
                $table->boolean($column)->default(false);
            }
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('attendance_records', function ($table) {
            $table->id();
            $table->string('employee_code', 20)->nullable();
            $table->dateTime('datetime', 6)->nullable();
            $table->date('date')->nullable();
            $table->time('time', 6)->nullable();
            $table->timestamps();
        });
        foreach (['2026_10_13_000001_create_attendance_daily_results_table.php', '2026_10_13_000002_create_attendance_archive_state_table.php', '2026_10_13_000003_create_attendance_records_archive_table.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        DB::table('employees')->insert(['id' => '001', 'name' => 'Test', 'company' => 'VVP', 'calendar_category_id' => '1']);
        DB::table('calendar_categories')->insert(['id' => '1', 'name' => 'Production']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_rolls_forward_preserves_full_archive_and_is_idempotent(): void
    {
        foreach (['2026-07-01', '2026-08-01', '2026-09-01', '2026-10-01'] as $date) {
            $this->day($date);
        }
        $retained = DB::table('attendance_records')->where('date', '>=', '2026-08-01')->get()->toArray();
        $service = app(AttendanceRetentionService::class);
        $first = $service->rotate();
        $this->assertSame(2, $first['deleted_live']);
        $this->assertEquals($retained, DB::table('attendance_records')->get()->toArray());
        $this->assertSame(1, DB::table('attendance_daily_results')->count());
        $this->assertSame('2026-07-01', DB::table('attendance_daily_results')->value('date'));
        $this->assertSame('2026-08-01', DB::table('attendance_archive_state')->value('archived_before'));
        $this->assertSame(0, $service->rotate()['deleted_live']);
        $this->assertSame(2, DB::table('attendance_records_archive')->count());
        Carbon::setTestNow('2026-10-31T17:00:00Z');
        $second = $service->rotate();
        $this->assertSame('2026-09-01', $second['cutoff']);
        $this->assertSame(2, $second['deleted_live']);
        $this->assertSame(2, DB::table('attendance_daily_results')->count());
        $this->assertSame(4, DB::table('attendance_records_archive')->count());
        $this->assertSame('2026-09-01', DB::table('attendance_records')->min('date'));
        $request = Request::create('/', 'GET', ['filter' => ['date_between' => '2026-07-01,2026-08-31']]);
        (new AttendanceDailyResultStore)->synchronize([], $request);
        $this->assertSame(2, DB::table('attendance_daily_results')->count());
    }

    public function test_calculation_failure_rolls_back_archive_and_keeps_live_rows(): void
    {
        $this->day('2026-07-01');
        $controller = Mockery::mock(AttendanceHistoryController::class);
        $controller->shouldReceive('detail')->once()->andReturn(new JsonResponse(['error' => 'failed'], 500));
        try {
            (new AttendanceRetentionService($controller))->rotate();
            $this->fail('Expected failed calculation to abort retention');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Calculation failed', $error->getMessage());
        }
        $this->assertSame(2, DB::table('attendance_records')->count());
        $this->assertSame(0, DB::table('attendance_records_archive')->count());
        $this->assertSame(0, DB::table('attendance_archive_state')->count());
    }

    public function test_missing_saved_result_prevents_deletion(): void
    {
        $this->day('2026-07-01');
        $controller = Mockery::mock(AttendanceHistoryController::class);
        $controller->shouldReceive('detail')->once()->andReturn(new JsonResponse(['data' => [['date' => '2026-07-01']], 'total' => 1]));
        try {
            (new AttendanceRetentionService($controller))->rotate();
            $this->fail('Expected missing saved results to abort retention');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Incomplete daily archive', $error->getMessage());
        }
        $this->assertSame(2, DB::table('attendance_records')->count());
        $this->assertSame(0, DB::table('attendance_records_archive')->count());
    }

    public function test_night_checkout_across_boundary_and_orphan_punches_are_preserved(): void
    {
        $this->schedule('2026-07-31', 'D');
        $this->schedule('2026-08-01', 'X');
        $this->punch('001', '2026-07-31', '19:30:00');
        $this->punch('001', '2026-08-01', '07:30:00');
        $this->punch('missing-employee', '2026-07-15', '07:30:00.123456');
        app(AttendanceRetentionService::class)->rotate();
        $this->assertSame('2026-08-01 07:30:00', DB::table('attendance_daily_results')->value('time_out'));
        $this->assertSame(1, DB::table('attendance_records')->count());
        $this->assertSame('2026-07-15 07:30:00.123456', DB::table('attendance_records_archive')->where('employee_code', 'missing-employee')->value('datetime'));
        $this->assertSame(2, DB::table('attendance_records_archive')->count());
    }

    public function test_late_reimport_does_not_erase_frozen_daily_result(): void
    {
        $this->day('2026-07-01');
        $service = app(AttendanceRetentionService::class);
        $service->rotate();
        $before = (array) DB::table('attendance_daily_results')->first();
        $this->punch('001', '2026-07-01', '07:45:00');
        $this->assertSame(1, $service->rotate()['deleted_live']);
        $this->assertSame($before, (array) DB::table('attendance_daily_results')->first());
        $this->assertSame(3, DB::table('attendance_records_archive')->count());
    }

    public function test_command_defaults_to_dry_run(): void
    {
        $this->day('2026-07-01');
        $this->artisan('attendance:rotate-storage')->assertExitCode(0);
        $this->assertSame(2, DB::table('attendance_records')->count());
        $this->assertSame(0, DB::table('attendance_records_archive')->count());
    }

    private function day(string $date): void
    {
        $this->schedule($date, 'N');
        $this->punch('001', $date, '07:30:00');
        $this->punch('001', $date, '17:00:00');
    }

    private function schedule(string $date, string $hnhc): void
    {
        DB::table('schedule_details')->insert(['employee_id' => '001', 'schedule_id' => 1, 'date' => $date, 'hnhc' => $hnhc]);
    }

    private function punch(string $employee, string $date, string $time): void
    {
        DB::table('attendance_records')->insert(['employee_code' => $employee, 'datetime' => $date.' '.$time, 'date' => $date, 'time' => $time, 'created_at' => now(), 'updated_at' => now()]);
    }
}
