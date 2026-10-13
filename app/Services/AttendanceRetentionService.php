<?php

namespace App\Services;

use App\Http\Controllers\Api\Admin\AttendanceHistoryController;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AttendanceRetentionService
{
    public function __construct(private AttendanceHistoryController $calculator) {}

    public function rotate(): array
    {
        $cutoff = Carbon::now('Asia/Ho_Chi_Minh')->startOfMonth()->subMonths(2)->toDateString();
        if (DB::getDriverName() === 'mysql') {
            foreach (['attendance_records', 'attendance_records_archive', 'attendance_daily_results', 'attendance_archive_state'] as $table) {
                $engine = DB::selectOne('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
                if (strtoupper($engine->ENGINE ?? '') !== 'INNODB') {
                    throw new RuntimeException('Retention requires InnoDB: '.$table);
                }
            }
        }
        $originalRequest = app('request');
        try {
            return DB::transaction(function () use ($cutoff) {
                DB::table('attendance_archive_state')->insertOrIgnore([
                    'id' => 1, 'archived_before' => '1970-01-01', 'archived_at' => now(),
                    'source_count' => 0, 'deleted_count' => 0,
                ]);
                // Serialize scheduled/manual rotation, including an empty first run.
                $state = DB::table('attendance_archive_state')->where('id', 1)->lockForUpdate()->first();
                if ($state->archived_before > $cutoff) {
                    throw new RuntimeException('Refusing to move the archive boundary backwards.');
                }
                $records = DB::table('attendance_records')->where('date', '<', $cutoff)->orderBy('id')->lockForUpdate()->get();
                foreach ($records->chunk(100) as $chunk) {
                    $rows = $chunk->map(fn ($row) => [...(array) $row, 'archived_at' => now()->format('Y-m-d H:i:s')])->all();
                    DB::table('attendance_records_archive')->upsert($rows, ['id'], array_diff(array_keys(reset($rows)), ['id']));
                    $saved = DB::table('attendance_records_archive')->whereIn('id', $chunk->pluck('id'))->get()->keyBy('id');
                    foreach ($chunk as $row) {
                        $backup = $saved->get($row->id);
                        foreach ((array) $row as $column => $value) {
                            if (! $backup || $value !== $backup->$column) {
                                throw new RuntimeException('Raw archive mismatch. Retention cancelled.');
                            }
                        }
                    }
                }

                // Use the same server calculation as the screen, without the live UI's
                // three-month middleware. Already frozen months must never be recalculated.
                $months = $records->filter(fn ($row) => $row->date >= $state->archived_before)
                    ->map(fn ($row) => substr($row->date, 0, 7))->unique()->sort();
                foreach ($months as $month) {
                    $from = max($month.'-01', $state->archived_before);
                    $to = Carbon::parse($month.'-01')->endOfMonth()->toDateString();
                    $request = Request::create('/api/attendances', 'GET', [
                        'include_calculation' => 1, 'limit' => 0,
                        'filter' => ['date_between' => $from.','.$to],
                    ]);
                    app()->instance('request', $request);
                    $response = $this->calculator->detail($request);
                    if ($response->getStatusCode() !== 200) {
                        throw new RuntimeException('Calculation failed for '.$month.'. Nothing deleted.');
                    }
                    $data = $response->getData(true);
                    $results = DB::table('attendance_daily_results')->whereBetween('date', [$from, $to])->get()
                        ->keyBy(fn ($row) => $row->employee_id.'|'.$row->date);
                    if (! isset($data['data'], $data['total']) || count($data['data']) !== $data['total'] || $results->count() !== $data['total']) {
                        throw new RuntimeException('Incomplete daily archive for '.$month);
                    }
                    foreach ($data['data'] as $row) {
                        $saved = $results->get($row['employee_id'].'|'.$row['date']);
                        $additional = in_array($row['hnhc'], ['LN', 'TC'], true);
                        $expected = [
                            ...$row,
                            'time_in' => $row['time_in'] ? Carbon::parse($row['time_in'])->format('Y-m-d H:i:s') : null,
                            'time_out' => $row['time_out'] ? Carbon::parse($row['time_out'])->format('Y-m-d H:i:s') : null,
                            'administrative_hours' => $additional ? 0 : $row['administrative_hours'],
                            'overtime_hours' => $additional ? $row['total_hours'] : $row['overtime_hours'],
                            'day_type' => match ($row['hnhc']) {
                                'LN' => 'Tăng cường ca ngày', 'TC' => 'Tăng cường ca đêm', default => $row['day_type'],
                            },
                        ];
                        foreach ($expected as $column => $value) {
                            if (! $saved || ! property_exists($saved, $column) || $value != $saved->$column) {
                                throw new RuntimeException('Daily archive mismatch for '.$month);
                            }
                        }
                    }
                }

                $deleted = 0;
                foreach ($records->pluck('id')->chunk(500) as $ids) {
                    $deleted += DB::table('attendance_records')->whereIn('id', $ids)->where('date', '<', $cutoff)->delete();
                }
                if ($deleted !== $records->count()) {
                    throw new RuntimeException('Retention count mismatch. Rolling back.');
                }
                DB::table('attendance_archive_state')->where('id', 1)->update([
                    'archived_before' => $cutoff, 'archived_at' => now(),
                ]);

                return ['cutoff' => $cutoff, 'archived_raw' => $records->count(), 'deleted_live' => $deleted];
            });
        } finally {
            app()->instance('request', $originalRequest);
        }
    }
}
