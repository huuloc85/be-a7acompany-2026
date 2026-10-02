# Đồng bộ nhân viên qua máy trung gian LAN

Web xác nhận → API host lưu `attendance_device_requests` → máy Mac đọc hàng đợi từ database host → ISAPI máy chấm công → đọc lại quyền → cập nhật trạng thái trên host.

## Triển khai

- Host và Mac phải dùng cùng database. Hiện tại: `aac91364_develop`.
- Deploy BE/FE mới lên host. Chạy riêng migration `2026_09_30_000001_create_attendance_device_requests_table.php` nếu chưa có, sau đó làm mới route cache. Không chạy toàn bộ migration chưa kiểm tra.
- Không thêm lịch chạy `attendance:device-bridge` trên host. Tác vụ này chỉ chạy ở máy LAN; host không cần tài khoản Hikvision cho việc thêm nhân viên.
- Máy Mac dùng cấu hình `acs` của BE local, hiện trỏ `192.168.1.200`. Không gửi mật khẩu ISAPI ra trình duyệt hoặc lưu trong bản ghi yêu cầu.
- Tắt lịch tự chạy `attendance:sync-employees` cũ ở mọi phiên bản BE đang triển khai để không tự thêm/xóa nhân viên ngoài xác nhận. Lệnh cũ vẫn còn để quản trị sử dụng thủ công.

## Máy Mac hiện tại

Đã cấu hình LaunchAgent `com.a7a.attendance-device-bridge` tại `/Users/MAC/Library/LaunchAgents/com.a7a.attendance-device-bridge.plist`.

- Khởi chạy khi tài khoản MAC đăng nhập và kiểm tra 15 giây/lần, mỗi lần một yêu cầu.
- Cần máy bật, không ngủ và kết nối LAN tới thiết bị, đồng thời kết nối được database host. Máy tắt thì yêu cầu vẫn chờ.
- Không chạy `schedule:work` chỉ để bật cầu nối; lệnh đó còn chạy các tác vụ khác.
- Chạy một lần thủ công: `ATTENDANCE_DEVICE_BRIDGE=1 php artisan attendance:device-bridge --once`.
- Xem trạng thái: `launchctl print gui/501/com.a7a.attendance-device-bridge`.
- Dừng: `launchctl bootout gui/501 /Users/MAC/Library/LaunchAgents/com.a7a.attendance-device-bridge.plist`.
- Bật lại: `launchctl bootstrap gui/501 /Users/MAC/Library/LaunchAgents/com.a7a.attendance-device-bridge.plist`.
- Log: `storage/logs/device-bridge.log` và `storage/logs/device-bridge-error.log`.
- Không di chuyển/xóa checkout BE local khi cầu nối còn đang dùng nó. Nếu đổi vị trí phải cập nhật LaunchAgent.

## An toàn và trạng thái

- API POST cần `confirmed=true`, đăng nhập và quyền quản lý nhân viên. GET cùng đường dẫn trả trạng thái.
- Chỉ tạo/cấp quyền cho nhân viên hợp lệ, không thuộc role 1, 15, 21, 22. Không xóa hồ sơ hay gửi vân tay/khuôn mặt.
- `pending`: chờ cầu nối; `processing`: đang xử lý; `succeeded`: đã đọc lại và xác minh quyền; `failed`: lỗi, bấm Thử lại sau khi khắc phục.
- Xác nhận lặp không tạo thêm yêu cầu cho cùng nhân viên. Yêu cầu bị gián đoạn được nhận lại sau 5 phút; khóa nhận việc ngăn tiến trình cũ ghi đè kết quả tiến trình mới.
- Đồng bộ gặp cùng mã nhưng khác tên sẽ dừng, không ghi đè người khác.
- Vân tay cần đăng ký trực tiếp sau khi trạng thái thành công.

## Kiểm thử

`php vendor/bin/phpunit --testsuite Unit` dùng SQLite bộ nhớ cho hàng đợi và HTTP giả lập cho ISAPI; không tạo hồ sơ trên máy thật.

## Tự lấy công và chạy nền trên Windows

Chỉ bật tại máy công ty kết nối được thiết bị LAN và database host. Cập nhật code BE mới lên máy đó trước. Không cần FE, `artisan serve`, `schedule:work` hay migration mới cho chế độ này (hai bảng hàng đợi trước đó vẫn phải có).

`--auto-import` bật việc xếp yêu cầu hôm qua + hôm nay theo giờ Việt Nam. Sau khi yêu cầu hoàn tất/thất bại ít nhất 5 phút, bridge sẽ xếp lại để lấy lượt mới. Lượt đầu được xếp ngay. Không chạy chồng yêu cầu cùng khoảng ngày; pending/processing được giữ nguyên, lease cũ vẫn do hàng đợi phục hồi. Khoảng ngày tự động thay đổi khi qua nửa đêm. Yêu cầu thủ công vẫn được xử lý theo thứ tự hàng đợi. Nếu máy tắt nhiều ngày, chọn thủ công khoảng bị thiếu trên web; chế độ tự động chỉ bù hôm qua và hôm nay.

### Cài một lần

1. Chuẩn bị PHP, `vendor` và `.env` trong thư mục BE trên ổ đĩa local, không dùng ổ mạng được map theo user. DB phải đúng database của web, cấu hình `acs` phải trỏ máy chấm công. Dừng các vòng PowerShell bridge cũ bằng Ctrl+C; không để một bridge ngoài LAN cùng nhận hàng đợi này.
2. Mở **Windows PowerShell → Run as administrator**, vào thư mục BE rồi chạy:

```powershell
php artisan config:clear
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\install-attendance-bridge.ps1
```

Nếu PHP không có trong PATH, truyền đường dẫn thật, ví dụ:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\install-attendance-bridge.ps1 -PhpPath "C:\php\php.exe"
```

Bộ cài tạo và khởi động task `A7A-Attendance-Bridge`: chạy lúc Windows khởi động, không cần đăng nhập. Task chạy với tài khoản SYSTEM; chỉ cho người quản trị tin cậy sửa thư mục BE, các script và `.env`. Task dùng đường dẫn PHP tuyệt đối, không dựa vào PATH của SYSTEM. Không tự sửa firewall, quyền database hoặc chế độ ngủ. Nếu task đã có, bộ cài dừng mà không ghi đè.

Runner kiểm tra hàng đợi 15 giây sau mỗi lượt, xử lý tuần tự và ghi log theo ngày tại `storage/logs/attendance-bridge-YYYY-MM-DD.log`. Task tự khởi động lại sau 1 phút nếu runner bị thoát lỗi. Máy phải đang bật/không ngủ; tắt Sleep khi cắm điện trong Windows Settings nếu cần chạy liên tục. Khi chỉ khóa màn hình, task vẫn chạy.

### Quản lý task (PowerShell Administrator)

```powershell
Get-ScheduledTask -TaskName "A7A-Attendance-Bridge"
Get-ScheduledTaskInfo -TaskName "A7A-Attendance-Bridge"
# Dừng (nếu đang lấy công, checkpoint ngày đã hoàn tất vẫn giữ):
Stop-ScheduledTask -TaskName "A7A-Attendance-Bridge"
# Chạy lại sau cập nhật code/cấu hình:
Start-ScheduledTask -TaskName "A7A-Attendance-Bridge"
# Tắt tự khởi động; lệnh Stop phía trên dừng lượt đang chạy:
Disable-ScheduledTask -TaskName "A7A-Attendance-Bridge"
# Bật lại tự khởi động:
Enable-ScheduledTask -TaskName "A7A-Attendance-Bridge"
```

Để chỉ chạy thủ công (không cài task), giữ cửa sổ PowerShell này mở:

```powershell
$env:ATTENDANCE_DEVICE_BRIDGE="1"
while ($true) { php artisan attendance:device-bridge --once --auto-import --no-interaction; Start-Sleep -Seconds 15 }
```

Script Windows chưa được xác minh trên máy công ty. Theo dõi log và kết quả trên web sau khi cài; task báo Running chỉ chứng minh runner đang hoạt động, không chứng minh lấy công thành công. Không bật `--auto-import` trên host hoặc Mac ngoài LAN.

## Lấy lịch sử chấm công theo ngày trên web

- Trong **Quản lý chấm công**, bấm **Lấy dữ liệu máy chấm công**, chọn từ ngày–đến ngày (tối đa 31 ngày, không ngày tương lai, giờ Việt Nam), rồi xác nhận.
- GET/POST `/api/attendances/device-imports` yêu cầu đăng nhập, token còn hạn và quyền `view_attendance` theo middleware hiện có. POST có giới hạn 10 lần/phút và bắt buộc `confirmed=true`.
- BE chỉ xếp yêu cầu vào `attendance_import_requests`; bridge Mac hiện tại xử lý một yêu cầu lịch sử sau lượt đồng bộ hồ sơ nhân viên. Không mở port thiết bị, không gửi mật khẩu thiết bị tới trình duyệt.
- Triển khai BE/FE mới, chạy riêng `php artisan migrate --path=database/migrations/2026_10_02_000001_create_attendance_import_requests_table.php --force` nếu chưa áp dụng, làm mới route cache trên host. Bridge dùng checkout BE local nên tự nhận code mới ở lần chạy kế tiếp.
- Giao diện hiển thị 10 yêu cầu cập nhật gần nhất; tự tải trạng thái mỗi 3 giây khi mở. `pending` chỉ là chờ bridge, không phải đã nhập xong. Nếu máy ngủ/offline, yêu cầu vẫn chờ.
- Đọc ISAPI từng ngày, kiểm tra đầy đủ phân trang rồi mới nhập ngày đó. Mỗi ngày nhập và checkpoint trong cùng transaction. Lượt chạy bị gián đoạn được nhận lại sau 5 phút và tiếp tục từ checkpoint; claim token chặn worker cũ ghi dữ liệu.
- Dùng ánh xạ mã cũ hiện có; mã không tìm thấy (kể cả hồ sơ đã xóa mềm) được báo riêng. `unmatched_codes` hiển thị tối đa 50 mã. `skipped` gồm sự kiện không có mã/thời gian hợp lệ hoặc ngoài ngày yêu cầu. Bộ lọc sự kiện major/minor giữ như luồng lấy công hiện tại.
- Luồng mới và controller lấy công cũ dùng chung `AttendanceEventWriter`: khóa hàng nhân viên, kiểm tra mã + thời điểm, chỉ thêm nếu chưa có, không cập nhật bản ghi cũ. Cần deploy cả writer/controller mới lên host để hai luồng phối hợp chống trùng. Không thêm unique index hoặc xóa các bản ghi trùng từ trước.
- Có thể xác nhận lại một khoảng đã hoàn tất để lấy các lượt mới phát sinh, đặc biệt ngày hôm nay. Bấm lặp khi pending/processing không tạo job khác. Khi thử lại sau lỗi, số liệu đếm bắt đầu lại; dữ liệu đã nhập vẫn giữ và được tính là “đã có”.
- Đây chỉ là đọc lịch sử, không sửa/xóa hồ sơ, quyền, vân tay hay sự kiện trong thiết bị. Các bản ghi công nhập tay qua luồng khác không tham gia khóa chung này.
