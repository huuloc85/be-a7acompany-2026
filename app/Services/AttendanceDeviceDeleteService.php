<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class AttendanceDeviceDeleteService
{
    public function delete(string $id, string $name): void
    {
        if (trim($id) === '' || $id === '0' || trim($name) === '') {
            throw new RuntimeException('Mã hoặc tên nhân viên không hợp lệ; không thực hiện xóa.');
        }
        $ip = config('acs.device_ip');
        if (! $ip || ! config('acs.username') || ! config('acs.password')) {
            throw new RuntimeException('Chưa cấu hình kết nối máy chấm công trên bridge.');
        }
        $http = Http::withDigestAuth(config('acs.username'), config('acs.password'))->connectTimeout(3)->timeout(10);
        $base = "http://{$ip}/ISAPI/AccessControl/UserInfo";
        $readProfile = function () use ($http, $base, $id): ?array {
            $response = $http->post($base.'/Search?format=json', ['UserInfoSearchCond' => [
                'searchID' => (string) Str::uuid(), 'searchResultPosition' => 0, 'maxResults' => 2,
                'EmployeeNoList' => [['employeeNo' => $id]],
            ]]);
            $search = $response->json('UserInfoSearch');
            if (! $response->successful() || ! is_array($search) || ! isset($search['totalMatches'])
                || ! is_numeric($search['totalMatches'])) {
                throw new RuntimeException('Không xác minh được hồ sơ trên thiết bị. Không coi là đã xóa.');
            }
            $profiles = $search['UserInfo'] ?? [];
            if ((int) $search['totalMatches'] === 0 && $profiles === []) {
                return null;
            }
            if ((int) $search['totalMatches'] !== 1 || ! is_array($profiles) || count($profiles) !== 1
                || (string) ($profiles[0]['employeeNo'] ?? '') !== $id) {
                throw new RuntimeException('Kết quả tìm kiếm không khớp chính xác mã nhân viên; dừng xóa.');
            }

            return $profiles[0];
        };
        $profile = $readProfile();
        if ($profile === null) {
            return;
        }
        if (($profile['name'] ?? '') !== $name) {
            throw new RuntimeException('Mã trên thiết bị có tên khác hồ sơ web. Không xóa để tránh nhầm người.');
        }
        // Exact single employee only. Never send an empty list or an all-users deletion.
        $response = $http->put($base.'/Delete?format=json', [
            'UserInfoDelCond' => ['EmployeeNoList' => [['employeeNo' => $id]]],
        ]);
        if (! $response->successful() || (int) $response->json('statusCode') !== 1) {
            throw new RuntimeException('Máy chấm công từ chối yêu cầu xóa hồ sơ.');
        }
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($readProfile() === null) {
                return;
            }
            usleep(250000);
        }
        throw new RuntimeException('Đã gửi lệnh nhưng chưa xác minh hồ sơ đã xóa. Kiểm tra thiết bị rồi thử lại.');
    }
}
