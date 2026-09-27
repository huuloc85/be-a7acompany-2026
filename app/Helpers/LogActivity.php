<?php

namespace App\Helpers;

use App\Models\LoginHistory;
use Carbon\Carbon;

class LogActivity
{
    private static function touchExistingHistory(LoginHistory $loginHistory): void
    {
        LoginHistory::whereKey($loginHistory->getKey())
            ->increment('login_count', 1, ['updated_at' => Carbon::now()]);
    }

    public static function logViewActivity($user, $activityType, $description)
    {
        $date = Carbon::now()->format('Y-m-d');
        $currentMonthYear = Carbon::now()->format('Y-m');
        $loginHistory = LoginHistory::where('employee_id', $user->id)
            ->where('month_history', $currentMonthYear)
            ->where('activity_type', $activityType)->where('date', $date)
            ->first();

        if ($loginHistory) {
            self::touchExistingHistory($loginHistory);
        } else {
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
        }
    }

    public static function logRoleSpecificLoginActivity($user, $activityType, $description)
    {
        // Mảng các role cần theo dõi
        $trackedRoles = [15, 16, 17];
        // Kiểm tra nếu role_id của người dùng không nằm trong mảng trackedRoles
        if (! in_array($user->role_id, $trackedRoles)) {
            return;
        }
        $date = Carbon::now()->format('Y-m-d');
        $currentMonthYear = Carbon::now()->format('Y-m');
        // check xem nhân viên đó có làm ca hay không
        if ($user->id != '25060400') {
            if ($user->calendar_category_id != 2) {
                $currentDateTime = Carbon::now();
                $hour = $currentDateTime->hour;
                // kiểm tra xem giờ hiện tại < 8h sáng -> giảm 1 ngày
                if ($hour < 8) {
                    $date = Carbon::now()->subDay()->format('Y-m-d');
                }
            }
        }
        $loginHistory = LoginHistory::where('employee_id', $user->id)
            ->where('month_history', $currentMonthYear)
            ->where('activity_type', $activityType)->where('date', $date)
            ->first();

        if ($loginHistory) {
            self::touchExistingHistory($loginHistory);
        } else {
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
        }
    }
}
