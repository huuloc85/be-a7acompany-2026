<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Admin\AttendanceDailyResultController;
use App\Http\Controllers\Api\Admin\AttendanceHistoryController;
use App\Services\AttendanceDailyResultStore;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AttendanceDailyResultTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Carbon::setTestNow('2026-10-13 10:00:00');
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
            $table->string('employee_code');
            $table->date('date');
            $table->time('time');
            $table->dateTime('datetime');
        });
        (require database_path('migrations/2026_10_13_000001_create_attendance_daily_results_table.php'))->up();
        DB::table('calendar_categories')->insert(['id' => '1', 'name' => 'Production']);
        foreach (['001', '002'] as $id) {
            DB::table('employees')->insert([
                'id' => $id, 'name' => 'Employee '.$id, 'company' => 'VVP', 'calendar_category_id' => '1',
            ]);
            foreach (['2026-09-30', '2026-10-01', '2026-10-02'] as $date) {
                DB::table('schedule_details')->insert([
                    'employee_id' => $id, 'schedule_id' => 1, 'date' => $date, 'hnhc' => 'N',
                ]);
                $this->punch($id, $date, '07:30:00');
                $this->punch($id, $date, '17:00:00');
            }
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_screen_saves_all_pages_and_updates_without_duplicates(): void
    {
        $response = $this->calculate(['limit' => 1]);
        $this->assertCount(1, $response['data']);
        $this->assertSame(4, $response['total']);
        $this->assertSame(4, DB::table('attendance_daily_results')->count());
        $saved = DB::table('attendance_daily_results')->where('employee_id', '001')->where('date', '2026-10-01')->first();
        $this->assertEquals(8.5, $saved->total_hours);
        $this->punch('001', '2026-10-01', '18:00:00');
        $this->calculate(['page' => 2, 'limit' => 1]);
        $updated = DB::table('attendance_daily_results')->where('id', $saved->id)->first();
        $this->assertEquals(9.5, $updated->total_hours);
        $this->assertEquals(1.5, $updated->overtime_hours);
        $this->assertSame('2026-10-01 18:00:00', $updated->time_out);
        $this->assertSame($saved->created_at, $updated->created_at);
        $this->assertSame('001', $updated->employee_id);
        $this->assertSame(4, DB::table('attendance_daily_results')->count());
    }

    public function test_database_rejects_duplicate_employee_date(): void
    {
        $this->calculate();
        $row = (array) DB::table('attendance_daily_results')->first();
        unset($row['id']);
        $this->expectException(QueryException::class);
        DB::table('attendance_daily_results')->insert($row);
    }

    public function test_empty_filtered_recalculation_removes_only_its_derived_results(): void
    {
        $this->calculate(['filter' => ['date_between' => '2026-09-30,2026-10-02']]);
        DB::table('attendance_records')->where('employee_code', '001')->where('date', '2026-10-01')->delete();
        $response = $this->calculate(['filter' => [
            'date_between' => '2026-10-01,2026-10-01', 'employee_id' => '001',
        ]]);
        $this->assertSame(0, $response['total']);
        $this->assertSame(5, DB::table('attendance_daily_results')->count());
        $this->assertFalse(DB::table('attendance_daily_results')->where('employee_id', '001')->where('date', '2026-10-01')->exists());
        $this->assertSame(2, DB::table('attendance_daily_results')->where('date', '2026-09-30')->count());
    }

    public function test_night_shift_and_additional_shift_match_display(): void
    {
        DB::table('attendance_records')->where('employee_code', '001')->delete();
        DB::table('schedule_details')->where('employee_id', '001')->update(['hnhc' => 'TC']);
        $this->punch('001', '2026-10-01', '21:30:00');
        $this->punch('001', '2026-10-02', '07:30:00');
        $this->calculate();
        $row = DB::table('attendance_daily_results')->where('employee_id', '001')->where('date', '2026-10-01')->first();
        $this->assertSame('2026-10-02 07:30:00', $row->time_out);
        $this->assertSame('Tăng cường ca đêm', $row->day_type);
        $this->assertEquals(9, $row->total_hours);
        $this->assertEquals(0, $row->administrative_hours);
        $this->assertEquals(9, $row->overtime_hours);
    }

    public function test_missing_checkout_is_nullable_and_schedule_change_updates_saved_result(): void
    {
        DB::table('schedule_details')->where('employee_id', '001')->where('date', '2026-10-01')->update(['hnhc' => 'D']);
        DB::table('attendance_records')->where('employee_code', '001')->delete();
        $this->punch('001', '2026-10-01', '21:30:00');
        $this->calculate();
        $row = DB::table('attendance_daily_results')->where('employee_id', '001')->first();
        $this->assertNull($row->time_out);
        $this->assertEquals(0, $row->total_hours);
        DB::table('schedule_details')->where('employee_id', '002')->where('date', '2026-10-01')->update(['hnhc' => 'LN']);
        $this->calculate();
        $row = DB::table('attendance_daily_results')->where('employee_id', '002')->where('date', '2026-10-01')->first();
        $this->assertSame('Tăng cường ca ngày', $row->day_type);
        $this->assertEquals(0, $row->administrative_hours);
        $this->assertEquals($row->total_hours, $row->overtime_hours);
    }

    public function test_saved_endpoint_reads_without_recalculating_and_has_permission_middleware(): void
    {
        $this->calculate();
        DB::table('attendance_records')->delete();
        $request = $this->request(['filter' => ['employees.name' => '001'], 'limit' => 1]);
        $response = (new AttendanceDailyResultController)->index($request, new AttendanceDailyResultStore)->getData(true);
        $this->assertSame(2, $response['total']);
        $this->assertCount(1, $response['data']);
        $this->assertEquals(8.5, $response['data'][0]['total_hours']);
        $this->assertArrayNotHasKey('sync_token', $response['data'][0]);
        $route = Route::getRoutes()->match(Request::create('/api/attendances/daily-results', 'GET'));
        $this->assertContains('api.can:view_attendance', $route->gatherMiddleware());
    }

    public function test_raw_history_request_does_not_write_calculated_results(): void
    {
        $request = $this->request(['include_calculation' => 0]);
        $response = (new AttendanceHistoryController)->detail($request);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, DB::table('attendance_daily_results')->count());
    }

    private function calculate(array $params = []): array
    {
        $request = $this->request(['include_calculation' => 1, ...$params]);
        $response = (new AttendanceHistoryController)->detail($request);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        return $response->getData(true);
    }

    private function request(array $params): Request
    {
        $request = Request::create('/api/attendances', 'GET', $params);
        $this->app->instance('request', $request);

        return $request;
    }

    private function punch(string $id, string $date, string $time): void
    {
        DB::table('attendance_records')->insert([
            'employee_code' => $id, 'date' => $date, 'time' => $time, 'datetime' => $date.' '.$time,
        ]);
    }
}
