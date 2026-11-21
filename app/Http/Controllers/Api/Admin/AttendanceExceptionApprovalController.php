<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\RequestForm;
use App\Services\AttendanceExceptionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceExceptionApprovalController extends BaseController
{
    public function store(Request $request, AttendanceExceptionService $service)
    {
        $data = $request->validate([
            'employee_id' => ['required', 'string', 'exists:employees,id'],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now('Asia/Ho_Chi_Minh')->startOfMonth()->subMonths(2)->toDateString(), 'before_or_equal:'.now('Asia/Ho_Chi_Minh')->endOfMonth()->toDateString()],
            'shift' => ['required', 'integer', 'in:1,2'],
            'kind' => ['required', 'in:ra_ngoai,di_tre,ve_som'],
            'time_out' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'time_in' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'action' => ['required', 'in:approve,cancel'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        return DB::transaction(function () use ($request, $data, $service) {
            $employee = Employee::whereKey($data['employee_id'])->lockForUpdate()->firstOrFail();
            $row = [...$data, 'calendar_category_id' => $employee->calendar_category_id];
            $scans = AttendanceRecord::where('employee_code', $data['employee_id'])
                ->whereBetween('date', [$data['date'], Carbon::parse($data['date'])->addDay()->toDateString()])
                ->get(['datetime'])->toArray();
            $event = collect($service->events($row, $scans))->first(fn ($item) => $item['kind'] === $data['kind']
                && $item['time_in'] === ($data['time_in'] ?? null) && $item['time_out'] === ($data['time_out'] ?? null));
            if (! $event) {
                return response()->json(['message' => 'Chấm công đã thay đổi. Vui lòng tải lại bảng công.'], 409);
            }
            $type = $event['kind'] === 'ra_ngoai' ? RequestForm::TYPE_DON_XIN_RA_NGOAI : RequestForm::TYPE_DON_XIN_DI_TRE_VE_SOM;
            $forms = $service->matchingRequests(RequestForm::where('employee_id', $data['employee_id'])
                ->where('type', $type)->lockForUpdate()->get(), $row, $event);
            if ($forms->contains('status', RequestForm::STATUS_APPROVED)) {
                return response()->json(['message' => 'Đã có đơn được duyệt. Vui lòng tải lại bảng công.'], 409);
            }
            $pending = $forms->where('status', RequestForm::STATUS_PENDING);
            if ($data['action'] === 'cancel' && $pending->isEmpty() && $forms->isNotEmpty()) {
                return response()->json(['message' => 'Đơn đã bị từ chối. Vui lòng tải lại bảng công.'], 409);
            }
            $decision = [
                'status' => $data['action'] === 'approve' ? RequestForm::STATUS_APPROVED : RequestForm::STATUS_REJECTED,
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
                'rejection_reason' => $data['action'] === 'cancel' ? $data['reason'] : null,
            ];
            if ($pending->isNotEmpty()) {
                foreach ($pending as $form) {
                    $form->update([...$decision, 'form_data' => [...$form->form_data, 'ly_do_xu_ly_admin' => $data['reason']]]);
                }
            } else {
                $outKey = $type === RequestForm::TYPE_DON_XIN_RA_NGOAI ? 'gio_ra_ngoai' : 'gio_ve_som';
                $inKey = $type === RequestForm::TYPE_DON_XIN_RA_NGOAI ? 'gio_quay_lai' : 'gio_vao_tre';
                RequestForm::create([
                    'employee_id' => $data['employee_id'], 'type' => $type,
                    'title' => RequestForm::getTypes()[$type],
                    'content' => 'Admin xử lý trực tiếp trên bảng công: '.$data['reason'],
                    'form_data' => [
                        'ngay_ap_dung' => $data['date'], 'ca_lam' => (int) $data['shift'],
                        $outKey => $event['time_out'] ? ($type === RequestForm::TYPE_DON_XIN_RA_NGOAI ? Carbon::parse($event['time_out'])->format('H:i') : $event['time_out']) : null,
                        $inKey => $event['time_in'] ? ($type === RequestForm::TYPE_DON_XIN_RA_NGOAI ? Carbon::parse($event['time_in'])->format('H:i') : $event['time_in']) : null,
                        'ly_do' => $data['reason'], 'xu_ly_tu_bang_cong' => true,
                    ],
                    'submitted_at' => now(), ...$decision,
                ]);
            }

            return response()->json(['success' => true, 'message' => $data['action'] === 'approve' ? 'Đã xác nhận đơn theo chấm công thực tế.' : 'Đã từ chối đơn.']);
        });
    }
}
