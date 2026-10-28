<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Adapter only: keep existing state/report parsing, RPC signing and guards unchanged.
class Cap7AIotTransport
{
    public function __construct(private readonly Cap7ASystem $system) {}

    public function configured(): bool
    {
        return Str::isUuid($this->system->config('device_id') ?? '')
            && strlen((string) config('iot.token')) >= 32
            && preg_match('~^https://[^/?#@]+/?$~', config('iot.url') ?? '');
    }

    public function request(string $method, string $path, array $data, bool $emptyResponse = false): array
    {
        $allowed = array_filter([$this->system->config('device_id'),
            $this->system->config('lifecycle_device_id')]);
        $device = null;
        $resource = null;
        if (preg_match('~^/api/plugins/telemetry/DEVICE/([^/]+)/(values/timeseries|values/attributes/SHARED_SCOPE|SHARED_SCOPE)$~', $path, $match)) {
            $device = $match[1];
            $resource = $match[2] === 'values/timeseries' ? 'records' : 'attributes';
        } elseif (preg_match('~^/api/rpc/twoway/([^/]+)$~', $path, $match)) {
            $device = $match[1];
            $resource = 'commands';
        }
        abort_unless(in_array($device, $allowed, true) && in_array($method, ['get', 'post'], true), 500,
            'Đường dẫn dịch vụ IoT không hợp lệ.');
        $url = rtrim(config('iot.url'), '/').'/api/v1/products/cap7a/systems/'.$this->system->id
            .'/devices/'.rawurlencode($device).'/'.$resource;
        $headers = [];
        if (config('iot.access_client_id') && config('iot.access_client_secret')) {
            $headers = ['CF-Access-Client-Id' => config('iot.access_client_id'),
                'CF-Access-Client-Secret' => config('iot.access_client_secret')];
        }
        try {
            // Never retry: a timeout does not prove that a command was not executed.
            $response = Http::acceptJson()->withToken(config('iot.token'))->withHeaders($headers)
                ->connectTimeout(3)->timeout(8)->withOptions(['allow_redirects' => false])
                ->{$method}($url, $data);
        } catch (ConnectionException $error) {
            throw new HttpException(504, 'Dịch vụ IoT không phản hồi; kiểm tra trạng thái máy trước khi thử lại.');
        }
        if (! $response->successful()) {
            $status = in_array($response->status(), [403, 409, 422, 503, 504], true) ? $response->status() : 502;
            throw new HttpException($status, 'Dịch vụ IoT từ chối hoặc chưa xác nhận yêu cầu; không tự gửi lại lệnh.');
        }
        if ($emptyResponse) {
            return [];
        }
        abort_unless(is_array($response->json()), 502, 'Phản hồi dịch vụ IoT không hợp lệ.');

        return $response->json();
    }
}
