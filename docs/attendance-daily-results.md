# Lưu kết quả bảng tính công

## Cách hoạt động

`GET /api/attendances?include_calculation=1` tự lưu kết quả vào
`attendance_daily_results` trước khi phân trang. Frontend hiện có đã gọi API này,
không cần nút lưu hay thay đổi giao diện.

- Mỗi `employee_id + date` có đúng một bản ghi, được bảo vệ bằng unique index.
- Tải/làm mới bảng cập nhật kết quả trong bộ lọc hiện tại; không tạo phiên bản mới.
- Lưu mã nhân viên dạng chuỗi, tên, công ty, danh mục, ngày công, ca, HNHC,
  loại ngày, đổi lịch, giờ vào/ra, tổng giờ, giờ chính, tăng ca và thời điểm tính.
- Giờ vào/ra có cả ngày để giữ đúng ca qua đêm; thiếu giờ được lưu `NULL`.
- Ca tăng cường `LN`/`TC`: giờ chính bằng 0, toàn bộ giờ là tăng ca, giống giao diện.
- STT và thứ trong tuần được suy ra khi hiển thị, không lưu lặp trong database.
- Khi tính lại thành công, kết quả cũ không còn tồn tại trong phạm vi bộ lọc sẽ
  được loại khỏi bảng kết quả. Không xóa dữ liệu quẹt máy hay lịch làm.
- Upsert và dọn kết quả cũ nằm trong một transaction; lỗi lưu trả lỗi API,
  không báo thành công khi chưa lưu đủ dữ liệu.

Đây là **kết quả mới nhất đã tính**, không phải lịch sử chỉnh sửa. Thay đổi dữ
liệu quẹt máy/lịch làm sẽ phản ánh ở lần tải/làm mới bảng
tính công tiếp theo cho khoảng ngày đó. Riêng ngày trước mốc
`attendance_archive_state.archived_before` đã được lưu trữ: không được cập
nhật/xóa bởi việc làm mới, vì dữ liệu quẹt máy tương ứng không còn ở bảng live.
Tác vụ cuốn chiếu bên dưới tính/lưu tháng hết hạn trước khi dọn nguồn; không
phải tác vụ tính lại công hàng ngày cho tất cả nhân viên.

## Cửa sổ 3 tháng và nút tra cứu

Bảng công admin, nhân viên và API live chỉ nhận khoảng ngày trong **tháng hiện
tại và hai tháng trước**, tính theo `Asia/Ho_Chi_Minh`. Ví dụ tháng 10/2026 là
01/08–31/10; sang tháng 11 là 01/09–30/11. Không phải 90 ngày gần nhất.
API trả 422 nếu khoảng ngày ngoài giới hạn; khi không có khoảng ngày thì dùng
tháng hiện tại. UI tự cập nhật khi đổi tháng (kiểm tra mỗi 30 giây và khi quay
lại tab). Kỳ công nhân viên 16→15 được cắt tại ranh giới cửa sổ này.

Nút **Tra cứu công lưu trữ** trên bảng tính công admin mở hồ sơ theo tháng,
mã/tên nhân viên, có phân trang và tải CSV toàn bộ kết quả theo bộ lọc. Nguồn
là `attendance_daily_results`, không tính lại và không giới hạn 3 tháng. CSV
là bản tổng hợp công, không thay thế bản sao lượt quẹt máy.

## Cuốn chiếu dữ liệu trong database

`attendance:rotate-storage --apply` được đăng ký chạy phút 05 mỗi giờ theo giờ
Việt Nam, không chạy chồng. Trong một transaction, tác vụ:

1. Khóa trạng thái lưu trữ và các lượt trước đầu tháng hiện tại trừ 2 tháng.
2. Sao chép vào `attendance_records_archive`, đối chiếu từng cột theo ID.
3. Tính/lưu/đối chiếu kết quả các tháng chưa đóng vào `attendance_daily_results`.
4. Chỉ xóa các ID đã đối chiếu khỏi `attendance_records`, rồi tăng mốc bảo vệ.

Lỗi ở bất kỳ bước nào sẽ rollback, không xóa nguồn. MySQL yêu cầu cả bốn bảng
tham gia dùng InnoDB. Bản ghi ngày NULL không bị xóa. Lượt cũ nhập lại sau khi
đóng tháng vẫn được đưa vào raw archive ở lần chạy tiếp theo, nhưng không ghi
đè kết quả công đã đóng. Muốn sửa công tháng đã đóng cần quy trình phục hồi và
tính lại riêng, không chỉ import lại rồi làm mới màn hình.

DB được dọn theo lịch, nên lượt quá hạn có thể còn tới lần chạy thành công
tiếp theo; API vẫn chặn xem ngoài 3 tháng ngay khi đổi tháng. Không xóa lịch
làm hoặc hồ sơ nhân viên. Bảng snapshot cũ `attendance_records_archive_20261013`
giữ nguyên; bảng raw archive mới tích lũy các lần cuốn chiếu tiếp theo.

## Tra cứu độc lập, không tính lại

`GET /api/attendances/daily-results`

API nằm trong nhóm xác thực và quyền `view_attendance` hiện có. Hỗ trợ phân
trang `page`, `limit` (1–500), sắp xếp `sort` theo `date`, `employee_id`, `name`,
`calendar_category_id`; dùng tiền tố `-` để giảm dần.

Các bộ lọc giống bảng công: `filter[date_between]=2026-10-01,2026-10-31`,
`filter[employee_id]`, `filter[date]`, `filter[employees.name]`,
`filter[employees.company]`, `filter[employees.calendar_category_id]`.
Không truyền khoảng ngày thì lấy đầu tháng hiện tại đến hôm nay.
Bộ lọc API lưu trữ dùng thông tin đã lưu, không lấy tên mới từ hồ sơ nhân viên.

Ví dụ truy vấn database:

```sql
SELECT employee_id, name, date, time_in, time_out, day_type,
       total_hours, administrative_hours, overtime_hours, calculated_at
FROM attendance_daily_results
WHERE date BETWEEN '2026-10-01' AND '2026-10-31'
ORDER BY employee_id, date;
```

## Triển khai

Chỉ sau khi xác nhận database đích và sao lưu, chạy migration riêng trong backend:

```sh
php artisan migrate --path=database/migrations/2026_10_13_000001_create_attendance_daily_results_table.php --force
php artisan migrate --path=database/migrations/2026_10_13_000002_create_attendance_archive_state_table.php --force
php artisan migrate --path=database/migrations/2026_10_13_000003_create_attendance_records_archive_table.php --force
```

Chạy migration trước khi đưa code lưu tự động vào phục vụ request. Migration
chỉ tạo bảng mới, không backfill hay sửa dữ liệu chấm công hiện có. Sau triển
khai, mở từng tháng cần lưu trên bảng tính công; mỗi lần tải lưu toàn bộ kết quả
phù hợp bộ lọc, không chỉ trang hiện tại. Bỏ các bộ lọc nhân viên/danh mục nếu
muốn lưu đầy đủ tháng trong cửa sổ live. Endpoint `/calculate` cũ và API nhân viên không ghi bảng
này; nguồn lưu là bảng tính công admin hiện đang sử dụng.

Trước khi bật scheduler, chạy thử chỉ đọc và đối chiếu tên database/cutoff:

```sh
php artisan attendance:rotate-storage
```

Lệnh không có `--apply` không ghi/xóa dữ liệu. Sau khi xác nhận đúng database,
đã backup và migration hoàn tất, có thể chạy `php artisan attendance:rotate-storage --apply`.
Server phải có Laravel scheduler chạy mỗi phút (`php artisan schedule:run`)
để lịch trong `app/Console/Kernel.php` có hiệu lực; không tạo thêm cron trùng
nếu server đã có. Xem `php artisan schedule:list` và log
`storage/logs/attendance-retention.log`. Chỉ có code local không đồng nghĩa
server đã bật tự động. Cache cấu hình cần trỏ đúng database trước khi bật lịch.

Rollback migration sẽ xóa bảng kết quả này; sao lưu trước nếu cần giữ dữ liệu.

## Kiểm thử an toàn

```sh
DB_CONNECTION=sqlite DB_DATABASE=:memory: vendor/bin/phpunit tests/Feature/AttendanceRetentionTest.php tests/Unit/RecentAttendanceWindowTest.php tests/Feature/AttendanceWindowRoutesTest.php tests/Feature/AttendanceArchiveProtectionTest.php tests/Feature/AttendanceDailyResultTest.php tests/Unit/SeptemberAttendanceTest.php
```

Test tạo bảng tạm trong SQLite bộ nhớ, không chạy migration lên database thật.
Lưu ý bootstrap hiện tại của AppServiceProvider có lệnh MySQL `SET time_zone`;
với config cache hiện có, bootstrap vẫn mở kết nối cấu hình trước khi test
chuyển sang SQLite. Không dùng bộ test này như bằng chứng chạy hoàn toàn offline.

## Chuyển dữ liệu cũ, giữ live từ 01/07/2026

Lệnh chuyển dữ liệu một lần đã hoàn tất và được gỡ khỏi mã nguồn khi dọn BE
ngày 13/10/2026; test riêng của lệnh cũng được gỡ. Phần dưới ghi lại quy trình
đã thực hiện, không phải hướng dẫn chạy lại. Bảng backup trong database vẫn giữ.

Migration `2026_10_13_000002_create_attendance_archive_state_table.php` vẫn cần
giữ để tạo cấu trúc bảo vệ dữ liệu lưu trữ khi triển khai môi trường khác.
Cấu hình cache có thể khác `.env`: kiểm tra trước khi chạy. Các thao tác ngày
13/10/2026 dùng cấu hình `.env` qua một `APP_CONFIG_CACHE` không tồn tại, không
thay đổi cache cấu hình của môi trường khác.

Lệnh tạo `attendance_records_archive_20261013` theo đúng cấu trúc bảng gốc,
giữ toàn bộ cột, ID, thời gian có microsecond và cả các lượt không tính được
công (ví dụ không có lịch hoặc nhân viên đã bị xóa). Không cascade theo việc
xóa hồ sơ nhân viên. Bản sao này là dữ liệu nhạy cảm, cần bảo vệ như bảng gốc.

Trong một transaction, lệnh khóa các lượt nguồn, sao chép toàn bộ lượt, so sánh
từng cột của từng ID, tính/lưu/đối chiếu kết quả bảng công theo từng tháng rồi
mới xóa `attendance_records.date < '2026-07-01'`. Lượt từ tháng 7 trở đi phải
giữ nguyên từng cột; bản ghi có ngày NULL không bị xóa. Bất kỳ lỗi đối chiếu nào
cũng rollback dữ liệu (bảng archive rỗng có thể còn lại do DDL nằm ngoài transaction).

Kết quả công tổng hợp không có quan hệ 1:1 với lượt quẹt máy. Những lượt không
hiển thị trong bảng tính công vẫn được giữ đầy đủ trong bảng raw archive.
Không sửa/xóa lịch làm, hồ sơ nhân viên hay bảng lương. Các API admin/nhân viên
đang đọc bảng live sẽ không còn lượt trước tháng 7 sau khi hoàn tất. Tại thời
điểm chuyển một lần này chưa bật lịch cuốn chiếu; chính sách 3 tháng ở trên
là phần triển khai tiếp theo, thay thế mốc live cố định tháng 7.

Khi cần khôi phục, đối chiếu bản sao theo ID rồi phục hồi các lượt thiếu trong
phạm vi được duyệt; không `TRUNCATE`, không ghi đè các lượt mới. Muốn tính lại
giai đoạn đã archive phải phục hồi dữ liệu nguồn và xử lý mốc bảo vệ có chủ đích.
Triển khai bản sửa `AttendanceDailyResultStore` cùng tính năng lưu tự động để
mốc bảo vệ có hiệu lực; không triển khai bản cũ chỉ upsert rồi xóa kết quả thiếu.

### Kết quả thực hiện 13/10/2026

Đã thực hiện và kiểm tra lại sau commit trên `aac91364_a7arelease`:

- Bản sao raw `attendance_records_archive_20261013`: 71.826 lượt, đối chiếu
  khớp từng cột trước khi xóa nguồn.
- `attendance_daily_results`: 29.140 dòng công, trong đó 23.761 dòng trước
  01/07/2026; không có cặp nhân viên/ngày trùng.
- Đã xóa 60.288 lượt trước 01/07/2026 khỏi `attendance_records`.
- Bảng live còn 11.538 lượt, từ 01/07/2026 đến 12/10/2026, giữ nguyên từng cột.
- Không còn lượt trước tháng 7 ở bảng live; không xóa bản sao raw hay lịch làm.
- Hai migration riêng đã chạy trên release. Mã backend thay đổi vẫn ở workspace,
  chưa được triển khai lên máy chủ trong thao tác này.
