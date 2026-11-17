<?php

namespace Database\Seeders;

use App\Support\RbacPermissionCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        // Nhóm 1: display_area = home
        $permissions_home = [
            ['key' => 'view_total_employees', 'name' => 'Tổng nhân viên', 'type' => 'admin'],
            ['key' => 'view_attendance_history', 'name' => 'Bảng Lịch Sử Chấm Công', 'type' => 'admin'],
            ['key' => 'view_attendance_calculation', 'name' => 'Bảng Tính Toán Chấm Công', 'type' => 'admin'],
            ['key' => 'view_total_positions', 'name' => 'Tổng chức vụ', 'type' => 'admin'],
            ['key' => 'view_production_plan', 'name' => 'Kế hoạch sản xuất', 'type' => 'admin'],
            ['key' => 'view_today_employees', 'name' => 'Danh sách NV làm việc trong ngày', 'type' => 'admin'],
            ['key' => 'view_total_schedule', 'name' => 'Tổng lịch làm việc', 'type' => 'admin'],
            ['key' => 'view_total_products', 'name' => 'Tổng sản phẩm', 'type' => 'admin'],
            ['key' => 'view_total_history', 'name' => 'Tổng lịch sử', 'type' => 'admin'],
            ['key' => 'view_po_list', 'name' => 'Danh sách PO', 'type' => 'admin'],
            ['key' => 'view_labels_to_print', 'name' => 'Danh Sách Tem Cần In', 'type' => 'both'],
            ['key' => 'view_export_warehouse', 'name' => 'Kho Xuất Hàng', 'type' => 'admin'],
            ['key' => 'view_total_salary', 'name' => 'Tổng bảng lương', 'type' => 'admin'],

            ['key' => 'view_attendance_sheet_history', 'name' => 'Bảng lịch sử chấm công', 'type' => 'employee'],
            ['key' => 'view_work_time_calc_sheet', 'name' => 'Bảng tính công', 'type' => 'employee'],
            ['key' => 'view_work_schedule', 'name' => 'Lịch làm việc', 'type' => 'employee'],
            ['key' => 'view_salary_sheet', 'name' => 'Bảng lương', 'type' => 'employee'],
            ['key' => 'view_employee_schedule', 'name' => 'Lịch làm việc nhân viên', 'type' => 'employee'],
            ['key' => 'view_daily_activities', 'name' => 'Lịch Hoạt Động Trong Ngày', 'type' => 'employee'],
            ['key' => 'scan_barcode', 'name' => 'Quét Barcode', 'type' => 'employee'],
            ['key' => 'scan_qrcode', 'name' => 'Quét QR Code', 'type' => 'employee'],
            ['key' => 'view_exported_warehouse', 'name' => 'Kho Đã Xuất Hàng', 'type' => 'employee'],
            ['key' => 'select_active_product', 'name' => 'Chọn sản phẩm hoạt động', 'type' => 'employee'],
            ['key' => 'view_daily_import_history', 'name' => 'Lịch sử nhập hàng ngày', 'type' => 'employee'],
            ['key' => 'request_label_printing', 'name' => 'Yêu Cầu In Tem', 'type' => 'employee'],
            ['key' => 'create_carton_label', 'name' => 'Tạo Tem Thùng', 'type' => 'employee'],
            ['key' => 'create_bag_label', 'name' => 'Tạo Tem Bịch', 'type' => 'employee'],
            ['key' => 'view_account_info', 'name' => 'Thông tin tài khoản', 'type' => 'both'],
            ['key' => 'logout', 'name' => 'Đăng xuất', 'type' => 'both'],
        ];

        $permissions_home = array_map(function ($item) {
            $item['display_area'] = 'home';

            return $item;
        }, $permissions_home);

        // Nhóm 2: display_area = sidebar
        $permissions_sidebar = [
            // Các key chính
            ['key' => 'view_dashboard', 'name' => 'Xem Trang chủ', 'type' => 'admin'],
            ['key' => 'view_employee_management', 'name' => 'Quản lý Nhân sự', 'type' => 'admin'],
            ['key' => 'view_products', 'name' => 'Xem Sản phẩm', 'type' => 'admin'],
            ['key' => 'view_planning', 'name' => 'Xem Kế hoạch', 'type' => 'admin'],
            ['key' => 'view_storage', 'name' => 'Xem Kho xuất hàng', 'type' => 'both'],
            ['key' => 'view_today_employees', 'name' => 'Xem Lịch hoạt động/ngày', 'type' => 'admin'],
            ['key' => 'view_label_management', 'name' => 'Quản lý Tem', 'type' => 'admin'],
            ['key' => 'view_attendance', 'name' => 'Quản lý Chấm công', 'type' => 'both'],
            ['key' => 'view_po_list', 'name' => 'Xem Danh sách PO', 'type' => 'admin'],
            ['key' => 'view_history', 'name' => 'Xem Lịch sử', 'type' => 'admin'],
            ['key' => 'view_schedule', 'name' => 'Xem Lịch làm việc', 'type' => 'admin'],
            ['key' => 'view_schedule_categories', 'name' => 'Xem Danh mục lịch làm việc', 'type' => 'admin'],
            ['key' => 'view_employee_schedule', 'name' => 'Xem Lịch làm việc nhân viên', 'type' => 'employee'],
            ['key' => 'view_salary', 'name' => 'Xem Bảng lương', 'type' => 'employee'],
            ['key' => 'select_products', 'name' => 'Chọn Sản phẩm', 'type' => 'employee'],
            ['key' => 'view_activity_history', 'name' => 'Xem Lịch sử hoạt động', 'type' => 'employee'],
            ['key' => 'request_label', 'name' => 'Yêu cầu In tem', 'type' => 'employee'],
            ['key' => 'view_team_schedule', 'name' => 'Xem Lịch làm việc nhân viên (trưởng nhóm)', 'type' => 'admin'],
            ['key' => 'view_today_activity', 'name' => 'Xem Lịch hoạt động/ngày (trưởng nhóm)', 'type' => 'admin'],
            ['key' => 'scan_qr', 'name' => 'Quét QR code', 'type' => 'employee'],
            ['key' => 'scan_barcode', 'name' => 'Quét Barcode', 'type' => 'employee'],

            // Các quyền con trong children
            ['key' => 'view_employees', 'name' => 'Xem Danh sách nhân sự', 'type' => 'admin'],
            ['key' => 'view_roles', 'name' => 'Xem Chức vụ', 'type' => 'admin'],
            ['key' => 'view_production_plan', 'name' => 'Xem Kế hoạch sản xuất', 'type' => 'admin'],
            ['key' => 'view_material_plan', 'name' => 'Xem Kế hoạch nguyên liệu', 'type' => 'admin'],
            ['key' => 'create_box_label', 'name' => 'Tạo Tem Thùng', 'type' => 'admin'],
            ['key' => 'create_pack_label', 'name' => 'Tạo Tem Bịch', 'type' => 'admin'],
            ['key' => 'view_label_history', 'name' => 'Xem Lịch sử in tem', 'type' => 'admin'],
            ['key' => 'view_attendance_history', 'name' => 'Xem Lịch sử chấm công', 'type' => 'both'],
            ['key' => 'view_attendance_calculation', 'name' => 'Xem Bảng tính công', 'type' => 'both'],
            ['key' => 'view_employee_attendance', 'name' => 'Xem Lịch sử chấm công (nhân viên)', 'type' => 'employee'],
            ['key' => 'view_employee_attendance_calculation', 'name' => 'Xem Bảng tính công (nhân viên)', 'type' => 'employee'],
        ];

        $permissions_sidebar = array_map(function ($item) {
            $item['display_area'] = 'sidebar';

            return $item;
        }, $permissions_sidebar);

        // Một key là một quyền; vị trí hiển thị không tạo quyền mới.
        $permissions = [];
        foreach (array_merge($permissions_home, $permissions_sidebar) as $permission) {
            $key = RbacPermissionCatalog::ALIASES[$permission['key']] ?? $permission['key'];
            $permission['key'] = $key;
            if (isset($permissions[$key])) {
                $permissions[$key]['display_area'] = 'both';
                if ($permissions[$key]['type'] !== $permission['type']) {
                    $permissions[$key]['type'] = 'both';
                }
            } else {
                $permissions[$key] = $permission;
            }
        }

        // Không ghi đè cấu hình hoặc thay ID của quyền đã được phân cho vai trò.
        foreach ($permissions as $key => $permission) {
            if (! DB::table('permissions')->where('key', $key)->exists()) {
                DB::table('permissions')->insert($permission);
            }
        }
    }
}
