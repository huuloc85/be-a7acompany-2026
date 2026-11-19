<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use UnexpectedValueException;

class AttendanceDeviceCodeTransfer
{
    private function request(string $method, string $path, array $body): array
    {
        if (! config('acs.device_ip') || ! config('acs.username') || ! config('acs.password')) {
            throw new UnexpectedValueException('Chưa cấu hình máy chấm công trên bridge.');
        }
        $response = Http::withDigestAuth(config('acs.username'), config('acs.password'))
            ->connectTimeout(3)->timeout(15)->{$method}('http://'.config('acs.device_ip').'/ISAPI/AccessControl/'.$path.'?format=json', $body);
        if (! $response->successful() || ! is_array($response->json())) {
            throw new UnexpectedValueException('Máy không hỗ trợ hoặc từ chối chuyển hồ sơ/vân tay. Giữ mã cũ; liên hệ IT để kiểm tra hoặc lấy lại vân tay.');
        }

        return $response->json();
    }

    private function profile(string $code, string $name): ?array
    {
        $data = $this->request('post', 'UserInfo/Search', ['UserInfoSearchCond' => [
            'searchID' => Str::random(32), 'searchResultPosition' => 0, 'maxResults' => 2,
            'EmployeeNoList' => [['employeeNo' => $code]],
        ]])['UserInfoSearch'] ?? null;
        if (is_array($data) && isset($data['totalMatches']) && (int) $data['totalMatches'] === 0
            && empty($data['UserInfo'])) {
            return null;
        }
        $profiles = $data['UserInfo'] ?? [];
        if (! is_array($profiles) || count($profiles) !== 1 || (int) ($data['totalMatches'] ?? -1) !== 1
            || (string) ($profiles[0]['employeeNo'] ?? '') !== $code || ($profiles[0]['name'] ?? '') !== $name) {
            throw new UnexpectedValueException('Mã hoặc tên trên máy không khớp hồ sơ web. Dừng chuyển mã.');
        }
        foreach (['numOfFace', 'numOfCard'] as $count) {
            if (! isset($profiles[0][$count]) || ! is_numeric($profiles[0][$count]) || (int) $profiles[0][$count] < 0) {
                throw new UnexpectedValueException('Không xác minh được số khuôn mặt/thẻ trên máy. Giữ hồ sơ cũ để IT kiểm tra.');
            }
        }
        if ((int) $profiles[0]['numOfFace'] > 0 || (int) $profiles[0]['numOfCard'] > 0) {
            throw new UnexpectedValueException('Hồ sơ có khuôn mặt hoặc thẻ. Luồng này chỉ chuyển vân tay; giữ mã cũ để IT xử lý đầy đủ.');
        }

        return $profiles[0];
    }

    private function fingerprints(string $code, array $profile): array
    {
        $count = filter_var($profile['numOfFP'] ?? null, FILTER_VALIDATE_INT);
        if ($count === false || $count < 0 || ! (is_int($profile['numOfFP']) || is_string($profile['numOfFP']))) {
            throw new UnexpectedValueException("Máy chưa cung cấp số vân tay đã đăng ký cho mã {$code}. Vui lòng báo IT kiểm tra hồ sơ trên máy chấm công.");
        }
        $fingers = $this->readFingerprintList($code);
        if (count($fingers) < $count) {
            // Retry the documented finger positions when the bulk response is incomplete.
            $bySlot = [];
            for ($slot = 1; $slot <= 10; $slot++) {
                $bySlot += $this->readFingerprintList($code, $slot);
            }
            foreach ($fingers as $key => $finger) {
                if (! isset($bySlot[$key]) || ! $this->sameFingerprint($finger, $bySlot[$key])) {
                    throw new UnexpectedValueException("Hai lần đọc vân tay của mã {$code} cho kết quả khác nhau. Vui lòng kiểm tra hồ sơ trên máy chấm công.");
                }
            }
            $fingers = $bySlot;
        }
        $received = count($fingers);
        if ($received !== $count) {
            throw new UnexpectedValueException("Mã {$code} có {$count} vân tay đã đăng ký. Máy trả về {$received} mẫu. Vui lòng báo IT kiểm tra trên máy chấm công.");
        }

        return $fingers;
    }

    private function readFingerprintList(string $code, ?int $slot = null): array
    {
        $condition = ['searchID' => Str::random(32), 'employeeNo' => $code];
        if ($slot !== null) {
            $condition['fingerPrintID'] = $slot;
        }
        $info = $this->request('post', 'FingerPrintUpload', ['FingerPrintCond' => $condition])['FingerPrintInfo'] ?? [];
        $items = $info['FingerPrintList'] ?? [];
        if (! in_array($info['status'] ?? '', ['OK', 'NoFP'], true) || ! is_array($items)
            || (($info['status'] === 'NoFP') !== ($items === []))) {
            throw new UnexpectedValueException("Máy trả về danh sách vân tay không hợp lệ cho mã {$code}. Vui lòng báo IT kiểm tra kết nối với máy chấm công.");
        }
        $result = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! is_int($item['fingerPrintID'] ?? null) || $item['fingerPrintID'] < 1 || $item['fingerPrintID'] > 10
                || ($slot !== null && $item['fingerPrintID'] !== $slot)
                || ! is_int($item['cardReaderNo'] ?? null) || $item['cardReaderNo'] < 1
                || ! is_string($item['fingerData'] ?? null) || $item['fingerData'] === ''
                || ! in_array($item['fingerType'] ?? '', ['normalFP', 'hijackFP', 'patrolFP', 'superFP', 'dismissingFP'], true)) {
                throw new UnexpectedValueException('Dữ liệu vân tay không hợp lệ. Không thay đổi hồ sơ cũ.');
            }
            $key = $item['cardReaderNo'].':'.$item['fingerPrintID'];
            if (isset($result[$key])) {
                throw new UnexpectedValueException('Máy trả trùng vị trí vân tay. Dừng chuyển mã.');
            }
            $result[$key] = $item;
        }

        return $result;
    }

    public function copy(object $job): void
    {
        $source = $this->profile($job->old_code, $job->employee_name);
        if (! $source) {
            throw new UnexpectedValueException('Không tìm thấy mã cũ trên máy. Không tự tạo hồ sơ mới.');
        }
        $fingers = $this->fingerprints($job->old_code, $source);
        if (! $fingers) {
            throw new UnexpectedValueException('Hồ sơ cũ chưa có vân tay. Giữ mã cũ; liên hệ IT để kiểm tra trước khi chuyển.');
        }
        $target = $this->profile($job->new_code, $job->employee_name);
        if ($target && ! $job->target_reserved) {
            throw new UnexpectedValueException('Mã mới đã có trên máy. Không ghi đè; cần IT đối chiếu hồ sơ.');
        }
        if ($target) {
            foreach ($this->fingerprints($job->new_code, $target) as $key => $finger) {
                if (! isset($fingers[$key]) || ! $this->sameFingerprint($fingers[$key], $finger)) {
                    throw new UnexpectedValueException('Vân tay mã mới khác hồ sơ cũ. Không ghi đè.');
                }
            }
        }
        // Reserve the exact destination durably before HTTP, so partial copies can be retried.
        DB::table('employee_code_transfers')->where('id', $job->id)->where('claim_token', $job->claim_token)
            ->update(['target_reserved' => true, 'fingerprint_count' => count($fingers), 'updated_at' => now()]);
        $profile = $this->transferProfile($source, $job->new_code);
        $response = $this->request($target ? 'put' : 'post', $target ? 'UserInfo/Modify' : 'UserInfo/Record', ['UserInfo' => $profile]);
        if ((int) ($response['statusCode'] ?? 0) !== 1) {
            throw new UnexpectedValueException('Máy từ chối tạo hồ sơ mã mới. Hồ sơ cũ được giữ nguyên.');
        }
        foreach ($fingers as $finger) {
            $config = [
                'employeeNo' => $job->new_code, 'enableCardReader' => [$finger['cardReaderNo']],
                'fingerPrintID' => $finger['fingerPrintID'], 'fingerType' => $finger['fingerType'],
                'fingerData' => $finger['fingerData'], 'checkEmployeeNo' => true,
            ];
            if (isset($finger['leaderFP'])) {
                $config['leaderFP'] = $finger['leaderFP'];
            }
            $reply = $this->request('post', 'FingerPrint/SetUp', ['FingerPrintCfg' => $config]);
            if ((isset($reply['statusCode']) && (int) $reply['statusCode'] !== 1)
                || ($reply['FingerPrintStatus']['status'] ?? '') === 'failed') {
                throw new UnexpectedValueException('Máy từ chối sao chép vân tay. Giữ mã cũ; cần kiểm tra hoặc lấy lại vân tay.');
            }
        }
        // Read-back is required even when the device reports a successful write.
        $this->verify($job);
    }

    private function transferProfile(array $source, string $new): array
    {
        $profile = ['employeeNo' => $new];
        foreach (['name', 'userType', 'Valid', 'doorRight', 'RightPlan', 'userVerifyMode'] as $field) {
            if (! isset($source[$field])) {
                throw new UnexpectedValueException('Thiếu thông tin quyền hoặc thời hạn hồ sơ cũ. Không tự đặt lại quyền.');
            }
            $profile[$field] = $source[$field];
        }
        if (! empty($source['password'])) {
            throw new UnexpectedValueException('Hồ sơ có mật khẩu thiết bị. Cần IT kiểm tra trước khi chuyển.');
        }
        if ($source['userType'] !== 'normal') {
            throw new UnexpectedValueException('Hồ sơ thiết bị không phải nhân viên thông thường. Cần IT kiểm tra quyền trước khi chuyển.');
        }

        return $profile;
    }

    private function sameFingerprint(array $a, array $b): bool
    {
        return hash_equals($a['fingerData'], $b['fingerData']) && $a['fingerType'] === $b['fingerType']
            && ($a['leaderFP'] ?? []) === ($b['leaderFP'] ?? []);
    }

    public function verify(object $job, bool $allowDeletedSource = false): void
    {
        $source = $this->profile($job->old_code, $job->employee_name);
        $target = $this->profile($job->new_code, $job->employee_name);
        if (! $target) {
            throw new UnexpectedValueException('Không tìm thấy hồ sơ mã mới. Không xóa mã cũ.');
        }
        $actual = $this->fingerprints($job->new_code, $target);
        if (! $source) {
            if ($allowDeletedSource && $job->confirmed_at && $job->fingerprint_count > 0
                && count($actual) === (int) $job->fingerprint_count) {
                return; // Retry after device deletion succeeded but its response was lost.
            }
            throw new UnexpectedValueException('Không còn hồ sơ cũ để đối chiếu. Cần IT kiểm tra.');
        }
        $expected = $this->fingerprints($job->old_code, $source);
        if (! $expected || count($actual) !== count($expected)) {
            throw new UnexpectedValueException('Số vân tay mã mới chưa khớp mã cũ. Không xóa mã cũ.');
        }
        foreach ($expected as $key => $finger) {
            if (! isset($actual[$key]) || ! $this->sameFingerprint($finger, $actual[$key])) {
                throw new UnexpectedValueException('Vân tay đọc lại không khớp. Không xóa mã cũ.');
            }
        }
        foreach ($this->transferProfile($source, $job->new_code) as $key => $value) {
            if (($target[$key] ?? null) != $value) {
                throw new UnexpectedValueException('Quyền hoặc thời hạn mã mới không khớp mã cũ. Không xóa mã cũ.');
            }
        }
    }
}
