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
            $cameras[] = ['label' => $label, 'daily' => $daily];
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

    private function respond(array $settings)
    {
        return response()->json([
            'enabled' => $settings['enabled'], 'recipient' => $settings['recipient'],
        ])->header('Cache-Control', 'no-store');
    }
}
