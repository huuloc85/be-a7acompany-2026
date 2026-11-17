<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\AttendanceImportQueue;
use App\Services\EmployeeCodeTransferService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AttendanceCodeTransfer extends Command
{
    protected $signature = 'attendance:code-transfer
        {action=status : status, register, retry, confirm}
        {old? : Mã cũ}
        {new? : Mã mới đã có trên web; bắt buộc khi register/confirm}
        {--from= : Ngày bắt đầu lấy lại lịch sử YYYY-MM-DD; bắt buộc khi register}
        {--tested : Đã chấm thử bằng vân tay và xác nhận máy ghi đúng mã mới}
        {--history-checked : Đã đối chiếu đủ lịch sử chấm công trên web}
        {--worker-stopped : Đã dừng mọi bridge cũ trước khi thử lại yêu cầu bị ngắt}';

    protected $description = 'Theo dõi chuyển mã qua bridge; chỉ xóa mã cũ sau khi xác nhận chấm thử và lịch sử';

    public function handle(EmployeeCodeTransferService $service): int
    {
        if (! Schema::hasTable('employee_code_transfers')) {
            $this->error('Chưa có bảng employee_code_transfers. Chạy migration chuyển mã trước.');

            return self::FAILURE;
        }
        try {
            $action = $this->argument('action');
            $old = (string) $this->argument('old');
            $new = (string) $this->argument('new');
            if ($action === 'status') {
                $this->table(['Mã cũ', 'Mã mới', 'Trạng thái', 'Yêu cầu lấy công', 'Lỗi'],
                    DB::table('employee_code_transfers')->when($old !== '', fn ($q) => $q->where('old_code', $old))
                        ->orderByDesc('id')->limit(50)->get()->map(fn ($r) => [
                            $r->old_code, $r->new_code, $r->status, $r->history_import_id, $r->error,
                        ])->all());

                return self::SUCCESS;
            }
            DB::transaction(function () use ($service, $action, $old, $new) {
                if ($action === 'register') {
                    $from = (string) $this->option('from');
                    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
                    if (! $date || $date->format('Y-m-d') !== $from || $from > now('Asia/Ho_Chi_Minh')->toDateString()) {
                        throw new \RuntimeException('Cần --from=YYYY-MM-DD hợp lệ để lấy lại lịch sử theo mã cũ.');
                    }
                    $employee = Employee::whereKey($new)->lockForUpdate()->firstOrFail();
                    $service->record($old, $employee, $from);

                    return;
                }
                $row = DB::table('employee_code_transfers')->where('old_code', $old)->lockForUpdate()->first();
                if (! $row) {
                    throw new \RuntimeException('Không tìm thấy yêu cầu chuyển mã.');
                }
                if ($action === 'confirm') {
                    if ($new !== $row->new_code || ! $this->option('tested') || ! $this->option('history-checked')) {
                        throw new \RuntimeException('Cần đúng mã mới, --tested và --history-checked. Chỉ xác nhận sau khi chấm thử đúng mã và đối chiếu lịch sử.');
                    }
                    $service->confirm($row);
                } elseif ($action === 'retry') {
                    if (in_array($row->status, ['processing', 'deleting'], true) && ! $this->option('worker-stopped')) {
                        throw new \RuntimeException('Yêu cầu đang chạy. Chỉ dùng --worker-stopped sau khi đã dừng mọi tiến trình bridge.');
                    }
                    if (! in_array($row->status, ['failed', 'delete_failed', 'processing', 'deleting', 'pending', 'delete_pending'], true)) {
                        throw new \RuntimeException('Trạng thái hiện tại không cần thử lại.');
                    }
                    $history = DB::table('attendance_import_requests')->find($row->history_import_id);
                    if (! $history) {
                        throw new \RuntimeException('Không tìm thấy yêu cầu lấy lịch sử. Cần IT kiểm tra.');
                    }
                    app(AttendanceImportQueue::class)->enqueue($history->start_date, $history->end_date, $row->new_code);
                    DB::table('employee_code_transfers')->where('id', $row->id)->update([
                        'status' => $row->confirmed_at ? 'delete_pending' : 'pending',
                        'error' => null, 'claim_token' => null, 'updated_at' => now(),
                    ]);
                } else {
                    throw new \RuntimeException('Thao tác hợp lệ: status, register, retry, confirm.');
                }
            });
            $this->info('Đã lưu yêu cầu. Bridge trong mạng công ty sẽ xử lý; chưa xác nhận thành công trên máy.');

            return self::SUCCESS;
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->error(collect($e->errors())->flatten()->implode(' '));
        } catch (\Throwable $e) {
            $this->error($e instanceof \RuntimeException && ! $e instanceof \Illuminate\Database\QueryException
                ? $e->getMessage() : 'Không lưu được yêu cầu. Kiểm tra mã nhân viên và migration.');
        }

        return self::FAILURE;
    }
}
