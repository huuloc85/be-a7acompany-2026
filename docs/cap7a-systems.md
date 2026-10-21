# Cấu hình CAP7A TAIL / SPIKE

Source mới dùng API `/api/cap7a/{system}/...`, system chỉ nhận `tail` hoặc `spike`.
Không có alias API cũ. Tài liệu `cap7a.md` cũ mô tả single-device; dùng tài liệu này cho cấu hình/route mới.

Mỗi system có service context riêng, không thay config toàn cục khi xử lý request. Cache JWT, khoá lệnh/reset, ticket, user lease và endpoint màn hình tách theo system/device.
Các route người dùng vẫn yêu cầu Sanctum, token chưa hết hạn và Super Admin như trước; chưa thêm phân quyền riêng từng camera.

## Biến môi trường mẫu (không chứa bí mật)

```dotenv
CAP7A_TAIL_ENABLED=true
CAP7A_TAIL_TB_URL=https://eu.thingsboard.cloud
CAP7A_TAIL_TB_DEVICE_ID=<UUID_TAIL>
CAP7A_TAIL_TB_API_KEY=<THINGSBOARD_ACCOUNT_API_KEY>
CAP7A_TAIL_COMMANDS_ENABLED=false
CAP7A_TAIL_SCREEN_ENABLED=true
CAP7A_TAIL_SCREEN_DYNAMIC_URL=false
CAP7A_TAIL_SCREEN_WS_URL=wss://<TAIL_SCREEN_HOST>/screen
CAP7A_TAIL_SCREEN_GATEWAY_KEY=<SECRET_TAIL_MIN_32_CHARS>

CAP7A_SPIKE_ENABLED=true
CAP7A_SPIKE_TB_URL=https://eu.thingsboard.cloud
CAP7A_SPIKE_TB_DEVICE_ID=<UUID_SPIKE>
CAP7A_SPIKE_TB_API_KEY=<THINGSBOARD_ACCOUNT_API_KEY>
CAP7A_SPIKE_SCREEN_ENABLED=false
```

TAIL COMMANDS_ENABLED: chuyển giá trị đang dùng nếu muốn giữ chức năng cũ. SPIKE hỗ trợ commands có chữ ký khi bật riêng; xem [điều khiển SPIKE](spike-remote-control.md). SPIKE chưa hỗ trợ daily.
Nếu không dùng API key, dùng `CAP7A_TAIL_TB_USERNAME/PASSWORD` hoặc `CAP7A_SPIKE_TB_USERNAME/PASSWORD` tương ứng.
Screen SPIKE để false đến khi có gateway view-only riêng được triển khai; khi đó dùng các biến SCREEN_DYNAMIC_URL, SCREEN_WS_URL, SCREEN_GATEWAY_KEY với prefix SPIKE. Không lấy URL TAIL làm URL SPIKE.

API người dùng: GET status, GET daily?date=YYYY-MM-DD, POST daily/reset, POST commands, POST screen-sessions. SPIKE trả 403 cho daily/reset; commands yêu cầu bật cấu hình và khóa ký riêng.
API gateway: POST `/api/cap7a/{system}/screen/authorize` và `/screen/register`, vẫn yêu cầu X-Cap7A-Gateway-Key.
Device ID và URL broker chỉ lấy từ config BE, không lấy từ request FE. Không gửi bí mật xuống FE.

Status có thêm `system` và `capabilities: {commands,daily,screen,threshold_unit}`. `commands` là khả năng hỗ trợ; `commands_enabled`, `remote_allowed`, online và quyền truy cập vẫn phải thoả trước khi điều khiển.
Hai device vẫn dùng key cap7a_state/cap7a_online và schema 1. Không cần đổi source MQTT trên Jetson.
SPIKE: ngưỡng grayscale, ROI chưa truyền lên; không giả lập báo cáo ngày hoặc thời gian chạy/dừng. Không cộng counter hai camera.

Triển khai BE/FE đồng bộ và cập nhật gateway TAIL theo [TAIL_HANDOFF.md](TAIL_HANDOFF.md). Không có migration DB. Cấu hình thật và deploy không được tự thực hiện bởi tác vụ sửa source.

## Kiểm tra local ngày 22/10/2026

- FE: ESLint toàn repo và `tsc -b --pretty false` đạt; không chạy Vite build.
- BE: PHP lint/Pint trên file thay đổi đạt. 34 test CAP7A có sẵn đã cập nhật cho API/config mới và 4 test cách ly tạm đạt (tổng 38 test, 181 assertion).
- Bộ kiểm tra tạm dùng SQLite memory, cache array, HTTP fake; giả lập đúng câu `SET time_zone` của AppServiceProvider để không kết nối database thật. Test tạm được dọn sau kiểm tra, không đưa vào source.
- Đã kiểm tra device/credentials tách riêng, SPIKE chặn RPC/daily/reset, ticket và gateway key không dùng chéo, lease cùng user không ghi đè giữa hai system, route cũ/system không hợp lệ bị từ chối.
- Chưa kiểm thử trên host production, broker thật hoặc gateway/VNC thật; cần nghiệm thu trong bảo trì sau deploy. Test PHP mặc định của repo vẫn cần database test riêng vì AppServiceProvider gọi SQL khi boot; không chạy thẳng trên database production.
