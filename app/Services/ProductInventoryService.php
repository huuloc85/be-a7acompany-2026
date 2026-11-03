<?php

namespace App\Services;

use App\Models\Product;
use App\Models\TotalDailyQuantityPO;
use App\Models\TotalMonthQuantity;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ProductInventoryService
{
    public function recalculate(int $productId, string $fromMonth, bool $dryRun = false): array
    {
        $from = Carbon::createFromFormat('!m-Y', $fromMonth);
        $end = Carbon::now()->startOfMonth();
        if ($from->gt($end)) {
            return [];
        }

        return DB::transaction(function () use ($productId, $from, $end, $dryRun) {
            Product::whereKey($productId)->lockForUpdate()->firstOrFail();
            $records = TotalMonthQuantity::where('product_id', $productId)->lockForUpdate()->get();
            $initializeCurrent = $from->equalTo($end);
            if ($initializeCurrent) {
                if ($records->where('month', $end->format('m-Y'))->whereIn('status', [4, 5])->pluck('status')->unique()->count() === 2) {
                    return [];
                }
                $from = $from->copy()->subMonth();
            }
            // Keep the opening balance of the source month as the baseline.
            // If that month is missing, bridge from the nearest earlier opening.
            $seed = $records->where('status', 4)
                ->filter(fn ($row) => Carbon::createFromFormat('!m-Y', $row->month)->lte($from))
                ->sortByDesc(fn ($row) => Carbon::createFromFormat('!m-Y', $row->month)->timestamp)
                ->first();
            if (! $seed) {
                return [];
            }

            $month = Carbon::createFromFormat('!m-Y', $seed->month);
            $monthly = $records->groupBy('month');
            $exports = TotalDailyQuantityPO::where('product_id', $productId)
                ->whereIn('status', [3, 8])
                ->where('date', '>=', $month->toDateString())
                ->where('date', '<', $end->toDateString())
                ->lockForUpdate()->get()->groupBy(fn ($row) => Carbon::parse($row->date)->format('m-Y'));
            $opening = (int) $seed->totalQuan;
            $checkedOpening = (int) $monthly->get($seed->month)->firstWhere('status', 5)?->totalQuan;
            $changes = [];

            while ($month->lt($end)) {
                $key = $month->format('m-Y');
                $totals = $monthly->get($key, collect())->keyBy('status');
                $exported = $exports->has($key)
                    ? (int) $exports->get($key)->sum('totalQuan')
                    : (int) ($totals->get(3)?->totalQuan ?? 0);
                $opening += (int) ($totals->get(1)?->totalQuan ?? 0)
                    - $exported - (int) ($totals->get(6)?->totalQuan ?? 0);
                // Reported checked quantity already excludes defective pieces.
                $checkedOpening += (int) ($totals->get(2)?->totalQuan ?? 0) - $exported;
                $month->addMonth();
                $nextKey = $month->format('m-Y');

                foreach ([4 => $opening, 5 => $checkedOpening] as $status => $quantity) {
                    $old = $monthly->get($nextKey, collect())->firstWhere('status', $status);
                    if ($initializeCurrent && $old) {
                        continue;
                    }
                    if ($old && (int) $old->totalQuan === $quantity) {
                        continue;
                    }
                    $changes[] = [$productId, $nextKey, $status, $old?->totalQuan, $quantity];
                    if (! $dryRun) {
                        TotalMonthQuantity::withoutEvents(fn () => TotalMonthQuantity::updateOrCreate(
                            ['product_id' => $productId, 'month' => $nextKey, 'status' => $status],
                            ['totalQuan' => $quantity]
                        ));
                    }
                }
            }

            return $changes;
        });
    }
}
