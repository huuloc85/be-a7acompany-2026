<?php

namespace App\Http\Controllers;

use App\Exports\CheckPo\ExportMultiSheetsPo;
use App\Models\DailyQuantity;
use App\Models\DailyQuantityPO;
use App\Models\Product;
use App\Models\TotalDailyQuantity;
use App\Models\TotalDailyQuantityPO;
use App\Models\TotalMonthQuantity;
use App\Traits\CalenderTranslate;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class CheckPoController extends Controller
{
    use CalenderTranslate;

    public function index(Request $request)
    {
        $products = Product::all();
        $today = Carbon::now();
        $filterMonth = $request->input('monthFilter', $today->format('m-Y'));
        $filteredDate = Carbon::createFromFormat('m-Y', $filterMonth);
        $startOfMonth = $filteredDate->copy()->startOfMonth();
        $endOfMonth = $filteredDate->copy()->endOfMonth();
        $months = [];

        $selectedMonth = $filterMonth;
        $totalMonthQuantities = TotalMonthQuantity::distinct()->pluck('month');
        for ($date = $startOfMonth; $date <= $endOfMonth; $date->addDay()) {
            $dateFormatted = $date->format('Y-m-d');
            $dateAfter = $date->format('d/m/Y');
            $nameDay = $date->format('l');

            $dailyQuantitiesStatus1 = TotalDailyQuantity::whereDate('date', $dateFormatted)
                ->where('status', 1)
                ->get();

            // Lấy dữ liệu từ bảng TotalDailyQuantityPO với status = 8
            $dailyQuantitiesStatus8 = TotalDailyQuantityPO::whereDate('date', $dateFormatted)
                ->where('status', 8)
                ->get();

            // Hợp nhất kết quả từ hai bảng
            $dailyQuantities = $dailyQuantitiesStatus1->merge($dailyQuantitiesStatus8);

            $quantities = [
                'quan100' => [],
                'quanExport' => [],
                'reamingOfWeek' => [],
                'previousReamingOfWeek' => [],
            ];

            foreach ($dailyQuantities as $record) {
                if ($record->status == 1) {
                    $quantities['quan100'][$record->product_id] = ($quantities['quan100'][$record->product_id] ?? 0) + $record->totalQuan;
                } elseif ($record->status == 8) {
                    $quantities['quanExport'][$record->product_id] = ($quantities['quanExport'][$record->product_id] ?? 0) + $record->totalQuan;
                }
            }

            $week[$dateAfter] = $quantities;

            if ($nameDay == 'Sunday' || $dateAfter == $endOfMonth->format('d/m/Y')) {
                array_push($months, $week);
                $week = [];
            }
        }

        $currentMonth = Carbon::now()->format('m-Y');
        $productIds = TotalMonthQuantity::distinct('product_id')->pluck('product_id');
        $productNearData = [];
        foreach ($productIds as $productId) {
            // Lấy dữ liệu gần nhất cho mỗi sản phẩm theo tháng
            $stockQuanNearly = TotalMonthQuantity::where('product_id', $productId)->where('status', 4)->where('month', $currentMonth)->value('totalQuan');

            // Lưu dữ liệu gần nhất vào mảng productNearData cho mỗi sản phẩm
            $productNearData[$productId] = [
                'stockQuanNearly' => $stockQuanNearly,
            ];
        }

        $listDate = $this->handleDayInMonth($selectedMonth);
        $currentDate = Carbon::now()->format('d-m-Y');

        return view('checkpo.index', compact('months', 'products', 'listDate', 'currentDate', 'totalMonthQuantities', 'selectedMonth', 'productNearData'));
    }

    public function handleAddPoExport(Request $request)
    {
        DB::beginTransaction();
        try {
            $date = date('Y-m-d', strtotime($request->date));
            $month = substr($request->date, -7);
            $listProductId = $request->productId;
            $listQuantity = $request->quantity;
            $status = $request->status;
            $shift = null; // Khởi tạo biến shift

            // Kiểm tra xem dữ liệu đã được cung cấp đầy đủ hay không
            if (empty($date) || empty($listProductId) || empty($listQuantity) || empty($status)) {
                toast('Vui lòng nhập đầy đủ thông tin sản lượng.', 'error', ['position' => 'top-right']);

                return redirect()->back();
            }

            // Kiểm tra xem có dữ liệu sản lượng được cung cấp hay không
            if (empty(array_filter($listQuantity)) || empty(array_filter($listProductId))) {
                toast('Vui lòng nhập thông tin sản lượng cho ít nhất một sản phẩm.', 'error', ['position' => 'top-right']);

                return redirect()->back();
            }

            if ($status == 1) { // Nếu status là Hàng 100%
                $shift = $request->shift; // Gán giá trị của shift nếu status là Hàng 100%
            }

            if (count($listQuantity) > 0) {
                for ($i = 0; $i < count($listQuantity); $i++) {
                    if ($listQuantity[$i] != null && $listQuantity[$i] > 0 && $listProductId[$i] != null) {
                        $product = Product::find($listProductId[$i]);
                        if ($product != null && $listQuantity[$i] != null) {
                            // Nếu status là Hàng 100% và shift có giá trị
                            if ($status == 1 && $shift != null) {
                                // Xác định thời gian tạo bản ghi theo ca làm việc
                                if ($shift == 1) {
                                    // Nếu là ca 1 là 19:30 sáng cùng ngày
                                    $created_at = $date.'19:30:00 ';
                                } else {
                                    // Nếu là ca 2 tạo bản ghi là 7:30 sáng của ngày sau
                                    $created_at = date('Y-m-d', strtotime($date.' +1 day')).'07:30:00 ';
                                }
                            } else {
                                // Nếu status khác 1 hoặc shift không có giá trị
                                // Không cần tính toán giá trị cho shift
                                $created_at = Carbon::now();
                            }
                            // Cập nhật sản lượng ngày
                            $dailyQuan = new DailyQuantity;
                            $dailyQuan->product_id = $product->id;
                            $dailyQuan->employee_id = Auth()->user()->id;
                            $dailyQuan->quantity = $listQuantity[$i];
                            $dailyQuan->status = $status;
                            $dailyQuan->date = $date;
                            $dailyQuan->created_at = $created_at;
                            $dailyQuan->save();

                            // Cập nhật tổng ngày
                            $totalDaily = TotalDailyQuantity::where('product_id', $product->id)
                                ->where('date', $date)
                                ->where('status', $status)->first();
                            if ($totalDaily != null) {
                                $totalDaily->totalQuan += $listQuantity[$i];
                                $totalDaily->save();
                            } else {
                                $totalDaily = new TotalDailyQuantity;
                                $totalDaily->product_id = $product->id;
                                $totalDaily->date = $date;
                                $totalDaily->status = $status;
                                $totalDaily->totalQuan = $listQuantity[$i];

                                $totalDaily->save();
                            }

                            // Cập nhật tổng tháng
                            $totalMonth = TotalMonthQuantity::where('product_id', $product->id)
                                ->where('month', $month)
                                ->where('status', $status)->first();
                            if ($totalMonth != null) {
                                $totalMonth->totalQuan += $listQuantity[$i];
                                $totalMonth->save();
                            } else {
                                $totalMonth = new TotalMonthQuantity;
                                $totalMonth->product_id = $product->id;
                                $totalMonth->month = $month;
                                $totalMonth->status = $status;
                                $totalMonth->totalQuan = $listQuantity[$i];
                                $totalMonth->save();
                            }
                        }
                    }
                }
            }

            DB::commit();
            toast('Cập nhật số lượng thành công!', 'success', 'top-right');

            return redirect()->route('admin.checkpo.index');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('errors'.$e->getMessage().' getLine'.$e->getLine());
            toast('Cập nhật số lượng sản phẩm không thành công!', 'error', 'top-right');

            return redirect()->back();
        }
    }

    public function handleAddPoImport(Request $request)
    {
        DB::beginTransaction();
        try {
            $status = 8;

            // Lấy employee_id từ thông tin đăng nhập hiện tại
            $employeeId = Auth::id();

            $listProductId = $request->productId;
            $listQuantity = $request->quantity;

            $date = Carbon::createFromFormat('d-m-Y', $request->date)->format('Y-m-d');

            foreach ($listProductId as $key => $productId) {
                $quantity = $listQuantity[$key];
                if ($quantity > 0) {
                    $product = Product::find($productId);
                    if ($product != null) {
                        $totalDaily = DailyQuantityPO::where('product_id', $productId)
                            ->where('status', $status)
                            ->whereDate('date', $date)
                            ->first();

                        // Tạo bản ghi mới ngay cả khi tồn tại bản ghi khớp
                        $totalDaily = new DailyQuantityPO;
                        $totalDaily->product_id = $productId;
                        $totalDaily->status = $status;
                        $totalDaily->quantity = $quantity;
                        $totalDaily->date = $date;
                        $totalDaily->employee_id = $employeeId;
                        $totalDaily->save();

                        $totalDailyPO = TotalDailyQuantityPO::where('product_id', $product->id)
                            ->where('date', $date)
                            ->where('status', $status)
                            ->first();

                        if ($totalDailyPO != null) {
                            $totalDailyPO->totalQuan += $quantity;
                            $totalDailyPO->save();
                        } else {
                            $totalDailyPO = new TotalDailyQuantityPO;
                            $totalDailyPO->product_id = $product->id;
                            $totalDailyPO->date = $date;
                            $totalDailyPO->status = $status;
                            $totalDailyPO->totalQuan = $quantity;
                            $totalDailyPO->save();
                        }
                    }
                }
            }
            DB::commit();
            toast('Cập nhật số lượng thành công!', 'success', 'top-right');

            return redirect()->route('admin.checkpo.index');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error: '.$e->getMessage().' at Line '.$e->getLine());
            toast('Cập nhật số lượng sản phẩm không thành công!', 'error', 'top-right');

            return redirect()->back();
        }
    }

    public function handleAddStockQuantityInventory(Request $request)
    {

        DB::beginTransaction();
        try {
            $month = $request->month;
            $listProductId = $request->productId;
            $listQuantity = $request->quantity;
            $status = 4;

            // Cập nhật số lượng gần nhất cho status 4
            $stockQuanNearly = TotalMonthQuantity::where('status', 4)->latest()->first();

            // Lặp qua danh sách sản phẩm và số lượng tương ứng để cập nhật
            foreach ($listProductId as $key => $productId) {
                $quantity = $listQuantity[$key];
                // Tìm sản phẩm và kiểm tra tồn tại
                $product = Product::find($productId);
                if ($product != null) {
                    // Tìm hoặc tạo mới bản ghi số lượng tháng
                    $totalMonth = TotalMonthQuantity::where('product_id', $productId)
                        ->where('month', $month)
                        ->where('status', $status)
                        ->first();

                    if ($totalMonth != null) {
                        $totalMonth->totalQuan = $quantity;
                        $totalMonth->save();
                    } else {
                        $totalMonth = new TotalMonthQuantity;
                        $totalMonth->product_id = $productId;
                        $totalMonth->month = $month;
                        $totalMonth->status = $status;
                        $totalMonth->totalQuan = $quantity;
                        $totalMonth->save();
                    }
                }
            }

            if ($stockQuanNearly) {
                $stockQuanNearly->totalQuan = $request->stockQuan;
                $stockQuanNearly->save();
            }
            DB::commit();
            toast('Cập nhật số lượng thành công!', 'success', 'top-right');

            return redirect()->route('admin.checkpo.index');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error: '.$e->getMessage().' at Line '.$e->getLine());
            toast('Cập nhật số lượng sản phẩm không thành công!', 'error', 'top-right');

            return redirect()->back();
        }
    }

    public function exportCheckPo(Request $request)
    {
        $today = Carbon::now();
        $filterMonth = $request->input('monthExport', $today->format('m-Y'));
        $filteredDate = Carbon::createFromFormat('m-Y', $filterMonth);
        $startOfMonth = $filteredDate->copy()->startOfMonth();
        $endOfMonth = $filteredDate->copy()->endOfMonth();
        $months = [];
        $week = [];

        for ($date = $startOfMonth; $date <= $endOfMonth; $date->addDay()) {
            $dateFormatted = $date->format('Y-m-d');
            $dateAfter = $date->format('d/m/Y');
            $nameDay = $date->format('l');

            $dailyQuantities = TotalDailyQuantity::whereDate('date', $dateFormatted)
                ->where('status', 1)
                ->get();

            $exportQuantities = TotalDailyQuantityPo::whereDate('date', $dateFormatted)
                ->where('status', 8)
                ->get();

            $quantities = [
                'quan100' => [],
                'quanExport' => [],
                'reamingOfWeek' => [],
                'previousReamingOfWeek' => [],
            ];

            foreach ($dailyQuantities as $record) {
                if ($record->status == 1) {
                    $quantities['quan100'][$record->product_id] = ($quantities['quan100'][$record->product_id] ?? 0) + $record->totalQuan;
                }
            }

            foreach ($exportQuantities as $record) {
                if ($record->status == 8) {
                    $quantities['quanExport'][$record->product_id] = ($quantities['quanExport'][$record->product_id] ?? 0) + $record->totalQuan;
                }
            }

            $week[$dateAfter] = $quantities;

            if ($nameDay == 'Sunday' || $dateAfter == $endOfMonth->format('d/m/Y')) {
                array_push($months, $week);
                $week = [];
            }
        }

        // Add data to $months array here if needed
        return Excel::download(new ExportMultiSheetsPo($filterMonth, $months), 'THEO DÕI PO THÁNG '.$filterMonth.'.xlsx', \Maatwebsite\Excel\Excel::XLSX, [
            'Content-Type' => 'text/xlsx',
        ]);
    }

    public function historyImport(Request $request)
    {
        $selectedDate = $request->input('date');
        if ($selectedDate) {
            $month = Carbon::parse($selectedDate);
        } else {
            $month = $request->input('month') ? Carbon::createFromFormat('Y-m', $request->input('month')) : Carbon::now();
        }
        $products = Product::all();
        // query
        if ($selectedDate) {
            $dailyQuantitiesQuery = DailyQuantityPO::whereDate('date', $selectedDate);
        } else {
            $dailyQuantitiesQuery = DailyQuantityPO::whereYear('date', $month->year)
                ->whereMonth('date', $month->month);
        }
        $dailyQuantities = $dailyQuantitiesQuery->orderBy('date', 'asc')->get();
        $dates = DailyQuantityPO::whereYear('date', $month->year)
            ->whereMonth('date', $month->month)
            ->select('date')
            ->distinct()
            ->orderBy('date')
            ->get();

        return view('checkpo.history-import-quantity', compact('products', 'month', 'dailyQuantities', 'selectedDate', 'dates'));
    }

    public function editPO($id)
    {
        $daily = DailyQuantityPO::findOrFail($id);

        return redirect()->route('admin.history-import-quantity', ['date' => $daily->date]);
    }

    public function updatePO(Request $request)
    {
        try {
            $quantities = $request->input('quantities', []);
            $productIds = $request->input('product_id', []);
            $date = $request->input('date');
            $status = $request->input('status');

            // Kiểm tra xem quantities và productIds có phải mảng hay không
            if (is_array($quantities) && is_array($productIds)) {
                foreach ($productIds as $productId) {
                    // Cho phép lưu giá trị 0
                    $newQuantity = $quantities[$productId] ?? 0;

                    $dailyQuantity = DailyQuantityPO::where('product_id', $productId)
                        ->whereDate('date', $date)
                        ->first();

                    if ($dailyQuantity) {
                        if ($dailyQuantity->quantity != $newQuantity) {
                            $dailyQuantity->quantity = $newQuantity;
                            $dailyQuantity->employee_id = Auth::id();
                            $dailyQuantity->status = $status;
                            $dailyQuantity->save();
                        }
                    } else {
                        $dailyQuantity = new DailyQuantityPO;
                        $dailyQuantity->product_id = $productId;
                        $dailyQuantity->quantity = $newQuantity;
                        $dailyQuantity->employee_id = Auth::id();
                        $dailyQuantity->date = $date;
                        $dailyQuantity->status = $status;
                        $dailyQuantity->save();
                    }

                    $totalDailyQuantity = TotalDailyQuantityPo::where('product_id', $productId)
                        ->whereDate('date', $date)
                        ->first();

                    if ($totalDailyQuantity) {
                        $totalDailyQuantity->totalQuan = $newQuantity;
                        $totalDailyQuantity->status = $status;
                        $totalDailyQuantity->save();
                    } else {
                        $totalDailyQuantity = new TotalDailyQuantityPo;
                        $totalDailyQuantity->product_id = $productId;
                        $totalDailyQuantity->date = $date;
                        $totalDailyQuantity->status = $status;
                        $totalDailyQuantity->totalQuan = $newQuantity;
                        $totalDailyQuantity->save();
                    }
                }
            }

            toast('Cập nhật số lượng thành công!', 'success');
        } catch (\Exception $e) {
            Log::error('Lỗi cập nhật số lượng', ['error' => $e->getMessage()]);
            toast('Có lỗi xảy ra khi cập nhật số lượng!', 'error');
        }

        return redirect()->route('admin.history-import-quantity', [
            'month' => $request->input('month'),
            'date' => $date,
            'status' => $status,
        ]);
    }

    public function deletePO($id)
    {
        try {
            // Tìm và lấy thông tin của bản ghi trong DailyQuantityPO cần xoá
            $dailyQuantity = DailyQuantityPO::findOrFail($id);
            $date = $dailyQuantity->date;

            // Xoá bản ghi trong DailyQuantityPO
            $dailyQuantity->delete();

            // Cập nhật lại các bản ghi trong TotalDailyQuantityPo cho ngày được xác định
            $totalDailyQuantities = TotalDailyQuantityPo::where('date', $date)->get();
            foreach ($totalDailyQuantities as $totalDailyQuantity) {
                $remainingDailyQuantities = DailyQuantityPO::where('product_id', $totalDailyQuantity->product_id)
                    ->whereDate('date', $date)
                    ->get();
                $totalQuantity = $remainingDailyQuantities->sum('quantity');
                $totalDailyQuantity->totalQuan = $totalQuantity;
                $totalDailyQuantity->save();
            }

            toast('Đã xoá thành công sản lượng PO và cập nhật lại tổng sản lượng.', 'success');
        } catch (\Exception $e) {
            toast('Xoá không thành công sản lượng PO.', 'error');
        }

        return redirect()->route('admin.history-import-quantity');
    }
}
