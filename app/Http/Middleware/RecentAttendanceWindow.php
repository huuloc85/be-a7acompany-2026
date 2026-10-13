<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class RecentAttendanceWindow
{
    public function handle(Request $request, Closure $next)
    {
        $month = Carbon::now('Asia/Ho_Chi_Minh')->startOfMonth();
        $earliest = $month->copy()->subMonths(2)->toDateString();
        $latest = $month->copy()->endOfMonth()->toDateString();
        $error = new JsonResponse([
            'message' => 'Bảng công chỉ cho phép xem tháng hiện tại và 2 tháng trước. Dữ liệu cũ xem tại hồ sơ công lưu trữ.',
            'allowed_from' => $earliest, 'allowed_to' => $latest,
        ], 422);
        $filters = $request->query('filter', []);
        if (! is_array($filters)) {
            return $error;
        }
        $range = $filters['date_between'] ?? $month->toDateString().','.$latest;
        if (! is_string($range)) {
            return $error;
        }
        $dates = explode(',', $range);
        if (count($dates) !== 2) {
            return $error;
        }
        if (isset($filters['date'])) {
            $dates[] = $filters['date'];
        }
        foreach ($dates as $date) {
            if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return $error;
            }
            try {
                if (Carbon::createFromFormat('!Y-m-d', $date)->toDateString() !== $date) {
                    return $error;
                }
            } catch (\Throwable) {
                return $error;
            }
            if ($date < $earliest || $date > $latest) {
                return $error;
            }
        }
        if ($dates[0] > $dates[1]) {
            return $error;
        }
        $filters['date_between'] = $range;
        $request->query->set('filter', $filters);

        return $next($request);
    }
}
