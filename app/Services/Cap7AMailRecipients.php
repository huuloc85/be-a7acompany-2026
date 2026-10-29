<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;

class Cap7AMailRecipients
{
    public static function parse(string $value): array
    {
        $recipients = array_values(array_unique(array_filter(array_map(
            fn ($email) => strtolower(trim($email)), preg_split('/[,;\r\n]+/', $value)
        ))));
        Validator::make(['recipients' => $recipients], [
            'recipients' => ['required', 'array', 'min:1', 'max:20'],
            'recipients.*' => ['required', 'string', 'email:rfc', 'max:254'],
        ], [
            'recipients.required' => 'Vui lòng nhập email nhận báo cáo.',
            'recipients.max' => 'Chỉ được nhập tối đa 20 email.',
            'recipients.*.email' => 'Danh sách có email không hợp lệ.',
            'recipients.*.max' => 'Mỗi email không được quá 254 ký tự.',
        ])->validate();

        return $recipients;
    }
}
