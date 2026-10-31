<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\HandleError;
use App\Http\Controllers\Controller;
use App\Models\DailyQuantity;
use App\Models\DailyQuantityPO;
use App\Models\Product;
use App\Models\TotalDailyQuantity;
use App\Models\TotalDailyQuantityPO;
use App\Models\TotalMonthQuantity;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckPoController extends Controller
{
    public function addPoImport(Request $request)
    {
        DB::beginTransaction();
        try {
            $validate = $request->validate([
                'date' => 'required|date_format:Y-m-d',
                'status' => 'required|in:1,6',
                'shift' => 'nullable|integer',
                'products' => 'required|array',
                'products.*.quantity' => 'required|integer',
                'products.*.productId' => 'required|integer|exists:products,id',
            ]);

            $date = $validate['date'];
            $month = date('m-Y', strtotime($date));
            $status = $validate['status'];
            $shift = $validate['shift'] ?? null;

            $results = collect($validate['products'])->map(function ($product) use ($date, $month, $status, $shift) {
                $productId = $product['productId'];
                $quantity = $product['quantity'];
                $created_at = Carbon::now();

                // Determine created_at if status == 1 and shift is set
                if ($status == 1 && $shift !== null) {
                    if ($shift == 1) {
                        $created_at = $date.' 19:30:00';
                    } else {
                        $created_at = date('Y-m-d', strtotime($date.' +1 day')).' 07:30:00';
                    }
                }

                // Create daily quantity
                $dailyQuan = DailyQuantity::create([
                    'product_id' => $productId,
                    'employee_id' => auth()->user()->id,
                    'quantity' => $quantity,
                    'status' => $status,
                    'date' => $date,
                    'created_at' => $created_at,
                ]);

                // Update or create total daily
                $totalDaily = TotalDailyQuantity::updateOrCreate(
                    [
                        'product_id' => $productId,
                        'date' => $date,
                        'status' => $status,
                    ],
                    [
                        'totalQuan' => DB::raw('COALESCE(totalQuan,0)+'.$quantity),
                    ]
                );

                // Update or create total month
                $totalMonth = TotalMonthQuantity::updateOrCreate(
                    [
                        'product_id' => $productId,
                        'month' => $month,
                        'status' => $status,
                    ],
                    [
                        'totalQuan' => DB::raw('COALESCE(totalQuan,0)+'.$quantity),
                    ]
                );

                return [
                    'daily' => $dailyQuan,
                    'totalDaily' => $totalDaily,
                    'totalMonth' => $totalMonth,
                ];
            });

            DB::commit();

            return response()->json([
                'message' => 'Cập nhật số lượng thành công!',
                'count' => $results->count(),
                'data' => $results,
            ], 200);
        } catch (\Throwable $th) {
            DB::rollBack();

            return HandleError::handle($th);
        }
    }

    public function addPoExport(Request $request)
    {
        DB::beginTransaction();
        try {
            $batches = $this->validatePoExportBatches($request);
            $status = 8;
            $employeeId = Auth::id();

            // Tạo batch_id chung cho tất cả records trong request này, kể cả bulk import.
            $batchId = Str::uuid()->toString();
            $now = Carbon::now();
            $dailyRows = [];
            $totalIncrements = [];

            foreach ($batches as $batch) {
                foreach ($batch['products'] as $product) {
                    $productId = $product['productId'];
                    $quantity = $product['quantity'];

                    $dailyRows[] = [
                        'product_id' => $productId,
                        'employee_id' => $employeeId,
                        'quantity' => $quantity,
                        'status' => $status,
                        'date' => $batch['date'],
                        'batch_id' => $batchId,
                        'file_name' => $batch['fileName'],
                        'note' => $batch['note'] ?? null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $totalKey = $this->poTotalKey($productId, $batch['date'], $status);
                    $totalIncrements[$totalKey] ??= [
                        'product_id' => $productId,
                        'date' => $batch['date'],
                        'status' => $status,
                        'totalQuan' => 0,
                    ];
                    $totalIncrements[$totalKey]['totalQuan'] += $quantity;
                }
            }

            collect($dailyRows)->chunk(500)->each(function ($rows) {
                DailyQuantityPO::insert($rows->all());
            });

            $dates = collect($totalIncrements)->pluck('date')->unique()->values();
            $productIds = collect($totalIncrements)->pluck('product_id')->unique()->values();
            $existingTotals = TotalDailyQuantityPO::where('status', $status)
                ->whereIn('date', $dates)
                ->whereIn('product_id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy(fn ($total) => $this->poTotalKey($total->product_id, $total->date, $total->status));

            $newTotalRows = [];
            foreach ($totalIncrements as $key => $increment) {
                $existingTotal = $existingTotals->get($key);

                if ($existingTotal) {
                    $existingTotal->totalQuan += $increment['totalQuan'];
                    $existingTotal->save();

                    continue;
                }

                $newTotalRows[] = [
                    'product_id' => $increment['product_id'],
                    'date' => $increment['date'],
                    'status' => $increment['status'],
                    'totalQuan' => $increment['totalQuan'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            collect($newTotalRows)->chunk(500)->each(function ($rows) {
                TotalDailyQuantityPO::insert($rows->all());
            });

            DB::commit();

            return response()->json([
                'message' => 'Cập nhật số lượng thành công!',
                'count' => count($dailyRows),
                'batch_count' => count($batches),
                'total_count' => count($totalIncrements),
                'batch_id' => $batchId,
            ], 200);
        } catch (\Throwable $th) {
            DB::rollBack();

            return HandleError::handle($th);
        }
    }

    private function poTotalKey(int $productId, string $date, int $status): string
    {
        return "{$productId}|{$date}|{$status}";
    }

    private function validatePoExportBatches(Request $request): array
    {
        if ($request->has('batches')) {
            $validate = $request->validate([
                'batches' => 'required|array|min:1',
                'batches.*.date' => 'required|date_format:Y-m-d',
                'batches.*.fileName' => 'required|string',
                'batches.*.note' => 'nullable|string',
                'batches.*.products' => 'required|array|min:1',
                'batches.*.products.*.quantity' => 'required|integer|min:1',
                'batches.*.products.*.productId' => 'required|integer|exists:products,id',
            ]);

            return $validate['batches'];
        }

        $validate = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'fileName' => 'required|string',
            'note' => 'nullable|string',
            'products' => 'required|array|min:1',
            'products.*.quantity' => 'required|integer|min:1',
            'products.*.productId' => 'required|integer|exists:products,id',
        ]);

        return [[
            'date' => $validate['date'],
            'fileName' => $validate['fileName'],
            'note' => $validate['note'] ?? null,
            'products' => $validate['products'],
        ]];
    }

    public function addStockQuantityInventory(Request $request)
    {
        DB::beginTransaction();
        try {
            $validate = $request->validate([
                'month' => 'required|date_format:m-Y',
                'products' => 'required|array',
                'products.*.productId' => 'required|integer|exists:products,id',
                'products.*.quantity' => 'required|integer',
            ]);

            $month = $validate['month'];
            $status = 4;

            $results = collect($validate['products'])->map(function ($product) use ($month, $status) {
                $productId = $product['productId'];
                $quantity = $product['quantity'];
                $productModel = Product::find($productId);
                if ($productModel) {
                    $totalMonth = TotalMonthQuantity::where('product_id', $productId)
                        ->where('month', $month)
                        ->where('status', $status)
                        ->first();
                    if ($totalMonth) {
                        $totalMonth->totalQuan = $quantity;
                        $totalMonth->save();
                    } else {
                        $totalMonth = TotalMonthQuantity::create([
                            'product_id' => $productId,
                            'month' => $month,
                            'status' => $status,
                            'totalQuan' => $quantity,
                        ]);
                    }

                    return $totalMonth;
                }

                return null;
            })->filter();

            DB::commit();

            return response()->json([
                'message' => 'Cập nhật số lượng thành công!',
                'count' => $results->count(),
                'data' => $results->values(),
            ], 200);
        } catch (\Throwable $th) {
            DB::rollBack();

            return HandleError::handle($th);
        }
    }

    public function getPoHistory(Request $request)
    {
        $month = $request->input('month') ? Carbon::createFromFormat('!Y-m', $request->input('month')) : Carbon::now();

        $dailyQuantities = DailyQuantityPO::whereYear('date', $month->year)
            ->whereMonth('date', $month->month)
            ->orderBy('date', 'asc')
            ->with(['product', 'employee'])
            ->get();

        $dates = DailyQuantityPO::whereYear('date', $month->year)
            ->whereMonth('date', $month->month)
            ->select('date')
            ->distinct()
            ->orderBy('date')
            ->pluck('date');

        return response()->json([
            'month' => $month,
            'dailyQuantitiesPo' => $dailyQuantities,
            'dates' => $dates,
        ], 200);
    }

    public function updatePO(Request $request)
    {
        DB::beginTransaction();
        try {
            $validate = $request->validate([
                'date' => 'required|date_format:Y-m-d',
                'products' => 'required|array',
                'products.*.quantity' => 'required|integer',
                'products.*.productId' => 'required|integer|exists:products,id',
            ]);

            $date = $validate['date'];
            $status = 8;

            $results = collect($validate['products'])->map(function ($product) use ($date, $status) {
                $productId = $product['productId'];
                $quantity = $product['quantity'];
                $employeeId = Auth::id();

                // Update or create DailyQuantityPO
                $dailyQuantity = DailyQuantityPO::updateOrCreate(
                    [
                        'product_id' => $productId,
                        'date' => $date,
                        'status' => $status,
                    ],
                    [
                        'quantity' => $quantity,
                        'employee_id' => $employeeId,
                    ]
                );

                // Update or create TotalDailyQuantityPO
                $totalDailyQuantity = TotalDailyQuantityPO::updateOrCreate(
                    [
                        'product_id' => $productId,
                        'date' => $date,
                        'status' => $status,
                    ],
                    [
                        'totalQuan' => $quantity,
                    ]
                );

                return [
                    'dailyPO' => $dailyQuantity,
                    'totalDailyPO' => $totalDailyQuantity,
                ];
            });

            DB::commit();

            return response()->json([
                'message' => 'Cập nhật số lượng thành công!',
                'count' => $results->count(),
                'data' => $results,
            ], 200);
        } catch (\Throwable $th) {
            DB::rollBack();

            return HandleError::handle($th);
        }
    }

    public function deleteBatch(string $batchId)
    {
        DB::beginTransaction();
        try {
            $records = DailyQuantityPO::where('batch_id', $batchId)->get();
            $count = $records->count();

            if ($count === 0) {
                DB::rollBack();

                return response()->json(['message' => 'Batch không tồn tại'], 404);
            }

            // Collect affected dates for recalculating totals
            $affectedDates = $records->pluck('date')->unique()->values()->all();

            // Delete all records in the batch
            DailyQuantityPO::where('batch_id', $batchId)->delete();

            // Update TotalDailyQuantityPO for each affected date
            $this->syncTotalDailyPO($affectedDates);

            DB::commit();

            return response()->json([
                'message' => "Đã xóa {$count} PO trong file import",
                'deleted_count' => $count,
            ]);
        } catch (\Throwable $th) {
            DB::rollBack();

            return HandleError::handle($th);
        }
    }

    public function deleteAll()
    {
        DB::beginTransaction();
        try {
            $affectedDates = DailyQuantityPO::select('date')->distinct()->pluck('date')->all();
            $count = DailyQuantityPO::count();

            if ($count === 0) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Hiện tại không có PO để xoá',
                    'deleted_count' => 0,
                ], 200);
            }

            DailyQuantityPO::query()->delete();
            $this->syncTotalDailyPO($affectedDates);

            DB::commit();

            return response()->json([
                'message' => "Đã xóa tất cả {$count} PO",
                'deleted_count' => $count,
            ]);
        } catch (\Throwable $th) {
            DB::rollBack();

            return HandleError::handle($th);
        }
    }

    public function deletePO($id)
    {
        DB::beginTransaction();
        try {
            // Find and get the record in DailyQuantityPO to delete
            $dailyQuantity = DailyQuantityPO::findOrFail($id);
            $date = $dailyQuantity->date;

            // Delete the record in DailyQuantityPO
            $dailyQuantity->delete();

            // Update the records in TotalDailyQuantityPo for the specified date
            $this->syncTotalDailyPO([$date]);

            DB::commit();

            return response()->json([
                'message' => 'Đã xoá thành công sản lượng PO và cập nhật lại tổng sản lượng.',
                'success' => true,
            ], 200);
        } catch (\Throwable $th) {
            DB::rollBack();

            return HandleError::handle($th);
        }
    }

    private function syncTotalDailyPO(array $dates): void
    {
        foreach (array_filter($dates) as $date) {
            $totalDailyQuantities = TotalDailyQuantityPO::whereDate('date', $date)->get();

            foreach ($totalDailyQuantities as $totalDailyQuantity) {
                $totalDailyQuantity->totalQuan = DailyQuantityPO::where('product_id', $totalDailyQuantity->product_id)
                    ->where('status', $totalDailyQuantity->status)
                    ->whereDate('date', $date)
                    ->sum('quantity');
                $totalDailyQuantity->save();
            }
        }
    }
}
