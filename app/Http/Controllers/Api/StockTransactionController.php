<?php

namespace App\Http\Controllers\Api;

use App\Helpers\HandleError;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Product;
use App\Models\StockTransaction;
use App\Models\StorageProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StockTransactionController extends Controller
{
    /**
     * Danh sách giao dịch kho — raw JOIN thay vì eager load
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = DB::table('stock_transactions as st')
                ->join('storage_product as sp', 'sp.id', '=', 'st.storage_product_id')
                ->join('products as p', 'p.id', '=', 'sp.product_id')
                ->leftJoin('employees as e', 'e.id', '=', 'st.employee_id')
                ->select([
                    'st.id',
                    'st.type',
                    'st.quantity',
                    'st.created_at',
                    'sp.id as sp_id',
                    'sp.lot',
                    'sp.bin',
                    'sp.quantity as sp_quantity',
                    'sp.barcode',
                    'p.id as product_id',
                    'p.code as product_code',
                    'p.name as product_name',
                    'e.id as employee_id',
                    'e.name as employee_name',
                ]);

            if ($request->filled('type')) {
                $query->where('st.type', $request->type);
            }
            if ($request->filled('storage_product_id')) {
                $query->where('st.storage_product_id', $request->storage_product_id);
            }
            if ($request->filled('employee_id')) {
                $query->where('st.employee_id', $request->employee_id);
            }
            if ($request->filled('from_date')) {
                $query->whereDate('st.created_at', '>=', $request->from_date);
            }
            if ($request->filled('to_date')) {
                $query->whereDate('st.created_at', '<=', $request->to_date);
            }

            $transactions = $query->orderBy('st.created_at', 'desc')
                ->paginate($request->input('per_page', 15));

            return response()->json($transactions);
        } catch (\Throwable $th) {
            return HandleError::handle($th);
        }
    }

    /**
     * Chi tiết giao dịch
     */
    public function show(int $id): JsonResponse
    {
        try {
            $transaction = StockTransaction::with(['storageProduct.product', 'employee'])
                ->findOrFail($id);

            return response()->json($transaction);
        } catch (\Throwable $th) {
            return HandleError::handle($th);
        }
    }

    /**
     * Tạo giao dịch thủ công (Import/Export)
     */
    public function store(Request $request): JsonResponse
    {
        DB::beginTransaction();
        try {
            $request->validate([
                'storage_product_id' => 'required|exists:storage_product,id',
                'type' => 'required|in:in,out',
                'quantity' => 'required|integer|min:1',
                'employee_id' => 'nullable|exists:employees,id',
            ]);

            $storageProduct = StorageProduct::lockForUpdate()->findOrFail($request->storage_product_id);
            if ($request->type === 'out' && $request->quantity > $storageProduct->quantity) {
                throw \Illuminate\Validation\ValidationException::withMessages(['quantity' => 'Số lượng xuất vượt tồn kho.']);
            }

            $transaction = StockTransaction::create([
                'storage_product_id' => $request->storage_product_id,
                'type' => $request->type,
                'quantity' => $request->quantity,
                'employee_id' => $request->employee_id,
            ]);

            if ($request->type === 'in') {
                $storageProduct->increment('quantity', $request->quantity);
            } else {
                $storageProduct->decrement('quantity', $request->quantity);
            }

            DB::commit();

            $transaction->load(['storageProduct.product', 'employee']);

            return response()->json($transaction, 201);
        } catch (\Throwable $th) {
            DB::rollBack();

            return HandleError::handle($th);
        }
    }

    /**
     * Cập nhật giao dịch
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $transaction = StockTransaction::findOrFail($id);

            if ($request->has('employee_id')) {
                $transaction->update(['employee_id' => $request->employee_id]);
            }

            $transaction->load(['storageProduct.product', 'employee']);

            return response()->json($transaction);
        } catch (\Throwable $th) {
            return HandleError::handle($th);
        }
    }

    /**
     * Xóa giao dịch (reverse quantity)
     */
    public function destroy(int $id): JsonResponse
    {
        DB::beginTransaction();
        try {
            $transaction = StockTransaction::lockForUpdate()->findOrFail($id);
            $storageProduct = StorageProduct::lockForUpdate()->findOrFail($transaction->storage_product_id);

            if ($storageProduct) {
                if ($transaction->type === 'in') {
                    if ($transaction->quantity > $storageProduct->quantity) {
                        throw \Illuminate\Validation\ValidationException::withMessages(['quantity' => 'Không thể xóa giao dịch nhập vì hàng đã được xuất.']);
                    }
                    $storageProduct->decrement('quantity', $transaction->quantity);
                } else {
                    $storageProduct->increment('quantity', $transaction->quantity);
                }
            }

            $transaction->delete();

            DB::commit();

            return response()->json(['message' => 'Xóa giao dịch thành công']);
        } catch (\Throwable $th) {
            DB::rollBack();

            return HandleError::handle($th);
        }
    }

    /**
     * Thống kê giao dịch kho
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $query = StockTransaction::query();

            if ($request->filled('from_date')) {
                $query->whereDate('created_at', '>=', $request->from_date);
            }
            if ($request->filled('to_date')) {
                $query->whereDate('created_at', '<=', $request->to_date);
            }

            $totalIn = (clone $query)->where('type', 'in')->sum('quantity');
            $totalOut = (clone $query)->where('type', 'out')->sum('quantity');

            $topProducts = StockTransaction::with('storageProduct.product')
                ->select('storage_product_id', DB::raw('SUM(quantity) as total_quantity'), DB::raw('COUNT(*) as transaction_count'))
                ->when($request->filled('from_date'), fn ($q) => $q->whereDate('created_at', '>=', $request->from_date))
                ->when($request->filled('to_date'), fn ($q) => $q->whereDate('created_at', '<=', $request->to_date))
                ->groupBy('storage_product_id')
                ->orderBy('total_quantity', 'desc')
                ->limit($request->input('limit', 50))
                ->get();

            return response()->json([
                'summary' => [
                    'total_in' => $totalIn,
                    'total_out' => $totalOut,
                    'net_change' => $totalIn - $totalOut,
                    'total_transactions' => (clone $query)->count(),
                ],
                'top_products' => $topProducts,
            ]);
        } catch (\Throwable $th) {
            return HandleError::handle($th);
        }
    }

    /**
     * Tình trạng tồn kho hiện tại
     */
    public function currentStock(Request $request): JsonResponse
    {
        try {
            $query = DB::table('storage_product as sp')
                ->join('products as p', 'p.id', '=', 'sp.product_id')
                ->where('sp.quantity', '>', 0)
                ->select([
                    'sp.product_id',
                    'p.code as product_code',
                    'p.name as product_name',
                    'p.material',
                    'p.color',
                    'sp.lot',
                    DB::raw('GROUP_CONCAT(DISTINCT sp.bin ORDER BY sp.bin) as bins'),
                    DB::raw('COUNT(DISTINCT sp.bin) as bin_count'),
                    DB::raw('SUM(sp.quantity) as current_quantity'),
                ]);

            if ($request->filled('product_id')) {
                $query->where('sp.product_id', $request->product_id);
            }
            if ($request->filled('lot')) {
                $query->where('sp.lot', 'like', '%'.$request->lot.'%');
            }

            $stocks = $query
                ->groupBy('sp.product_id', 'p.code', 'p.name', 'p.material', 'p.color', 'sp.lot')
                ->orderByDesc('current_quantity')
                ->limit($request->input('limit', 100))
                ->get();

            return response()->json([
                'summary' => [
                    'total_products' => $stocks->pluck('product_id')->unique()->count(),
                    'total_bins' => $stocks->sum('bin_count'),
                    'total_quantity' => $stocks->sum('current_quantity'),
                ],
                'stocks' => $stocks,
            ]);
        } catch (\Throwable $th) {
            return HandleError::handle($th);
        }
    }

    /**
     * Lịch sử giao dịch theo storage product
     */
    public function byStorageProduct(int $storageProductId, Request $request): JsonResponse
    {
        try {
            $storageProduct = StorageProduct::with('product')->findOrFail($storageProductId);

            $transactions = StockTransaction::with('employee')
                ->where('storage_product_id', $storageProductId)
                ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
                ->orderBy('created_at', 'desc')
                ->paginate($request->input('per_page', 15));

            return response()->json([
                'storage_product' => $storageProduct,
                'transactions' => $transactions,
            ]);
        } catch (\Throwable $th) {
            return HandleError::handle($th);
        }
    }

    /**
     * Scan barcode - chỉ xem thông tin
     */
    public function scanBarcode(Request $request): JsonResponse
    {
        try {
            $request->validate(['barcode' => 'required|string']);

            $barcodeData = $this->parseBarcode($request->barcode);
            if (! $barcodeData) {
                return response()->json(['error' => ['code' => 422, 'message' => 'Format barcode không đúng']], 422);
            }

            DB::beginTransaction();
            $product = Product::lockForUpdate()->findOrFail($barcodeData['product_id']);
            $lotCode = $this->generateLotCode($barcodeData['date'], $barcodeData['shift'], $barcodeData['separator']);

            $storageProduct = StorageProduct::firstOrCreate(
                [
                    'product_id' => $barcodeData['product_id'],
                    'lot' => $lotCode,
                    'bin' => $barcodeData['bin_number'],
                ],
                [
                    'barcode' => $request->barcode,
                    'quantity' => 0,
                    'employee_id' => auth()->user()->id ?? null,
                ]
            );

            DB::commit();
            $storageProduct->load('product');

            return response()->json([
                'storage_product' => $storageProduct,
                'barcode_info' => $barcodeData,
                'lot_code' => $lotCode,
                'quantity_per_bin' => $product->quanEntityBin ?? 0,
            ]);
        } catch (\Throwable $th) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return HandleError::handle($th);
        }
    }

    /**
     * Scan barcode → nhập kho
     */
    public function scanIn(Request $request): JsonResponse
    {
        try {
            $request->validate(['barcode' => 'required|string', 'quantity' => 'sometimes|required|integer|min:1']);

            $barcodeData = $this->parseBarcode($request->barcode);
            if (! $barcodeData) {
                return response()->json([
                    'error' => ['code' => 422, 'message' => 'Format barcode không đúng'],
                ], 422);
            }

            $lotCode = $this->generateLotCode($barcodeData['date'], $barcodeData['shift'], $barcodeData['separator']);

            DB::beginTransaction();
            $product = Product::lockForUpdate()->findOrFail($barcodeData['product_id']);

            // Check trùng
            $exists = StorageProduct::where('product_id', $barcodeData['product_id'])
                ->where('lot', $lotCode)
                ->where('bin', $barcodeData['bin_number'])
                ->whereHas('stockTransactions', fn ($q) => $q->where('type', 'in'))
                ->exists();

            if ($exists) {
                DB::rollBack();

                return response()->json([
                    'error' => ['code' => 409, 'message' => 'Thùng này đã được nhập kho trước đó'],
                ], 409);
            }

            $quantity = $request->input('quantity', $product->quanEntityBin ?? 0);
            if ($quantity < 1) {
                throw \Illuminate\Validation\ValidationException::withMessages(['quantity' => 'Số lượng nhập phải lớn hơn 0.']);
            }
            $employee = auth()->user();

            $storageProduct = StorageProduct::firstOrCreate(
                ['product_id' => $barcodeData['product_id'], 'lot' => $lotCode, 'bin' => $barcodeData['bin_number']],
                ['barcode' => $request->barcode, 'quantity' => 0, 'employee_id' => auth()->id()]
            );
            $storageProduct = StorageProduct::lockForUpdate()->findOrFail($storageProduct->id);
            $newQuantity = (int) $storageProduct->quantity + (int) $quantity;

            $transaction = StockTransaction::create([
                'storage_product_id' => $storageProduct->id,
                'type' => 'in',
                'quantity' => $quantity,
                'employee_id' => auth()->id(),
            ]);

            $storageProduct->increment('quantity', $quantity);

            DB::commit();

            return response()->json([
                'id' => $transaction->id,
                'storage_product_id' => $transaction->storage_product_id,
                'type' => $transaction->type,
                'quantity' => $transaction->quantity,
                'employee_id' => $transaction->employee_id,
                'created_at' => $transaction->created_at,
                'updated_at' => $transaction->updated_at,
                'storage_product' => [
                    'id' => $storageProduct->id,
                    'product_id' => $storageProduct->product_id,
                    'lot' => $storageProduct->lot,
                    'bin' => $storageProduct->bin,
                    'quantity' => $newQuantity,
                    'barcode' => $storageProduct->barcode,
                    'product' => [
                        'id' => $product->id,
                        'code' => $product->code,
                        'name' => $product->name,
                    ],
                ],
                'employee' => $employee ? [
                    'id' => $employee->id,
                    'name' => $employee->name,
                ] : null,
            ], 201);
        } catch (\Throwable $th) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return HandleError::handle($th);
        }
    }

    /**
     * Scan barcode → xuất kho
     */
    public function scanOut(Request $request): JsonResponse
    {
        try {
            $request->validate(['barcode' => 'required|string', 'quantity' => 'sometimes|required|integer|min:1']);

            $barcodeData = $this->parseBarcode($request->barcode);
            if (! $barcodeData) {
                return response()->json([
                    'error' => ['code' => 422, 'message' => 'Format barcode không đúng'],
                ], 422);
            }

            $lotCode = $this->generateLotCode($barcodeData['date'], $barcodeData['shift'], $barcodeData['separator']);
            $employee = auth()->user();

            DB::beginTransaction();

            $storageProduct = StorageProduct::where('product_id', $barcodeData['product_id'])
                ->select('id', 'product_id', 'lot', 'bin', 'quantity', 'barcode')
                ->with('product:id,code,name')
                ->where('lot', $lotCode)
                ->where('bin', $barcodeData['bin_number'])
                ->where('quantity', '>', 0)
                ->lockForUpdate()
                ->firstOrFail();

            $quantityOut = $request->input('quantity', $storageProduct->quantity);
            if ($quantityOut > $storageProduct->quantity) {
                throw \Illuminate\Validation\ValidationException::withMessages(['quantity' => 'Số lượng xuất vượt tồn kho.']);
            }
            $remainingQuantity = (int) $storageProduct->quantity - (int) $quantityOut;

            $transaction = StockTransaction::create([
                'storage_product_id' => $storageProduct->id,
                'type' => 'out',
                'quantity' => $quantityOut,
                'employee_id' => auth()->id(),
            ]);

            $storageProduct->update(['quantity' => $remainingQuantity]);

            DB::commit();

            return response()->json([
                'id' => $transaction->id,
                'storage_product_id' => $transaction->storage_product_id,
                'type' => $transaction->type,
                'quantity' => $transaction->quantity,
                'employee_id' => $transaction->employee_id,
                'created_at' => $transaction->created_at,
                'updated_at' => $transaction->updated_at,
                'storage_product' => [
                    'id' => $storageProduct->id,
                    'product_id' => $storageProduct->product_id,
                    'lot' => $storageProduct->lot,
                    'bin' => $storageProduct->bin,
                    'quantity' => $remainingQuantity,
                    'barcode' => $storageProduct->barcode,
                    'product' => $storageProduct->product ? [
                        'id' => $storageProduct->product->id,
                        'code' => $storageProduct->product->code,
                        'name' => $storageProduct->product->name,
                    ] : null,
                ],
                'employee' => $employee ? [
                    'id' => $employee->id,
                    'name' => $employee->name,
                ] : null,
            ], 201);
        } catch (\Throwable $th) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return HandleError::handle($th);
        }
    }

    /**
     * Dashboard: dữ liệu kho với filter + lot modal
     * (Thay thế EmpScanController::StorageProduct)
     */
    public function storageProduct(Request $request): JsonResponse
    {
        try {
            // Ngày mới nhất làm mặc định
            $latestDate = StorageProduct::selectRaw('DATE(created_at) as date')
                ->orderBy('date', 'desc')
                ->first()?->date;

            $defaultDate = $latestDate ? Carbon::parse($latestDate)->toDateString() : now()->toDateString();
            $filterDate = $request->input('filter_date', $defaultDate);

            $filterMonth = $request->input('filter_month');
            if (! $filterMonth && $filterDate) {
                $filterMonth = Carbon::parse($filterDate)->format('Y-m');
            }

            // Tháng và ngày có sẵn
            $availableMonths = StorageProduct::selectRaw('DATE_FORMAT(created_at, "%Y-%m") as month')
                ->distinct()->orderBy('month', 'desc')->pluck('month')->toArray();

            $availableDatesQuery = StorageProduct::selectRaw('DATE(created_at) as date')
                ->distinct()->orderBy('date', 'desc');

            if ($filterMonth) {
                $availableDatesQuery->whereRaw('DATE_FORMAT(created_at, "%Y-%m") = ?', [$filterMonth]);
            }

            $availableDates = $availableDatesQuery->pluck('date')
                ->map(fn ($d) => Carbon::parse($d)->toDateString())->toArray();

            if (! in_array($filterDate, $availableDates)) {
                $filterDate = $availableDates[0] ?? $defaultDate;
            }

            // Products và Employees có sẵn theo filter
            $productQuery = StorageProduct::distinct();
            $employeeQuery = StorageProduct::distinct();

            if ($filterDate) {
                $productQuery->whereDate('created_at', $filterDate);
                $employeeQuery->whereDate('created_at', $filterDate);
            } elseif ($filterMonth) {
                $productQuery->whereRaw('DATE_FORMAT(created_at, "%Y-%m") = ?', [$filterMonth]);
                $employeeQuery->whereRaw('DATE_FORMAT(created_at, "%Y-%m") = ?', [$filterMonth]);
            }

            if ($request->filled('product_id')) {
                $employeeQuery->where('product_id', $request->input('product_id'));
            }

            $products = Product::whereIn('id', $productQuery->pluck('product_id'))->orderBy('name')->get();
            $employees = Employee::whereIn('id', $employeeQuery->pluck('employee_id'))->orderBy('name')->get();

            // Query storage data
            $storageQuery = StorageProduct::with(['product', 'employee']);

            if ($filterDate) {
                $storageQuery->whereDate('created_at', $filterDate);
            } elseif ($filterMonth) {
                $storageQuery->whereRaw('DATE_FORMAT(created_at, "%Y-%m") = ?', [$filterMonth]);
            }

            if ($request->filled('product_id')) {
                $storageQuery->where('product_id', $request->input('product_id'));
            }
            if ($request->filled('employee_id')) {
                $storageQuery->where('employee_id', $request->input('employee_id'));
            }

            $storage = $storageQuery->get()
                ->groupBy(fn ($item) => Carbon::parse($item->created_at)->format('Y-m-d').'|'.$item->employee_id.'|'.$item->product_id);

            // Lot modal
            $lotModalData = null;
            if ($request->filled('lot') && $request->filled('lot_product_id')) {
                $lotModalData = $this->buildLotModal($request->input('lot'), (int) $request->input('lot_product_id'));
            }

            return response()->json([
                'storage' => $storage,
                'products' => $products,
                'employees' => $employees,
                'availableMonths' => $availableMonths,
                'availableDates' => $availableDates,
                'filterMonth' => $filterMonth,
                'filterDate' => $filterDate,
                'lotModalData' => $lotModalData,
            ]);
        } catch (\Throwable $th) {
            return HandleError::handle($th);
        }
    }

    // ============================================================
    // Private helpers
    // ============================================================

    /**
     * Parse barcode: 39a101020251001 → [product_id, separator, date, shift, bin_number]
     */
    private function parseBarcode($barcode)
    {
        $clean = preg_replace('/[^a-zA-Z0-9]/', '', $barcode);
        preg_match('/^(\d+)([a-zA-Z])(\d{8})(\d{1})(\d{3})$/', $clean, $m);

        if (count($m) !== 6) {
            return null;
        }

        return [
            'product_id' => (int) $m[1],
            'separator' => $m[2],
            'date' => $m[3],
            'shift' => (int) $m[4],
            'bin_number' => (int) $m[5],
        ];
    }

    private function generateLotCode($date, $shift, $separator = 'A')
    {
        return strtoupper($separator).'-'.$date.'-'.$shift;
    }

    private function generateBinCode($date, $shift, $binNumber, $separator = 'A')
    {
        return strtoupper($separator).'-'.$date.'-'.$shift.'-'.str_pad($binNumber, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Xây dựng dữ liệu lot modal - tìm missing bins
     */
    private function buildLotModal(string $lotInput, int $productId): array
    {
        $lotCode = strtoupper(trim($lotInput));
        if (! str_starts_with($lotCode, 'A-')) {
            $lotCode = 'A-'.$lotCode;
        }

        if (! preg_match('/^A-(\d{2})(\d{2})(\d{4})-([12])$/', $lotCode, $matches)) {
            return ['error' => 'Mã lot không đúng định dạng.'];
        }

        [$all, $day, $month, $year, $shift] = $matches;

        $product = Product::find($productId);
        if (! $product) {
            return ['error' => 'Không tìm thấy sản phẩm.'];
        }

        $existingNumbers = StorageProduct::where('product_id', $productId)
            ->where('lot', $lotCode)
            ->pluck('bin')
            ->map(fn ($bin) => (int) $bin)
            ->unique()->sort()->values();

        if ($existingNumbers->isEmpty()) {
            return [
                'code' => $lotCode,
                'product' => $product->name,
                'date' => "$day/$month/$year",
                'shift' => $shift,
                'expected' => 0,
                'actual' => 0,
                'missing' => 0,
                'missingBins' => [],
                'status' => 'empty',
            ];
        }

        $expectedNumbers = range($existingNumbers->min(), $existingNumbers->max());
        $missingNumbers = array_diff($expectedNumbers, $existingNumbers->all());
        $missingBins = array_values(array_map(
            fn ($num) => str_pad($num, 3, '0', STR_PAD_LEFT),
            $missingNumbers
        ));

        return [
            'code' => $lotCode,
            'product' => $product->name,
            'date' => "$day/$month/$year",
            'shift' => $shift,
            'expected' => count($expectedNumbers),
            'startFrom' => $existingNumbers->min(),
            'endAt' => $existingNumbers->max(),
            'actual' => count($expectedNumbers) - count($missingNumbers),
            'missing' => count($missingNumbers),
            'missingBins' => $missingBins,
            'status' => count($missingNumbers) > 0 ? 'warning' : 'success',
        ];
    }
}
