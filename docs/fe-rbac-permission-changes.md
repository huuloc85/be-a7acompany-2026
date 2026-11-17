# 🔐 RBAC Permission — Tài liệu Frontend

> Tài liệu lịch sử tháng 03/2026. Không dùng hướng dẫn “cùng module là có quyền” bên dưới để kiểm soát truy cập. Xem [kiểm tra RBAC ngày 17/11/2026](RBAC-AUDIT-2026-11-17.md) cho logic và các điểm chưa thống nhất hiện tại.

> **Ngày cập nhật:** 31/03/2026  
> **Backend version:** v2.1

---

## 1. Thay đổi quan trọng

### ❌ Permissions đã bị xóa (trùng lặp)

| Key bị trùng             | ID đã xóa | ID giữ lại |
| ------------------------ | --------- | ---------- |
| `view_account_info`      | ~~64~~    | **28**     |
| `view_employee_schedule` | ~~18~~    | **42**     |
| `view_po_list`           | ~~38~~    | **10**     |
| `view_today_employees`   | ~~35~~    | **6**      |

> ⚠️ Nếu FE đang reference bằng ID, cần cập nhật theo bảng trên.

### ✅ Thêm trường `module` vào Permission

Mỗi permission giờ thuộc 1 `module`. Backend middleware check theo module — user có bất kỳ quyền nào trong cùng module đều truy cập được.

### ✅ TypeScript Interface mới

```typescript
interface Permission {
    id: number
    key: string
    name: string
    type: "admin" | "employee" | "both"
    module: string // ← THÊM MỚI
    display_area: "home" | "sidebar" | "both"
    icon: string | null
    url: string | null
    sort_order: number
}
```

---

## 2. Danh sách Permissions hiện tại (39 records)

### Module: `dashboard` — Trang chủ Admin

| ID  | Key                    | Tên                     | Type  |
| --- | ---------------------- | ----------------------- | ----- |
| 1   | `view_total_employees` | Tổng nhân viên          | admin |
| 4   | `view_total_positions` | Tổng chức vụ            | admin |
| 6   | `view_today_employees` | Nhân viên đang làm việc | admin |
| 7   | `view_total_schedule`  | Tổng lịch làm việc      | admin |
| 8   | `view_total_products`  | Tổng sản phẩm           | admin |
| 9   | `view_total_history`   | Tổng lịch sử            | admin |
| 13  | `view_total_salary`    | Tổng bảng lương         | admin |
| 69  | `view_chart_salary`    | Chart Lương             | admin |
| 70  | `view_chart_product`   | Chart Sản Phẩm          | admin |

### Module: `employee_management` — Quản lý nhân sự

| ID  | Key                        | Tên             | Type  |
| --- | -------------------------- | --------------- | ----- |
| 31  | `view_employee_management` | Quản lý Nhân sự | admin |

### Module: `attendance` — Chấm công

| ID  | Key                           | Tên                   | Type     |
| --- | ----------------------------- | --------------------- | -------- |
| 3   | `view_attendance_calculation` | Chấm Công (tính toán) | admin    |
| 15  | `view_work_time_calc_sheet`   | Chấm công             | employee |
| 37  | `view_attendance`             | Chấm công             | admin    |
| 44  | `employees_view_attendance`   | Chấm Công             | employee |

### Module: `salary` — Bảng lương

| ID  | Key                 | Tên        | Type     |
| --- | ------------------- | ---------- | -------- |
| 17  | `view_salary_sheet` | Bảng lương | employee |
| 43  | `view_salary`       | Bảng lương | employee |
| 71  | `view_salary_total` | Bảng Lương | admin    |

### Module: `schedule` — Lịch làm việc

| ID  | Key                        | Tên                    | Type     |
| --- | -------------------------- | ---------------------- | -------- |
| 16  | `view_work_schedule`       | Lịch làm việc          | employee |
| 40  | `view_schedule`            | Lịch làm việc          | admin    |
| 41  | `view_schedule_categories` | Danh mục lịch làm việc | admin    |
| 42  | `view_employee_schedule`   | Lịch làm việc          | employee |
| 47  | `view_team_schedule`       | Lịch làm việc tổng     | employee |

### Module: `products` — Sản phẩm

| ID  | Key                         | Tên                     | Type     |
| --- | --------------------------- | ----------------------- | -------- |
| 23  | `select_active_product`     | Chọn sản phẩm hoạt động | employee |
| 24  | `view_daily_import_history` | Lịch sử nhập hàng ngày  | employee |
| 32  | `view_products`             | Sản phẩm                | admin    |
| 60  | `employees_select_products` | Sản Phẩm                | employee |

### Module: `labels` — Tem / nhãn

| ID  | Key                      | Tên                  | Type     |
| --- | ------------------------ | -------------------- | -------- |
| 11  | `view_labels_to_print`   | Danh Sách Tem Cần In | both     |
| 25  | `request_label_printing` | Yêu Cầu In Tem       | employee |
| 36  | `view_label_management`  | Quản lý Tem          | both     |
| 46  | `request_label`          | Yêu cầu In tem       | employee |
| 85  | `view_history_print`     | Lịch Sử In Tem       | admin    |

### Module: `history` — Lịch sử

| ID  | Key            | Tên               | Type  |
| --- | -------------- | ----------------- | ----- |
| 73  | `view_history` | Lịch sử trang web | admin |

### Module: `po` — PO

| ID  | Key            | Tên          | Type  |
| --- | -------------- | ------------ | ----- |
| 10  | `view_po_list` | Danh sách PO | admin |

### Module: `storage` — Kho

| ID  | Key                      | Tên           | Type     |
| --- | ------------------------ | ------------- | -------- |
| 49  | `storage_export_product` | Xuất nhập tồn | employee |

### Module: `activity` — Hoạt động

| ID  | Key                     | Tên                       | Type     |
| --- | ----------------------- | ------------------------- | -------- |
| 19  | `view_daily_activities` | Lịch Hoạt Động Trong Ngày | employee |
| 45  | `view_activity_history` | Lịch sử hoạt động         | employee |
| 48  | `view_today_activity`   | Nhân viên đang làm        | employee |

### Module: `account` — Tài khoản

| ID  | Key                 | Tên                 | Type     |
| --- | ------------------- | ------------------- | -------- |
| 28  | `view_account_info` | Thông tin tài khoản | employee |
| 29  | `logout`            | Đăng xuất           | employee |

---

## 3. Cách check quyền ở FE

### Check theo module (khuyến nghị)

```typescript
// Helper function
function hasModule(permissions: Permission[], module: string): boolean {
    return permissions.some((p) => p.module === module)
}

// Sử dụng
hasModule(userPermissions, "schedule") // Có quyền lịch làm việc?
hasModule(userPermissions, "salary") // Có quyền bảng lương?
hasModule(userPermissions, "attendance") // Có quyền chấm công?
hasModule(userPermissions, "products") // Có quyền sản phẩm?
```

### Check theo key cụ thể

```typescript
function hasPermission(permissions: Permission[], key: string): boolean {
    return permissions.some((p) => p.key === key)
}

hasPermission(userPermissions, "view_schedule")
```

### Nhóm sidebar theo module

```typescript
const grouped = userPermissions.reduce(
    (acc, p) => {
        if (!acc[p.module]) acc[p.module] = []
        acc[p.module].push(p)
        return acc
    },
    {} as Record<string, Permission[]>,
)

// grouped.schedule = [view_schedule, view_schedule_categories, ...]
// grouped.salary   = [view_salary_total, view_salary, ...]
```

---

## 4. Pusher Broadcast — Thông báo real-time

### Cấu hình Pusher

```
App Key:   4c79f3c4bd6485b77f25
Cluster:   ap1
Channel:   employee.notifications
```

### Events

| Event name (FE listen) | Khi nào                  | Payload                                     |
| ---------------------- | ------------------------ | ------------------------------------------- |
| `.salary.created`      | Admin thêm bảng lương    | `{ type, id, title, start_date, end_date }` |
| `.schedule.created`    | Admin thêm lịch làm việc | `{ type, id, title, date }`                 |

### Code mẫu

```typescript
const channel = pusher.subscribe("employee.notifications")

channel.bind(
    ".salary.created",
    (data: { type: "salary"; id: number; title: string; start_date: string; end_date: string }) => {
        // Hiển thị notification
    },
)

channel.bind(".schedule.created", (data: { type: "schedule"; id: number; title: string; date: string }) => {
    // Hiển thị notification
})
```

> ⚠️ **Event name phải có dấu `.` ở đầu** — đây là quy tắc Laravel custom broadcast event.
