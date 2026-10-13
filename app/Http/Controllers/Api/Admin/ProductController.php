<?php

namespace App\Http\Controllers\Api\Admin;

use App\Filters\NameOrCodeFilter;
use App\Helpers\HandleError;
use App\Helpers\LogActivity;
use App\Models\CelenderDetailHNHC;
use App\Models\CheckEmployee;
use App\Models\DailyQuantity;
use App\Models\DailyQuantityPO;
use App\Models\Product;
use App\Models\TotalDailyQuantity;
use App\Models\TotalDailyQuantityPO;
use App\Models\TotalMonthQuantity;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedInclude;
use Spatie\QueryBuilder\QueryBuilder;

class ProductController extends BaseController
{
    public function getMonthList()
    {
        $months = TotalMonthQuantity::distinct()->pluck('month');
        $sortedMonths = $months->sortByDesc(function ($month) {
            return Carbon::createFromFormat('m-Y', $month);
        })->values();

        return response()->json([
            'months' => $sortedMonths,
        ]);
    }

    public function getProducts(Request $request)
    {
        try {
            $validated = $request->validate([
                'limit' => 'nullable|integer|min:0',
                'page' => 'nullable|integer|min:1',
                'month' => 'nullable|date_format:Y-m',
                'status' => 'nullable|integer|min:1|max:8',
                'date' => 'nullable|date_format:Y-m-d',
            ]);

            $currentMonth = $validated['month'] ?? null;
            $status = $validated['status'] ?? null;
            $date = $validated['date'] ?? null;

            $products = QueryBuilder::for(Product::class)
                ->allowedFields(
                    'id',
                    'code',
                    'name',
                    'moldSize',
                    'CAV',
                    'cycle',
                    'binCode',
                    'quanEntityBin',
                    'FAPV',
                    'FASV',
                    'FAVV',
                )
                ->allowedSorts([
                    'id',
                    'code',
                    'name',
                    'moldSize',
                    'binCode',
                    'created_at',
                    'updated_at',
                ])
                ->allowedFilters([
                    'id',
                    'code',
                    'name',
                    'moldSize',
                    'binCode',
                    'created_at',
                    AllowedFilter::custom('search', new NameOrCodeFilter),
                ])
                ->allowedIncludes([
                    AllowedInclude::callback('totalmonthquantities', function ($query) use ($currentMonth, $status, $date) {
                        if ($currentMonth) {
                            $query->where('month', Carbon::parse($currentMonth)->format('m-Y'));
                        }
                        if ($date) {
                            $query->where('date', $date);
                        }
                        if ($status) {
                            $query->where('status', $status);
                        }
                    }),
                    AllowedInclude::callback('totaldailyquantities', function ($query) use ($currentMonth, $status, $date) {
                        if ($currentMonth) {
                            $query->where('date', 'like', Carbon::parse($currentMonth)->format('Y-m-').'%');
                        }
                        if ($date) {
                            $query->where('date', $date);
                        }
                        if ($status) {
                            $query->where('status', $status);
                        }
                    }),
                    AllowedInclude::callback('dailyquantities', function ($query) use ($currentMonth, $status, $date) {
                        if ($currentMonth) {
                            $query->where('date', 'like', Carbon::parse($currentMonth)->format('Y-m-').'%');
                        }
                        if ($date) {
                            $query->where('date', $date);
                        }
                        if ($status) {
                            $query->where('status', $status);
                        }
                    }),
                    AllowedInclude::callback('dailyQuantitiesPo', function ($query) use ($currentMonth, $status, $date) {
                        if ($currentMonth) {
                            $query->where('date', 'like', Carbon::parse($currentMonth)->format('Y-m-').'%');
                        }
                        if ($status) {
                            $query->where('status', $status);
                        }
                        if ($date) {
                            $query->where('date', $date);
                        }
                    }),
                    AllowedInclude::callback('totaldailyquantitiespo', function ($query) use ($currentMonth, $status, $date) {
                        if ($currentMonth) {
                            $query->where('date', 'like', Carbon::parse($currentMonth)->format('Y-m-').'%');
                        }
                        if ($status) {
                            $query->where('status', $status);
                        }
                        if ($date) {
                            $query->where('date', $date);
                        }
                    }),
                ]);

            // Logic to get products
            $limit = $validated['limit'] ?? 10;
            if (! is_null($limit) && $limit == 0) {
                $limit = $products->count();
            }
            $products = $products->paginate($limit);

            return response()->json($products);
        } catch (\Throwable $e) {
            return HandleError::handle($e);
        }
    }

    public function getProduct($id)
    {
        try {
            $product = Product::findOrFail($id);

            $companies = [];
            if ($product->FAPV) {
                array_push($companies, 'FAPV');
            }
            if ($product->FASV) {
                array_push($companies, 'FASV');
            }
            if ($product->FAVV) {
                array_push($companies, 'FAVV');
            }
            $product->companies = $companies;

            return response()->json($product);
        } catch (\Throwable $e) {
            return HandleError::handle($e);
        }
    }

    public function addProduct(Request $request)
    {
        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'code' => 'required|string|unique:products,code',
                'name' => 'required|string',
                'stockQuanMOQ' => 'required|numeric',
                'stockQuan' => 'required|numeric',
                'moldSize' => 'required|string',
                'CAV' => 'required|numeric',
                'cycle' => 'required|numeric',
                'stockQuan200' => 'required|numeric',
                'binCode' => 'required|string',
                'quanEntityBin' => 'required|numeric',
                'material' => 'nullable|string',
                'color' => 'nullable|string',
                'quantity_per_package' => 'nullable|numeric',
                'companies' => 'required|array',
            ]);

            $product = Product::create([
                'code' => $validated['code'],
                'name' => $validated['name'],
                'moldSize' => $validated['moldSize'],
                'CAV' => $validated['CAV'],
                'cycle' => $validated['cycle'],
                'binCode' => $validated['binCode'],
                'quanEntityBin' => $validated['quanEntityBin'],
                'material' => $validated['material'] ?? null,
                'color' => $validated['color'] ?? null,
                'quantity_per_package' => $validated['quantity_per_package'] ?? null,
                'FAPV' => in_array('FAPV', $validated['companies']) ? 1 : 0,
                'FASV' => in_array('FASV', $validated['companies']) ? 1 : 0,
                'FAVV' => in_array('FAVV', $validated['companies']) ? 1 : 0,
            ]);

            $month = Carbon::now()->format('m-Y');

            // Create a new TotalMonthQuantity record
            $stockQuan = TotalMonthQuantity::create([
                'product_id' => $product->id,
                'month' => $month,
                'status' => 4,
                'totalQuan' => $validated['stockQuan'],

            ]);

            $stockQuan200 = TotalMonthQuantity::create([
                'product_id' => $product->id,
                'month' => $month,
                'status' => 5,
                'totalQuan' => $validated['stockQuan200'],
            ]);

            $stockQuanMOQ = TotalMonthQuantity::create([
                'product_id' => $product->id,
                'month' => $month,
                'status' => 7,
                'totalQuan' => $validated['stockQuanMOQ'],
            ]);

            // ✅ Refresh để lấy dữ liệu mới nhất từ database
            $product->refresh();

            DB::commit();

            return response()->json([
                'message' => 'Product created successfully',
                'data' => $product,
                'stockQuantity' => $stockQuan,
                'stockQuantity200' => $stockQuan200,
                'stockQuantityMOQ' => $stockQuanMOQ,
                'month' => $month,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return HandleError::handle($e);
        }
        // Logic to create a new product
    }

    public function updateProduct(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $product = Product::findOrFail($id);

            $validated = $request->validate([
                'code' => 'sometimes|string|unique:products,code,'.$id,
                'name' => 'sometimes|string',
                'moldSize' => 'sometimes|string',
                'CAV' => 'sometimes|numeric',
                'cycle' => 'sometimes|numeric',
                'binCode' => 'sometimes|string',
                'quanEntityBin' => 'sometimes|numeric',
                'material' => 'nullable|string',
                'color' => 'nullable|string',
                'quantity_per_package' => 'nullable|numeric',
                'companies' => 'sometimes|array',
            ]);

            $updateData = [
                'code' => $validated['code'] ?? $product->code,
                'name' => $validated['name'] ?? $product->name,
                'moldSize' => $validated['moldSize'] ?? $product->moldSize,
                'CAV' => $validated['CAV'] ?? $product->CAV,
                'cycle' => $validated['cycle'] ?? $product->cycle,
                'binCode' => $validated['binCode'] ?? $product->binCode,
                'quanEntityBin' => $validated['quanEntityBin'] ?? $product->quanEntityBin,
            ];

            if (isset($validated['material'])) {
                $updateData['material'] = $validated['material'];
            }

            if (isset($validated['color'])) {
                $updateData['color'] = $validated['color'];
            }

            if (isset($validated['quantity_per_package'])) {
                $updateData['quantity_per_package'] = $validated['quantity_per_package'];
            }

            if (isset($validated['companies'])) {
                $updateData['FAPV'] = in_array('FAPV', $validated['companies']) ? 1 : 0;
                $updateData['FASV'] = in_array('FASV', $validated['companies']) ? 1 : 0;
                $updateData['FAVV'] = in_array('FAVV', $validated['companies']) ? 1 : 0;
            }

            $product->update($updateData);

            // ✅ Refresh để lấy dữ liệu mới nhất từ database
            $product->refresh();

            DB::commit();

            return response()->json([
                'message' => 'Product updated successfully',
                'data' => $product,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return HandleError::handle($e);
        }
    }

    public function deleteProduct($id)
    {
        DB::beginTransaction();
        try {
            $product = Product::findOrFail($id);
            $product->delete();

            DB::commit();

            return response()->json([
                'message' => 'Product deleted successfully',
                'data' => $product,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return HandleError::handle($e);
        }
    }

    public function getTrashProducts(Request $request)
    {
        try {
            $validated = $request->validate([
                'limit' => 'integer|nullable',
                'page' => 'integer|nullable',
            ]);

            $products = QueryBuilder::for(Product::class)
                ->onlyTrashed()
                ->allowedSorts([
                    'id',
                    'code',
                    'name',
                    'moldSize',
                    'CAV',
                    'binCode',
                    'quanEntityBin',
                    'created_at',
                    'updated_at',
                ])
                ->allowedFilters([
                    'id',
                    'code',
                    'name',
                    'moldSize',
                    'binCode',
                    'created_at',
                    'updated_at',
                    AllowedFilter::custom('search', new NameOrCodeFilter),
                ]);

            $limit = $validated['limit'] ?? 10;
            if (! is_null($limit) && $limit == 0) {
                $limit = $products->count();
            }
            $products = $products->paginate($limit);

            return response()->json($products);
        } catch (\Throwable $e) {
            return HandleError::handle($e);
        }
    }

    public function restoreProduct($id)
    {
        DB::beginTransaction();
        try {
            $product = Product::onlyTrashed()->findOrFail($id);
            $product->restore();

            DB::commit();

            return response()->json([
                'message' => 'Product restored successfully',
                'data' => $product,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return HandleError::handle($e);
        }
    }

    /**
     * Force delete a product permanently from trash
     */
    public function forceDeleteProduct($id)
    {
        DB::beginTransaction();
        try {
            $product = Product::onlyTrashed()->findOrFail($id);

            // Lưu thông tin product để log
            $productData = [
                'id' => $product->id,
                'code' => $product->code,
                'name' => $product->name,
            ];

            // Xóa vĩnh viễn
            $product->forceDelete();

            DB::commit();

            // Log activity
            // LogActivity::addToLog('Xóa vĩnh viễn sản phẩm: ' . $productData['name'] . ' (Mã: ' . $productData['code'] . ')');

            return response()->json([
                'message' => 'Product permanently deleted successfully',
                'data' => $productData,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return HandleError::handle($e);
        }
    }

    public function updateQuantity()
    {
        DB::beginTransaction();
        try {
            $userId = auth()->user()->id;

            $calendar = CelenderDetailHNHC::where('employee_id', $userId)->latest()->first();
            if (! $calendar) {
                return response()->json([
                    'message' => 'Không tìm thấy lịch làm việc cho nhân viên này.',
                ], 404);
            }

            $date = Carbon::now()->format('d');
            $date = $this->convertDate($date); // Chuyển đổi nếu cần
            $column = 'day'.$date;

            if (! isset($calendar->$column)) {
                return response()->json([
                    'message' => "Không có dữ liệu lịch làm việc cho cột: $column.",
                ], 404);
            }

            $calendarDetail = $this->translateCalendar($calendar->$column);

            $today = Carbon::now()->toDateString();
            $yesterday = Carbon::now()->subDay()->toDateString();

            $addQuantity = CheckEmployee::where('employee_id', $userId)
                ->where(function ($query) use ($today, $yesterday) {
                    $query->whereDate('date', $today)
                        ->orWhere(function ($query) use ($yesterday) {
                            $query->whereDate('date', $yesterday)
                                ->where('shift', 'Ca 2');
                        });
                })
                ->whereHas('product', function ($query) {
                    $query->whereColumn('products.id', 'check_employees.product_id');
                })
                ->get()
                ->filter(function ($item) use ($today, $yesterday) {
                    $itemDate = Carbon::parse($item->date)->toDateString();

                    return $itemDate === $today || ($itemDate === $yesterday && $item->shift === 'Ca 2');
                })
                ->values(); // Reset chỉ số mảng

            DB::commit();

            return response()->json([
                'calendar_detail' => $calendarDetail,
                'quantities' => $addQuantity,
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error(basename(__FILE__).' - '.__FUNCTION__.' - Error: '.$e->getMessage());
            DB::rollBack();

            return response()->json([
                'error' => 'Hãy bổ sung lịch làm việc để cập nhật sản lượng!',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    // detailProduct
    public function detailProduct($id, Request $request)
    {
        try {
            $month = Carbon::now()->format('m');
            $year = Carbon::now()->format('Y');
            $monthNearly = $request->month ?? Carbon::now()->format('m-Y');
            $monthYearArray = explode('-', $monthNearly);

            if (count($monthYearArray) > 1) {
                $month = $monthYearArray[0];
                $year = $monthYearArray[1];
            }

            $product = Product::find($id);
            if (! $product) {
                return response()->json(['message' => 'Product not found'], 404);
            }

            $listMonth = TotalMonthQuantity::distinct()->pluck('month');

            // Lấy chi tiết từng status kèm employee
            $status1 = DailyQuantity::with('employee:id,name')
                ->where('product_id', $id)
                ->where('status', 1)
                ->whereYear('date', $year)
                ->whereMonth('date', $month)
                ->orderBy('id', 'DESC')
                ->get();

            $status2 = DailyQuantity::with('employee:id,name')
                ->where('product_id', $id)
                ->where('status', 2)
                ->whereYear('date', $year)
                ->whereMonth('date', $month)
                ->orderBy('id', 'DESC')
                ->get();

            $status8 = DailyQuantityPO::with('employee:id,name')
                ->where('product_id', $id)
                ->where('status', 8)
                ->whereYear('date', $year)
                ->whereMonth('date', $month)
                ->orderBy('id', 'DESC')
                ->get();

            $status6 = DailyQuantity::with('employee:id,name')
                ->where('product_id', $id)
                ->where('status', 6)
                ->whereYear('date', $year)
                ->whereMonth('date', $month)
                ->orderBy('id', 'DESC')
                ->get();

            return response()->json([
                'product' => $product,
                'status1' => $status1,
                'status2' => $status2,
                'status8' => $status8,
                'status6' => $status6,
                'listMonth' => $listMonth,
                'monthNearly' => $monthNearly,
            ]);
        } catch (\Throwable $e) {
            return HandleError::handle($e);
        }
    }

    // update detailProduct
    public function updateDetailProduct(Request $request)
    {
        if ($request->quantity == 0) {
            return response()->json([
                'message' => 'Bạn không thể cập nhật sản lượng là 0!',
            ], 400);
        }

        try {
            if ((int) $request->status === 8) {
                return $this->updatePoDetailProduct($request);
            }

            $month = Carbon::now()->format('m');
            $year = Carbon::now()->format('Y');
            $monthYear = Carbon::now()->format('m-Y');

            $daily = DailyQuantity::with('employee:id,name')->find($request->dailyId);
            if (! $daily) {
                return response()->json([
                    'message' => 'Không tìm thấy bản ghi!',
                ], 404);
            }

            // cập nhật lại số lượng mới
            $daily->quantity = $request->quantity;
            $daily->save();

            // tính lại totalDaily từ DailyQuantity
            $sumDaily = DailyQuantity::where('product_id', $request->product_id)
                ->where('date', $daily->date)
                ->where('status', $request->status)
                ->sum('quantity');

            $totalDaily = TotalDailyQuantity::updateOrCreate(
                [
                    'product_id' => $request->product_id,
                    'date' => $daily->date,
                    'status' => $request->status,
                ],
                ['totalQuan' => $sumDaily]
            );

            // tính lại totalMonth từ DailyQuantity
            $sumMonth = DailyQuantity::where('product_id', $request->product_id)
                ->whereYear('date', $year)
                ->whereMonth('date', $month)
                ->where('status', $request->status)
                ->sum('quantity');

            $totalMonth = TotalMonthQuantity::updateOrCreate(
                [
                    'product_id' => $request->product_id,
                    'month' => $monthYear,
                    'status' => $request->status,
                ],
                ['totalQuan' => $sumMonth]
            );

            return response()->json([
                'message' => 'Cập nhật sản lượng thành công!',
                'daily' => $daily,
                'totalDaily' => $totalDaily,
                'totalMonth' => $totalMonth,
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error(basename(__FILE__).' - '.__FUNCTION__.' - Error: '.$e->getMessage());
            Log::error('errors: '.$e->getMessage().' line: '.$e->getLine());

            return response()->json([
                'message' => 'Cập nhật số lượng sản phẩm không thành công!',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // delete detail product
    public function deleteDetailProduct($id, Request $request)
    {
        DB::beginTransaction();
        try {
            if ((int) $request->status === 8) {
                return $this->deletePoDetailProduct($id);
            }

            $detail = DailyQuantity::findOrFail($id);

            $monthYear = Carbon::now()->format('m-Y');

            // Update quantity total day
            $totalDaily = TotalDailyQuantity::where('product_id', $detail->product_id)
                ->where('date', $detail->date)
                ->where('status', $detail->status)->first();
            if ($totalDaily != null) {
                $newTotalDaily = $totalDaily->totalQuan - $detail->quantity;
                if ($newTotalDaily > 0) {
                    $totalDaily->totalQuan = $newTotalDaily;
                    $totalDaily->save();
                } else {
                    $totalDaily->delete();
                }
            }

            // Update quantity total month
            $totalMonth = TotalMonthQuantity::where('product_id', $detail->product_id)
                ->where('month', $monthYear)
                ->where('status', $detail->status)->first();
            if ($totalMonth != null) {
                $newTotalMonth = $totalMonth->totalQuan - $detail->quantity;
                if ($newTotalMonth > 0) {
                    $totalMonth->totalQuan = $newTotalMonth;
                    $totalMonth->save();
                } else {
                    $totalMonth->delete();
                }
            }

            $detail->delete();
            DB::commit();

            return response()->json([
                'message' => 'Xóa sản lượng thành công!',
                'data' => [
                    'id' => $id,
                    'deleted' => true,
                ],
            ]);
        } catch (\Throwable $th) {
            DB::rollBack();

            return HandleError::handle($th);
        }
    }

    private function updatePoDetailProduct(Request $request)
    {
        DB::beginTransaction();
        try {
            $daily = DailyQuantityPO::with('employee:id,name')->find($request->dailyId);
            if (! $daily) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Không tìm thấy bản ghi PO!',
                ], 404);
            }

            $daily->quantity = $request->quantity;
            $daily->employee_id = auth()->id();
            $daily->save();

            $totalDaily = $this->syncTotalDailyQuantityPO($daily->product_id, $daily->date, $daily->status);

            DB::commit();

            return response()->json([
                'message' => 'Cập nhật sản lượng PO thành công!',
                'daily' => $daily,
                'totalDaily' => $totalDaily,
            ]);
        } catch (\Throwable $th) {
            DB::rollBack();

            return HandleError::handle($th);
        }
    }

    private function deletePoDetailProduct($id)
    {
        $detail = DailyQuantityPO::findOrFail($id);
        $date = $detail->date;
        $productId = $detail->product_id;
        $status = $detail->status;

        $detail->delete();
        $this->syncTotalDailyQuantityPO($productId, $date, $status);

        DB::commit();

        return response()->json([
            'message' => 'Xóa sản lượng PO thành công!',
            'data' => [
                'id' => $id,
                'deleted' => true,
            ],
        ]);
    }

    private function syncTotalDailyQuantityPO(int $productId, string $date, int $status)
    {
        $sumDaily = DailyQuantityPO::where('product_id', $productId)
            ->where('date', $date)
            ->where('status', $status)
            ->sum('quantity');

        if ($sumDaily > 0) {
            return TotalDailyQuantityPO::updateOrCreate(
                [
                    'product_id' => $productId,
                    'date' => $date,
                    'status' => $status,
                ],
                ['totalQuan' => $sumDaily]
            );
        }

        TotalDailyQuantityPO::where('product_id', $productId)
            ->where('date', $date)
            ->where('status', $status)
            ->delete();

        return null;
    }

    // addQuantityDetailProduct
    public function addQuantityDetailProduct(Request $request)
    {
        $request->validate([
            'shift' => 'required_if:status,1|nullable|in:Ca 1,Ca 2',
        ], [
            'shift.required_if' => 'Vui lòng chọn ca làm việc.',
            'shift.in' => 'Ca làm việc phải là Ca 1 hoặc Ca 2.',
        ]);

        DB::beginTransaction();
        try {
            $date = $request->date ?? Carbon::now()->format('Y-m-d');
            $month = Carbon::parse($date)->format('m-Y');
            $year = Carbon::parse($date)->format('Y');
            $monthNum = Carbon::parse($date)->format('m');

            // Thêm mới DailyQuantity
            $dailyQuan = new DailyQuantity;
            $dailyQuan->product_id = $request->product_id;
            $dailyQuan->employee_id = auth()->id();
            $dailyQuan->quantity = $request->quantity;
            $dailyQuan->status = $request->status;
            $dailyQuan->date = $date;
            $dailyQuan->shift = (int) $request->status === 1 ? $request->shift : null;
            $dailyQuan->save();

            // Tính lại TotalDailyQuantity từ DailyQuantity
            $sumDaily = DailyQuantity::where('product_id', $request->product_id)
                ->where('date', $date)
                ->where('status', $request->status)
                ->sum('quantity');

            $totalDaily = TotalDailyQuantity::updateOrCreate(
                [
                    'product_id' => $request->product_id,
                    'date' => $date,
                    'status' => $request->status,
                ],
                ['totalQuan' => $sumDaily]
            );

            // Tính lại TotalMonthQuantity từ DailyQuantity
            $sumMonth = DailyQuantity::where('product_id', $request->product_id)
                ->whereYear('date', $year)
                ->whereMonth('date', $monthNum)
                ->where('status', $request->status)
                ->sum('quantity');

            $totalMonth = TotalMonthQuantity::updateOrCreate(
                [
                    'product_id' => $request->product_id,
                    'month' => $month,
                    'status' => $request->status,
                ],
                ['totalQuan' => $sumMonth]
            );

            DB::commit();

            LogActivity::logRoleSpecificLoginActivity(
                auth()->user(),
                'Admin Thêm Sản Lượng',
                'Admin đã thêm mới sản lượng'
            );

            return response()->json([
                'message' => 'Thêm sản lượng thành công!',
                'daily' => $dailyQuan->load('employee:id,name'),
                'totalDaily' => $totalDaily,
                'totalMonth' => $totalMonth,
            ], 201);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error(basename(__FILE__).' - '.__FUNCTION__.' - Error: '.$e->getMessage());
            DB::rollBack();
            Log::error('errors: '.$e->getMessage().' line: '.$e->getLine());

            return response()->json([
                'message' => 'Thêm sản lượng không thành công!',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
