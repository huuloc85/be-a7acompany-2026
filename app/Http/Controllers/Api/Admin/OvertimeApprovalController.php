<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Employee;
use App\Models\RequestForm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OvertimeApprovalController extends BaseController
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'employee_id' => ['required', 'string', 'exists:employees,id'],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.\App\Services\OvertimeApprovalService::EFFECTIVE_DATE],
            'shift' => ['required', 'integer', 'in:1,2'],
            'action' => ['required', 'in:approve,cancel'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        return DB::transaction(function () use ($request, $data) {
            // Serialize decisions for this employee, including when no request exists yet.
            Employee::whereKey($data['employee_id'])->lockForUpdate()->firstOrFail();
            $forms = RequestForm::where('employee_id', $data['employee_id'])
                ->where('type', RequestForm::TYPE_DON_XIN_TANG_CA)
                ->where('form_data->ngay_ap_dung', $data['date'])
                ->where('form_data->ca_lam', $data['shift'])
                ->lockForUpdate()->get();

            if ($forms->contains('status', RequestForm::STATUS_APPROVED)) {
                return response()->json(['message' => 'Tăng ca này đã được duyệt. Vui lòng tải lại bảng công.'], 409);
            }

            $status = $data['action'] === 'approve' ? RequestForm::STATUS_APPROVED : RequestForm::STATUS_REJECTED;
            $decision = [
                'status' => $status,
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
                'rejection_reason' => $data['action'] === 'cancel' ? $data['reason'] : null,
            ];
            $pending = $forms->where('status', RequestForm::STATUS_PENDING);
            if ($data['action'] === 'cancel' && $pending->isEmpty() && $forms->isNotEmpty()) {
                return response()->json(['message' => 'Tăng ca này đã bị từ chối. Vui lòng tải lại bảng công.'], 409);
            }
            if ($pending->isEmpty()) {
                // A direct admin decision is recorded as a request, not a change to punches/hours.
                RequestForm::create([
                    'employee_id' => $data['employee_id'],
                    'type' => RequestForm::TYPE_DON_XIN_TANG_CA,
                    'title' => 'Đơn xin tăng ca',
                    'content' => 'Admin xử lý tăng ca trực tiếp trên bảng công: '.$data['reason'],
                    'form_data' => [
                        'ngay_ap_dung' => $data['date'], 'ca_lam' => (int) $data['shift'],
                        'ly_do' => $data['reason'], 'xu_ly_tu_bang_cong' => true,
                    ],
                    'submitted_at' => now(),
                    ...$decision,
                ]);
            } else {
                foreach ($pending as $form) {
                    $form->update([
                        ...$decision,
                        'form_data' => [...$form->form_data, 'ly_do_xu_ly_admin' => $data['reason']],
                    ]);
                }
            }

            return response()->json(['success' => true, 'message' => $data['action'] === 'approve'
                ? 'Đã duyệt tăng ca theo chấm công thực tế.' : 'Đã cancel tăng ca. Giữ nguyên giờ thực tế và cảnh báo.']);
        });
    }
}
