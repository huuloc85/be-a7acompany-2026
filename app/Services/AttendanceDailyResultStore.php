<?php

namespace App\Services;

use App\Models\AttendanceDailyResult;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class AttendanceDailyResultStore
{
    public function query(Request $request): QueryBuilder
    {
        $range = explode(',', (string) $request->input('filter.date_between', ''));
        $start = count($range) === 2 ? Carbon::parse($range[0]) : Carbon::now()->startOfMonth();
        $end = count($range) === 2 ? Carbon::parse($range[1]) : Carbon::now();

        return QueryBuilder::for(AttendanceDailyResult::query(), $request)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->allowedFilters([
                'employee_id', 'date',
                AllowedFilter::partial('employees.name', 'name'),
                AllowedFilter::partial('employees.company', 'company'),
                AllowedFilter::partial('employees.calendar_category_id', 'calendar_category_id'),
                AllowedFilter::callback('date_between', function () {
                    // Applied above, including the default current-month range.
                }),
            ]);
    }

    public function synchronize(array $results, Request $request): void
    {
        $archivedBefore = Schema::hasTable('attendance_archive_state')
            ? DB::table('attendance_archive_state')->where('id', 1)->value('archived_before')
            : null;
        $token = (string) Str::uuid();
        $now = now()->format('Y-m-d H:i:s');
        $rows = [];
        foreach ($results as $result) {
            // Archived dates no longer have live punches; never recalculate or erase them.
            if ($archivedBefore && $result['date'] < $archivedBefore) {
                continue;
            }
            $additionalShift = in_array($result['hnhc'], ['TC', 'LN'], true);
            $rows[] = [
                'employee_id' => (string) $result['employee_id'],
                'name' => $result['name'],
                'company' => $result['company'],
                'calendar_category_id' => $result['calendar_category_id'],
                'date' => $result['date'],
                'shift' => $result['shift'],
                'hnhc' => $result['hnhc'],
                'day_type' => match ($result['hnhc']) {
                    'TC' => 'Tăng cường ca đêm',
                    'LN' => 'Tăng cường ca ngày',
                    default => $result['day_type'],
                },
                'is_schedule_change' => $result['is_schedule_change'],
                'time_in' => $result['time_in'] ?: null,
                'time_out' => $result['time_out'] ?: null,
                'total_hours' => $result['total_hours'],
                'administrative_hours' => $additionalShift ? 0 : $result['administrative_hours'],
                'overtime_hours' => $additionalShift ? $result['total_hours'] : $result['overtime_hours'],
                'sync_token' => $token,
                'calculated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($rows, $request, $token, $archivedBefore) {
            foreach (array_chunk($rows, 40) as $chunk) {
                $updates = array_diff(array_keys($chunk[0]), ['employee_id', 'date', 'created_at']);
                DB::table('attendance_daily_results')->upsert($chunk, ['employee_id', 'date'], $updates);
            }
            // Reconcile only the requested scope, never another month or employee.
            // This also removes outdated derived results after punches/schedules are deleted.
            $query = $this->query($request)->where('sync_token', '!=', $token);
            if ($archivedBefore) {
                $query->where('date', '>=', $archivedBefore);
            }
            $query->delete();
        }, 3);
    }
}
