# Đồng bộ nhân viên qua máy trung gian LAN

## Tạo nhân viên không xác nhận lần hai và giảm thời gian chờ

- API tạo nhân viên tự xếp yêu cầu thêm/cấp quyền trong cùng transaction với hồ sơ (trừ role 1, 15, 21, 22). API không gọi ISAPI và không chờ thiết bị. Nếu không lưu được hàng đợi, hồ sơ không được commit; cần sửa lỗi rồi gửi lại.
- Trang thêm nhân viên hiển thị trạng thái ngay trên trang, không tự mở modal xác nhận nữa. Yêu cầu đã lưu trên host vẫn được bridge xử lý khi người dùng đóng trang. Nút đồng bộ thủ công vẫn dùng cho thử lại; thao tác xóa vẫn phải nhập mã và xác nhận.
- Sau khi gửi thành công một yêu cầu thủ công, modal đóng và UI theo dõi trong nền khi trang còn mở. “Đã gửi” không phải “đã hoàn tất”. Web chỉ báo hoàn tất khi bridge xác minh thiết bị.
- Windows runner nghỉ 3 giây giữa các lượt thành công, 15 giây khi command bị lỗi. Lịch tự lấy hôm qua+hôm nay vẫn là 5 phút, không thay đổi.
- Khi đọc lịch sử, bridge kiểm tra yêu cầu hồ sơ giữa các trang ISAPI (giãn ít nhất 3 giây) và sau transaction từng ngày. Mỗi lần xử lý tối đa một yêu cầu hồ sơ, không gọi thiết bị song song hoặc giữa transaction lưu công. Nhờ vậy hồ sơ không phải chờ hết toàn bộ khoảng ngày. Đang chờ HTTP hoặc transaction lưu một ngày thì vẫn phải chờ bước đó xong; không cam kết đồng bộ xong trong 3 giây.
- Cập nhật **BE host + BE Windows + FE**. Không có migration mới cho tối ưu này; vẫn cần migration action của tính năng xóa đã triển khai trước đó. Không cần cài lại task.
- Trên PowerShell Administrator, dừng task **trước khi cập nhật code** rồi bật sau khi cập nhật xong để runner nạp thời gian nghỉ mới:

```powershell
Stop-ScheduledTask -TaskName "A7A-Attendance-Bridge"
# Cap nhat code BE tai may cong ty, sau do:
Start-ScheduledTask -TaskName "A7A-Attendance-Bridge"
```

- Nếu dừng giữa lúc xử lý một yêu cầu, lease có thể cần 5 phút để phục hồi; không tạo nhiều worker để né thời gian chờ này. File BAT chỉ bật task đang có, không thay thế bước restart khi cập nhật runner.

## Xóa hồ sơ thiết bị qua bridge (13/10/2026)

- **Thùng rác:** xóa mềm không tác động thiết bị. Xóa vĩnh viễn từng người hoặc toàn bộ sẽ lưu mã/tên và `delete_after_purge` cùng giao dịch xóa web. Nếu không lưu được lệnh hoặc đang có lệnh ngược chiều, giao dịch được hoàn tác. Role 1, 15, 21, 22 không được gửi lệnh thiết bị.
- Bridge dùng bản chụp mã/tên khi hồ sơ đã bị xóa vĩnh viễn; nếu mã xuất hiện lại trên web thì từ chối xóa. Vẫn đối chiếu chính xác mã/tên trên thiết bị trước khi xóa vân tay và hồ sơ. Không gọi API xóa lịch sử chấm công.
- Hồ sơ đã xóa vĩnh viễn **trước bản cập nhật này** không tự có lệnh mới: cần xác minh riêng mã/tên, không đoán danh sách để xóa. Kết quả yêu cầu còn trong `attendance_device_requests` (status/error), dù hồ sơ web không còn. Lỗi cần IT kiểm tra; không tự báo đã xóa thiết bị khi mới xếp hàng.

- Trang **Sửa nhân viên → Xóa khỏi máy chấm công**. Nhập lại chính xác mã và xác nhận. Đây là xóa riêng trên thiết bị; không xóa hồ sơ nhân viên hoặc dữ liệu công web, không gọi API xóa lịch sử sự kiện.
- Xóa hồ sơ thiết bị có thể xóa vân tay/khuôn mặt đăng ký. Khôi phục hồ sơ web không khôi phục sinh trắc học. Chỉ thực hiện khi đã xác nhận với người quản lý; không tự động xóa trên máy khi xóa mềm nhân viên web.
- DELETE `/api/employees/{id}/attendance-device` dùng cùng quyền quản lý nhân viên, yêu cầu `confirmed=true` và `employee_code` khớp chính xác. Không đồng bộ role 1, 15, 21, 22. API chỉ xếp hàng; GET trả thêm `action=sync|delete` và trạng thái.
- Bridge kiểm tra mã/tên thiết bị trùng với hồ sơ đã xác nhận, gửi lệnh xóa đúng một mã, đọc lại xác minh vắng mặt. Hồ sơ đã vắng mặt được coi là hoàn tất để có thể thử lại sau mất mạng. Nếu khác tên hoặc phản hồi không rõ ràng, dừng không xóa.
- Thêm/xóa cùng mã dùng chung hàng đợi; yêu cầu ngược chiều bị trả HTTP 409 khi đang pending/processing. Sau khi xóa hoàn tất có thể bấm đồng bộ thêm lại; vân tay có thể phải lấy lại.
- Trạng thái DB `delete_pending/delete_processing` không được bridge cũ nhận; FE mới nhận trạng thái chuẩn pending/processing qua API. Phải cập nhật toàn bộ BE/bridge/FE đồng bộ phiên bản, không dùng lẫn API cũ khi đã cho phép xóa.

### Triển khai tính năng xóa

1. Dừng task Windows bằng PowerShell Administrator: `Stop-ScheduledTask -TaskName "A7A-Attendance-Bridge"`. Dừng mọi bridge khác trên cùng DB; tạm không gửi yêu cầu nhân viên.
2. Cập nhật BE mới trên host và máy bridge (bao gồm sửa `Employee::$incrementing=false`). Trên host chạy riêng migration sau; không chạy migrate:fresh hoặc tất cả migration khác:

```bash
/opt/plesk/php/8.2/bin/php artisan migrate --path=database/migrations/2026_10_13_000001_add_device_request_action.php --force
/opt/plesk/php/8.2/bin/php artisan migrate --path=database/migrations/2026_10_13_000002_add_device_request_purge_flag.php --force
/opt/plesk/php/8.2/bin/php artisan route:cache
```

3. Deploy FE mới để có nút xóa và phân biệt kết quả thêm/xóa. Sau đó bật Windows task: `Start-ScheduledTask -TaskName "A7A-Attendance-Bridge"`.
4. Không cần cài lại task hay file BAT. Migration chỉ thêm cột thao tác và tên đã xác nhận, không xóa dữ liệu. Không khôi phục migration này khi còn job hoặc code xóa đang sử dụng.

Code xóa chưa được chạy trên thiết bị thật. Khi triển khai, chỉ xác nhận trên đúng nhân viên thực sự cần xóa, không dùng nhân viên đang làm việc để thử.

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

Runner kiểm tra hàng đợi 3 giây sau mỗi lượt thành công (15 giây khi lỗi), xử lý tuần tự và ghi log theo ngày tại `storage/logs/attendance-bridge-YYYY-MM-DD.log`. Task tự khởi động lại sau 1 phút nếu runner bị thoát lỗi. Máy phải đang bật/không ngủ; tắt Sleep khi cắm điện trong Windows Settings nếu cần chạy liên tục. Khi chỉ khóa màn hình, task vẫn chạy.

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
