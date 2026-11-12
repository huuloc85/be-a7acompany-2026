<?php

namespace App\Helpers;

use App\Models\LoginHistory;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;

class LogActivity
{
    private static function touchExistingHistory(LoginHistory $loginHistory): void
    {
        LoginHistory::whereKey($loginHistory->getKey())
            ->increment('login_count', 1, ['updated_at' => Carbon::now()]);
    }

    public static function logViewActivity($user, $activityType, $description)
    {
        if (request()->attributes->get('employee_preview')) {
            return;
        }
        $now = Carbon::now();
        self::record($user, $activityType, $description, $now->toDateString(), $now->format('Y-m'));
    }

    public static function logRoleSpecificLoginActivity($user, $activityType, $description)
    {
        if (request()->attributes->get('employee_preview')) {
            return;
        }
        // Mảng các role cần theo dõi
        $trackedRoles = [15, 16, 17];
        // Kiểm tra nếu role_id của người dùng không nằm trong mảng trackedRoles
        if (! in_array($user->role_id, $trackedRoles)) {
            return;
        }
        $now = Carbon::now();
        $date = $now->toDateString();
        if ($user->id != '25060406' && $user->calendar_category_id != 2 && $now->hour < 8) {
            $date = $now->copy()->subDay()->toDateString();
        }

        self::record($user, $activityType, $description, $date, $now->format('Y-m'));
    }

    private static function record($user, $activityType, $description, string $date, string $currentMonthYear): void
    {
        $query = LoginHistory::where('employee_id', $user->id)
            ->where('month_history', $currentMonthYear)
            ->where('activity_type', $activityType)->where('date', $date);

        if ($loginHistory = $query->first()) {
            self::touchExistingHistory($loginHistory);

            return;
        }

        try {
            LoginHistory::create([
                'employee_id' => $user->id,
                'activity_type' => $activityType,
                'description' => $description,
                'month_history' => $currentMonthYear,
                'date' => $date,
                'employee_code' => $user->id,
                'employee_name' => $user->name,
                'login_count' => 1,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            $loginHistory = $query->lockForUpdate()->first();
            if (! $loginHistory) {
                throw $exception;
            }

            self::touchExistingHistory($loginHistory);
        }
    }
}
