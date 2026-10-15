<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Cap7AThingsBoard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class Cap7AController extends Controller
{
    public function status(Cap7AThingsBoard $thingsboard)
    {
        return response()->json($thingsboard->status())->header('Cache-Control', 'no-store');
    }

    public function daily(Request $request, Cap7AThingsBoard $thingsboard)
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d',
            'before_or_equal:'.now('Asia/Ho_Chi_Minh')->toDateString()]]);

        return response()->json($thingsboard->daily($data['date']))->header('Cache-Control', 'no-store');
    }

    public function command(Request $request, Cap7AThingsBoard $thingsboard)
    {
        $data = $request->validate([
            'request_id' => ['required', 'uuid'],
            'session_id' => ['required', 'uuid'],
            'action' => ['required', Rule::in(['ping', 'run', 'stop', 'test', 'reset_counters', 'set_threshold', 'set_roi', 'clear_roi'])],
            'confirmed' => ['required', 'boolean', 'accepted'],
            'values' => ['sometimes', 'array:threshold,roi'],
            'values.threshold' => ['required_if:action,set_threshold', 'numeric', 'between:1,100'],
            'values.roi' => ['required_if:action,set_roi', 'array', 'size:4'],
            'values.roi.*' => ['integer', 'min:0', 'max:600'],
        ]);
        abort_unless(config('cap7a.commands_enabled'), 403, 'Backend chưa bật điều khiển từ xa.');
        if ($data['action'] === 'set_roi') {
            [$x, $y, $w, $h] = $data['values']['roi'];
            abort_unless($w > 20 && $h > 20 && $x + $w <= 600 && $y + $h <= 400, 422, 'ROI ngoài khung 600×400.');
        }
        $deviceKey = 'cap7a:'.hash('sha256', config('cap7a.device_id') ?? '');
        $lock = Cache::lock($deviceKey.':command', 30);
        abort_unless($lock->get(), 409, 'Một lệnh khác đang xử lý.');
        try {
            $status = $thingsboard->status();
            abort_unless($status['online'], 409, 'Thiết bị offline hoặc dữ liệu đã cũ.');
            abort_unless(($status['state']['session_id'] ?? null) === $data['session_id'], 409, 'Ứng dụng đã khởi động lại; tải trạng thái mới.');
            if ($data['action'] !== 'ping') {
                abort_unless(($status['state']['remote_allowed'] ?? false) === true, 409, 'Người tại máy chưa cho phép điều khiển.');
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
            Log::info('Cap7A command dispatched', ['actor' => $request->user()->getAuthIdentifier(),
                'request_id' => $data['request_id'], 'action' => $data['action'], 'values' => $data['values']]);
            $reply = $thingsboard->command($data);
            abort_unless(($reply['request_id'] ?? null) === $data['request_id'] && is_bool($reply['ok'] ?? null),
                502, 'Phản hồi không khớp lệnh; kiểm tra máy, không tự thử lại.');
            Log::info('Cap7A command reply', ['request_id' => $data['request_id'], 'ok' => $reply['ok']]);

            return response()->json($reply)->header('Cache-Control', 'no-store');
        } finally {
            $lock->release();
        }
    }

    public function resetDaily(Request $request, Cap7AThingsBoard $thingsboard)
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        return response()->json($thingsboard->resetDaily($data['date'], (string) $request->user()->getAuthIdentifier()))
            ->header('Cache-Control', 'no-store');
    }
}
