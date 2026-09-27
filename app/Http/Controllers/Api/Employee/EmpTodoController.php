<?php

namespace App\Http\Controllers\Api\Employee;

use App\Helpers\HandleError;
use App\Helpers\LogActivity;
use App\Helpers\Status;
use App\Http\Controllers\Controller;
use App\Models\CheckEmployee;
use App\Models\DailyQuantity;
use App\Models\Product;
use App\Models\TotalDailyQuantity;
use App\Models\TotalMonthQuantity;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class EmpTodoController extends Controller
{
    public function index(Request $request)
    {
        try {
            $userId = Auth()->user()->id;

            // Lấy bản ghi theo ngày hiện tại hoặc ngày trước nếu là ca 2
            $today = Carbon::now()->toDateString();
            $yesterday = Carbon::now()->subDay()->toDateString(); // Ngày trước

            $quantities = CheckEmployee::where('employee_id', $userId)
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
                ->with([
                    'product' => function ($query) {
                        $query->select('id', 'name');
                    },
                ])
                ->get()
                ->filter(function ($item) use ($today, $yesterday) {
                    $itemDate = Carbon::parse($item->date)->toDateString();

                    return $itemDate == $today || ($itemDate == $yesterday && $item->shift == 'Ca 2');
                });

            LogActivity::logViewActivity(auth()->user(), 'Xem Danh Sách Sản Phẩm', 'Nhân viên xem danh sách sản phẩm cần kiểm tra');

            return response()->json([
                'success' => true,
                'data' => $quantities,
            ], 200);
        } catch (\Throwable $th) {
            return HandleError::handle($th);
        }
    }

    public function store(Request $request)
    {
        try {
            $employee = Auth::user();
            $employeeId = $employee->id;
            $roleId = $employee->role_id;
            if (in_array($roleId, [10, 14, 18])) {
                $status = 1;
            } elseif (in_array($roleId, [8, 9, 13, 19])) {
                $status = 2;
            } else {
                $status = null;
            }
            $shiftInput = $request->input('shift');
            // Hỗ trợ cả 2 format: số (1, 2) hoặc chuỗi ("Ca 1", "Ca 2")
            if ($shiftInput === 'Ca 2' || $shiftInput == 2) {
                $shift = 'Ca 2';
            } else {
                $shift = 'Ca 1';
            }
            $date = Carbon::now();

            // Nếu là ca 2 và giờ hiện tại từ trừ một ngày
            if ($shift == 'Ca 2' && $date->hour < 8) {
                $date->subDay();
            }

            // Kiểm tra xem bản ghi đã tồn tại chưa
            $existingRecord = CheckEmployee::where('employee_id', $employeeId)
                ->where('product_id', $request->productId)
                ->where('shift', $shift)
                ->whereDate('date', $date->toDateString())
                ->first();

            if ($existingRecord) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product already exists for the current date and shift!',
                ], 409);
            }

            $checkEmployee = new CheckEmployee;
            $checkEmployee->product_id = $request->productId;
            $checkEmployee->employee_id = $employeeId;
            $checkEmployee->shift = $shift;
            $checkEmployee->date = $date;
            $checkEmployee->status = $status;
            $checkEmployee->save();

            LogActivity::logViewActivity(auth()->user(), 'Thêm Sản Phẩm Hoạt Động', 'Nhân viên thêm sản phẩm vào danh sách hoạt động');

            return response()->json([
                'success' => true,
                'message' => 'Product activity updated successfully!',
                'data' => $checkEmployee,
            ], 201);
        } catch (\Throwable $th) {
            return HandleError::handle($th);
        }
    }

    public function handleUpdateQuantity(Request $request)
    {
        DB::beginTransaction();
        try {
            $validatedData = $request->validate([
                'productId' => 'required|exists:products,id',
                'quantity' => 'required|numeric|min:1',
                'shift' => 'required|in:Ca 1,Ca 2',
            ]);

            $user = Auth()->user();
            $employeeId = $user->id;
            $productId = $validatedData['productId'];
            $quantity = $validatedData['quantity'];
            $shiftFilter = $validatedData['shift'];

            $checkEmployee = CheckEmployee::where('employee_id', $employeeId)
                ->where('product_id', $productId)
                ->where('shift', $shiftFilter)
                ->orderBy('date', 'desc')
                ->orderBy('created_at', 'desc')
                ->first();

            if (! $checkEmployee) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nhân viên cần nhập sản phẩm cần kiểm hoặc sản xuất trước khi nhập sản lượng!',
                ], 404);
            }

            // TODO: ĐỔI LẠI 10 PHÚT TRƯỚC KHI DEPLOY
            // Kiểm tra created_at có trong vòng 1 phút so với hiện tại không (tạm giảm để test)
            if ($checkEmployee->created_at->diffInMinutes(Carbon::now()) <= 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nhân viên nhập sản phẩm hoạt động chưa quá 1 phút. Vui lòng chờ thêm.',
                ], 403);
            }

            $shift = $checkEmployee->shift;
            $date = Carbon::parse($checkEmployee->date);
            $today = Carbon::today();

            // Kiểm tra điều kiện dựa trên ca làm việc
            if (
                ($shift == 'Ca 1' && ! $date->isSameDay($today)) ||
                ($shift == 'Ca 2' && ! ($date->isSameDay($today) || $date->copy()->addDay()->isSameDay($today)))
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bạn chỉ có thể nhập sản lượng cho sản phẩm cùng ngày với ngày hiện tại hoặc ngày hôm sau nếu là Ca 2!',
                ], 403);
            }

            $status = 0;
            $employeeCode = $user->id;
            $categoryCalender = $user->calendarCategory->id;
            if ($employeeCode != '25060400') {
                if ($categoryCalender != 2) {
                    $status = 1;
                } else {
                    $status = 2;
                }
            } else {
                $status = 3;
            }

            if ($status == 0) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Bạn không có quyền!',
                ], 403);
            }

            $date = Carbon::now()->format('Y-m-d');
            $month = Carbon::now()->format('m-Y');
            $currentDateTime = Carbon::now();
            $hour = $currentDateTime->hour;

            if ($status == 1 && $hour < 9) {
                $subDate = Carbon::now()->subDay()->format('Y-m-d');
                $dailyQuan = new DailyQuantity;
                $dailyQuan->product_id = $productId;
                $dailyQuan->employee_id = Auth()->user()->id;
                $dailyQuan->quantity = $quantity;
                $dailyQuan->status = $status;
                $dailyQuan->shift = $shift;
                $dailyQuan->date = $subDate;

                // cập nhật dailytotal với subDate
                $totalDaily = TotalDailyQuantity::where('product_id', $productId)
                    ->where('date', $subDate)
                    ->where('status', $status)->first();
                if ($totalDaily != null) {
                    $totalDaily->totalQuan = $totalDaily->totalQuan + $quantity;
                    $totalDaily->save();
                } else {
                    $totalDaily = new TotalDailyQuantity;
                    $totalDaily->product_id = $productId;
                    $totalDaily->date = $subDate;
                    $totalDaily->status = $status;
                    $totalDaily->totalQuan = $quantity;
                    $totalDaily->save();
                }

                $dailyQuan->save();
                $currentDateTime1 = Carbon::now()->format('d');
                if ($currentDateTime1 == '1' || $currentDateTime1 == '01') {
                    $subMonth = Carbon::now()->subMonth()->format('m-Y');
                    $totalMonth = TotalMonthQuantity::where('product_id', $productId)
                        ->where('month', $subMonth)
                        ->where('status', $status)->first();
                    if ($totalMonth != null) {
                        $totalMonth->totalQuan = $totalMonth->totalQuan + $quantity;
                        $totalMonth->save();
                    } else {
                        $totalMonth = new TotalMonthQuantity;
                        $totalMonth->product_id = $productId;
                        $totalMonth->month = $subMonth;
                        $totalMonth->status = $status;
                        $totalMonth->totalQuan = $quantity;
                        $totalMonth->save();
                    }
                } else {
                    $totalMonth = TotalMonthQuantity::where('product_id', $productId)
                        ->where('month', $month)
                        ->where('status', $status)->first();
                    if ($totalMonth != null) {
                        $totalMonth->totalQuan = $totalMonth->totalQuan + $quantity;
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
            } else {
                $dailyQuan = new DailyQuantity;
                $dailyQuan->product_id = $productId;
                $dailyQuan->employee_id = Auth()->user()->id;
                $dailyQuan->quantity = $quantity;
                $dailyQuan->status = $status;
                $dailyQuan->shift = $shift;
                $dailyQuan->date = $date;
                $dailyQuan->save();

                $totalDaily = TotalDailyQuantity::where('product_id', $productId)
                    ->where('date', $date)
                    ->where('status', $status)->first();
                if ($totalDaily != null) {
                    $totalDaily->totalQuan = $totalDaily->totalQuan + $quantity;
                    $totalDaily->save();
                } else {
                    $totalDaily = new TotalDailyQuantity;
                    $totalDaily->product_id = $productId;
                    $totalDaily->date = $date;
                    $totalDaily->status = $status;
                    $totalDaily->totalQuan = $quantity;
                    $totalDaily->save();
                }

                $totalMonth = TotalMonthQuantity::where('product_id', $productId)
                    ->where('month', $month)
                    ->where('status', $status)->first();
                if ($totalMonth != null) {
                    $totalMonth->totalQuan = $totalMonth->totalQuan + $quantity;
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

            DB::commit();
            LogActivity::logViewActivity(auth()->user(), 'Nhập Sản Lượng', 'Nhân viên nhập sản lượng');

            return response()->json([
                'success' => true,
                'message' => 'Quantity updated successfully!',
            ], 200);
        } catch (\Throwable $th) {
            DB::rollBack();

            return HandleError::handle($th);
        }
    }

    public function handleUpdateError(Request $request)
    {
        // Validate input
        $validatedData = $request->validate([
            'productId' => 'required|exists:products,id',
            'quantity' => 'required|numeric|min:1',
        ]);

        $productId = $validatedData['productId'];
        $quantity = $validatedData['quantity'];

        DB::beginTransaction();
        try {
            $date = Carbon::now()->format('Y-m-d');
            $month = Carbon::now()->format('m-Y');
            $employeeId = Auth::user()->id;
            $status = 6; // Status 6: error product

            // Add DailyQuantity
            $dailyQuan = new DailyQuantity;
            $dailyQuan->product_id = $productId;
            $dailyQuan->employee_id = $employeeId;
            $dailyQuan->quantity = $quantity;
            $dailyQuan->status = $status;
            $dailyQuan->date = $date;
            $dailyQuan->save();

            // Update TotalDailyQuantity
            $totalDaily = TotalDailyQuantity::firstOrNew(
                ['product_id' => $productId, 'date' => $date, 'status' => $status],
                ['totalQuan' => 0]
            );
            $totalDaily->totalQuan += $quantity;
            $totalDaily->save();

            // Update TotalMonthQuantity
            $totalMonth = TotalMonthQuantity::firstOrNew(
                ['product_id' => $productId, 'month' => $month, 'status' => $status],
                ['totalQuan' => 0]
            );
            $totalMonth->totalQuan += $quantity;
            $totalMonth->save();

            DB::commit();
            LogActivity::logViewActivity(auth()->user(), 'Nhập Sản Lượng Lỗi', 'Nhân viên nhập sản luợng lỗi');

            return response()->json([
                'success' => true,
                'message' => 'Product error information updated successfully!',
            ], 200);
        } catch (\Throwable $th) {
            DB::rollBack();

            return HandleError::handle($th);
        }
    }

    public function history(Request $request)
    {
        $validate = $request->validate([
            'month' => 'nullable|date_format:Y-m',
            'productId' => 'nullable|exists:products,id',
        ]);

        $month = $validate['month'] ?? Carbon::now()->format('Y-m');
        $productId = $validate['productId'] ?? null;

        [$year, $month] = explode('-', $month);

        $productsId = DailyQuantity::where('employee_id', Auth()->user()->id)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->distinct()
            ->pluck('product_id');
        $listProduct = Product::whereIn('id', $productsId)->get();

        $histories = DailyQuantity::where('employee_id', Auth()->user()->id)
            ->where('product_id', $productId)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->get();

        LogActivity::logViewActivity(auth()->user(), 'Xem Lịch Sử Sản Lượng', 'Nhân viên xem lịch sử sản lượng theo tháng');

        return response()->json(['products' => $listProduct, 'data' => $histories]);
    }
}
