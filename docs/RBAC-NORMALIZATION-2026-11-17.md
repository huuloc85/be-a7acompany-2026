# Chuẩn hóa RBAC cho FE React và API — 17/11/2026

## Trạng thái

- **Cập nhật sau xác nhận hoàn tác:** đã sửa source FE/API để bỏ `manage_daily_activity`, chưa triển khai bản sửa này lên host và **chưa hoàn tác DB thật**. Người dùng đã xác nhận host đồng bộ bản BE trước đó; bản BE đó vẫn phụ thuộc quyền 91. Không được xóa quyền hoặc khôi phục `role14 → view_schedule` trước khi triển khai middleware chỉ đọc cho tổ trưởng.
- Bản sửa hiện tại trả `/daily-schedules` về `view_schedule`; FE dùng cùng key. `CheckScheduleAccess` và FE giữ tổ trưởng chỉ đọc lịch, kể cả khi có `view_schedule`. Lệnh `rbac:normalize` không còn tạo quyền hoạt động mới hoặc xóa gán `view_schedule` của tổ trưởng; grant/deny cũ của key này được giữ nguyên. Các mục mô tả lần chuyển đổi đã chạy bên dưới là lịch sử, không phải hướng dẫn chạy lại.
- Đã đọc lại DB release bằng kết nối chỉ đọc: hiện có **106 tài khoản**, không phải 105. Mô phỏng hoàn tác trên SQLite `:memory:` giữ quyền hiệu lực và đọc/ghi của toàn bộ 106 tài khoản; không phục hồi đè tài khoản hay dữ liệu phát sinh sau bản sao.
- Kiểm tra lại sau phản ánh của người dùng: người dùng xác nhận BE trên host đã đồng bộ. Phát hiện Dashboard tạo ô Truy cập nhanh từ `manage_daily_activity` dù URL rỗng; đã sửa FE để chỉ giữ ô thống kê, liên kết hợp lệ hoặc thao tác có callback. Giữ nguyên thiết kế và không đổi DB trong lần sửa này.
- Cần phân biệt kết quả đối chiếu key với quyền vào màn hình: FE cũ cho ba tài khoản `25060406`, `22120703`, `22120905` mở `/activity-schedule` bằng ngoại lệ vai trò, nhưng bản sao trước chuyển đổi không cấp `view_schedule` mà API cũ yêu cầu. Việc FE nay từ chối mở trang không chứng minh quyền đọc dữ liệu đang hoạt động đã bị thu hồi. Không tự cấp `manage_daily_activity` cho họ vì quyền đó cho cả sửa/xóa. `Admin10` cũng đã bị chặn `view_schedule` trước chuyển đổi.
- Đối chiếu bản sao và DB sau phản ánh: không đổi `employees.role_id` hoặc 10 menu con; còn 39 quyền, 213 liên kết vai trò–quyền, 8 cấp riêng và 71 chặn riêng. Các số đếm RBAC thay đổi so với trước chuyển đổi không đồng nghĩa bản ghi nghiệp vụ bị sửa. Bản sao RBAC không chứa dữ liệu chấm công/lương/sản phẩm để khẳng định lịch sử các bảng đó.
- Đã áp dụng trực tiếp vào MySQL release `aac91364_a7arelease` theo yêu cầu người dùng ngày 17/11/2026, sau khi thử chuyển đổi trên SQLite `:memory:`.
- Trước khi chạy: 41 quyền, 105 tài khoản và 10 menu con. Khác số liệu kiểm tra ban đầu (40 quyền, 9 menu con) vì có thay đổi dữ liệu từ tác vụ khác trong giai đoạn kiểm tra.
- Kết quả thật: 41 → 39 quyền (gộp 4 quyền dư, thêm 2 quyền biểu diễn các hành vi đã có), giữ nguyên 105 tài khoản và 10 menu con. Đối chiếu quyền trước/sau của toàn bộ 105 tài khoản đạt trước commit.
- Đã chạy riêng migration `2026_11_17_000004_make_permission_keys_unique`, ghi nhận batch 52. Xác minh sau commit: unique index `permissions_key_unique` tồn tại, không có key trùng hoặc liên kết permission mồ côi. Không chạy seeder hoặc migration khác.
- Bản sao trước chuyển đổi nằm tại `storage/app/rbac-backups/20261117-124233-769a3d37-6272-47c3-ac6a-567fb1b830a4.json` trên máy thực thi; quyền file `0600`, dung lượng 83.326 byte. SHA-256: `30b203f43c2317e532525545c056c699b75c452c47fe3286a0a0a32d58f2cb17`. Bốn bản quyền cũ đã được xóa sau khi chuyển liên kết; có dữ liệu sao lưu để phục hồi có kiểm soát.
- Không cần chạy lại chuyển đổi database trên host nếu host dùng cùng database này. Tác vụ chỉ cập nhật DB, chưa triển khai code FE/BE lên host; cần triển khai code mới để đồng bộ quy tắc truy cập, sau đó làm mới phiên đăng nhập.
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
- Lần chuyển đổi đã chạy từng tạo `manage_daily_activity` bằng cách sao chép role/grant/deny của `view_schedule`. Đây là quyền tự thêm ngoài yêu cầu, đã được người dùng yêu cầu hoàn tác. Source hiện tại dùng lại `view_schedule` cho API hoạt động và giữ hạn chế sửa lịch ở middleware; DB đang chờ triển khai bản sửa trước khi gỡ quyền 91.

## Hoàn tác riêng quyền hoạt động — đang chờ triển khai

1. Trong cửa sổ bảo trì, triển khai BE đã sửa `routes/api.php` và `app/Http/Middleware/API/CheckScheduleAccess.php`; đồng bộ `app/Console/Commands/NormalizeRbacPermissions.php` để không tạo lại quyền. Làm mới route cache theo quy trình host. Kiểm tra `daily-schedules` dùng `api.can:view_schedule` và tổ trưởng bị chặn ghi ở cả `schedules` / `schedule-categories`.
2. Đọc lại DB release, kiểm tra không có thay đổi mới ở các liên kết liên quan. Sao lưu RBAC hiện tại trước khi ghi. Không dùng bản sao cũ để thay thế toàn bộ bảng.
3. Trong transaction và có khóa: khôi phục đúng bản `role_permission` trước chuyển đổi `{id: 695, role_id: 14, permission_id: 40}` nếu ID vẫn trống; gỡ các liên kết permission 91 rồi gỡ đúng permission có `id=91 AND key=manage_daily_activity`. Tại lần kiểm tra này, liên kết cần gỡ là role 14/15/21/22 và deny của `Admin10`, không có grant riêng hoặc sidebar của quyền 91. Nếu khác, dừng để đối chiếu; không xóa phát sinh mới. Giữ nguyên deny `Admin10:40`.
4. Kiểm tra trước commit: tập quyền ngoài phần ánh xạ không đổi cho từng tài khoản, quyền API hoạt động giữ nguyên, đọc/ghi lịch giữ nguyên với middleware mới, vai trò tài khoản/sidebar không đổi. Dự kiến theo snapshot hiện tại: permissions 39→38, role_permission 213→210, grants giữ 8, denies 71→70. Số lượng chỉ là kiểm tra bổ sung, không thay thế đối chiếu từng dòng.
5. Triển khai FE đã sửa `src/utils/authUtil.ts` và `src/pages/admin/dailySchedule/DailySchedule.tsx` cùng đợt; làm mới phiên quyền, kiểm tra tổ trưởng sản xuất, Admin/Co Admin, Super Admin, tài khoản bị chặn và nhân viên đặc biệt. Kết thúc bảo trì sau khi xác minh. Không chạy lại `rbac:normalize --apply` để hoàn tác: lệnh đó không xử lý DB đã chuyển đổi.

Kiểm tra bản sửa: 5 test BE tạm / 1.357 assertions trên SQLite riêng; 2.059 assertions FE, bao gồm 106 tài khoản × 16 đường dẫn; ESLint, TypeScript, PHP lint và Pint đạt (PHP kiểm tra 3 file BE). Chạy thêm bộ có sẵn: `RbacLogsTest` đạt 5 test; `RequestFormSupervisorAccessTest` đạt 7, lỗi 20 do mock Auth thiếu `user()` trong controller duyệt đơn. Hai file test/controller này không đổi so với HEAD trong lần hoàn tác; chưa sửa lan sang luồng duyệt đơn. Không báo toàn bộ test BE đạt. Các kiểm tra này không chứng minh bản sửa đã được phục vụ trên host; chưa kiểm tra E2E. Test và snapshot tạm đã được dọn, giữ nguyên bản sao RBAC trước chuyển đổi.

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
- Đã chạy chuyển đổi transaction và DDL unique index trên MySQL release thành công; đã kiểm tra lại bằng kết nối chỉ đọc sau commit. Chưa kiểm tra E2E trình duyệt hoặc xác minh phiên bản FE/BE đang được phục vụ trên host. Đợt áp dụng DB không sửa code PHP/TypeScript nên không chạy lại lint/typecheck/test; các kết quả code ở trên thuộc đợt kiểm tra trước triển khai.
