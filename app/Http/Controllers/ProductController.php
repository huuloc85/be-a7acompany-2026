<?php

namespace App\Http\Controllers;

use App\Exports\Product\ExportMultiSheets;
use App\Helpers\LogActivity;
use App\Http\Requests\ProductStoreRequest;
use App\Http\Requests\ProductUpdateRequest;
use App\Models\CelenderDetailHNHC;
use App\Models\CheckEmployee;
use App\Models\DailyQuantity;
use App\Models\Product;
use App\Models\TotalDailyQuantity;
use App\Models\TotalMonthQuantity;
use App\Traits\CalenderTranslate;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class ProductController extends Controller
{
    use CalenderTranslate;

    // index
    public function index(Request $request)
    {
        $page = 'product';
        if ($request->page) {
            $page = $request->page;
        }
        $monthNearly = Carbon::now()->format('m-Y');
        $listMonth = TotalMonthQuantity::distinct()->pluck('month');
        $listMonthExport = TotalMonthQuantity::where('status', 3)->distinct()->pluck('month');
        $orderBy = $request->orderBy;
        $filter = 'desc';
        if (count($listMonth) == 0) {
            $monthNearly = $listMonth[count($listMonth) - 1];
        }
        $products = Product::query();
        if ($request->month) {
            $monthNearly = $request->month;
        }
        // $monthDate = Carbon::createFromFormat('m-Y', $monthNearly)->startOfMonth();
        // $products = $products->whereYear('created_at', $monthDate->year)->whereMonth('created_at', $monthDate->month);

        if (! empty($request->code)) {
            $products->Code($request);
        }

        if (! empty($request->name)) {
            $products->Name($request);
        }

        if (! empty($request->moldSize)) {
            $products->MoldSize($request);
        }

        if (! empty($request->binCode)) {
            $products->BinCode($request);
        }

        // mặc định data = asc
        if (! $orderBy) {
            $orderBy = 'asc';
        }

        $productIds = Product::pluck('id'); // Lấy tất cả product IDs
        $errorQuantities = TotalDailyQuantity::where('status', 6)
            ->whereIn('product_id', $productIds)
            ->get()
            ->groupBy('product_id') // Nhóm theo product_id
            ->map(function ($prod) {
                return $prod->sum('totalQuan'); // Tính tổng totalQuan cho mỗi nhóm
            });

        $totalproduct = $products->get();
        if ($orderBy && $orderBy == 'asc') {
            $products = $products->orderBy('id', 'ASC');
            $filter = 'asc';
        } else {
            $products = $products->orderBy('id', 'DESC');
        }
        $products = $products->get();
        $total = count($totalproduct);
        $product = new Product;
        $models = $product->models;
        $modelSizes = $product->modelSizes;
        // dd($monthNearly);
        $listDate = $this->handleDayInMonth($monthNearly);

        return view('product.index', compact('products', 'total', 'models', 'modelSizes', 'listMonth', 'monthNearly', 'listMonthExport', 'listDate', 'filter', 'errorQuantities', 'page'));
    }

    // add
    public function add(Request $request)
    {
        $product = new Product;
        $models = $product->models;
        $modelSizes = $product->modelSizes;

        return view('product.add', compact('models', 'modelSizes'));
    }

    // store
    public function store(ProductStoreRequest $request)
    {
        DB::beginTransaction();
        try {
            $product = new Product;
            $product->code = trim($request->code);
            $product->name = trim($request->name);
            // $product->quantity = trim($request->quantity);
            // $product->quantityCaTon = trim($request->quantityCaTon);
            $product->moldSize = trim($request->moldSize);
            $product->CAV = trim($request->CAV);
            $product->cycle = trim($request->cycle);
            // $product->planTime = trim($request->planTime);
            // $product->realTime = trim($request->realTime);
            $product->binCode = $request->binCode;
            $product->quanEntityBin = trim($request->quanEntityBin);

            $listCompany = $request->company;
            $product->FAPV = 0;
            $product->FASV = 0;
            $product->FAVV = 0;
            if ($listCompany != null && count($listCompany) != 0) {
                for ($i = 0; $i < count($listCompany); $i++) {
                    $listCompany[$i] == 'FAPV' ? $product->FAPV = 1 : '';
                    $listCompany[$i] == 'FASV' ? $product->FASV = 1 : '';
                    $listCompany[$i] == 'FAVV' ? $product->FAVV = 1 : '';
                }
            }
            // dd($product);
            $product->save();

            // save tồn đầu kỳ tháng hiện tại
            $month = Carbon::now()->format('m-Y');
            $stockQuan = new TotalMonthQuantity;
            $stockQuan->product_id = $product->id;
            $stockQuan->month = $month;
            $stockQuan->status = 4;
            $stockQuan->totalQuan = $request->stockQuan ?? 0;
            $stockQuan->save();

            // save tồn đầu kì 200% tháng hiện tại
            $stockQuan200 = new TotalMonthQuantity;
            $stockQuan200->product_id = $product->id;
            $stockQuan200->month = $month;
            $stockQuan200->status = 5;
            $stockQuan200->totalQuan = $request->stockQuan200 ?? 0;
            $stockQuan200->save();

            // save MOQ tháng hiện tiện
            $stockQuanMOQ = new TotalMonthQuantity;
            $stockQuanMOQ->product_id = $product->id;
            $stockQuanMOQ->month = $month;
            $stockQuanMOQ->status = 7;
            $stockQuanMOQ->totalQuan = $request->stockQuanMOQ ?? 0;
            // dd($stockQuanMOQ);
            $stockQuanMOQ->save();

            DB::commit();
            toast('Thêm sản phẩm mới thành công!', 'success', 'top-right');
            LogActivity::logRoleSpecificLoginActivity(auth()->user(), 'Admin Thêm Sản Phẩm', 'Admin đã thêm sản phẩm');

            return redirect()->route('admin.product.home');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('errors'.$e->getMessage().' getLine'.$e->getLine());
            toast('Thêm sản phẩm mới không thành công!', 'error', 'top-right');

            return redirect()->back();
        }

        return view('product.index');
    }

    // edit
    public function edit($id)
    {
        $product = new Product;
        $models = $product->models;
        $modelSizes = $product->modelSizes;
        $product = $product::find($id);

        return view('product.edit', compact('product', 'models', 'modelSizes'));
    }

    // update
    public function update($id, ProductUpdateRequest $request)
    {
        DB::beginTransaction();
        $product = Product::find($id);
        $product->code = trim($request->code);
        $product->name = trim($request->name);
        // $product->quantity = trim($request->quantity);
        $product->moldSize = trim($request->moldSize);
        $product->CAV = trim($request->CAV);
        $product->cycle = trim($request->cycle);
        $product->binCode = $request->binCode;
        $product->quanEntityBin = trim($request->quanEntityBin);
        $listCompany = $request->company;
        $product->FAPV = 0;
        $product->FASV = 0;
        $product->FAVV = 0;
        if ($listCompany != null && count($listCompany) != 0) {
            for ($i = 0; $i < count($listCompany); $i++) {
                $listCompany[$i] == 'FAPV' ? $product->FAPV = 1 : '';
                $listCompany[$i] == 'FASV' ? $product->FASV = 1 : '';
                $listCompany[$i] == 'FAVV' ? $product->FAVV = 1 : '';
            }
        }
        $product->save();
        DB::commit();
        toast('Cập nhật sản phẩm thành công!', 'success', 'top-right');
        LogActivity::logRoleSpecificLoginActivity(auth()->user(), 'Admin Sửa Sản Phẩm', 'Admin đã cập nhật sản phẩm');

        return redirect()->route('admin.product.home');
    }

    // delete
    public function delete($id)
    {
        $product = Product::findOrFail($id);
        try {
            $product->delete();
            toast('Sản phẩm đã được đưa vào thùng rác!', 'success', 'top-right');

            return redirect()->route('admin.product.home');
        } catch (\Exception $th) {
            toast('Đưa sản phẩm vào thùng rác không thành công!', 'error', 'top-right');

            return redirect()->route('admin.product.home');
        }
    }

    // trash
    public function getTrash(Request $request)
    {
        $products = Product::onlyTrashed();

        if (! empty($request->code)) {
            $products->Code($request);
        }

        if (! empty($request->name)) {
            $products->Name($request);
        }

        if (! empty($request->moldSize)) {
            $products->MoldSize($request);
        }

        if (! empty($request->binCode)) {
            $products->BinCode($request);
        }

        $totalproduct = $products->get();
        $products = $products->orderBy('id', 'DESC')->paginate(Product::paginate);
        $total = count($totalproduct);
        $product = new Product;
        $models = $product->models;
        $modelSizes = $product->modelSizes;

        return view('product.trash', compact('products', 'total', 'models', 'modelSizes'));
    }

    // restore
    public function restore($id)
    {
        try {
            Product::withTrashed()->where('id', $id)->restore();
            toast('Sản phẩm được khôi phục thành công!', 'success', 'top-right');

            return redirect()->route('admin.product.getTrash');
        } catch (\Exception $th) {
            toast('Khôi phục Sản phẩm không thành công!', 'error', 'top-right');

            return redirect()->route('admin.product.getTrash');
        }
    }

    // history
    public function detail($id, Request $request)
    {
        $month = Carbon::now()->format('m');
        $year = Carbon::now()->format('Y');
        $monthNearly = Carbon::now()->format('m-Y');
        $listMonth = TotalMonthQuantity::distinct()->pluck('month');
        if ($request->month != null) {
            $monthNearly = $request->month;
        }
        $monthYearArray = explode('-', $monthNearly);
        if (count($monthYearArray) > 1) {
            $month = $monthYearArray[0];
            $year = $monthYearArray[1];
        }
        $product = Product::find($id);
        $dailyQuanStatus1 = DailyQuantity::where('product_id', $id)->where('status', 1)->whereYear('date', $year)->whereMonth('date', $month)->orderBy('id', 'DESC');
        $dailyQuanStatus2 = DailyQuantity::where('product_id', $id)->where('status', 2)->whereYear('date', $year)->whereMonth('date', $month)->orderBy('id', 'DESC');
        $dailyQuanStatus3 = DailyQuantity::where('product_id', $id)->where('status', 3)->whereYear('date', $year)->whereMonth('date', $month)->orderBy('id', 'DESC');
        $dailyQuanStatus6 = DailyQuantity::where('product_id', $id)->where('status', 6)->whereYear('date', $year)->whereMonth('date', $month)->orderBy('id', 'DESC');
        $total1 = count($dailyQuanStatus1->get());
        $total2 = count($dailyQuanStatus2->get());
        $total3 = count($dailyQuanStatus3->get());
        $total6 = count($dailyQuanStatus6->get());
        $dailyQuanStatus1 = $dailyQuanStatus1->paginate(DailyQuantity::paginate);
        $dailyQuanStatus2 = $dailyQuanStatus2->paginate(DailyQuantity::paginate);
        $dailyQuanStatus3 = $dailyQuanStatus3->paginate(DailyQuantity::paginate);
        $dailyQuanStatus6 = $dailyQuanStatus6->paginate(DailyQuantity::paginate);

        return view('product.detail', compact(
            'product',
            'dailyQuanStatus1',
            'dailyQuanStatus2',
            'dailyQuanStatus3',
            'dailyQuanStatus6',
            'total1',
            'total2',
            'total3',
            'total6',
            'id',
            'listMonth',
            'monthNearly'
        ));
    }

    // update detail
    public function updateDetail(Request $request)
    {
        // Kiểm tra nếu số lượng bằng 0
        if ($request->quantity == 0) {
            toast('Bạn không thể cập nhật sản lượng là 0!', 'error', 'top-right');

            return redirect()->back();
        }

        try {
            $month = Carbon::now()->format('m');
            $monthYear = Carbon::now()->format('m-Y');

            // Tìm bản ghi DailyQuantity theo ID
            $daily = DailyQuantity::find($request->dailyId);
            if (! $daily) {
                toast('Không tìm thấy bản ghi!', 'error', 'top-right');

                return redirect()->back();
            }

            $carbonDate = strtotime($daily->date);
            $monthDaily = date('m', $carbonDate);
            if ($monthDaily == $month) {
                $daily->quantity = $request->quantity;
                $daily->save();

                // Cập nhật số lượng tổng cho ngày
                $totalDaily = TotalDailyQuantity::where('product_id', $request->product_id)
                    ->where('date', $daily->date)
                    ->where('status', $request->status)->first();
                if ($totalDaily != null) {
                    $totalDaily->totalQuan = ($totalDaily->totalQuan - $request->oldQuan) + $request->quantity;
                    $totalDaily->save();
                }

                // Cập nhật số lượng tổng cho tháng
                $totalMonth = TotalMonthQuantity::where('product_id', $request->product_id)
                    ->where('month', $monthYear)
                    ->where('status', $request->status)->first();
                if ($totalMonth != null) {
                    $totalMonth->totalQuan = ($totalMonth->totalQuan - $request->oldQuan) + $request->quantity;
                    $totalMonth->save();
                }

                toast('Cập nhật sản lượng thành công!', 'success', 'top-right');
                LogActivity::logRoleSpecificLoginActivity(auth()->user(), 'Admin Cập Nhật Sản Lượng', 'Admin đã cập nhật sản lượng');
            } else {
                toast('Sản phẩm đã quá thời gian cho phép cập nhật sản lượng!', 'error', 'top-right');
            }

            return redirect()->back();
        } catch (\Exception $e) {
            Log::error('errors'.$e->getMessage().' getLine'.$e->getLine());
            toast('Cập nhật số lượng sản phẩm không thành công!', 'error', 'top-right');

            return redirect()->back();
        }
    }

    // export
    public function export(Request $request)
    {
        return view('product.index');
    }

    // updateQuantity for employee
    public function updateQuantity(Request $request)
    {
        try {
            $userId = Auth()->user()->id;
            $calendar = CelenderDetailHNHC::where('employee_id', $userId)->latest()->first();
            $date = Carbon::now()->format('d');
            $date = $this->convertDate($date);
            $column = 'day'.$date;
            $calendarDetail = $calendar->$column;
            $calendarDetail = $this->translateCalendar($calendarDetail);

            // Lấy bản ghi theo ngày hiện tại hoặc ngày trước nếu là ca 2
            $today = Carbon::now()->toDateString();
            $yesterday = Carbon::now()->subDay()->toDateString(); // Ngày trước

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

                    return $itemDate == $today || ($itemDate == $yesterday && $item->shift == 'Ca 2');
                });

            return view('product.update-quantity', compact('addQuantity', 'calendarDetail'));
        } catch (\Exception $e) {
            toast('Hãy bổ sung lịch làm việc để cập nhật sản lượng!', 'error', 'top-right');

            return redirect()->back();
        }
    }

    public function showUpdateError()
    {
        try {
            // $products = Product::get();
            $userId = Auth()->user()->id;
            $calendar = CelenderDetailHNHC::where('employee_id', $userId)->latest()->first();
            $date = Carbon::now()->format('d');
            $date = $this->convertDate($date);
            $column = 'day'.$date;
            $calendarDetail = $calendar->$column;
            $calendarDetail = $this->translateCalendar($calendarDetail);

            $today = Carbon::now()->toDateString();
            $addQuantityError = CheckEmployee::where('employee_id', $userId)
                ->whereDate('date', $today)
                ->whereHas('product', function ($query) {
                    $query->whereColumn('products.id', 'check_employees.product_id');
                })
                ->get()
                ->filter(function ($item) use ($today) {
                    return Carbon::parse($item->date)->toDateString() == $today;
                });

            return view('product.update-error', compact('calendarDetail', 'addQuantityError'));
        } catch (\Exception $e) {
            toast('Hãy bổ sung lịch làm việc để cập nhật sản lượng!', 'error', 'top-right');

            return redirect()->back();
        }
    }

    // updateQuantity for employee
    public function handleUpdateQuantity(Request $request)
    {
        if ($request->quantity == 0) {
            toast('Bạn không thể nhập sản lượng là 0!', 'error', 'top-right');

            return redirect()->back();
        }

        $employeeId = auth()->user()->id;
        $productId = $request->product_id;

        $checkEmployee = CheckEmployee::where('employee_id', $employeeId)
            ->where('product_id', $productId)
            ->orderBy('date', 'desc')
            ->first();

        if (! $checkEmployee) {
            toast('Nhân viên cần nhập sản phẩm cần kiểm hoặc sản xuất trước khi nhập sản lượng!', 'error', 'top-right');

            return redirect()->back();
        }

        // Kiểm tra created_at có trong vòng 1 giờ so với hiện tại không
        if ($checkEmployee->created_at->diffInMinutes(Carbon::now()) <= 10) {
            toast('Nhân viên nhập sản phẩm hoạt động chưa quá 10 phút. Cần báo cáo cho quản lý để tiếp tục hỗ trợ hoặc có thể nhập lại sau 10 phút.', 'error', 'top-right');

            return redirect()->back();
        }

        $shift = $checkEmployee->shift;
        $date = Carbon::parse($checkEmployee->date);
        $today = Carbon::today();

        // Kiểm tra điều kiện dựa trên ca làm việc
        if (($shift == 'Ca 1' && ! $date->isSameDay($today)) ||
            ($shift == 'Ca 2' && ! ($date->isSameDay($today) || $date->addDay()->isSameDay($today)))
        ) {
            toast('Bạn chỉ có thể nhập sản lượng cho sản phẩm cùng ngày với ngày hiện tại hoặc ngày hôm sau nếu là Ca 2!', 'error', 'top-right');

            return redirect()->back();
        }

        DB::beginTransaction();
        try {
            $status = 0;
            $employeeCode = Auth()->user()->id;
            $categoryCalender = Auth()->user()->category_celender->id;
            if ($employeeCode != '25060400') {
                if ($categoryCalender != 2) {
                    $status = 1;
                } else {
                    $status = 2;
                }
            } else {
                $status = 3;
            }

            if ($status != 0) {
                // $date = Carbon::create(2024, 7, 10)->format('Y-m-d');
                // $month = Carbon::create(2024, 7, 10)->format('m-Y');
                $date = Carbon::now()->format('Y-m-d');
                $month = Carbon::now()->format('m-Y');
                $currentDateTime = Carbon::now();
                $hour = $currentDateTime->hour;
                // $hour = 14;
                // cắt phần tính tồn -> cronjob;
                if ($status == 1 && $hour < 9) {
                    // nếu là 100% và trước 7h sáng thì trừ đi 1 ngày
                    $subDate = Carbon::now()->subDay()->format('Y-m-d');
                    $dailyQuan = new DailyQuantity;
                    $dailyQuan->product_id = $request->product_id;
                    $dailyQuan->employee_id = Auth()->user()->id;
                    $dailyQuan->quantity = $request->quantity;
                    $dailyQuan->status = $status;
                    $dailyQuan->date = $subDate;

                    // cập nhật dailytotal với subDate
                    $totalDaily = TotalDailyQuantity::where('product_id', $request->product_id)
                        ->where('date', $subDate)
                        ->where('status', $status)->first();
                    if ($totalDaily != null) {
                        $totalDaily->totalQuan = $totalDaily->totalQuan + $request->quantity;
                        $totalDaily->save();
                    } else {
                        $totalDaily = new TotalDailyQuantity;
                        $totalDaily->product_id = $request->product_id;
                        $totalDaily->date = $subDate;
                        $totalDaily->status = $status;
                        $totalDaily->totalQuan = $request->quantity;
                        $totalDaily->save();
                    }

                    $dailyQuan->save();
                    $currentDateTime1 = Carbon::now()->format('d');
                    if ($currentDateTime1 == '1' || $currentDateTime1 == '01') {
                        // nếu là này đầu tháng thì giảm đi 1 tháng để tính tổng tháng
                        $subMonth = Carbon::now()->subMonth()->format('m-Y');

                        // cập nhật monthtotal
                        $totalMonth = TotalMonthQuantity::where('product_id', $request->product_id)
                            ->where('month', $subMonth)
                            ->where('status', $status)->first();

                        if ($totalMonth != null) {
                            $totalMonth->totalQuan = $totalMonth->totalQuan + $request->quantity;
                            $totalMonth->save();
                        } else {
                            $totalMonth = new TotalMonthQuantity;
                            $totalMonth->product_id = $request->product_id;
                            $totalMonth->month = $subMonth;
                            $totalMonth->status = $status;
                            $totalMonth->totalQuan = $request->quantity;
                            $totalMonth->save();
                        }
                    } else {
                        // nếu k phải ngày đầu tháng thì cập nhật tổng tháng như bình thường
                        // cập nhật monthtotal
                        $totalMonth = TotalMonthQuantity::where('product_id', $request->product_id)
                            ->where('month', $month)
                            ->where('status', $status)->first();

                        if ($totalMonth != null) {
                            $totalMonth->totalQuan = $totalMonth->totalQuan + $request->quantity;
                            $totalMonth->save();
                        } else {
                            $totalMonth = new TotalMonthQuantity;
                            $totalMonth->product_id = $request->product_id;
                            $totalMonth->month = $month;
                            $totalMonth->status = $status;
                            $totalMonth->totalQuan = $request->quantity;
                            $totalMonth->save();
                        }
                    }
                } else {
                    // cập nhật daily
                    $dailyQuan = new DailyQuantity;
                    $dailyQuan->product_id = $request->product_id;
                    $dailyQuan->employee_id = Auth()->user()->id;
                    $dailyQuan->quantity = $request->quantity;
                    $dailyQuan->status = $status;
                    $dailyQuan->date = $date;
                    $dailyQuan->save();

                    // cập nhật dailytotal
                    $totalDaily = TotalDailyQuantity::where('product_id', $request->product_id)
                        ->where('date', $date)
                        ->where('status', $status)->first();
                    if ($totalDaily != null) {
                        $totalDaily->totalQuan = $totalDaily->totalQuan + $request->quantity;
                        $totalDaily->save();
                    } else {
                        $totalDaily = new TotalDailyQuantity;
                        $totalDaily->product_id = $request->product_id;
                        $totalDaily->date = $date;
                        $totalDaily->status = $status;
                        $totalDaily->totalQuan = $request->quantity;
                        $totalDaily->save();
                    }

                    // cập nhật monthtotal
                    $totalMonth = TotalMonthQuantity::where('product_id', $request->product_id)
                        ->where('month', $month)
                        ->where('status', $status)->first();
                    if ($totalMonth != null) {
                        $totalMonth->totalQuan = $totalMonth->totalQuan + $request->quantity;
                        $totalMonth->save();
                    } else {
                        $totalMonth = new TotalMonthQuantity;
                        $totalMonth->product_id = $request->product_id;
                        $totalMonth->month = $month;
                        $totalMonth->status = $status;
                        $totalMonth->totalQuan = $request->quantity;
                        $totalMonth->save();
                    }
                }

                DB::commit();
            } else {
                toast('Bạn không có quyền!', 'error', 'top-right');

                return redirect()->back();
            }
            toast('Cập nhật số lượng thành công!', 'success', 'top-right');
            LogActivity::logViewActivity(auth()->user(), 'Nhập Sản Lượng', 'Nhân viên nhập sản lượng');

            return redirect()->back();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('errors'.$e->getMessage().' getLine'.$e->getLine());
            toast('Cập nhật số lượng sản phẩm không thành công!', 'error', 'top-right');

            return redirect()->back();
        }
    }

    // handle update error
    public function handleUpdateError(Request $request)
    {
        // Kiểm tra điều kiện đầu vào
        if ($request->quantity == 0) {
            toast('Số lượng sản phẩm lỗi không thể là 0!', 'error', 'top-right');

            return redirect()->back();
        }

        DB::beginTransaction();
        try {
            $date = Carbon::now()->format('Y-m-d');
            $month = Carbon::now()->format('m-Y');
            $employeeId = Auth::user()->id;
            $status = 6; // Định nghĩa status = 6 hàng lỗi

            //  Thêm DailyQuantity
            $dailyQuan = new DailyQuantity;
            $dailyQuan->product_id = $request->product_id;
            $dailyQuan->employee_id = $employeeId;
            $dailyQuan->quantity = $request->quantity;
            $dailyQuan->status = $status;
            $dailyQuan->date = $date;
            $dailyQuan->save();

            // Cập nhật TotalDailyQuantity
            $totalDaily = TotalDailyQuantity::firstOrNew(
                ['product_id' => $request->product_id, 'date' => $date, 'status' => $status],
                ['totalQuan' => 0]
            );
            $totalDaily->totalQuan += $request->quantity;
            $totalDaily->save();

            // Cập nhật TotalMonthQuantity
            $totalMonth = TotalMonthQuantity::firstOrNew(
                ['product_id' => $request->product_id, 'month' => $month, 'status' => $status],
                ['totalQuan' => 0]
            );
            $totalMonth->totalQuan += $request->quantity;
            $totalMonth->save();

            DB::commit();
            toast('Cập nhật thông tin lỗi sản phẩm thành công!', 'success', 'top-right');
            LogActivity::logViewActivity(auth()->user(), 'Nhập Sản Lượng Lỗi', 'Nhân viên nhập sản lượng lỗi');

            return redirect()->back();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating product error info: '.$e->getMessage());
            toast('Cập nhật thông tin lỗi sản phẩm không thành công!', 'error', 'top-right');

            return redirect()->back();
        }
    }

    // export product
    public function exportProduct(Request $request)
    {
        $time = $request->month;

        return Excel::download(new ExportMultiSheets($time), 'コピーFAVV_REQ_-TON-KHO-THANG-'.$time.'.xlsx', \Maatwebsite\Excel\Excel::XLSX, [
            'Content-Type' => 'text/xlsx',
        ]);
    }

    // history update quantity
    public function historyUpdate(Request $request)
    {
        $listMonth = TotalMonthQuantity::distinct()->pluck('month');
        $productsId = DailyQuantity::where('employee_id', Auth()->user()->id)
            ->whereIn('status', [1, 2])
            ->distinct()
            ->pluck('product_id');
        $listProduct = Product::whereIn('id', $productsId)->get();
        $monthNearly = Carbon::now()->format('m-Y');
        $product_id = '';
        if ($request->month) {
            $monthNearly = $request->month;
        }
        if ($request->product_id) {
            $product_id = $request->product_id;
        }
        [$month, $year] = explode('-', $monthNearly);
        if ($product_id != '') {
            $datas = DailyQuantity::where('employee_id', Auth()->user()->id)
                ->where('product_id', $product_id)
                ->whereIn('status', [1, 2])
                ->whereYear('date', $year)
                ->whereMonth('date', $month)
                ->get();
        } else {
            $datas = [];
        }

        // Lọc theo monthNearly
        if ($monthNearly) {
            $listProduct = DailyQuantity::where('employee_id', Auth()->user()->id)
                ->whereYear('date', $year)
                ->whereMonth('date', $month)
                ->distinct()
                ->pluck('product_id');
            $listProduct = Product::whereIn('id', $listProduct)->get();
        }

        return view('product.history-update', compact('listMonth', 'monthNearly', 'datas', 'listProduct', 'product_id'));
    }

    // history update error
    public function historyUpdateError(Request $request)
    {
        $listMonth = TotalMonthQuantity::distinct()->pluck('month');
        $productsId = DailyQuantity::where('employee_id', Auth()->user()->id)
            ->where('status', 6)
            ->distinct()
            ->pluck('product_id');
        $listProduct = Product::whereIn('id', $productsId)->get();

        $monthNearly = $request->month ?: Carbon::now()->format('m-Y');
        $product_id = $request->product_id ?: '';
        $datas = collect();

        if ($request->has('month') && $request->has('product_id')) {
            [$month, $year] = explode('-', $monthNearly);
            $datas = DailyQuantity::where('employee_id', Auth()->user()->id)
                ->where('product_id', $product_id)
                ->where('status', 6)
                ->whereYear('date', $year)
                ->whereMonth('date', $month)
                ->get();
        } else {
            [$month, $year] = explode('-', $monthNearly);
        }

        // Lọc theo monthNearly
        if ($monthNearly) {
            $productsId = DailyQuantity::where('employee_id', Auth()->user()->id)
                ->where('status', 6)
                ->whereYear('date', $year)
                ->whereMonth('date', $month)
                ->distinct()
                ->pluck('product_id');
            $listProduct = Product::whereIn('id', $productsId)->get();
        }

        return view('product.history-update-error', compact('listMonth', 'monthNearly', 'datas', 'listProduct', 'product_id'));
    }

    // view cập nhật sản lượng admin
    public function updateQuantityAdmin($id)
    {
        $product = Product::find($id);

        return view('product.update-quantity-admin', compact('product'));
    }

    // cập nhật sản lượng view product/update-quantity
    public function handleUpdate(Request $request)
    {
        DB::beginTransaction();
        try {
            $date = Carbon::now()->format('Y-m-d');
            $month = Carbon::now()->format('m-Y');
            // cập nhật daily
            $dailyQuan = new DailyQuantity;
            $dailyQuan->product_id = $request->product_id;
            $dailyQuan->employee_id = Auth()->user()->id;
            $dailyQuan->quantity = $request->quantity;
            $dailyQuan->status = $request->status;
            $dailyQuan->date = $request->date;
            $dailyQuan->save();

            // cập nhật dailytotal
            $totalDaily = TotalDailyQuantity::where('product_id', $request->product_id)
                ->where('date', $request->date)
                ->where('status', $request->status)->first();
            if ($totalDaily != null) {
                $totalDaily->totalQuan = $totalDaily->totalQuan + $request->quantity;
                $totalDaily->save();
            } else {
                $totalDaily = new TotalDailyQuantity;
                $totalDaily->product_id = $request->product_id;
                $totalDaily->date = $request->date;
                $totalDaily->status = $request->status;
                $totalDaily->totalQuan = $request->quantity;
                $totalDaily->save();
            }

            // cập nhật monthtotal
            $totalMonth = TotalMonthQuantity::where('product_id', $request->product_id)
                ->where('month', $month)
                ->where('status', $request->status)->first();
            if ($totalMonth != null) {
                $totalMonth->totalQuan = $totalMonth->totalQuan + $request->quantity;
                $totalMonth->save();
            } else {
                $totalMonth = new TotalMonthQuantity;
                $totalMonth->product_id = $request->product_id;
                $totalMonth->month = $month;
                $totalMonth->status = $request->status;
                $totalMonth->totalQuan = $request->quantity;
                $totalMonth->save();
            }

            DB::commit();
            toast('Cập nhật số lượng thành công!', 'success', 'top-right');
            LogActivity::logRoleSpecificLoginActivity(auth()->user(), 'Admin Thêm Sản Lượng ', 'Admin đã cập nhật số lượng tất cả loại hàng');

            return redirect()->back();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('errors'.$e->getMessage().' getLine'.$e->getLine());
            toast('Cập nhật số lượng sản phẩm không thành công!', 'error', 'top-right');

            return redirect()->back();
        }
    }

    // xoá sản lượng
    public function handleDeleteUpdateQUantity($id)
    {
        DB::beginTransaction();
        try {
            $daily = DailyQuantity::where('id', $id)->first();
            if ($daily != null) {
                $month = Carbon::now()->format('m');
                $monthYear = Carbon::now()->format('m-Y');
                $carbonDate = strtotime($daily->date);
                $monthDaily = date('m', $carbonDate);

                if ($monthDaily == $month) {
                    // update quantity total day
                    $totalDaily = TotalDailyQuantity::where('product_id', $daily->product_id)
                        ->where('date', $daily->date)
                        ->where('status', $daily->status)->first();
                    if ($totalDaily != null) {
                        $totalDaily->totalQuan = ($totalDaily->totalQuan - $daily->quantity);
                        $totalDaily->save();
                    }

                    // update quantity total month
                    $totalMonth = TotalMonthQuantity::where('product_id', $daily->product_id)
                        ->where('month', $monthYear)
                        ->where('status', $daily->status)->first();
                    if ($totalMonth != null) {
                        $totalMonth->totalQuan = ($totalMonth->totalQuan - $daily->quantity);
                        $totalMonth->save();
                    }

                    $daily->delete();
                    DB::commit();
                    LogActivity::logRoleSpecificLoginActivity(auth()->user(), 'Admin Sửa Xoá Sản Lượng', 'Admin đã xoá sản lượng');
                    toast('Xoá sản lượng thành công!', 'success', 'top-right');
                } else {
                    toast('Sản phẩm đã quá thời gian cho phép xoá sản lượng!', 'error', 'top-right');
                }

                return redirect()->back();
            }

            return redirect()->back();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('errors'.$e->getMessage().' getLine'.$e->getLine());
            toast('Xoá sản lượng không thành công!', 'error', 'top-right');

            return redirect()->back();
        }
    }

    // view thay đổi MOQ
    public function updateMOQ()
    {
        // Lấy tháng hiện tại
        $currentMonth = Carbon::now()->format('m-Y');
        $listMonth = TotalMonthQuantity::distinct()->pluck('month');

        // Lấy danh sách tất cả các product_id
        $productIds = TotalMonthQuantity::distinct('product_id')->pluck('product_id');

        // Khởi tạo mảng chứa dữ liệu gần nhất cho mỗi sản phẩm

        $productNearData = [];
        foreach ($productIds as $productId) {
            // Lấy dữ liệu gần nhất cho mỗi sản phẩm theo tháng
            $stockQuanNearly = TotalMonthQuantity::where('product_id', $productId)->where('status', 4)->where('month', $currentMonth)->value('totalQuan');
            $stockQuan200Nearly = TotalMonthQuantity::where('product_id', $productId)->where('status', 5)->where('month', $currentMonth)->value('totalQuan');
            $stockQuanMOQNearly = TotalMonthQuantity::where('product_id', $productId)->where('status', 7)->where('month', $currentMonth)->value('totalQuan');

            // Lưu dữ liệu gần nhất vào mảng productNearData cho mỗi sản phẩm
            $productNearData[$productId] = [
                'stockQuanNearly' => $stockQuanNearly,
                'stockQuan200Nearly' => $stockQuan200Nearly,
                'stockQuanMOQNearly' => $stockQuanMOQNearly,
            ];
            // Kiểm tra dữ liệu của product_id = 4
            // if ($productId == 4) {
            //     dd($productNearData[$productId]);
            // }
        }

        // Lấy danh sách tất cả sản phẩm
        $products = Product::select('id', 'name')->get();

        return view('product.update-moq', compact('listMonth', 'currentMonth', 'productNearData', 'products'));
    }

    // handle thay đổi moq
    public function handleUpdateMOQ(Request $request)
    {
        DB::beginTransaction();
        try {
            $month = $request->month;
            $listProductId = $request->productId;
            $listQuantity = $request->quantity;
            $status = $request->status;

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

            DB::commit();
            toast('Cập nhật số lượng thành công!', 'success', 'top-right');

            return redirect()->route('admin.product.home');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error: '.$e->getMessage().' at Line '.$e->getLine());
            toast('Cập nhật số lượng sản phẩm không thành công!', 'error', 'top-right');

            return redirect()->back();
        }
    }

    public function viewUpdateQuantityAdmin()
    {
        $monthNearly = Carbon::now()->format('m-Y');
        $listDate = $this->handleDayInMonth($monthNearly);
        $currentDate = Carbon::now()->format('d-m-Y');
        $products = Product::select('id', 'name')->get();

        return view('product.add-quantity-admin', compact('listDate', 'currentDate', 'products'));
    }

    public function handleUpdateQuantityAdmin(Request $request)
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

            return redirect()->route('admin.product.home');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('errors'.$e->getMessage().' getLine'.$e->getLine());
            toast('Cập nhật số lượng sản phẩm không thành công!', 'error', 'top-right');

            return redirect()->back();
        }
    }
}
