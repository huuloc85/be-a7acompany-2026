<?php

use App\Http\Controllers\Api\Admin\AdminRequestFormController;
use App\Http\Controllers\Api\Admin\ApiRegistryController;
use App\Http\Controllers\Api\Admin\AttendanceCalculationController;
use App\Http\Controllers\Api\Admin\AttendanceHistoryController;
use App\Http\Controllers\Api\Admin\AttendanceRecordController;
use App\Http\Controllers\Api\Admin\AuthController;
use App\Http\Controllers\Api\Admin\CheckPoController;
use App\Http\Controllers\Api\Admin\CheckStampController;
use App\Http\Controllers\Api\Admin\DailyScheduleController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\EmployeeController;
use App\Http\Controllers\Api\Admin\FeedbackController;
use App\Http\Controllers\Api\Admin\HistoryController;
use App\Http\Controllers\Api\Admin\LogController;
use App\Http\Controllers\Api\Admin\PermissionController;
use App\Http\Controllers\Api\Admin\ProductController;
use App\Http\Controllers\Api\Admin\QuantityAttendanceController;
use App\Http\Controllers\Api\Admin\RBACController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\SalaryController;
use App\Http\Controllers\Api\Admin\ScheduleCategoryController;
use App\Http\Controllers\Api\Admin\ScheduleController;
use App\Http\Controllers\Api\Admin\ScheduleDetailController;
use App\Http\Controllers\Api\Admin\StampController;
use App\Http\Controllers\Api\Admin\TotalQuantityController;
use App\Http\Controllers\Api\Employee\EmpAttendanceController;
use App\Http\Controllers\Api\Employee\EmpDailyActivity;
use App\Http\Controllers\Api\Employee\EmpFeedbackController;
use App\Http\Controllers\Api\Employee\EmpRequestFormController;
use App\Http\Controllers\Api\Employee\EmpSalaryController;
use App\Http\Controllers\Api\Employee\EmpScheduleDetailController;
use App\Http\Controllers\Api\Employee\EmpStampController;
use App\Http\Controllers\Api\Employee\EmpTodoController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\StockTransactionController;
use App\Http\Controllers\Api\UploadController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
Route::post('/updateDataCC', [App\Http\Controllers\AttendanceRecordController::class, 'updateDataCC'])->name('updateDataCC');

// Attendance Records (Chấm Công)
Route::get('/acs-events/today', [AttendanceRecordController::class, 'fetchTodayEvents']);

// Đăng nhập
Route::post('/login', [AuthController::class, 'authLogin'])->name('auth.login');
Route::post('/logout', [AuthController::class, 'authLogout'])->name('auth.logout');

Route::middleware(['auth:sanctum', 'check.token.expiration'])->group(function () {
    if (connection_aborted()) {
        Log::info('Request aborted early.');

        return;
    }
    Route::post('/auth/check', [AuthController::class, 'authCheck']);
    Route::post('/auth/confirm-login', [AuthController::class, 'authConfirmLogin']);
    Route::get('/birthday', [AuthController::class, 'getBirthdayEmployees']);

    // Login, Dashboard, Change Profile (Quản Lý Đăng Nhập và Trang Chủ)
    Route::middleware(['api.can:view_total_employees'])->prefix('admin')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index']);
    });

    Route::prefix('profile')->group(function () {
        Route::get('/', [AuthController::class, 'authProfile']);
        Route::patch('/', [AuthController::class, 'authChangeProfile']);
        Route::patch('/change-password', [AuthController::class, 'changePassword']);
        Route::post('/change-avatar', [AuthController::class, 'changeAvatar']);
        Route::middleware(['api.can:view_employee_management'])->put('/reset-password/{id}', [AuthController::class, 'resetPassword']);
    });

    // Employees (Quản Lý Nhân Sự)
    Route::middleware(['api.can:view_employee_management'])->prefix('employees')->group(function () {
        Route::post('/{id}/attendance-device', [\App\Http\Controllers\Api\Admin\AttendanceDeviceEmployeeController::class, 'store']);
        Route::get('/{id}/attendance-device', [\App\Http\Controllers\Api\Admin\AttendanceDeviceEmployeeController::class, 'show']);
        Route::get('/trash', [EmployeeController::class, 'getTrashEmployees']);
        Route::post('/restore/{id}', [EmployeeController::class, 'restoreEmployee']);
        Route::delete('/trash/force-delete-all', [EmployeeController::class, 'forceDeleteAllTrash']); // Xoá vĩnh viễn tất cả quá hạn
        Route::delete('/force-delete/{id}', [EmployeeController::class, 'forceDeleteEmployee']); // Xoá vĩnh viễn 1 nhân viên
        Route::get('/', [EmployeeController::class, 'getEmployees']);
        Route::get('/{id}', [EmployeeController::class, 'getEmployee']);
        Route::post('/', [EmployeeController::class, 'addEmployee']);
        Route::patch('/{id}', [EmployeeController::class, 'updateEmployee']);
        Route::delete('/{id}', [EmployeeController::class, 'removeEmployee']);
    });

    // Request Forms Management (Quản Lý Đơn Yêu Cầu)
    Route::middleware(['api.can:view_employee_management'])->prefix('request-forms')->group(function () {
        Route::get('/', [AdminRequestFormController::class, 'index']);  // Xem tất cả đơn
        Route::get('/statistics', [AdminRequestFormController::class, 'getStatistics']); // Thống kê
        Route::get('/{id}', [AdminRequestFormController::class, 'show']); // Xem chi tiết đơn
        Route::post('/{id}/approve', [AdminRequestFormController::class, 'approve']); // Duyệt/từ chối đơn
    });

    // Attendance Records (Quản Lý Chấm Công)
    Route::middleware(['api.can:view_attendance'])->prefix('attendances')->group(function () {
        Route::get('/device-imports', [\App\Http\Controllers\Api\Admin\AttendanceImportController::class, 'index']);
        Route::post('/device-imports', [\App\Http\Controllers\Api\Admin\AttendanceImportController::class, 'store'])->middleware('throttle:10,1');
        Route::prefix('history')->group(function () {
            Route::get('/', [AttendanceHistoryController::class, 'index']);
            Route::get('/{id}', [AttendanceHistoryController::class, 'show']);
            Route::post('/', [AttendanceHistoryController::class, 'store']);
            Route::patch('/{id}', [AttendanceHistoryController::class, 'update']);
            Route::delete('/{id}', [AttendanceHistoryController::class, 'delete']);
            // Route::post('/import', [AttendanceController::class, 'import']);
            // Route::get('/export/{id}', [AttendanceController::class, 'exportById']);
        });
        Route::get('/quantity-report/employees', [QuantityAttendanceController::class, 'employees']);
        Route::get('/quantity-report', [QuantityAttendanceController::class, 'index']);
        Route::get('/calculate', [AttendanceCalculationController::class, 'calculate']);
        Route::get('/', [AttendanceHistoryController::class, 'detail']);
    });

    // Roles (Quản Lý Chức Vụ)
    Route::middleware(['api.can:view_total_positions'])->prefix('roles')->group(function () {
        Route::get('/', [RoleController::class, 'getRoles']);
        Route::get('/{id}', [RoleController::class, 'getRole']);
        Route::post('/', [RoleController::class, 'addRole']);
        Route::patch('/{id}', [RoleController::class, 'updateRole']);
        Route::delete('/{id}', [RoleController::class, 'deleteRole']);
    });

    // Logs (Quản Lý Logs)
    Route::middleware(['api.can:view_history'])->prefix('logs')->group(function () {
        Route::get('/', [LogController::class, 'index']);
        Route::delete('/{id}', [LogController::class, 'delete']);
        Route::post('/delete-all', [LogController::class, 'deleteAll']);

        // System Logs from laravel.log
        Route::get('/system', [LogController::class, 'getSystemLogs']);
        Route::delete('/system/clear', [LogController::class, 'clearSystemLogs']);
    });

    Route::middleware(['api.can:view_salary_total'])->prefix('salaries')->group(function () {
        Route::get('/', [SalaryController::class, 'getSalaries']);
        Route::post('/', [SalaryController::class, 'addSalary']);
        Route::get('/{id}', [SalaryController::class, 'getSalary']);
        Route::delete('/{id}', [SalaryController::class, 'deleteSalary']);
    });

    Route::middleware(['api.can:view_schedule_categories'])->prefix('schedule-categories')->group(function () {
        Route::get('/', [ScheduleCategoryController::class, 'index']);   // Lấy danh sách danh mục
        Route::post('/', [ScheduleCategoryController::class, 'store']);  // Thêm mới danh mục
        Route::get('/{id}', [ScheduleCategoryController::class, 'show']);  // Lấy chi tiết danh mục
        Route::patch('/{id}', [ScheduleCategoryController::class, 'update']); // Cập nhật danh mục
        Route::delete('/{id}', [ScheduleCategoryController::class, 'delete']); // Xóa danh mục
    });

    Route::middleware(['api.can:view_schedule'])->prefix('schedules')->group(function () {
        Route::prefix('detail')->group(function () {
            Route::get('/', [ScheduleDetailController::class, 'index']);
            //     Route::get('/{id}', [ScheduleDetailController::class, 'detail']);
            //     Route::post('/', [ScheduleDetailController::class, 'store']);
            //     Route::get('/{id}', [ScheduleDetailController::class, 'show']);
            //     Route::patch('/{id}', [ScheduleDetailController::class, 'update']);
            //     Route::delete('/{id}', [ScheduleDetailController::class, 'destroy']);
        });
        Route::get('/', [ScheduleController::class, 'index']);
        Route::post('/', [ScheduleController::class, 'create']);
        Route::get('/{id}', [ScheduleController::class, 'show']);
        Route::delete('/{id}', [ScheduleController::class, 'delete']);
    });

    Route::prefix('products')->group(function () {
        Route::get('/month-list', [ProductController::class, 'getMonthList']);
        Route::middleware(['api.can:view_products'])->get('/trash', [ProductController::class, 'getTrashProducts']);
        Route::middleware(['api.can:view_products'])->post('/restore/{id}', [ProductController::class, 'restoreProduct']);
        Route::middleware(['api.can:view_products'])->delete('/force-delete/{id}', [ProductController::class, 'forceDeleteProduct']);
        Route::get('/', [ProductController::class, 'getProducts']);
        Route::middleware(['api.can:view_products'])->post('/', [ProductController::class, 'addProduct']);
        Route::get('/{id}', [ProductController::class, 'getProduct']);
        Route::middleware(['api.can:view_products'])->patch('/{id}', [ProductController::class, 'updateProduct']);
        Route::middleware(['api.can:view_products'])->delete('/{id}', [ProductController::class, 'deleteProduct']);
        Route::middleware(['api.can:view_products'])->get('/detail/{id}', [ProductController::class, 'detailProduct']);
        Route::middleware(['api.can:view_products'])->put('/detail', [ProductController::class, 'updateDetailProduct']);
        Route::middleware(['api.can:view_products'])->delete('/detail/{id}', [ProductController::class, 'deleteDetailProduct']);
        Route::middleware(['api.can:view_products'])->put('/addQuantityDetail', [ProductController::class, 'addQuantityDetailProduct']);
    });

    Route::middleware(['api.can:view_products'])->prefix('quantities')->group(function () {
        Route::post('/addList', [TotalQuantityController::class, 'addProductsQuantity']);
        Route::prefix('monthly')->group(function () {
            Route::patch('/updateList', [TotalQuantityController::class, 'updateMonthQuantities']);
            Route::get('/', [TotalQuantityController::class, 'getMonthly']);
            Route::patch('/{id}', [TotalQuantityController::class, 'updateMonthQuantity']);
        });
        // TODO: Implement more if needed
        // Route::post('/', [TotalQuantityController::class, 'store']);
        // Route::get('/{id}', [TotalQuantityController::class, 'show']);
        // Route::delete('/{id}', [TotalQuantityController::class, 'delete']);
    });

    Route::middleware(['api.can:view_label_management'])->prefix('stamps')->group(function () {
        Route::post('/check-duplicate', [StampController::class, 'checkDuplicate']);
        Route::put('/savePrint', [StampController::class, 'savePrint']);
        Route::get('/history', [StampController::class, 'getStampHistory']);
        Route::middleware(['api.permission'])->delete('/history/{id}', [StampController::class, 'deleteStampHistory']);
        Route::post('/reject/{id}', [StampController::class, 'rejectPrint']);
    });

    Route::middleware(['api.can:view_schedule'])->prefix('daily-schedules')->group(function () {
        Route::get('/', [DailyScheduleController::class, 'index']);
        // Route::post('/', [DailyScheduleController::class, 'store']);
        Route::get('/{id}', [DailyScheduleController::class, 'show']);
        Route::patch('/{id}', [DailyScheduleController::class, 'update']);
        Route::delete('/{id}', [DailyScheduleController::class, 'destroy']);
    });

    Route::middleware(['api.can:view_history'])->prefix('history')->group(function () {
        Route::get('/', [HistoryController::class, 'index'])->name('api.history.index');
        Route::delete('/{id}', [HistoryController::class, 'destroy'])->name('api.history.destroy');
        Route::get('/view-log-all-quantity', [HistoryController::class, 'viewLogAllQuantity']);
    });

    Route::middleware(['api.can:view_labels_to_print'])->get('/check-stamp', [CheckStampController::class, 'index']);

    // EMPLOYEE ROUTES
    Route::middleware(['api.authEmployees'])->prefix('employee')->group(function () {
        Route::prefix('schedules')->group(function () {
            Route::get('/details', [EmpScheduleDetailController::class, 'showByDateRange']);
            Route::get('/', [ScheduleController::class, 'index']);
            Route::get('/{id}', [EmpScheduleDetailController::class, 'show']);
        });

        Route::prefix('salaries')->group(function () {
            Route::get('/', [EmpSalaryController::class, 'empSalaries']);
            Route::get('/{id}', [EmpSalaryController::class, 'empSalary']);
        });

        Route::prefix('attendances')->group(function () {
            Route::get('/history', [EmpAttendanceController::class, 'history']);
            // Route::get('/calculate', ...) → Now merged into index with ?include_calculation=1
            Route::get('/', [EmpAttendanceController::class, 'index']);
            // Route::get('/{id}', [EmpAttendance::class, 'show']);
            // Route::patch('/{id}', [EmpAttendance::class, 'update']);
        });

        Route::prefix('daily-activities')->group(function () {
            Route::get('/', [EmpDailyActivity::class, 'index']);
            Route::get('/{id}', [EmpDailyActivity::class, 'show']);
            Route::patch('/{id}', [EmpDailyActivity::class, 'update']);
            Route::delete('/{id}', [EmpDailyActivity::class, 'destroy']);
        });

        Route::prefix('todos')->group(function () {
            Route::prefix('quantity')->group(function () {
                Route::put('/', [EmpTodoController::class, 'handleUpdateQuantity']);
                Route::put('/error', [EmpTodoController::class, 'handleUpdateError']);
            });
            Route::get('/', [EmpTodoController::class, 'index']);
            Route::post('/', [EmpTodoController::class, 'store']);
            Route::get('/history', [EmpTodoController::class, 'history']);
        });

        Route::prefix('request-forms')->group(function () {
            Route::get('/', [EmpRequestFormController::class, 'index']);
            Route::get('/as-supervisor', [EmpRequestFormController::class, 'getAsSupervisor']);
            Route::get('/as-supervisor/{id}', [EmpRequestFormController::class, 'getDetailAsSupervisor']);
            Route::post('/as-supervisor/{id}/approve', [EmpRequestFormController::class, 'approveBySupervisor']);
            Route::post('/as-supervisor/{id}/reject', [EmpRequestFormController::class, 'rejectBySupervisor']);
            Route::post('/', [EmpRequestFormController::class, 'store']);
            Route::get('/types', [EmpRequestFormController::class, 'getFormTypes']);
            Route::get('/signature-fields', [EmpRequestFormController::class, 'getSignatureFields']);
            Route::get('/authorizable-employees', [EmpRequestFormController::class, 'getAuthorizableEmployees']);
            Route::get('/authorized-to-me', [EmpRequestFormController::class, 'getAuthorizedToMe']);
            Route::post('/{id}/sign-delegation', [EmpRequestFormController::class, 'signDelegation']);
            Route::post('/{id}/approve-as-authorized', [EmpRequestFormController::class, 'approveAsAuthorized']);
            Route::post('/{id}/reject-as-authorized', [EmpRequestFormController::class, 'rejectAsAuthorized']);
            Route::get('/{id}', [EmpRequestFormController::class, 'show']);
            Route::put('/{id}', [EmpRequestFormController::class, 'update']);
            Route::delete('/{id}', [EmpRequestFormController::class, 'destroy']);
        });

        Route::prefix('stamps')->group(function () {
            Route::post('/check-duplicate', [EmpStampController::class, 'checkDuplicate']);
            Route::post('/request', [EmpStampController::class, 'requestStamp']);
            Route::get('/history', [EmpStampController::class, 'getStampHistory']);
        });

        // Employee gửi góp ý
        Route::prefix('feedbacks')->group(function () {
            Route::get('/', [EmpFeedbackController::class, 'index']);       // Xem góp ý của mình
            Route::post('/', [EmpFeedbackController::class, 'store']);      // Gửi góp ý mới
            Route::get('/{id}', [EmpFeedbackController::class, 'show']);    // Xem chi tiết
            Route::delete('/{id}', [EmpFeedbackController::class, 'destroy']); // Xóa góp ý pending
        });
    });

    Route::middleware('api.can:view_po_list')->prefix('check-po')->group(function () {
        // Route::get('/', [CheckPoController::class, 'index']);
        // Route::get('/{id}', [CheckPoController::class, 'show']);
        Route::post('/export', [CheckPoController::class, 'addPoExport']);
        Route::post('/import', [CheckPoController::class, 'addPoImport']);
        Route::post('/inventory', [CheckPoController::class, 'addStockQuantityInventory']);
        Route::get('/history', [CheckPoController::class, 'getPoHistory']);
        Route::put('/', [CheckPoController::class, 'updatePO']);
        Route::delete('/all', [CheckPoController::class, 'deleteAll']);
        Route::delete('/batch/{batchId}', [CheckPoController::class, 'deleteBatch']);
        Route::delete('/{id}', [CheckPoController::class, 'deletePO']);
    });

    Route::prefix('upload')->group(function () {
        Route::prefix('images')->group(function () {
            Route::get('/', [UploadController::class, 'getImages']);
            Route::get('/{id}', [UploadController::class, 'getImage']);
            Route::middleware('api.can:view_employee_management')->post('/', [UploadController::class, 'uploadImage']);
            Route::middleware('api.can:view_employee_management')->delete('/{id}', [UploadController::class, 'deleteImage']);
            Route::middleware('api.can:view_employee_management')->patch('/{id}', [UploadController::class, 'updateImage']);
        });
    });

    Route::prefix('notifications')->group(function () {
        Route::middleware('api.can:view_employee_management')->get('/trashed', [NotificationController::class, 'trashed']);
        Route::middleware('api.can:view_employee_management')->post('/restore/{id}', [NotificationController::class, 'restore']);
        Route::middleware('api.can:view_employee_management')->delete('/force-delete/{id}', [NotificationController::class, 'forceDelete']);
        Route::get('/', [NotificationController::class, 'index']);
        Route::post('/', [NotificationController::class, 'store']);
        Route::middleware('api.can:view_employee_management')->get('/{id}', [NotificationController::class, 'show']);
        Route::middleware('api.can:view_employee_management')->patch('/{id}', [NotificationController::class, 'update']);
        Route::middleware('api.can:view_employee_management')->delete('/{id}', [NotificationController::class, 'destroy']);
    });

    // Stock Transaction Management (Quản Lý Giao Dịch Kho)
    Route::prefix('stock-transactions')->middleware('api.can:storage_export_product')->group(function () {
        Route::get('/', [StockTransactionController::class, 'index']);
        Route::post('/', [StockTransactionController::class, 'store']);
        Route::get('/statistics', [StockTransactionController::class, 'statistics']);
        Route::get('/current-stock', [StockTransactionController::class, 'currentStock']);
        Route::get('/storage', [StockTransactionController::class, 'storageProduct']);

        Route::post('/scan', [StockTransactionController::class, 'scanBarcode']);
        Route::post('/scan-in', [StockTransactionController::class, 'scanIn']);
        Route::post('/scan-out', [StockTransactionController::class, 'scanOut']);

        Route::get('/storage-product/{storageProductId}', [StockTransactionController::class, 'byStorageProduct']);
        Route::get('/{id}', [StockTransactionController::class, 'show']);
        Route::put('/{id}', [StockTransactionController::class, 'update']);
        Route::delete('/{id}', [StockTransactionController::class, 'destroy']);
    });

    // Feedbacks - Admin nhận và phản hồi góp ý
    Route::middleware(['api.can:view_employee_management'])->prefix('feedbacks')->group(function () {
        Route::get('/', [FeedbackController::class, 'index']);            // Xem tất cả góp ý
        Route::get('/{id}', [FeedbackController::class, 'show']);         // Xem chi tiết
        Route::post('/{id}/reply', [FeedbackController::class, 'reply']); // Phản hồi
        Route::delete('/{id}', [FeedbackController::class, 'destroy']);   // Xóa
    });

    // RBAC Management (Quản Lý Phân Quyền - Chỉ Super Admin)
    Route::middleware(['api.permission'])->prefix('rbac')->group(function () {
        Route::get('/', [RBACController::class, 'index']);                      // Lấy danh sách roles, permissions, mapping
        Route::post('/save', [RBACController::class, 'save']);                  // Lưu phân quyền cho roles
        Route::post('/create-admin-user', [RBACController::class, 'createAdminUser']); // Tạo admin user
        Route::get('/admin-users', [RBACController::class, 'getAdminUsers']);   // Danh sách admin users
        Route::delete('/admin-users/{id}', [RBACController::class, 'deleteAdminUser']); // Xoá admin user
        Route::get('/admin-users/{id}/permissions', [RBACController::class, 'getUserPermissions']); // Lấy quyền của 1 admin user
        Route::post('/admin-users/{id}/permissions', [RBACController::class, 'saveUserPermissions']); // Lưu quyền trực tiếp cho admin user
        Route::get('/roles/{roleId}/permissions', [RBACController::class, 'getRolePermissions']); // Lấy permissions của 1 role
    });

    // Permissions & Sidebar Items Management (Quản Lý Quyền & Menu - Chỉ Super Admin)
    Route::middleware(['api.permission'])->prefix('permissions')->group(function () {
        Route::get('/', [PermissionController::class, 'index']);                // Lấy danh sách permissions
        Route::post('/', [PermissionController::class, 'store']);               // Tạo permission mới
        Route::get('/{id}', [PermissionController::class, 'show']);             // Xem chi tiết permission
        Route::put('/{id}', [PermissionController::class, 'update']);           // Cập nhật permission
        Route::delete('/{id}', [PermissionController::class, 'destroy']);       // Xóa permission

        // Sidebar Items
        Route::get('/sidebar-items/all', [PermissionController::class, 'getSidebarItems']);            // Lấy tất cả sidebar items
        Route::post('/sidebar-items', [PermissionController::class, 'storeSidebarItem']);              // Tạo sidebar item
        Route::patch('/sidebar-items/{sidebarItemId}', [PermissionController::class, 'updateSidebarItem']); // Cập nhật sidebar item
        Route::delete('/sidebar-items/{sidebarItemId}', [PermissionController::class, 'destroySidebarItem']); // Xóa sidebar item
    });

    // API Registry (Danh sách tất cả API routes - Chỉ Super Admin)
    Route::middleware(['api.permission'])->get('/registry', [ApiRegistryController::class, 'index']);
});
