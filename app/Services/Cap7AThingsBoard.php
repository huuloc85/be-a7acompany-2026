<?php

namespace App\Services;

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

    public function status(): array
    {
        $metadata = ['system' => $this->system->id, 'capabilities' => $this->system->capabilities()];
        if (! $this->configured()) {
            return $metadata + ['configured' => false, 'online' => false, 'state' => null,
                'received_at_ms' => null, 'commands_enabled' => false];
        }
        $data = $this->request('get', '/api/plugins/telemetry/DEVICE/'.$this->system->config('device_id').'/values/timeseries',
            ['keys' => 'cap7a_state,cap7a_online']);
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
            'commands_enabled' => (bool) $this->system->config('commands_enabled')
                && ($this->system->id === 'tail' || strlen((string) $this->system->config('command_key')) >= 32)];
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
