<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Employee;
use App\Services\AttendanceEventWriter;
use App\Support\AttendanceEmployeeCode;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AttendanceRecordController extends BaseController
{
    public function fetchTodayEvents(Request $request)
    {
        $deviceIp = config('acs.device_ip');
        $username = config('acs.username');
        $password = config('acs.password');

        $url = "http://{$deviceIp}/ISAPI/AccessControl/AcsEvent?format=json";
        // Lấy thời gian hiện tại theo múi giờ Việt Nam
        // Ưu tiên lấy thời gian do user truyền vào (nếu có)
        $startTimeStr = $request->input('start_time');
        $endTimeStr = $request->input('end_time');

        if (! $startTimeStr || ! $endTimeStr) {
            // Nếu không truyền vào thì xử lý mặc định theo khung giờ 12 tiếng
            $now = Carbon::now('Asia/Ho_Chi_Minh');
            if ($now->hour < 12) {
                $startTime = $now->copy()->startOfDay();
                $endTime = $now->copy()->startOfDay()->addHours(12)->subSecond();
            } else {
                $startTime = $now->copy()->startOfDay()->addHours(12);
                $endTime = $now->copy()->endOfDay();
            }
            $startTimeStr = $startTime->format('Y-m-d\TH:i:sP');
            $endTimeStr = $endTime->format('Y-m-d\TH:i:sP');
        } else {
            // Parse và format lại input
            $startTimeStr = Carbon::parse($startTimeStr)->format('Y-m-d\TH:i:sP');
            $endTimeStr = Carbon::parse($endTimeStr)->format('Y-m-d\TH:i:sP');
        }

        $minorCode = 0;
        $majorCode = 0;
        $searchId = (string) \Illuminate\Support\Str::uuid();

        $eventRows = [];
        $unmatchedEvents = [];
        $employeeIds = Employee::withTrashed()->pluck('id')->mapWithKeys(fn ($id) => [(string) $id => true])->all();
        $searchPosition = 0;
        $maxResults = 30;
        $writer = new AttendanceEventWriter;

        while (true) {
            $payload = [
                'AcsEventCond' => [
                    'searchID' => $searchId,
                    'searchResultPosition' => $searchPosition,
                    'maxResults' => $maxResults,
                    'major' => $majorCode,
                    'minor' => $minorCode,
                    'startTime' => $startTimeStr,
                    'endTime' => $endTimeStr,
                ],
            ];

            try {
                $response = Http::withDigestAuth($username, $password)
                    ->timeout(10)
                    ->post($url, $payload);

                if (! $response->successful()) {
                    return response()->json(['error' => 'Request failed', 'details' => $response->body()], $response->status());
                }

                $data = $response->json();
                $acs = $data['AcsEvent'] ?? [];
                $events = $acs['InfoList'] ?? [];

                foreach ($events as $ev) {
                    $employeeCode = AttendanceEmployeeCode::resolve((string) ($ev['employeeNoString'] ?? ''));

                    // Bỏ qua nếu không có mã nhân viên
                    if (empty($employeeCode)) {
                        continue;
                    }

                    if (! isset($employeeIds[$employeeCode])) {
                        $unmatchedEvents[] = [
                            'Time' => $ev['time'] ?? '',
                            'Name' => $ev['name'] ?? '',
                            'EmployeeNo' => $employeeCode,
                        ];

                        continue;
                    }

                    $eventRows[] = [
                        'Time' => $ev['time'] ?? '',
                        'Name' => $ev['name'] ?? '',
                        'EmployeeNo' => $employeeCode,
                    ];

                    // Lưu dữ liệu hợp lệ vào database
                    $writer->store($employeeCode, Carbon::parse($ev['time'] ?? null));
                }

                $numReturned = $acs['numOfMatches'] ?? 0;
                $total = $acs['totalMatches'] ?? 0;

                if ($searchPosition + $numReturned >= $total) {
                    break;
                }

                if ($numReturned <= 0) {
                    throw new \RuntimeException('Thiết bị không trả thêm dữ liệu dù chưa hết lịch sử chấm công.');
                }

                $searchPosition += $numReturned;
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error(basename(__FILE__).' - '.__FUNCTION__.' - Error: '.$e->getMessage());

                return response()->json(['error' => 'Exception occurred', 'message' => $e->getMessage()], 500);
            }
        }

        return response()->json([
            'startTime' => $startTimeStr,
            'endTime' => $endTimeStr,
            'total' => count($eventRows),
            'events' => $eventRows,
            'unmatched_total' => count($unmatchedEvents),
            'unmatched_events' => $unmatchedEvents,
        ]);
    }
}
