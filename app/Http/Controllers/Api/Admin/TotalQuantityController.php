<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\HandleError;
use App\Models\DailyQuantity;
use App\Models\TotalDailyQuantity;
use App\Models\TotalDailyQuantityPO;
use App\Models\TotalMonthQuantity;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\QueryBuilder;

class TotalQuantityController extends BaseController
{
    public function getMonthly(Request $request)
    {
        try {
            if ((int) $request->input('status') === 8) {
                return response()->json($this->getMonthlyPoExports($request));
            }

            $totalMonthlyQuantities = QueryBuilder::for(TotalMonthQuantity::class)
                ->allowedSorts([
                    'id',
                    'product_id',
                    'quantity',
                    'month',
                    'status',
                ])
                ->allowedIncludes('product')
                ->allowedFilters([
                    'id',
                    'product_id',
                    'quantity',
                    'month',
                    'status',
                ]);

            if (! is_null($request['month'])) {
                $totalMonthlyQuantities->where('month', $request['month']);
            }

            if ($request['status']) {
                $totalMonthlyQuantities->where('status', $request['status']);
            }

            if ($request['productId']) {
                $totalMonthlyQuantities->where('product_id', $request['productId']);
            }

            $limit = $request->limit;
            if (! is_null($limit) && $limit == 0) {
                $limit = $totalMonthlyQuantities->count();
            }
            $totalMonthlyQuantities = $totalMonthlyQuantities->paginate($limit ?? 10);

            return response()->json($totalMonthlyQuantities);
        } catch (\Throwable $e) {
            return HandleError::handle($e);
        }
    }

    private function getMonthlyPoExports(Request $request)
    {
        $query = TotalDailyQuantityPO::query()
            ->select([
                'product_id',
                'status',
                DB::raw("DATE_FORMAT(date, '%m-%Y') as month"),
                DB::raw('SUM(totalQuan) as totalQuan'),
            ])
            ->where('status', 8)
            ->groupBy('product_id', 'status', DB::raw("DATE_FORMAT(date, '%m-%Y')"));

        if (! is_null($request['month'])) {
            $month = Carbon::createFromFormat('m-Y', $request['month']);
            $query->whereYear('date', $month->year)
                ->whereMonth('date', $month->month);
        }

        if ($request['productId']) {
            $query->where('product_id', $request['productId']);
        }

        if (str_contains((string) $request->input('include'), 'product')) {
            $query->with('product');
        }

        $limit = $request->limit;
        if (! is_null($limit) && (int) $limit === 0) {
            $limit = $query->get()->count();
        }

        return $query->paginate($limit ?? 10);
    }

    public function updateMonthQuantity(Request $request)
    {
        DB::beginTransaction();
        try {
            $validate = $request->validate([
                'month' => 'required|date_format:m-Y',
                'status' => 'required|integer|in:1,2,3,4,5,6,7',
                'productId' => 'required|integer|exists:products,id',
                'quantity' => 'required|integer',
            ]);

            \App\Models\Product::lockForUpdate()->findOrFail($validate['productId']);
            $totalQuantity = TotalMonthQuantity::lockForUpdate()->updateOrCreate(
                [
                    'product_id' => $validate['productId'],
                    'month' => $validate['month'],
                    'status' => $validate['status'],
                ],
                [
                    'totalQuan' => $validate['quantity'],
                ]
            );
            DB::commit();

            return response()->json($totalQuantity);
        } catch (\Throwable $e) {
            DB::rollBack();

            return HandleError::handle($e);
        }
    }

    public function updateMonthQuantities(Request $request)
    {
        DB::beginTransaction();
        try {
            $validate = $request->validate([
                'month' => 'required|date_format:m-Y',
                'status' => 'required|integer|in:1,2,3,4,5,6,7',
                'products' => 'nullable|array',
                'products.*.quantity' => 'required|integer',
                'products.*.productId' => 'required|integer|exists:products,id',
            ]);

            $totalQuantities = collect($validate['products'] ?? [])->sortBy('productId')->map(function ($product) use ($validate) {
                \App\Models\Product::lockForUpdate()->findOrFail($product['productId']);

                return TotalMonthQuantity::lockForUpdate()->updateOrCreate(
                    [
                        'product_id' => $product['productId'],
                        'month' => $validate['month'],
                        'status' => $validate['status'],
                    ],
                    [
                        'totalQuan' => $product['quantity'],
                    ]
                );
            });
            DB::commit();

            return response()->json([
                'message' => 'Total quantities updated successfully.',
                'count' => $totalQuantities->count(),
                'data' => $totalQuantities,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return HandleError::handle($e);
        }
    }

    public function addProductsQuantity(Request $request)
    {
        DB::beginTransaction();
        try {
            $validate = $request->validate([
                'products' => 'required|array',
                'products.*.date' => 'required|string|date_format:d-m-Y',
                'products.*.status' => 'required|integer|in:1,2,3,6',
                'products.*.shift' => 'nullable|integer|in:1,2',
                'products.*.quantity' => 'required|integer|min:1',
                'products.*.productId' => 'required|exists:products,id',
            ]);

            $totalQuantities = collect($validate['products'] ?? [])->sortBy('productId')->map(function ($product) {
                \App\Models\Product::lockForUpdate()->findOrFail($product['productId']);
                $date = date('Y-m-d', strtotime($product['date']));
                $status = $product['status'];
                $shift = $product['shift'] ?? null;
                $quantity = $product['quantity'];
                $productId = $product['productId'];
                $createdAt = null;

                if ($status == 1 && ! is_null($shift)) {
                    if ($shift == 1) {
                        $createdAt = $date.' 19:30:00';
                    } else {
                        $createdAt = date('Y-m-d', strtotime($date.' +1 day')).' 07:30:00';
                    }
                } else {
                    $createdAt = Carbon::now();
                }

                // Create the daily quantity record
                $dayQuantity = new DailyQuantity;
                $dayQuantity->product_id = $productId;
                $dayQuantity->employee_id = Auth()->user()->id;
                $dayQuantity->quantity = $quantity;
                $dayQuantity->date = $date;
                $dayQuantity->status = $status;
                $dayQuantity->shift = $shift ? 'Ca '.$shift : null;
                $dayQuantity->created_at = $createdAt;
                $dayQuantity->save();

                // Create or update the total daily and monthly quantities
                $totalDaily = TotalDailyQuantity::lockForUpdate()->firstOrNew(
                    [
                        'product_id' => $productId,
                        'date' => $date,
                        'status' => $status,
                    ]
                );
                if ($totalDaily->exists) {
                    $totalDaily->totalQuan += $quantity;
                } else {
                    $totalDaily->totalQuan = $quantity;
                }
                $totalDaily->save();

                $totalMonth = TotalMonthQuantity::lockForUpdate()->firstOrNew(
                    [
                        'product_id' => $productId,
                        'month' => date('m-Y', strtotime($date)),
                        'status' => $status,
                    ]
                );
                if ($totalMonth->exists) {
                    $totalMonth->totalQuan += $quantity;
                } else {
                    $totalMonth->totalQuan = $quantity;
                }
                $totalMonth->save();

                return [
                    'daily' => $dayQuantity,
                    'totalDaily' => $totalDaily,
                    'totalMonth' => $totalMonth,
                ];
            });

            DB::commit();

            return response()->json([
                'message' => 'Total quantities added successfully.',
                'count' => $totalQuantities->count(),
                'data' => $totalQuantities,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return HandleError::handle($e);
        }
    }
}
