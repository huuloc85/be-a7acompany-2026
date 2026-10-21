# Bàn giao TAIL sau chuẩn hoá CAP7A

Ngày: 22/10/2026. Đây là hợp đồng source mới; chưa deploy.

## Danh tính

- CAP7A là hệ thống chung. TAIL = Screen 1 cũ, chạy capture-tool version 2.
- SPIKE = Screen 2 cũ, là device ThingsBoard khác và hiện chỉ giám sát.
- Không dùng chung Device ID/token MQTT/gateway key của hai hệ thống.
- Không cộng bộ đếm TAIL và SPIKE thành sản lượng CAP7A: có thể là cùng sản phẩm đi qua hai camera.

## Phía TAIL phải sửa

1. Giữ nguyên token MQTT và device TAIL đang chạy. Không đổi thuật toán, GPIO, PLC, bộ đếm, nhật ký hoặc file trạng thái.
2. MQTT giữ nguyên topic `v1/devices/me/telemetry`, key `cap7a_state`, `cap7a_online`, schema 1; RPC vẫn `cap7a.command`, request/reply và guard session/expiry như cũ.
3. Báo cáo giữ key `cap7a_daily_YYYY_MM_DD`, reset `cap7a_reset_YYYY_MM_DD` và hậu tố `_since_<timestamp>` như cũ.
4. Sửa URL xác thực gateway thành `https://<BE>/api/cap7a/tail/screen/authorize`.
5. Nếu dùng Quick Tunnel, sửa URL đăng ký endpoint thành `https://<BE>/api/cap7a/tail/screen/register`. Named Tunnel cố định không cần đăng ký.
6. Rà `cap7a_screen.py`, `configure_screen.py`, file cấu hình triển khai và service systemd: mọi chỗ còn tạo `/api/cap7a-screen/authorize` hoặc `/register` phải đổi. Gateway trong BE đọc `CAP7A_AUTH_URL`; giữ cơ chế xác thực, lease, view-only, origin và TLS.
7. Gateway key trên TAIL phải khớp `CAP7A_TAIL_SCREEN_GATEWAY_KEY` của BE. Không đổi gateway key SPIKE để chữa lỗi TAIL.
8. Đổi nhãn hiển thị Screen 1/CAP7A đơn lẻ thành CAP7A / TAIL khi nhãn đó chỉ riêng camera TAIL. Không đổi hàng loạt tên key MQTT hoặc tên module chỉ vì nhãn.

Không cần sửa MQTT publisher TAIL để BE mới nhận dữ liệu. Phần bắt buộc đổi ở app/gateway là URL màn hình nếu đang dùng endpoint cũ.

## BE/FE mới

API `/api/cap7a/tail/{status,daily,commands,screen-sessions}`; reset báo cáo là `/api/cap7a/tail/daily/reset`. API cũ `/api/cap7a/status`... và `/api/cap7a-screen/*` đã bỏ.

BE dùng `CAP7A_TAIL_*` thay `CAP7A_*`: ENABLED, COMMANDS_ENABLED, TB_URL, TB_DEVICE_ID, TB_API_KEY (hoặc TB_USERNAME/TB_PASSWORD), SCREEN_ENABLED, SCREEN_DYNAMIC_URL, SCREEN_WS_URL, SCREEN_GATEWAY_KEY. Chuyển nguyên giá trị TAIL hiện có sang prefix mới; không chép token MQTT vào TB_API_KEY.

FE gọi API theo system và dùng cache riêng; TAIL giữ chức năng điều khiển/báo cáo, vẫn yêu cầu quyền Super Admin và quyền local. STOP từ xa không phải dừng khẩn cấp. SPIKE không có RPC, kể cả ping.

## Triển khai đồng bộ

1. Chuẩn bị cấu hình mới BE và cập nhật URL gateway TAIL trước thời gian bảo trì; chưa khởi động bản mới khi BE chưa sẵn sàng.
2. Trong bảo trì triển khai BE + FE + cấu hình gateway/app TAIL tương ứng. Xử lý config/route cache theo quy trình deploy của host; không chạy migration vì thay đổi này không thêm bảng.
3. Kết nối màn hình cũ sẽ phải mở lại để lấy ticket mới. FE cũ không tương thích API mới.
4. Nếu rollback, rollback BE/FE và URL gateway/cấu hình BE cùng nhau. Giữ nguyên dữ liệu/counter/nhật ký trên Jetson.

## Nghiệm thu TAIL

- Trạng thái đúng device TAIL; online/offline và timestamp đúng; counter khớp UI máy.
- Lệnh được gửi đúng TAIL, kiểm tra session/expiry/STOP khi chỉnh ROI/ngưỡng như cũ; không tự retry khi timeout.
- Báo cáo ngày/reset báo cáo giữ nguyên ý nghĩa và không reset SPIKE.
- Mở màn hình TAIL, mở SPIKE ở tab trình duyệt khác không thu hồi ticket TAIL.
- Ticket/gateway key của TAIL không mở được SPIKE; người không đủ quyền không vào được API.
- SPIKE mất mạng hoặc chưa cấu hình không làm lỗi API TAIL.

Chỉ thử lệnh điều khiển máy trong điều kiện bảo trì an toàn. Không dùng máy sản xuất để chạy test tự động.
