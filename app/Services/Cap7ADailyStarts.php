<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class Cap7ADailyStarts
{
    public function record(Cap7ASystem $system, mixed $startedAt): void
    {
        if (! is_int($startedAt) || $startedAt <= 0 || $startedAt > now()->getTimestampMs()) {
            return;
        }
        $time = CarbonImmutable::createFromTimestampMs($startedAt)->setTimezone('Asia/Ho_Chi_Minh');
        if ($time->hour < 7) {
            return;
        }
        $key = ['system' => $system->id, 'date' => $time->toDateString()];
        DB::table('cap7a_daily_starts')->insertOrIgnore($key + [
            'started_at_ms' => $startedAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Atomic minimum: late or concurrent samples cannot replace an earlier start.
        DB::table('cap7a_daily_starts')->where($key)->where('started_at_ms', '>', $startedAt)
            ->update(['started_at_ms' => $startedAt, 'updated_at' => now()]);
    }

    public function first(Cap7ASystem $system, string $date): ?int
    {
        $value = DB::table('cap7a_daily_starts')->where(['system' => $system->id, 'date' => $date])->value('started_at_ms');

        return $value === null ? null : (int) $value;
    }
}
