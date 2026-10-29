<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Cap7AMailRecipients;
use App\Services\Cap7AMailSettings;
use App\Services\Cap7ASystem;
use App\Services\Cap7AThingsBoard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Throwable;

class Cap7AMailSettingsController extends Controller
{
    public function show(Cap7AMailSettings $settings)
    {
        return $this->respond($settings->current());
    }

    public function update(Request $request, Cap7AMailSettings $settings)
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'recipient' => ['required', 'string', 'max:6000'],
        ], [
            'enabled.required' => 'Vui lòng chọn trạng thái gửi báo cáo.',
            'enabled.boolean' => 'Trạng thái gửi báo cáo không hợp lệ.',
            'recipient.required' => 'Vui lòng nhập email nhận báo cáo.',
            'recipient.email' => 'Email nhận báo cáo không hợp lệ.',
            'recipient.max' => 'Danh sách email không được quá 6.000 ký tự.',
        ]);

        $recipients = Cap7AMailRecipients::parse($data['recipient']);

        return $this->respond($settings->save((bool) $data['enabled'], implode(', ', $recipients)));
    }

    public function sendTest(Request $request)
    {
        $data = $request->validate([
            'recipient' => ['required', 'string', 'max:6000'],
            'confirmed' => ['required', 'accepted'],
        ]);
        $recipients = Cap7AMailRecipients::parse($data['recipient']);
        abort_if(in_array(config('mail.mailers.'.config('mail.default').'.transport'), ['log', 'array', 'failover'], true),
            422, 'Cần cấu hình dịch vụ gửi email thật trên máy chủ.');
        $generatedAt = now('Asia/Ho_Chi_Minh');
        $date = $generatedAt->toDateString();
        $cameras = [];
        foreach (['tail' => 'Camera kiểm tra điểm thiếu nhựa', 'spike' => 'Camera kiểm tra cổng keo cao'] as $system => $label) {
            $source = app()->makeWith(Cap7AThingsBoard::class, ['system' => new Cap7ASystem($system)]);
            $daily = $source->daily($date);
            abort_unless($daily['available'] && ! $daily['reset_pending'], 422,
                $label.' chưa có báo cáo ngày. Chưa gửi email tổng sản lượng.');
            $timing = $this->dailyTiming($source, $generatedAt->copy()->startOfDay()->getTimestampMs(), $generatedAt->getTimestampMs(), $date);
            $percent = $daily['report']['ok_percent'];
            $details = [
                'Tỷ lệ OK' => $percent === null ? '—' : number_format($percent, 2, ',', '.').'%',
                'Tỷ lệ NG' => $percent === null ? '—' : number_format(100 - $percent, 2, ',', '.').'%',
            ] + $timing;
            $cameras[] = ['label' => $label, 'daily' => $daily, 'details' => $details];
        }
        try {
            Mail::send(['html' => 'mail.cap7a-daily-report', 'text' => 'mail.cap7a-daily-report-text'],
                compact('generatedAt', 'cameras'),
                fn ($mail) => $mail->bcc($recipients)->subject('Báo cáo sản lượng CAP7A · '.$generatedAt->format('d/m/Y')));
        } catch (Throwable $error) {
            report($error);
            abort(502, 'Chưa xác nhận được kết quả gửi. Kiểm tra hộp thư trước khi gửi lại để tránh trùng thư.');
        }

        return response()->json(['message' => 'Đã chuyển báo cáo tổng sản lượng đến dịch vụ gửi thư. Vui lòng kiểm tra hộp thư và thư rác.']);
    }

    private function dailyTiming(Cap7AThingsBoard $source, int $start, int $end, string $date): array
    {
        $shiftStart = \Carbon\CarbonImmutable::parse($date.' 07:00:00', 'Asia/Ho_Chi_Minh')->getTimestampMs();
        $cursor = $start;
        $first = null;
        $last = null;
        $latestStart = null;
        $run = null;
        $stop = null;
        for ($page = 0; $page < 100; $page++) {
            $records = $source->reportStateRecords($cursor, $end);
            $lastTimestamp = $cursor - 1;
            foreach ($records as $record) {
                $ts = $record['ts'] ?? null;
                abort_unless(is_int($ts) && $ts >= $cursor && $ts >= $lastTimestamp && $ts <= $end,
                    502, 'Lịch sử thời gian camera không hợp lệ.');
                $lastTimestamp = $ts;
                $state = $record['value'] ?? null;
                $state = is_string($state) ? json_decode($state, true) : $state;
                if (! is_array($state) || ($state['schema'] ?? null) !== 1) {
                    continue;
                }
                $began = $state['app_started_at_ms'] ?? null;
                $exited = $state['app_exited_at_ms'] ?? null;
                if (is_int($began) && $began > 0 && $began <= $end
                    && (! is_int($exited) || $exited <= 0 || $exited >= $start)) {
                    if ($began >= $shiftStart) {
                        $first = min($first ?? $end, $began);
                    }
                    $latestStart = max($latestStart ?? 0, $began);
                    if (is_int($exited) && $exited >= max($start, $began) && $exited <= $end) {
                        $last = max($last ?? 0, $exited);
                    }
                }
                if (($state['runtime_date'] ?? null) === $date) {
                    // These are daily cumulative counters, not per-sample durations.
                    $run = $state['run_seconds'] ?? null;
                    $stop = $state['stop_seconds'] ?? null;
                }
            }
            if (count($records) < 1000 || $lastTimestamp >= $end) {
                break;
            }
            abort_if($page === 99, 422, 'Lịch sử camera quá dài. Chưa gửi báo cáo.');
            $cursor = $lastTimestamp + 1;
        }
        $time = fn ($ms) => $ms === null ? 'Chưa ghi nhận' : \Carbon\CarbonImmutable::createFromTimestampMs($ms)
            ->setTimezone('Asia/Ho_Chi_Minh')->format('H:i:s d/m');
        $duration = function ($seconds) {
            if (! is_numeric($seconds) || $seconds < 0 || $seconds > 86400) {
                return 'Chưa ghi nhận';
            }
            $seconds = (int) $seconds;

            return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
        };

        return [
            'Bắt đầu' => $time($first),
            'Kết thúc' => $latestStart !== null && ($last === null || $latestStart > $last) ? 'Chưa kết thúc' : $time($last),
            'Thời gian kiểm' => $duration($run),
            'Thời gian dừng kiểm' => $duration($stop),
        ];
    }

    private function respond(array $settings)
    {
        return response()->json([
            'enabled' => $settings['enabled'], 'recipient' => $settings['recipient'],
        ])->header('Cache-Control', 'no-store');
    }
}
