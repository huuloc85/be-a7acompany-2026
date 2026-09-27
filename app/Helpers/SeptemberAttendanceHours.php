<?php

namespace App\Helpers;

use Illuminate\Support\Carbon;

class SeptemberAttendanceHours
{
    public const EFFECTIVE_DATE = '2026-09-01';

    public static function calculate(string $date, int $shift, string $category, string $timeIn, string $timeOut): float
    {
        $day = Carbon::parse($date)->startOfDay();
        $shiftStart = $day->copy()->setTime($shift === 2 ? 21 : 7, 30);
        $start = max(Carbon::parse($timeIn)->timestamp, $shiftStart->timestamp);
        $end = Carbon::parse($timeOut)->timestamp;

        // Production breaks are paid; only the one-hour meal break is deducted.
        // Night times are offsets from the date on which the shift starts.
        $breaks = $category === '4'
            ? [[9 * 60 + 40, 9 * 60 + 55], [12 * 60, 13 * 60], [14 * 60 + 40, 14 * 60 + 55]]
            : ($shift === 2
                ? [[25 * 60 + 30, 26 * 60 + 30]]
                : [[11 * 60 + 30, 12 * 60 + 30]]);

        $unpaidSeconds = 0;
        foreach ($breaks as [$from, $to]) {
            $breakStart = $day->copy()->addMinutes($from)->timestamp;
            $breakEnd = $day->copy()->addMinutes($to)->timestamp;
            $unpaidSeconds += max(0, min($end, $breakEnd) - max($start, $breakStart));
        }

        // Preserve the existing quarter-hour rounding and overtime beyond eight hours.
        return floor(max(0, $end - $start - $unpaidSeconds) / 900) / 4;
    }
}
