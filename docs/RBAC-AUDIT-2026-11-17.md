# Kiểm tra RBAC — 17/11/2026

> Cập nhật: đã áp dụng chuẩn hóa và migration unique key trực tiếp vào database release theo yêu cầu người dùng ngày 17/11/2026; xem [RBAC-NORMALIZATION-2026-11-17.md](RBAC-NORMALIZATION-2026-11-17.md) để biết kết quả và bản sao lưu. Phạm vi là FE React, API BE và database; Blade/web legacy không thuộc tác vụ. Các số liệu và mục “chưa gộp/chưa chạy” bên dưới ghi nhận giai đoạn kiểm tra ban đầu, trước lần áp dụng này. Chưa triển khai code FE/BE lên host trong tác vụ.

## Phạm vi và dữ liệu đã kiểm tra

- FE: `fe-a7acompany-2026`; BE: `be-a7acompany-2026`.
- Database theo cấu hình BE: `aac91364_a7arelease`. Chỉ truy vấn bằng kết nối đặt `SET SESSION TRANSACTION READ ONLY`.
- 40 bản ghi permissions; không có key trùng, cặp role–permission trùng, key sidebar trùng, tên vai trò đang hoạt động trùng hoặc cặp user–permission vừa grant vừa deny.
- Các nhận xét về database là ảnh chụp lúc kiểm tra, không phải bảo đảm cho những lần ghi tiếp theo.
- Không chạy migration/seeder và không sửa/xóa dữ liệu trên database đang dùng.
- Có nhiều thay đổi chưa commit của người dùng ở cả hai repo; giữ nguyên phần không thuộc RBAC.

## Những bảng cần giữ riêng

| Bảng                       | Trách nhiệm                                                |
| -------------------------- | ---------------------------------------------------------- |
| `roles`                    | Chức vụ/vai trò của nhân viên                              |
| `permissions`              | Mã quyền, nhóm chức năng và cấu hình hiển thị              |
| `role_permission`          | Quyền mặc định theo vai trò                                |
| `user_granted_permissions` | Quyền cấp riêng cho tài khoản                              |
| `user_denied_permissions`  | Quyền chặn riêng cho tài khoản                             |
| `sidebar_items`            | Menu con của một quyền, không phải một bảng cấp quyền khác |

Không gộp bảng cấp và bảng chặn: chúng mang hai ý nghĩa đối lập. `display_area=both` là cách dùng chung một quyền ở Trang chủ và Sidebar. `module` dùng để nhóm, không tự cấp tất cả quyền cùng module. Cùng URL chưa đủ để kết luận cùng nghiệp vụ.

## Đã sửa trong code

1. `api.can`: chỉ Super Admin bỏ qua kiểm tra; Admin/Co Admin phải có một trong các key được API yêu cầu. Giữ OR giữa những key được khai báo rõ, không có fallback theo module.
2. Quyền hiệu lực `(role ∪ granted) − denied`; deny ưu tiên theo key để không lọt qua bản ghi cùng key khác ID của dữ liệu cũ. Đăng nhập, kiểm tra phiên và employee preview dùng chung `Employee::getEffectivePermissions()`.
3. Kiểm tra phiên trả lại permissions (có module và menu con), giúp FE cập nhật quyền khi điều hướng hoặc sau khi lưu thay vì giữ bản đăng nhập cũ.
4. Lưu grant/deny trong một transaction; từ chối ID vừa cấp vừa chặn, ID lặp và payload thiếu mảng. Mảng rỗng gửi rõ vẫn cho phép thu hồi toàn bộ.
5. Lưu nhiều vai trò có `unchanged_permissions`: giữ nguyên quyền ở trạng thái một phần nếu chưa thao tác; tick/bỏ tick cả nhóm xóa trạng thái một phần tương ứng. Không lấy hợp quyền rồi cấp cho tất cả vai trò nữa. BE mới cần được triển khai trước hoặc cùng FE mới.
6. Tạo/sửa permission ở API kiểm tra key duy nhất, không phân biệt vị trí hiển thị. Không sửa controller web legacy. Seeder nhóm key trùng thành `both`, không thay ID/cấu hình đã có và có thể chạy lặp mà không tạo bản sao. Seeder vẫn là dữ liệu bootstrap cũ, không phải bản chuẩn của các quyền hiện tại; không chạy để “đồng bộ” production.
7. Migration `2026_11_17_000004_make_permission_keys_unique.php` thêm unique index trên key. Nếu có dữ liệu trùng thì dừng, không tự xóa/gộp. Chưa chạy trên database đang dùng.
8. Sửa key ở routes: Góp ý dùng `feed_back` hoặc `view_feedbacks` cũ; Đơn xin phép dùng `view_request_forms` hoặc quyền Nhân sự có menu con tương ứng; Chức vụ chấp nhận quyền Nhân sự hoặc `view_total_positions`. Danh mục lịch dùng cùng kiểm tra `view_schedule` với trang Lịch làm việc, không yêu cầu `view_schedule_categories` đã không còn trong database kiểm tra.
9. FE sửa kiểu payload URL của menu con, đọc đúng response bọc `permission`/`sidebar_item`, tham số `role_ids`, đồng bộ cache quyền cá nhân sau thay đổi vai trò/quyền.

## Các cặp có thể dùng chung, chưa gộp dữ liệu

Những cặp dưới đây cùng type, cùng màn hình nhân viên và chỉ khác vị trí hiển thị. Nếu chấp nhận việc không cấu hình Trang chủ và Sidebar độc lập, có thể giữ key Sidebar và chuyển sang `display_area=both`:

| Bản Trang chủ                  | Bản Sidebar đề xuất giữ        | Màn hình                   |
| ------------------------------ | ------------------------------ | -------------------------- |
| 15 `view_work_time_calc_sheet` | 44 `employees_view_attendance` | `/employee/attendances`    |
| 16 `view_work_schedule`        | 42 `view_employee_schedule`    | `/employee/schedules`      |
| 17 `view_salary_sheet`         | 43 `view_salary`               | `/employee/salaries`       |
| 25 `request_label_printing`    | 46 `request_label`             | `/employee/stamps/request` |

Bản 25 đang có URL thiếu `/` đầu. Cần sửa URL khi xử lý cặp này; FE hiện không chấp nhận path đó trong kiểm tra quyền.

Không dùng thao tác xóa permission thông thường để gộp: FK cascade có thể xóa các liên kết role, grant/deny và menu con. Cần sao lưu, lập bảng ánh xạ ID, kiểm tra xung đột deny/grant, chuyển liên kết trong transaction rồi mới xóa bản dư. Seeder cũng phải cập nhật các alias tương ứng để không tạo lại key cũ.

## Các điểm còn cần thống nhất nghiệp vụ trước khi sửa tiếp

- Các quyền `view_total_*` ở dashboard dùng URL giống quyền quản lý. FE dùng URL làm route guard, còn API dùng key: ví dụ chỉ có `view_total_products` có thể mở trang Sản phẩm nhưng bị API yêu cầu `view_products` từ chối. Cần quyết định đây chỉ là quyền xem số liệu hay cũng là quyền quản lý; không tự cho quyền thống kê được sửa/xóa dữ liệu.
- `view_labels_to_print`, `view_history_print` và menu con của `view_label_management` cùng tới `/stamps/history` nhưng API check-stamp và API stamps yêu cầu các key khác nhau. Không gộp quyền xem hàng chờ in, xem lịch sử và thực hiện in trước khi chốt phạm vi.
- Các quyền lịch/hoạt động dùng chung URL nhưng khác vai trò. `CheckScheduleAccess` còn ngoại lệ tổ trưởng và tài khoản xem riêng; FE cũng có fallback theo vai trò/tài khoản. Chưa bỏ các ngoại lệ nghiệp vụ này.
- FE hiện mặc định cho tài khoản không phải admin vào các route `/employee/*`; các API cá nhân chủ yếu bảo vệ bằng đăng nhập và điều kiện sở hữu. Chưa đổi thành chính sách deny từng chức năng nhân viên vì đây là thay đổi nghiệp vụ rộng hơn.
- `VvpPayrollProfile::canEdit()` và các API bảng lương mới còn chính sách Admin/Co Admin riêng, không đi qua `api.can`. Sửa middleware chung không có nghĩa toàn bộ hệ thống đã hết bypass theo vai trò.
- API dashboard hiện trả gộp nhiều nhóm dữ liệu dưới `view_total_employees`, chưa lọc trường theo từng quyền widget. Cần thiết kế lại phạm vi dữ liệu nếu muốn phân quyền riêng từng chỉ số.
- Blade/web legacy là hệ thống cũ, được người dùng xác nhận ngoài phạm vi. Không sửa hoặc xóa các thành phần đó trong tác vụ này.

## Kiểm tra

- Test BE trên SQLite `:memory:`, xác nhận driver và database trước khi tạo schema: kiểm tra deny/grant, bypass, lưu hàng loạt, transaction rollback, auth check, validation unique, seeder, migration và route mapping.
- Kiểm tra callback FE chọn nhiều vai trò và response service bằng mã TypeScript hiện tại, không tạo file trong `src/`.
- Chạy riêng ESLint, TypeScript, PHP lint và Pint. Pint còn lỗi định dạng có sẵn ở API `PermissionController.php` (khoảng trắng nối chuỗi và dòng trống trong catch), ngoài các dòng logic sửa.
- Test tạm được xóa sau khi chạy; giữ test có sẵn của người dùng. Chưa kiểm tra E2E qua trình duyệt hoặc triển khai migration thực tế.
