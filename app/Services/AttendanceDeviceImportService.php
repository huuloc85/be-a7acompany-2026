<?php

namespace App\Services;

use App\Support\AttendanceEmployeeCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AttendanceDeviceImportService
{
    public function run(object $job, AttendanceImportQueue $queue, AttendanceEventWriter $writer): void
    {
        $start = CarbonImmutable::parse($job->start_date, 'Asia/Ho_Chi_Minh')->addDays($job->completed_days);
        $end = CarbonImmutable::parse($job->end_date, 'Asia/Ho_Chi_Minh');
        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $events = $this->fetchDay($day, fn () => $queue->heartbeat($job));
            // One day's rows, counts and checkpoint commit together. A stale worker cannot write.
            DB::transaction(function () use ($job, $day, $end, $events, $writer) {
                $row = DB::table('attendance_import_requests')->where('id', $job->id)
                    ->where('claim_token', $job->claim_token)->where('status', 'processing')->lockForUpdate()->first();
                if (! $row) {
                    throw new \UnexpectedValueException('Yêu cầu đã được chuyển cho tiến trình khác.');
                }
                $counts = ['inserted' => $row->inserted, 'existing' => $row->existing,
                    'unmatched' => $row->unmatched, 'skipped' => $row->skipped];
                $unknown = json_decode($row->unmatched_codes ?? '[]', true);
                // Consistent lock order when different date-range jobs overlap.
                usort($events, fn ($a, $b) => strcmp($a['employeeNoString'] ?? '', $b['employeeNoString'] ?? ''));
                foreach ($events as $event) {
                    $code = AttendanceEmployeeCode::resolve((string) ($event['employeeNoString'] ?? ''));
                    $rawTime = $event['time'] ?? '';
                    if ($code === '' || ! is_string($rawTime) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/', $rawTime)) {
                        $counts['skipped']++;

                        continue;
                    }
                    try {
                        $time = CarbonImmutable::parse($rawTime)->setTimezone('Asia/Ho_Chi_Minh');
                    } catch (\Throwable $e) {
                        $counts['skipped']++;

                        continue;
                    }
                    if ($time->toDateString() !== $day->toDateString()) {
                        $counts['skipped']++;

                        continue;
                    }
                    $inserted = $writer->store($code, $time);
                    $counts[$inserted === null ? 'unmatched' : ($inserted ? 'inserted' : 'existing')]++;
                    if ($inserted === null && count($unknown) < 50 && ! in_array($code, $unknown, true)) {
                        $unknown[] = $code;
                    }
                }
                DB::table('attendance_import_requests')->where('id', $job->id)->update($counts + [
                    'completed_days' => $row->completed_days + 1,
                    'unmatched_codes' => json_encode($unknown), 'claimed_at' => now(), 'updated_at' => now(),
                    'status' => $day->equalTo($end) ? 'succeeded' : 'processing',
                    'claim_token' => $day->equalTo($end) ? null : $job->claim_token,
                ]);
            }, 3);
        }
    }

    public function fetchDay(CarbonImmutable $day, callable $heartbeat): array
    {
        $searchId = (string) Str::uuid();
        $position = 0;
        $all = [];
        $seenPages = [];
        for ($page = 0; $page < 5000; $page++) {
            $heartbeat();
            $response = Http::withDigestAuth(config('acs.username'), config('acs.password'))
                ->connectTimeout(3)->timeout(15)
                ->post('http://'.config('acs.device_ip').'/ISAPI/AccessControl/AcsEvent?format=json', [
                    'AcsEventCond' => [
                        'searchID' => $searchId, 'searchResultPosition' => $position, 'maxResults' => 30,
                        'major' => 0, 'minor' => 0,
                        'startTime' => $day->startOfDay()->toIso8601String(),
                        'endTime' => $day->endOfDay()->toIso8601String(),
                    ],
                ]);
            $acs = $response->json('AcsEvent');
            if (! $response->successful() || ! is_array($acs)
                || ! isset($acs['numOfMatches'], $acs['totalMatches'])) {
                throw new \UnexpectedValueException('Máy chấm công trả phản hồi lỗi. Kiểm tra cấu hình ISAPI và thử lại.');
            }
            $events = $acs['InfoList'] ?? [];
            $returned = (int) $acs['numOfMatches'];
            $total = (int) $acs['totalMatches'];
            if (! is_array($events) || count($events) !== $returned || $returned < 0 || $total < 0
                || ($returned === 0 && $position < $total)) {
                throw new \UnexpectedValueException('Máy trả lịch sử không đầy đủ; ngày đang đọc chưa được nhập. Vui lòng thử lại.');
            }
            $hash = hash('sha256', json_encode($events));
            if ($returned > 0 && isset($seenPages[$hash])) {
                throw new \UnexpectedValueException('Máy trả lặp trang lịch sử; ngày đang đọc chưa được nhập.');
            }
            $seenPages[$hash] = true;
            foreach ($events as $event) {
                if (! is_array($event)) {
                    throw new \UnexpectedValueException('Dữ liệu lịch sử không hợp lệ.');
                }
                $all[] = $event;
            }
            $position += $returned;
            if ($position >= $total) {
                return $all;
            }
        }

        throw new \UnexpectedValueException('Lịch sử vượt giới hạn xử lý trong một ngày. Vui lòng liên hệ IT.');
    }
}
