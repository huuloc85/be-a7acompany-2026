<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Cap7AMailRecipients;
use App\Services\Cap7AMailSettings;
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
        try {
            Mail::raw('Đây là email thử từ CAP7A để kiểm tra cấu hình gửi thư. Không phải báo cáo sản lượng thực tế. Thao tác này không thay đổi cấu hình gửi tự động.',
                fn ($mail) => $mail->bcc($recipients)->subject('[EMAIL THỬ] CAP7A · Kiểm tra gửi báo cáo'));
        } catch (Throwable $error) {
            report($error);
            abort(502, 'Chưa xác nhận được kết quả gửi. Kiểm tra hộp thư trước khi gửi lại để tránh trùng thư.');
        }

        return response()->json(['message' => 'Đã chuyển email thử đến dịch vụ gửi thư. Vui lòng kiểm tra hộp thư và thư rác.']);
    }

    private function respond(array $settings)
    {
        return response()->json([
            'enabled' => $settings['enabled'], 'recipient' => $settings['recipient'],
        ])->header('Cache-Control', 'no-store');
    }
}
