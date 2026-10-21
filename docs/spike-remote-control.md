# Điều khiển SPIKE từ FE qua BE/MQTT

## Quyền và phạm vi

Super Admin đăng nhập FE gửi lệnh qua API BE được bảo vệ bởi middleware quyền hiện có.
Không nhập mật khẩu HMI trên web. Màn hình Jetson vẫn kiểm tra `HMI_CALIB_PASSWORD`.
BE ký lệnh HMAC-SHA256 bằng khóa riêng; FE không nhận khóa. Khóa này khác token MQTT
và mật khẩu HMI. Python xác minh rồi chuyển qua socket riêng cho C++ thực hiện giữa
các chu kỳ. Không viết lại thuật toán nhận diện, GPIO/UART hoặc cách lưu bộ đếm.

Hỗ trợ: Cài đặt, Quay lại, chọn/tăng/giảm tham số, ảnh nhị phân, reset tham số,
reset bộ đếm, trang ROI, dịch cạnh ROI, lưu/reset ROI. Không hỗ trợ thoát ứng dụng,
tắt bảo vệ mật khẩu hoặc RUN/STOP từ xa. TAIL dùng luồng lệnh riêng hiện có.

Vào Cài đặt chuyển máy sang chế độ căn chỉnh hiện có; Quay lại tiếp tục nhận trigger.
Chỉ thử trong thời gian bảo trì, khi người vận hành xác nhận máy an toàn.
Đây không phải dừng khẩn cấp và không thay thế interlock vật lý.

## Bật sau khi cập nhật source

1. Triển khai BE và FE mới; chép source SPIKE mới sang Jetson. Giữ config MQTT,
   file bộ đếm/căn chỉnh của Jetson; không chép venv hoặc state từ Mac.
2. Tạo khóa ngẫu nhiên tối thiểu 32 ký tự (ví dụ `openssl rand -hex 32`) trên máy
   quản trị tin cậy. Không gửi khóa lên chat/Git, không đưa vào biến VITE.
3. BE `.env` dùng đúng device SPIKE, giữ các biến TAIL riêng:

    ```dotenv
    CAP7A_SPIKE_ENABLED=true
    CAP7A_SPIKE_TB_URL=https://eu.thingsboard.cloud
    CAP7A_SPIKE_TB_DEVICE_ID=<UUID_DEVICE_SPIKE>
    CAP7A_SPIKE_TB_API_KEY=<THINGSBOARD_ACCOUNT_API_KEY>
    CAP7A_SPIKE_COMMANDS_ENABLED=true
    CAP7A_SPIKE_COMMAND_KEY=<KHOA_NGAU_NHIEN>
    ```

    Làm mới cache config theo quy trình deploy BE. API key tài khoản BE khác device
    access token trong `mqtt_config.json`. Không đổi credentials TAIL.

4. Dừng ứng dụng Jetson an toàn, rồi chạy:

    ```bash
    cd /home/vvp/cap7a-spike
    bash scripts/build_jetson_cuda.sh
    .venv-mqtt/bin/python scripts/configure_mqtt.py --remote
    ```

    Dán đúng khóa BE tại prompt ẩn. Script giữ token/host cũ và ghi config quyền 0600.
    Mở lại bằng icon PyQt5 của bản `/home/vvp/cap7a-spike`.

5. Đồng bộ giờ BE/Jetson. Mở trang SPIKE bằng Super Admin; kiểm tra online,
   trạng thái mới và nhóm **Điều khiển SPIKE**. TAIL không dùng nhóm nút này.

## Kiểm tra thực tế

- Thử Cài đặt: web không hỏi mật khẩu, màn hình máy chuyển chế độ. Quay lại,
  rồi thử vào Cài đặt trực tiếp trên Jetson: vẫn phải nhập mật khẩu.
- Chọn tham số và tăng/giảm một bước có kiểm soát; xác nhận trên máy rồi khôi phục
  giá trị ban đầu. Chờ trạng thái mới sau mỗi lệnh.
- ROI cần quan sát qua màn hình/VNC trước khi lưu. MQTT không truyền ảnh;
  gateway Screen 2 vẫn phải được cấu hình riêng nếu muốn xem trực tiếp trên FE.
- Reset tham số/ROI/bộ đếm chỉ thử khi được phép mất giá trị hiện tại.
- Kiểm tra TAIL vẫn hoạt động với device và bộ nút riêng.

Lệnh có session, revision, request ID, hạn 5 giây và chống lặp; không tự gửi lại.
Nếu timeout/mất mạng, lệnh đã đến lõi vẫn có thể đã được thực hiện. Kiểm tra máy
trước khi bấm **Đã kiểm tra máy** hoặc thao tác tiếp; timeout không có nghĩa là hủy.

Để tắt: đặt `CAP7A_SPIKE_COMMANDS_ENABLED=false` và làm mới cache config BE.
Muốn ngừng nhận RPC trên Jetson, dừng app an toàn, đặt `remote_enabled` thành `false`
trong config MQTT rồi mở lại. Telemetry/local UI vẫn hoạt động.

Đã kiểm tra local: build C++, test lõi với phần cứng giả lập, MQTT giả lập,
test BE cô lập và ESLint/TypeScript. Chưa nghiệm thu MQTT/PLC/camera trên Jetson thật.
