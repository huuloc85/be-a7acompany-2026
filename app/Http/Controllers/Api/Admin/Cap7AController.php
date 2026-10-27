<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Cap7AReadCache;
use App\Services\Cap7ASystem;
use App\Services\Cap7AThingsBoard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class Cap7AController extends Controller
{
    public function launcherStatus()
    {
        $service = new Cap7AThingsBoard(new Cap7ASystem('tail'));

        $data = (new Cap7AReadCache)->remember($service->system, 'launcher', 1, fn () => $service->launcherStatus());

        return response()->json($data)->header('Cache-Control', 'no-store');
    }

    public function startApplication(Request $request)
    {
        $data = $request->validate([
            'request_id' => ['required', 'uuid'], 'session_id' => ['required', 'uuid'],
            'confirmed' => ['required', 'boolean', 'accepted'],
        ]);
        $service = new Cap7AThingsBoard(new Cap7ASystem('tail'));
        $key = $service->system->cachePrefix();
        $lock = Cache::lock($key.':command', 30);
        abort_unless($lock->get(), 409, 'Một lệnh khác đang xử lý.');
        try {
            $launcher = $service->launcherStatus();
            abort_unless($launcher['enabled'] && $launcher['online'], 409, 'Supervisor chưa sẵn sàng.');
            $state = $launcher['state'];
            abort_unless($state['session_id'] === $data['session_id'], 409, 'Phiên supervisor đã đổi; tải trạng thái mới.');
            abort_unless($state['desktop_ready'] && ! $state['app_running'], 409, 'App đã mở hoặc desktop chưa sẵn sàng.');
            abort_if($service->status()['online'], 409, 'Ứng dụng TAIL đang kết nối.');
            abort_unless(Cache::add($key.':request:'.$data['request_id'], true, 86400), 409, 'Yêu cầu đã được xử lý; kiểm tra máy.');
            $data += ['action' => 'start_app', 'expires_at_ms' => now()->getTimestampMs() + 5000];
            Log::info('TAIL app start requested', ['actor' => $request->user()->getAuthIdentifier(), 'request_id' => $data['request_id']]);
            $reply = $service->startApplication($data);
            abort_unless(($reply['request_id'] ?? null) === $data['request_id'] && is_bool($reply['ok'] ?? null),
                502, 'Phản hồi không khớp; kiểm tra trạng thái trước khi thử lại.');
            Log::info('TAIL app start reply', ['request_id' => $data['request_id'], 'ok' => $reply['ok']]);

            return response()->json($reply)->header('Cache-Control', 'no-store');
        } finally {
            $lock->release();
        }
    }

    public function tailLogs(Request $request)
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d',
            'before_or_equal:'.now('Asia/Ho_Chi_Minh')->toDateString()]]);
        $thingsboard = app()->makeWith(Cap7AThingsBoard::class, ['system' => new Cap7ASystem('tail')]);
        $seconds = $data['date'] === now('Asia/Ho_Chi_Minh')->toDateString() ? 1 : 30;
        $logs = $thingsboard->system->config('transport') === 'iot'
            ? (new Cap7AReadCache)->remember($thingsboard->system, 'logs:'.$data['date'], $seconds,
                fn () => $thingsboard->tailLogs($data['date']))
            : Cache::remember($thingsboard->system->cachePrefix().'logs:'.$data['date'], 5,
                fn () => $thingsboard->tailLogs($data['date']));

        return $this->reportResponse($request, $logs);
    }

    public function status(string $system)
    {
        $thingsboard = app()->makeWith(Cap7AThingsBoard::class, ['system' => new Cap7ASystem($system)]);

        $data = (new Cap7AReadCache)->remember($thingsboard->system, 'status', 1, fn () => $thingsboard->status());

        return response()->json($data)->header('Cache-Control', 'no-store');
    }

    public function daily(Request $request, string $system)
    {
        $thingsboard = app()->makeWith(Cap7AThingsBoard::class, ['system' => new Cap7ASystem($system)]);
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d',
            'before_or_equal:'.now('Asia/Ho_Chi_Minh')->toDateString()]]);

        $seconds = $data['date'] === now('Asia/Ho_Chi_Minh')->toDateString() ? 1 : 30;
        $report = (new Cap7AReadCache)->remember($thingsboard->system, 'daily:'.$data['date'], $seconds,
            fn () => $thingsboard->daily($data['date']));

        return $this->reportResponse($request, $report);
    }

    public function command(Request $request, string $system)
    {
        if ($system === 'spike') {
            return $this->spikeCommand($request);
        }
        $thingsboard = app()->makeWith(Cap7AThingsBoard::class, ['system' => new Cap7ASystem($system)]);
        $thingsboard->system->requireCapability('commands');
        $data = $request->validate([
            'request_id' => ['required', 'uuid'],
            'session_id' => ['required', 'uuid'],
            'action' => ['required', Rule::in(['ping', 'run', 'stop', 'test', 'reset_counters', 'reset_runtime', 'set_threshold', 'set_roi', 'clear_roi', 'close_app'])],
            'confirmed' => ['required', 'boolean', 'accepted'],
            'values' => ['sometimes', 'array:threshold,roi'],
            'values.threshold' => ['required_if:action,set_threshold', 'numeric', 'between:1,100'],
            'values.roi' => ['required_if:action,set_roi', 'array', 'size:4'],
            'values.roi.*' => ['integer', 'min:0', 'max:600'],
        ]);
        abort_unless($thingsboard->system->config('commands_enabled'), 403, 'Backend chưa bật điều khiển từ xa.');
        if ($data['action'] === 'close_app') {
            abort_unless($thingsboard->system->config('lifecycle_enabled'), 403, 'Backend chưa bật mở/thoát ứng dụng.');
        }
        if ($data['action'] === 'set_roi') {
            [$x, $y, $w, $h] = $data['values']['roi'];
            abort_unless($w > 20 && $h > 20 && $x + $w <= 600 && $y + $h <= 400, 422, 'ROI ngoài khung 600×400.');
        }
        $deviceKey = $thingsboard->system->cachePrefix();
        $lock = Cache::lock($deviceKey.':command', 30);
        abort_unless($lock->get(), 409, 'Một lệnh khác đang xử lý.');
        try {
            $status = $thingsboard->status();
            abort_unless($status['online'], 409, 'Thiết bị offline hoặc dữ liệu đã cũ.');
            abort_unless(($status['state']['session_id'] ?? null) === $data['session_id'], 409, 'Ứng dụng đã khởi động lại; tải trạng thái mới.');
            if ($data['action'] !== 'ping') {
                abort_unless(($status['state']['remote_allowed'] ?? false) === true, 409, 'Người tại máy chưa cho phép điều khiển.');
            }
            if ($data['action'] === 'close_app') {
                abort_unless(in_array($status['state']['mode'] ?? null, ['STOP', 'RUN'], true),
                    409, 'Kết thúc chế độ kiểm tra trước khi thoát từ web.');
            }
            if (in_array($data['action'], ['set_threshold', 'set_roi', 'clear_roi'], true)) {
                abort_unless(($status['state']['mode'] ?? null) === 'STOP', 409, 'Cần STOP trước khi đổi cài đặt.');
            }
            // Mark before dispatch, including uncertain timeout outcomes. No automated replay.
            abort_unless(Cache::add($deviceKey.':request:'.$data['request_id'], true, 86400), 409, 'Mã lệnh đã được sử dụng; kiểm tra trạng thái máy.');
            $data['expires_at_ms'] = now()->getTimestampMs() + 5000;
            $data['values'] = match ($data['action']) {
                'set_threshold' => ['threshold' => (float) $data['values']['threshold']],
                'set_roi' => ['roi' => array_map('intval', $data['values']['roi'])],
                default => [],
            };
            Log::info('Cap7A command dispatched', ['system' => $system, 'actor' => $request->user()->getAuthIdentifier(),
                'request_id' => $data['request_id'], 'action' => $data['action'], 'values' => $data['values']]);
            $reply = $thingsboard->command($data);
            abort_unless(($reply['request_id'] ?? null) === $data['request_id'] && is_bool($reply['ok'] ?? null),
                502, 'Phản hồi không khớp lệnh; kiểm tra máy, không tự thử lại.');
            Log::info('Cap7A command reply', ['system' => $system, 'request_id' => $data['request_id'], 'ok' => $reply['ok']]);

            return response()->json($reply)->header('Cache-Control', 'no-store');
        } finally {
            $lock->release();
        }
    }

    public function resetDaily(Request $request, string $system)
    {
        $thingsboard = app()->makeWith(Cap7AThingsBoard::class, ['system' => new Cap7ASystem($system)]);
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        try {
            return response()->json($thingsboard->resetDaily($data['date'], (string) $request->user()->getAuthIdentifier()))
                ->header('Cache-Control', 'no-store');
        } finally {
            // Also invalidate on uncertain timeouts: reset may have reached the device.
            (new Cap7AReadCache)->invalidate($thingsboard->system, 'daily:'.$data['date']);
        }
    }

    private function reportResponse(Request $request, array $data)
    {
        $response = response()->json($data)->header('Cache-Control', 'private, no-cache, must-revalidate');
        $response->setVary(['Authorization', 'Cookie']);
        $response->setEtag(hash('sha256', $response->getContent()));
        $response->isNotModified($request);

        return $response;
    }

    private function spikeCommand(Request $request)
    {
        $context = new Cap7ASystem('spike');
        abort_unless($context->config('commands_enabled'), 403, 'Backend chưa bật điều khiển SPIKE.');
        abort_unless(strlen((string) $context->config('command_key')) >= 32, 503, 'Chưa cấu hình khóa ký lệnh SPIKE.');
        $data = $request->validate([
            'request_id' => ['required', 'uuid'], 'session_id' => ['required', 'uuid'],
            'revision' => ['required', 'integer', 'min:0', 'max:2147483646'],
            'confirmed' => ['required', 'boolean', 'accepted'],
            'action' => ['required', Rule::in(['calibrate', 'back', 'page', 'select', 'adjust',
                'threshold_view', 'reset_params', 'reset_counters', 'move_roi', 'save_roi', 'reset_roi'])],
            'values' => ['sometimes', 'array:page,index,direction,edge,dx,dy'],
            'values.page' => ['required_if:action,page', Rule::in(['vision', 'roi'])],
            'values.index' => ['required_if:action,select', 'integer', 'between:0,2'],
            'values.direction' => ['required_if:action,adjust', 'integer', Rule::in([-1, 1])],
            'values.edge' => ['required_if:action,move_roi', 'integer', 'between:0,3'],
            'values.dx' => ['required_if:action,move_roi', 'numeric', 'between:-0.1,0.1'],
            'values.dy' => ['required_if:action,move_roi', 'numeric', 'between:-0.1,0.1'],
        ]);
        $lock = Cache::lock($context->cachePrefix().'command', 30);
        abort_unless($lock->get(), 409, 'Một lệnh SPIKE khác đang xử lý.');
        try {
            $service = app()->makeWith(Cap7AThingsBoard::class, ['system' => $context]);
            $status = $service->status();
            $state = $status['state'] ?? [];
            abort_unless(($status['online'] ?? false) && ($state['remote_allowed'] ?? false) === true,
                409, 'SPIKE offline hoặc chưa bật nhận lệnh từ xa.');
            abort_unless(($state['session_id'] ?? null) === $data['session_id'], 409, 'Phiên SPIKE đã thay đổi.');
            $controls = $state['controls'] ?? [];
            abort_unless(is_string($controls['core_session'] ?? null)
                && ($controls['revision'] ?? null) === (int) $data['revision'], 409, 'Cài đặt đã thay đổi; tải lại trạng thái.');
            $cal = ! empty($controls['calibration']);
            if ($data['action'] === 'calibrate' || $data['action'] === 'reset_counters') {
                abort_if($cal, 409, 'Thoát cài đặt trước khi thao tác.');
            } else {
                abort_unless($cal, 409, 'Cần vào cài đặt SPIKE.');
            }
            if (in_array($data['action'], ['select', 'adjust', 'threshold_view', 'reset_params'], true)) {
                abort_unless(($controls['page'] ?? '') === 'vision', 409, 'Chuyển sang trang tham số.');
            }
            if (in_array($data['action'], ['move_roi', 'save_roi', 'reset_roi'], true)) {
                abort_unless(($controls['page'] ?? '') === 'roi', 409, 'Chuyển sang trang ROI.');
            }
            abort_unless(Cache::add($context->cachePrefix().'request:'.$data['request_id'], true, 86400),
                409, 'Lệnh đã được sử dụng; kiểm tra máy, không gửi lại.');
            $values = $data['values'] ?? [];
            $data['values'] = match ($data['action']) {
                'page' => ['page' => $values['page']],
                'select' => ['index' => (int) $values['index']],
                'adjust' => ['direction' => (int) $values['direction']],
                'move_roi' => ['edge' => (int) $values['edge'], 'dx' => (float) $values['dx'], 'dy' => (float) $values['dy']],
                default => [],
            };
            $data['confirmed'] = true;
            $data['revision'] = (int) $data['revision'];
            $data['core_session'] = $controls['core_session'];
            $data['expires_at_ms'] = now()->getTimestampMs() + 5000;
            Log::info('SPIKE command dispatched', ['actor' => $request->user()->getAuthIdentifier(),
                'request_id' => $data['request_id'], 'action' => $data['action'], 'revision' => $data['revision']]);
            $reply = $service->command($data);
            abort_unless(($reply['request_id'] ?? null) === $data['request_id'] && is_bool($reply['ok'] ?? null),
                502, 'Phản hồi không khớp lệnh; kiểm tra máy, không tự gửi lại.');
            Log::info('SPIKE command reply', ['request_id' => $data['request_id'], 'ok' => $reply['ok']]);

            return response()->json($reply)->header('Cache-Control', 'no-store');
        } finally {
            $lock->release();
        }
    }
}
