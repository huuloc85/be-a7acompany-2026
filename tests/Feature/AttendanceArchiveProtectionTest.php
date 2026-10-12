<?php

namespace Tests\Feature;

use App\Services\AttendanceDailyResultStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AttendanceArchiveProtectionTest extends TestCase
{
    public function test_archived_results_survive_refresh_and_cannot_be_overwritten(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        (require database_path('migrations/2026_10_13_000001_create_attendance_daily_results_table.php'))->up();
        (require database_path('migrations/2026_10_13_000002_create_attendance_archive_state_table.php'))->up();
        $request = Request::create('/', 'GET', ['filter' => ['date_between' => '2026-06-01,2026-07-31']]);
        $row = [
            'employee_id' => '001', 'name' => 'Test', 'company' => 'VVP', 'calendar_category_id' => '1',
            'date' => '2026-06-30', 'shift' => 1, 'hnhc' => 'N', 'day_type' => 'Ca ngày',
            'is_schedule_change' => false, 'time_in' => '2026-06-30 07:30:00', 'time_out' => '2026-06-30 17:00:00',
            'total_hours' => 8, 'administrative_hours' => 8, 'overtime_hours' => 0,
        ];
        $store = new AttendanceDailyResultStore;
        $store->synchronize([$row, [...$row, 'date' => '2026-07-01']], $request);
        DB::table('attendance_archive_state')->insert([
            'id' => 1, 'archived_before' => '2026-07-01', 'archived_at' => now(), 'source_count' => 4, 'deleted_count' => 2,
        ]);
        $store->synchronize([[...$row, 'total_hours' => 0], [...$row, 'date' => '2026-07-01', 'total_hours' => 9]], $request);
        $this->assertEquals(8, DB::table('attendance_daily_results')->where('date', '2026-06-30')->value('total_hours'));
        $this->assertEquals(9, DB::table('attendance_daily_results')->where('date', '2026-07-01')->value('total_hours'));
        $store->synchronize([], $request);
        $this->assertSame(1, DB::table('attendance_daily_results')->count());
        $this->assertSame('2026-06-30', DB::table('attendance_daily_results')->value('date'));
    }
}
