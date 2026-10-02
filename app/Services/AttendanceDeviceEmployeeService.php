<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class AttendanceDeviceEmployeeService
{
    public function sync(string $id, string $name): void
    {
        $ip = config('acs.device_ip');
        $username = config('acs.username');
        $password = config('acs.password');
        if (! $ip || ! $username || ! $password) {
            throw new RuntimeException('Chưa cấu hình kết nối máy chấm công trên BE.');
        }

        $http = Http::withDigestAuth($username, $password)->connectTimeout(3)->timeout(10);
        $base = "http://{$ip}/ISAPI/AccessControl/UserInfo";
        $search = ['UserInfoSearchCond' => [
            'searchID' => (string) \Illuminate\Support\Str::uuid(),
            'searchResultPosition' => 0,
            'maxResults' => 1,
            'EmployeeNoList' => [['employeeNo' => $id]],
        ]];
        $read = $http->post($base.'/Search?format=json', $search);
        if (! $read->successful() || ! is_array($read->json('UserInfoSearch'))) {
            throw new RuntimeException('Không đọc được hồ sơ từ máy chấm công. Kiểm tra kết nối và tài khoản ISAPI.');
        }
        $existing = $read->json('UserInfoSearch.UserInfo.0');
        if ($existing && (($existing['employeeNo'] ?? '') !== $id || ($existing['name'] ?? '') !== $name)) {
            throw new RuntimeException('Mã trên máy đã thuộc hồ sơ khác. Cần kiểm tra trước khi đồng bộ.');
        }

        $profile = [
            'employeeNo' => $id,
            'name' => $name,
            'userType' => $existing['userType'] ?? 'normal',
            'Valid' => $existing['Valid'] ?? [
                'enable' => true,
                'beginTime' => now()->startOfDay()->format('Y-m-d\TH:i:s'),
                'endTime' => '2036-01-01T23:59:59',
                'timeType' => 'local',
            ],
            'doorRight' => '1',
            'RightPlan' => [['doorNo' => 1, 'planTemplateNo' => '1']],
            'userVerifyMode' => 'faceOrFpOrCardOrPw',
        ];
        $response = $existing
            ? $http->put($base.'/Modify?format=json', ['UserInfo' => $profile])
            : $http->post($base.'/Record?format=json', ['UserInfo' => $profile]);
        if (! $response->successful() || (int) $response->json('statusCode') !== 1) {
            throw new RuntimeException('Máy chấm công từ chối tạo/cấp quyền hồ sơ.');
        }
        $verify = $http->post($base.'/Search?format=json', $search);
        $saved = $verify->json('UserInfoSearch.UserInfo.0');
        if (! $verify->successful() || ($saved['employeeNo'] ?? '') !== $id
            || ($saved['name'] ?? '') !== $name || ($saved['doorRight'] ?? '') !== '1'
            || ($saved['RightPlan'][0]['planTemplateNo'] ?? '') !== '1'
            || ($saved['userVerifyMode'] ?? '') !== 'faceOrFpOrCardOrPw') {
            throw new RuntimeException('Chưa xác minh được quyền trên máy. Có thể thử đồng bộ lại.');
        }
    }
}
