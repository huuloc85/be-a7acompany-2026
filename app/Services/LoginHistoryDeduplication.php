<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class LoginHistoryDeduplication
{
    public const KEY_COLUMNS = ['employee_id', 'date', 'activity_type', 'month_history'];

    public const PREVIEW_ACTIVITY = 'Xem với tư cách nhân viên';

    public function merge(?string $before = null): void
    {
        $duplicates = DB::table('login_history')
            ->select(self::KEY_COLUMNS)
            ->where('activity_type', '!=', self::PREVIEW_ACTIVITY)
            ->whereNotNull('date')
            ->when($before !== null, fn ($query) => $query->where('date', '<', $before))
            ->groupBy(self::KEY_COLUMNS)
            ->havingRaw('COUNT(*) > 1')
            ->get();

        DB::transaction(function () use ($duplicates) {
            foreach ($duplicates as $key) {
                $rows = DB::table('login_history')->where((array) $key)->orderBy('id')
                    ->lockForUpdate()->get(['id', 'login_count', 'updated_at']);

                if ($rows->count() < 2) {
                    continue;
                }

                $keeper = $rows->first();
                $total = $rows->sum('login_count');
                DB::table('login_history')->where('id', $keeper->id)->update([
                    'login_count' => $total,
                    'updated_at' => $rows->max('updated_at'),
                ]);

                $deleted = DB::table('login_history')->whereIn('id', $rows->skip(1)->pluck('id'))->delete();
                if ($deleted !== $rows->count() - 1 || (int) DB::table('login_history')->where('id', $keeper->id)->value('login_count') !== $total) {
                    throw new RuntimeException('Login history consolidation did not preserve the group total.');
                }
            }
        }, 3);
    }
}
