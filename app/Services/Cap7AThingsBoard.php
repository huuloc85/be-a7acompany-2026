<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class Cap7AThingsBoard
{
    public function __construct(public readonly Cap7ASystem $system) {}

    public function configured(): bool
    {
        if ($this->system->config('transport') === 'iot') {
            return (bool) $this->system->config('enabled')
                && (new Cap7AIotTransport($this->system))->configured();
        }

        return $this->system->config('enabled') && Str::isUuid($this->system->config('device_id') ?? '')
            && (trim((string) $this->system->config('api_key')) !== ''
                || ($this->system->config('username') && $this->system->config('password')))
            && preg_match('~^https://[^/?#@]+/?$~', $this->system->config('thingsboard_url') ?? '');
    }

    private function cacheKey(): string
    {
        return $this->system->cachePrefix().'jwt:'.hash('sha256', json_encode([
            $this->system->config('thingsboard_url'), $this->system->config('username'), $this->system->config('password'),
        ]));
    }

    private function token(): string
    {
        return Cache::remember($this->cacheKey(), 300, function () {
            try {
                $response = Http::acceptJson()->connectTimeout(3)->timeout(8)
                    ->withOptions(['allow_redirects' => false])
                    ->post(rtrim($this->system->config('thingsboard_url'), '/').'/api/auth/login', [
                        'username' => $this->system->config('username'), 'password' => $this->system->config('password'),
                    ]);
            } catch (ConnectionException $error) {
                throw new HttpException(503, 'Không kết nối được ThingsBoard.');
            }
            if (! $response->successful() || ! is_string($response->json('token'))) {
                throw new HttpException(503, 'Kiểm tra tài khoản ThingsBoard trong cấu hình backend.');
            }

            return $response->json('token');
        });
    }

    private function request(string $method, string $path, array $data, bool $emptyResponse = false): array
    {
        if ($this->system->config('transport') === 'iot') {
            abort_unless($this->configured(), 503, 'Chưa cấu hình dịch vụ IoT.');

            return (new Cap7AIotTransport($this->system))->request($method, $path, $data, $emptyResponse);
        }

        abort_unless($this->configured(), 503, 'Chưa cấu hình ThingsBoard cho Cap7A.');
        $apiKey = trim((string) $this->system->config('api_key'));
        $authorization = $apiKey !== '' ? 'ApiKey '.$apiKey : 'Bearer '.$this->token();
        $client = Http::acceptJson()->withHeaders(['X-Authorization' => $authorization])
            ->connectTimeout(3)->timeout(8)->withOptions(['allow_redirects' => false]);
        try {
            // Never retry an RPC: a timeout may mean it executed but its reply was lost.
            $response = $client->{$method}(rtrim($this->system->config('thingsboard_url'), '/').$path, $data);
        } catch (ConnectionException $error) {
            throw new HttpException(504, 'ThingsBoard timeout; kết quả lệnh chưa xác định. Kiểm tra máy trước khi thử lại.');
        }
        if ($response->status() === 401 && $apiKey === '') {
            Cache::forget($this->cacheKey());
        }
        if ($emptyResponse && $response->successful()) {
            return [];
        }
        if (! $response->successful() || ! is_array($response->json())) {
            throw new HttpException($response->status() === 504 ? 504 : 502,
                'Không nhận được phản hồi hợp lệ từ ThingsBoard; không tự gửi lại lệnh.');
        }

        return $response->json();
    }

    public function launcherStatus(): array
    {
        abort_unless($this->system->id === 'tail', 404);
        $device = $this->system->config('lifecycle_device_id');
        $enabled = $this->configured() && $this->system->config('lifecycle_enabled')
            && $this->system->config('commands_enabled') && Str::isUuid($device ?? '')
            && $device !== $this->system->config('device_id')
            && strlen((string) $this->system->config('lifecycle_key')) >= 32;
        if (! $enabled) {
            return ['enabled' => false, 'online' => false, 'state' => null];
        }
        $data = $this->request('get', '/api/plugins/telemetry/DEVICE/'.$device.'/values/timeseries',
            ['keys' => 'cap7a_launcher,cap7a_launcher_online']);
        $record = $data['cap7a_launcher'][0] ?? [];
        $state = $record['value'] ?? null;
        $state = is_string($state) ? json_decode($state, true) : $state;
        $valid = is_array($state) && ($state['schema'] ?? null) === 1 && ($state['system'] ?? null) === 'tail'
            && is_string($state['session_id'] ?? null) && Str::isUuid($state['session_id'])
            && is_int($state['sampled_at_ms'] ?? null) && is_bool($state['app_running'] ?? null)
            && is_bool($state['desktop_ready'] ?? null);
        $online = $data['cap7a_launcher_online'][0] ?? [];
        $now = now()->getTimestampMs();
        $fresh = $valid && is_numeric($record['ts'] ?? null)
            && $now - $record['ts'] >= -5000 && $now - $record['ts'] <= 15000
            && $now - $state['sampled_at_ms'] >= -5000 && $now - $state['sampled_at_ms'] <= 15000
            && in_array($online['value'] ?? null, [true, 'true'], true)
            && ($online['ts'] ?? 0) >= $record['ts'];

        return ['enabled' => true, 'online' => $fresh, 'state' => $valid ? [
            'session_id' => $state['session_id'], 'sampled_at_ms' => $state['sampled_at_ms'],
            'app_running' => $state['app_running'], 'desktop_ready' => $state['desktop_ready'],
            'last_exit_code' => is_int($state['last_exit_code'] ?? null) ? $state['last_exit_code'] : null,
        ] : null];
    }

    public function startApplication(array $command): array
    {
        abort_unless($this->system->id === 'tail' && $this->system->config('lifecycle_enabled')
            && $this->system->config('commands_enabled'), 403);
        $device = $this->system->config('lifecycle_device_id');
        $key = (string) $this->system->config('lifecycle_key');
        abort_unless(Str::isUuid($device ?? '') && $device !== $this->system->config('device_id')
            && strlen($key) >= 32 && ($command['action'] ?? null) === 'start_app', 422);
        $payload = json_encode($command + ['system' => 'tail'], JSON_THROW_ON_ERROR);

        return $this->request('post', '/api/rpc/twoway/'.$device, [
            'method' => 'cap7a.tail.lifecycle', 'params' => [
                'payload' => $payload, 'signature' => hash_hmac('sha256', $payload, $key),
            ], 'persistent' => false, 'timeout' => 5000, 'expirationTime' => $command['expires_at_ms'],
        ]);
    }

    public function status(): array
    {
        $metadata = ['system' => $this->system->id, 'capabilities' => $this->system->capabilities()];
        if (! $this->configured()) {
            return $metadata + ['configured' => false, 'online' => false, 'state' => null,
                'received_at_ms' => null, 'commands_enabled' => false];
        }
        $data = $this->request('get', '/api/plugins/telemetry/DEVICE/'.$this->system->config('device_id').'/values/timeseries',
            ['keys' => 'cap7a_state,cap7a_online'.($this->system->id === 'spike' ? ',cap7a_logs' : '')]);
        $record = $data['cap7a_state'][0] ?? [];
        $state = $record['value'] ?? null;
        if (is_string($state)) {
            $state = json_decode($state, true);
        }
        $received = $record['ts'] ?? null;
        $age = is_numeric($received) ? now()->getTimestampMs() - $received : INF;
        $valid = is_array($state) && ($state['schema'] ?? null) === 1
            && Str::isUuid($state['session_id'] ?? '') && is_numeric($state['sampled_at_ms'] ?? null);
        $sampleAge = $valid ? now()->getTimestampMs() - $state['sampled_at_ms'] : INF;
        $onlineRecord = $data['cap7a_online'][0] ?? [];
        $online = $onlineRecord['value'] ?? false;
        $fresh = $age >= -5000 && $age <= $this->system->config('stale_seconds', 20) * 1000
            && $sampleAge >= -5000 && $sampleAge <= $this->system->config('stale_seconds', 20) * 1000;
        $isOnline = $valid && $fresh && in_array($online, [true, 'true'], true)
            && ($onlineRecord['ts'] ?? 0) >= ($received ?? 0);

        return $metadata + ['configured' => true, 'online' => $isOnline, 'received_at_ms' => $received,
            'state' => $valid ? $state : null,
            'app_logs' => $this->system->id === 'spike' ? $this->appLogs($data['cap7a_logs'][0] ?? null) : null,
            'commands_enabled' => (bool) $this->system->config('commands_enabled')
                && ($this->system->id === 'tail' || strlen((string) $this->system->config('command_key')) >= 32)];
    }

    public function tailLogs(string $date): array
    {
        abort_unless($this->system->id === 'tail', 404);
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'Asia/Ho_Chi_Minh');
        $data = $this->request('get', '/api/plugins/telemetry/DEVICE/'.$this->system->config('device_id').'/values/timeseries', [
            'keys' => 'cap7a_app_log', 'startTs' => $start->getTimestampMs(),
            'endTs' => $start->addDay()->getTimestampMs() - 1,
            'agg' => 'NONE', 'orderBy' => 'DESC', 'limit' => 501,
        ]);
        $messages = [
            'app_open' => ['info', 'Mở ứng dụng'], 'app_close' => ['info', 'Thoát ứng dụng'],
            'worker_error' => ['error', 'Worker gặp lỗi; kiểm tra thông báo tại máy và nhật ký local.'],
            'camera_read_failed' => ['error', 'Không lấy được ảnh camera'],
            'camera_ready' => ['info', 'Camera sẵn sàng'], 'camera_unavailable' => ['warning', 'Camera chưa sẵn sàng'],
            'model_ready' => ['info', 'Model sẵn sàng'], 'model_unavailable' => ['warning', 'Model chưa sẵn sàng'],
            'worker_ready' => ['info', 'Worker đang hoạt động'], 'worker_stopped' => ['error', 'Worker đã dừng'],
            'plc_ready' => ['info', 'PLC sẵn sàng'], 'plc_unavailable' => ['error', 'PLC chưa sẵn sàng'],
            'mqtt_connected' => ['info', 'Đã kết nối MQTT'], 'mqtt_disconnected' => ['warning', 'Mất kết nối MQTT'],
            'mode_run' => ['info', 'Chế độ chạy'], 'mode_stop' => ['info', 'Chế độ dừng'],
            'mode_test' => ['info', 'Chế độ kiểm tra'], 'mode_file_test' => ['info', 'Chế độ kiểm tra ảnh'],
            'screen_start_failed' => ['error', 'Không khởi động được màn hình từ xa'],
            'log_dropped' => ['warning', 'Bộ đệm nhật ký đầy; một số sự kiện không được lưu.'],
        ];
        $records = $data['cap7a_app_log'] ?? [];
        abort_unless(is_array($records), 502, 'Nhật ký không hợp lệ.');
        $logs = [];
        foreach (array_slice($records, 0, 500) as $record) {
            if (! is_array($record)) {
                continue;
            }
            $value = $record['value'] ?? null;
            if (is_string($value)) {
                $value = strlen($value) <= 4096 ? json_decode($value, true, 16) : null;
            }
            if (! is_array($value) || ($value['schema'] ?? null) !== 1
                || ! is_string($value['code'] ?? null) || ! isset($messages[$value['code']])
                || ! is_string($value['session_id'] ?? null) || ! Str::isUuid($value['session_id'])
                || ! is_int($value['at_ms'] ?? null) || $value['at_ms'] <= 0
                || $value['at_ms'] > 9007199254740991 || ! is_int($record['ts'] ?? null)) {
                continue;
            }
            [$level, $message] = $messages[$value['code']];
            $details = [];
            foreach (['sdk_code', 'streak', 'count'] as $key) {
                $number = is_array($value['details'] ?? null) ? ($value['details'][$key] ?? null) : null;
                if (is_int($number) && $number >= -1 && $number <= 4294967295) {
                    $details[$key] = $number;
                }
            }
            $logs[] = ['id' => (string) $record['ts'], 'at_ms' => $value['at_ms'],
                'session_id' => $value['session_id'], 'code' => $value['code'],
                'level' => $level, 'message' => $message, 'details' => (object) $details];
        }

        return ['system' => 'tail', 'date' => $date, 'logs' => $logs, 'truncated' => count($records) > 500];
    }

    private function appLogs(mixed $record): ?array
    {
        // Fixed messages only: never reflect raw device logs or credentials into the API.
        $catalog = [
            'app_start' => ['info', 'Ứng dụng SPIKE khởi động.'],
            'app_exit' => ['info', 'Ứng dụng đã thoát bình thường.'],
            'app_crash' => ['error', 'Ứng dụng gặp lỗi nghiêm trọng. Kiểm tra log tại Jetson.'],
            'app_lost' => ['error', 'Kênh giám sát kết thúc khi chưa nhận xác nhận thoát từ lõi SPIKE.'],
            'app_restart' => ['warning', 'Watchdog yêu cầu tự khởi động lại ứng dụng.'],
            'ui_error' => ['error', 'Giao diện hoặc kênh truyền ảnh không hoạt động bình thường.'],
            'camera_ready' => ['info', 'Camera đã khởi động hoặc phục hồi.'],
            'camera_error' => ['error', 'Camera lỗi lấy ảnh hoặc phục hồi. Kiểm tra camera và kết nối.'],
            'camera_fallback' => ['warning', 'Chuyển phương án mở camera dự phòng.'],
            'uart_ready' => ['info', 'UART/ESP32 đã mở hoặc kết nối lại.'],
            'uart_error' => ['error', 'UART/ESP32 lỗi giao tiếp hoặc quá tải hàng đợi.'],
            'mqtt_rejected' => ['error', 'MQTT từ chối kết nối. Kiểm tra host và thông tin xác thực.'],
            'mqtt_offline' => ['warning', 'MQTT mất kết nối.'],
            'mqtt_online' => ['info', 'MQTT đã kết nối.'],
            'mqtt_error' => ['error', 'MQTT không gửi được dữ liệu hoặc tiến trình đã dừng.'],
            'storage_error' => ['error', 'Không lưu hoặc đồng bộ được dữ liệu thống kê. Kiểm tra ổ đĩa và log tại máy.'],
            'screen_ready' => ['info', 'Dịch vụ xem màn hình SPIKE đã sẵn sàng.'],
            'screen_error' => ['warning', 'Dịch vụ xem màn hình không khởi động được; kiểm tra vẫn chạy tại máy.'],
            'roi_warning' => ['warning', 'ROI đã lưu không hợp lệ; ứng dụng dùng giá trị mặc định.'],
            'counter_day' => ['info', 'Bộ đếm đã chuyển sang ngày mới.'],
            'rpc_rejected' => ['warning', 'Lệnh từ xa bị từ chối hoặc hết thời gian chờ. Kiểm tra máy trước khi thử lại.'],
            'generic_error' => ['error', 'Ứng dụng báo lỗi. Xem log tại Jetson để biết chi tiết.'],
        ];
        if (! is_array($record) || ! is_int($record['ts'] ?? null)) {
            return null;
        }
        $payload = $record['value'] ?? null;
        if (is_string($payload)) {
            if (strlen($payload) > 65536) {
                return null;
            }
            $payload = json_decode($payload, true, 16);
        }
        if (! is_array($payload) || ($payload['schema'] ?? null) !== 1
            || ! is_array($payload['events'] ?? null) || count($payload['events']) > 200) {
            return null;
        }
        $events = [];
        foreach ($payload['events'] as $event) {
            if (! is_array($event) || ! is_string($event['code'] ?? null) || ! isset($catalog[$event['code']])) {
                continue;
            }
            foreach (['id', 'at_ms', 'last_at_ms', 'count'] as $key) {
                if (! is_int($event[$key] ?? null) || $event[$key] <= 0 || $event[$key] > 9007199254740991) {
                    continue 2;
                }
            }
            if ($event['last_at_ms'] < $event['at_ms'] || $event['last_at_ms'] > now()->getTimestampMs() + 300000) {
                continue;
            }
            [$level, $message] = $catalog[$event['code']];
            $events[] = ['id' => $event['id'], 'at_ms' => $event['at_ms'], 'last_at_ms' => $event['last_at_ms'],
                'count' => $event['count'], 'observed' => ($event['observed'] ?? true) !== false,
                'level' => $level, 'message' => $message];
        }

        return ['events' => array_reverse($events), 'received_at_ms' => $record['ts'],
            'source_available' => ($payload['source_available'] ?? false) === true,
            'storage_ok' => ($payload['storage_ok'] ?? false) === true,
            'partial' => is_int($payload['skipped_bytes'] ?? null) && $payload['skipped_bytes'] > 0];
    }

    public function command(array $command): array
    {
        $this->system->requireCapability('commands');
        abort_unless($this->system->config('commands_enabled'), 403, 'Backend chưa bật điều khiển từ xa.');

        $method = 'cap7a.command';
        $params = $command;
        if ($this->system->id === 'spike') {
            $key = (string) $this->system->config('command_key');
            abort_unless(strlen($key) >= 32, 503, 'Chưa cấu hình khóa ký lệnh SPIKE.');
            $payload = json_encode($command + ['system' => 'spike'], JSON_THROW_ON_ERROR);
            $params = ['payload' => $payload, 'signature' => hash_hmac('sha256', $payload, $key)];
            $method = 'spike.command';
        }

        return $this->request('post', '/api/rpc/twoway/'.$this->system->config('device_id'), [
            'method' => $method, 'params' => $params,
            'persistent' => false, 'timeout' => 5000, 'expirationTime' => $command['expires_at_ms'],
        ]);
    }

    public function daily(string $date): array
    {
        $this->system->requireCapability('daily');
        $key = 'cap7a_daily_'.str_replace('-', '_', $date);
        $resetAt = $this->dailyResetAt($date);
        if ($resetAt > 0) {
            $key .= '_since_'.$resetAt;
        }
        $data = $this->request('get', '/api/plugins/telemetry/DEVICE/'.$this->system->config('device_id').'/values/timeseries',
            ['keys' => $key]);
        $record = $data[$key][0] ?? [];
        $report = $record['value'] ?? null;
        if (is_string($report)) {
            $report = json_decode($report, true);
        }
        if ($report === null) {
            return ['available' => false, 'date' => $date, 'report' => null,
                'reset_at_ms' => $resetAt, 'reset_pending' => $resetAt > 0];
        }
        abort_unless(is_array($report) && ($report['schema'] ?? null) === 1
            && ($report['date'] ?? null) === $date && ($report['timezone'] ?? null) === 'Asia/Ho_Chi_Minh'
            && is_array($report['hours'] ?? null) && count($report['hours']) === 24,
            502, 'Bản tổng hợp ngày không hợp lệ.');
        abort_unless(($report['reset_at_ms'] ?? 0) === $resetAt, 502, 'Báo cáo không khớp mốc reset.');
        $totals = ['ok' => 0, 'ng' => 0, 'unconfirmed' => 0];
        foreach ($report['hours'] as $hour => $row) {
            abort_unless(is_array($row) && ($row['hour'] ?? null) === $hour, 502, 'Giờ thống kê không hợp lệ.');
            foreach (array_keys($totals) as $field) {
                abort_unless(is_int($row[$field] ?? null) && $row[$field] >= 0, 502, 'Bộ đếm ngày không hợp lệ.');
                $totals[$field] += $row[$field];
            }
        }
        $classified = $totals['ok'] + $totals['ng'];

        return ['available' => true, 'date' => $date, 'received_at_ms' => $record['ts'] ?? null,
            'reset_at_ms' => $resetAt, 'reset_pending' => false,
            'report' => array_merge($totals, [
                'total' => $classified + $totals['unconfirmed'],
                'ok_percent' => $classified > 0 ? round(100 * $totals['ok'] / $classified, 2) : null,
                'hours' => $report['hours'],
                'partial' => ! empty($report['partial']),
                'coverage_start_ms' => is_int($report['coverage_start_ms'] ?? null) ? $report['coverage_start_ms'] : null,
                'warning' => ! empty($report['invalid_rows']) || ! empty($report['logger_dropped_session']) || ! empty($report['logger_error']),
            ])];
    }

    private function dailyResetAt(string $date): int
    {
        $key = 'cap7a_reset_'.str_replace('-', '_', $date);
        $attributes = $this->request('get', '/api/plugins/telemetry/DEVICE/'.$this->system->config('device_id').'/values/attributes/SHARED_SCOPE', ['keys' => $key]);
        foreach ($attributes as $attribute) {
            if (($attribute['key'] ?? null) === $key) {
                abort_unless(is_int($attribute['value'] ?? null) && $attribute['value'] > 0, 502, 'Mốc reset không hợp lệ.');

                return $attribute['value'];
            }
        }

        return 0;
    }

    public function resetDaily(string $date, string $actor): array
    {
        $this->system->requireCapability('daily');
        abort_unless($date === now('Asia/Ho_Chi_Minh')->toDateString(), 422, 'Chỉ reset hiệu suất hôm nay.');
        $lock = Cache::lock($this->system->cachePrefix().'daily-reset', 30);
        abort_unless($lock->get(), 409, 'Đang xử lý reset.');
        try {
            $resetAt = max(now()->getTimestampMs(), $this->dailyResetAt($date) + 1);
            $this->request('post', '/api/plugins/telemetry/DEVICE/'.$this->system->config('device_id').'/SHARED_SCOPE',
                ['cap7a_reset_'.str_replace('-', '_', $date) => $resetAt], true);
            \Illuminate\Support\Facades\Log::info('Cap7A daily reset', ['system' => $this->system->id, 'actor' => $actor, 'date' => $date, 'reset_at_ms' => $resetAt]);

            return ['available' => false, 'date' => $date, 'report' => null,
                'reset_at_ms' => $resetAt, 'reset_pending' => true];
        } finally {
            $lock->release();
        }
    }
}
