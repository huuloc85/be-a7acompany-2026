<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\AttendanceDeviceEmployeeService;
use App\Services\AttendanceDeviceImportService;
use App\Services\AttendanceDeviceRequestService;
use App\Services\AttendanceEventWriter;
use App\Services\AttendanceImportQueue;
use App\Services\AutomaticAttendanceImport;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;

class AttendanceDeviceBridge extends Command
{
    protected $signature = 'attendance:device-bridge
        {--once : Xử lý tối đa một yêu cầu hồ sơ và một yêu cầu lấy công}
        {--auto-import : Tự xếp lịch lấy công hôm qua và hôm nay mỗi 5 phút}';

    protected $description = 'Chạy trong LAN: lấy yêu cầu từ database host và đồng bộ máy chấm công';

    public function handle(AttendanceDeviceRequestService $queue, AttendanceDeviceEmployeeService $device): int
    {
        if (getenv('ATTENDANCE_DEVICE_BRIDGE') !== '1') {
            $this->error('Chỉ chạy tại máy LAN: đặt ATTENDANCE_DEVICE_BRIDGE=1 cho tiến trình này.');

            return self::FAILURE;
        }
        // launchd runs one process at a time; the DB claim also protects against another worker.
        $limit = $this->option('once') ? 1 : 5;
        for ($i = 0; $i < $limit; $i++) {
            $job = $queue->claim();
            if (! $job) {
                break;
            }
            try {
                $employee = Employee::find($job->employee_id);
                if (! $employee || in_array((int) $employee->role_id, [1, 15, 21, 22], true)) {
                    $queue->finish($job, 'Nhân viên đã xóa hoặc thuộc nhóm không đồng bộ.');

                    continue;
                }
                $device->sync((string) $employee->id, $employee->name);
                $queue->finish($job);
                $this->info('Đã xác minh đồng bộ yêu cầu '.$job->id);
            } catch (ConnectionException $e) {
                $queue->finish($job, 'Máy trung gian không kết nối được máy chấm công. Kiểm tra LAN rồi bấm Thử lại.');
            } catch (\RuntimeException $e) {
                $queue->finish($job, $e->getMessage());
            } catch (\Throwable $e) {
                $queue->finish($job, 'Đồng bộ lỗi. Vui lòng liên hệ IT và thử lại.');
            }
        }

        // Keep employee provisioning responsive, then process at most one history request.
        // During rollout the host migration may not be present yet.
        if (\Illuminate\Support\Facades\Schema::hasTable('attendance_import_requests')) {
            $imports = app(AttendanceImportQueue::class);
            if ($this->option('auto-import') && app(AutomaticAttendanceImport::class)->enqueueIfDue($imports)) {
                $this->info('Đã xếp yêu cầu tự động lấy công hôm qua và hôm nay (giờ Việt Nam).');
            }
            $job = $imports->claim();
            if ($job) {
                try {
                    app(AttendanceDeviceImportService::class)->run($job, $imports, app(AttendanceEventWriter::class));
                    $this->info('Đã hoàn tất yêu cầu lấy công '.$job->id);
                } catch (ConnectionException $e) {
                    $imports->fail($job, 'Không kết nối được máy chấm công. Kiểm tra máy bridge và mạng LAN rồi thử lại. Các ngày đã nhập vẫn được giữ.');
                } catch (\UnexpectedValueException $e) {
                    $imports->fail($job, $e->getMessage());
                } catch (\Throwable $e) {
                    $imports->fail($job, 'Lấy công gặp lỗi. Các ngày đã nhập vẫn được giữ; có thể thử lại an toàn hoặc liên hệ IT.');
                }
            }
        } elseif ($this->option('auto-import')) {
            $this->error('Thiếu bảng attendance_import_requests. Hãy chạy migration hàng đợi lấy công đúng database.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
