# Chuẩn hóa RBAC cho FE React và API — 17/11/2026

## Trạng thái

- Đã sửa code và thử chuyển đổi trên SQLite `:memory:` bằng bản sao dữ liệu lấy qua kết nối MySQL chỉ đọc. Chưa chạy chuyển đổi, migration hoặc seeder trên database thật.
- Bản chụp mới nhất có 41 quyền, 105 tài khoản và 10 menu con. Khác số liệu kiểm tra ban đầu (40 quyền, 9 menu con) vì dữ liệu có thay đổi trong lúc làm việc; tác vụ này không ghi database thật.
- Mô phỏng: 41 → 39 quyền (gộp 4 quyền dư, thêm 2 quyền biểu diễn các hành vi đã có), giữ nguyên 10 menu con. Đối chiếu toàn bộ 105 tài khoản đạt.
- Chỉ xử lý FE React, API BE và RBAC. Đã hoàn tác các thay đổi vừa thử trên Blade và controller web legacy theo xác nhận của người dùng.

## Các quyền dùng chung

| Quyền cũ                         | Quyền giữ lại                    | Chức năng             |
| -------------------------------- | -------------------------------- | --------------------- |
| `view_work_time_calc_sheet` (15) | `employees_view_attendance` (44) | Chấm công cá nhân     |
| `view_work_schedule` (16)        | `view_employee_schedule` (42)    | Lịch làm việc cá nhân |
| `view_salary_sheet` (17)         | `view_salary` (43)               | Bảng lương cá nhân    |
| `request_label_printing` (25)    | `request_label` (46)             | Yêu cầu in tem        |

ID là ảnh chụp tham khảo; lệnh chuyển đổi tìm theo key. Giữ bản đích, đặt `display_area=both`, chuẩn hóa dấu `/` ở URL và chuyển các liên kết trước khi xóa bản dư. Seeder dùng cùng bảng ánh xạ để không tạo lại alias.

Quyền hiệu lực mới của mỗi chức năng bằng OR các quyền hiệu lực cũ, sau khi áp dụng grant/deny từng key. Ví dụ một alias bị chặn nhưng alias còn lại được cấp thì tài khoản vẫn truy cập được chức năng. Không lấy hợp quyền của mọi tài khoản để cấp lại cho tất cả. Cấu hình vị trí hiển thị riêng Trang chủ/Sidebar được thay bằng một cấu hình dùng chung, không thêm chức năng nghiệp vụ.

## Tổ trưởng và tài khoản đặc biệt

- Xem lịch tổng dùng `view_team_schedule`; quản lý lịch dùng `view_schedule`. FE và middleware cùng phân biệt đọc/ghi. Các vai trò tổ trưởng trước đây được middleware cho đọc được chuyển thành quyền đọc trong database; ngoại lệ tài khoản `23080130` chuyển thành grant riêng. Giữ chặn riêng đã có và không cho tổ trưởng có thêm quyền ghi lịch.
- Quyền `view_team_schedule` đã được cấp rõ trong database cũng được API công nhận; trước đây FE có quyền nhưng middleware có thể trả 403 vì chỉ kiểm tra `view_schedule`.
- `approve_assigned_request_forms` biểu diễn quyền duyệt đã hardcode cho sáu tài khoản: `25060406`, `22120703`, `22120905`, `22120304`, `22122809`, `22121822`. Không tự cấp cho toàn bộ vai trò 23/24/25 chỉ vì FE cũ từng hiển thị nút. API vẫn kiểm tra người được giao trên từng đơn.
- Danh sách chọn người duyệt lấy từ quyền hiệu lực ở BE, không lấy các danh sách ID/vai trò khác nhau ở FE. Quyền chặn được áp dụng cả khi chọn người duyệt và xử lý đơn.
- Quy tắc nghiệp vụ chuyển đơn tăng ca lên Quản lý nhà máy giữ nguyên. Có quyền duyệt đơn người khác không tự động đổi tuyến duyệt đơn tăng ca của bản thân.
- `manage_daily_activity` sao chép đúng role/grant/deny của `view_schedule` trước khi tách quyền lịch. API hoạt động trong ngày dùng quyền này cho đọc/ghi, tránh làm mất quyền đang có khi tổ trưởng được chuyển sang lịch chỉ đọc. Không tự cấp thêm quyền hoạt động cho mọi tổ trưởng.

## Đồng nhất Trang chủ, Sidebar và API

- Route guard và Sidebar dùng cùng hàm kiểm tra truy cập; loại menu lặp cùng đường dẫn. Quyền thao tác không có URL không tạo mục điều hướng.
- Dashboard lấy dữ liệu theo key quyền, không chỉ theo nhãn vai trò. API chỉ trả các trường được cấp, thay vì trả toàn bộ dashboard dưới một key duy nhất. Cache FE có tài khoản và tập quyền trong key để không tái sử dụng dữ liệu sau đổi quyền.
- Quyền thống kê không cấp quyền sửa/xóa tại màn hình quản lý. Liên kết từ chỉ số chỉ mở khi có quyền vào màn hình tương ứng.
- Biểu đồ sản lượng có quyền đọc dữ liệu biểu đồ riêng; endpoint sửa số lượng vẫn yêu cầu quyền quản lý sản phẩm.

Đây là sửa chỗ FE/API xử lý sai hoặc không nhất quán với quyền đã cấu hình, không phải giữ lại khả năng truy cập vượt quyền do lỗi cũ. Không sửa các gán quyền thống kê, sản phẩm, lương, kho hoặc tem không thuộc ánh xạ.

## Những phần cố ý giữ riêng

- Không gộp `view_total_*` với quyền quản lý dù cùng URL.
- Không gộp `view_today_employees`, `view_daily_activities`, `view_today_activity`: trùng đường dẫn không đủ chứng minh trùng phạm vi dữ liệu/thống kê.
- Không gộp hàng chờ in, lịch sử in và thao tác in; không gộp lịch cá nhân với lịch tổng.
- Giữ bảng vai trò, liên kết vai trò–quyền, cấp riêng, chặn riêng và menu con vì mỗi bảng có trách nhiệm khác nhau.
- Không đổi chính sách `/employee/*` đang dựa trên đăng nhập và sở hữu dữ liệu, chính sách riêng của bảng lương VVP, hoặc phạm vi dòng/danh mục lịch. Bộ lọc tổ trưởng ở FE không phải bằng chứng rằng API đã giới hạn cùng phạm vi; cần tác vụ nghiệp vụ riêng nếu muốn siết phạm vi đó.

## Cách triển khai an toàn

1. Triển khai đồng bộ FE + API mới trong cửa sổ bảo trì. Không chỉ triển khai FE rồi xóa quyền khi BE còn dùng quy tắc cũ, hoặc chỉ đổi BE mà chưa chuyển ngoại lệ sang quyền mới.
2. Xem trước bằng `php artisan rbac:normalize`. Mặc định không ghi dữ liệu.
3. Sau khi xác nhận đúng môi trường, chạy `php artisan rbac:normalize --apply`. Lệnh khóa các bảng liên quan trong transaction, ghi bản sao vào disk local `rbac-backups/`, gộp liên kết và kiểm tra lại toàn bộ tài khoản trước commit.
4. Nếu thiếu quyền đích, trùng key, gặp chuyển đổi dở dang, sao lưu thất bại hoặc quyền trước/sau lệch ngoài ánh xạ thì dừng/rollback. Bản sao có RBAC, ID/vai trò tài khoản và vai trò; cần giữ an toàn để phục hồi có kiểm soát. Không có thao tác phục hồi tự động ghi đè các thay đổi phát sinh sau đó.
5. Áp dụng riêng migration `database/migrations/2026_11_17_000004_make_permission_keys_unique.php` theo quy trình triển khai. Không chạy tất cả migration đang chờ vì repo còn các tác vụ khác.
6. Làm mới phiên đăng nhập và kiểm tra tài khoản quản trị, tổ trưởng, người duyệt được giao, nhân viên đặc biệt, tài khoản có deny. Không chạy seeder cũ để đồng bộ production.

Chạy lại lệnh khi không còn alias sẽ không cấp lại các ngoại lệ đã bị thu hồi. Lệnh này là chuyển đổi dữ liệu hiện có, không thay thế quy trình bootstrap một database mới.

## Kiểm chứng

- Mô phỏng trên bản sao mới nhất: kiểm tra mọi key hiệu lực sau ánh xạ của 105 tài khoản; kiểm tra đọc/ghi lịch và ngoại lệ đã chuyển; giữ menu con và sao lưu đầy đủ.
- Test SQLite tách biệt: dry-run, grant/deny với 64 tổ hợp, chuyển menu con, đọc/ghi, quyền duyệt và người được giao, dashboard theo từng trường, chạy lặp, thiếu quyền đích, lỗi sao lưu và rollback khi phát hiện cấp thêm quyền.
- FE: kiểm tra bằng mã TypeScript thực tế cho route guard, thống kê không mở quyền quản lý, supervisor theo quyền, đúng người được giao và tuyến duyệt tăng ca.
- Kết quả cuối: 20 test BE / 186 assertions đạt; 23 assertions FE đạt; ESLint và `tsc -b --pretty false` đạt; PHP lint 15 file đạt. Pint kiểm tra 15 file còn lỗi định dạng có sẵn ở API `PermissionController.php` (`concat_space`, dòng trống), không nằm trong logic thay đổi. Test tạm đã được dọn, giữ test có sẵn.
- Chưa kiểm tra E2E trình duyệt hoặc chạy chuyển đổi trên MySQL thật. Kiểm tra SQLite không thay thế kiểm thử locking/DDL MySQL khi triển khai.
