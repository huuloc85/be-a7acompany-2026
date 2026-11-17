<?php

namespace App\Support;

final class RbacPermissionCatalog
{
    // Chỉ gộp quyền cùng chức năng; không gộp quyền thống kê với quyền quản lý.
    public const ALIASES = [
        'view_work_time_calc_sheet' => 'employees_view_attendance',
        'view_work_schedule' => 'view_employee_schedule',
        'view_salary_sheet' => 'view_salary',
        'request_label_printing' => 'request_label',
    ];

    public const SUPERVISOR_PERMISSION = 'approve_assigned_request_forms';
}
