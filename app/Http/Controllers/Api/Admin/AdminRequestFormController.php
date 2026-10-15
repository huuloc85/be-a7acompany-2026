<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\RequestForm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AdminRequestFormController extends Controller
{
    public function destroy(Request $request, string $id): JsonResponse
    {
        $role = strtolower(trim($request->user()?->role?->role_name ?? ''));
        if (! in_array($role, ['super admin', 'super-admin', 'super_admin'], true)) {
            return response()->json(['success' => false, 'message' => 'Chỉ Super Admin được xoá đơn.'], 403);
        }

        return DB::transaction(function () use ($request, $id) {
            $form = RequestForm::whereKey($id)->lockForUpdate()->first();
            if (! $form) {
                return response()->json(['success' => false, 'message' => 'Không tìm thấy đơn yêu cầu.'], 404);
            }
            $backup = 'deleted-request-forms/'.$form->id.'-'.\Illuminate\Support\Str::uuid().'.json';
            $saved = Storage::disk('local')->put($backup, json_encode([
                'deleted_by' => $request->user()->id,
                'deleted_at' => now()->toIso8601String(),
                'request_form' => $form->getAttributes(),
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if (! $saved) {
                throw new \RuntimeException('Không thể sao lưu đơn trước khi xoá.');
            }
            // Keep signature files for recovery; no punches or attendance hours are deleted.
            $form->delete();

            return response()->json(['success' => true, 'message' => 'Đã xoá đơn yêu cầu.']);
        });
    }

    /**
     * Display a listing of all request forms for admin management
     */
    public function index(Request $request): JsonResponse
    {
        $query = RequestForm::with(['employee', 'approvedBy', 'supervisorApprovedBy', 'managerApprovedBy']);

        // Lọc theo loại đơn
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Lọc theo trạng thái
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Lọc theo employee_id
        if ($request->has('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        // Lọc theo ngày tạo
        if ($request->has('from_date')) {
            $query->whereDate('submitted_at', '>=', $request->from_date);
        }

        if ($request->has('to_date')) {
            $query->whereDate('submitted_at', '<=', $request->to_date);
        }

        $requestForms = $query->orderBy('submitted_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $requestForms,
            'types' => RequestForm::getTypes(),
            'statuses' => RequestForm::getStatuses(),
        ]);
    }

    /**
     * Display the specified resource for admin
     */
    public function show(string $id): JsonResponse
    {
        $requestForm = RequestForm::with(['employee', 'approvedBy', 'supervisorApprovedBy', 'managerApprovedBy'])->find($id);

        if (! $requestForm) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đơn yêu cầu',
            ], 404);
        }

        // Chuẩn bị thông tin chữ ký cho response
        $signatureInfo = [
            'has_supervisor_signature' => ! empty($requestForm->digital_signature_supervisor),
            'has_manager_signature' => ! empty($requestForm->digital_signature_manager),
            'digital_signature_supervisor' => $requestForm->digital_signature_supervisor,
            'digital_signature_manager' => $requestForm->digital_signature_manager,
            'signatures_complete' => $requestForm->hasRequiredApprovalSignatures(),
            'supervisor_approved_by' => $requestForm->supervisor_approved_by,
            'supervisor_approved_at' => $requestForm->supervisor_approved_at,
            'manager_approved_by' => $requestForm->manager_approved_by,
            'manager_approved_at' => $requestForm->manager_approved_at,
            'supervisor_approved_by_employee' => $requestForm->supervisorApprovedBy ? [
                'id' => $requestForm->supervisorApprovedBy->id,
                'name' => $requestForm->supervisorApprovedBy->name,
            ] : null,
            'manager_approved_by_employee' => $requestForm->managerApprovedBy ? [
                'id' => $requestForm->managerApprovedBy->id,
                'name' => $requestForm->managerApprovedBy->name,
            ] : null,
        ];

        return response()->json([
            'success' => true,
            'data' => array_merge($requestForm->toArray(), [
                'signature_info' => $signatureInfo,
            ]),
        ]);
    }

    /**
     * Approve hoặc reject đơn yêu cầu (chỉ admin)
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        $requestForm = RequestForm::find($id);

        if (! $requestForm) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đơn yêu cầu',
            ], 404);
        }

        if ($requestForm->status !== RequestForm::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'Đơn này đã được xử lý',
            ], 403);
        }

        if ($requestForm->type === RequestForm::TYPE_DON_XIN_TANG_CA && ! $requestForm->requiresManagerApproval() && $request->input('action') === 'approve') {
            // Employee-submitted overtime is approved by its assigned supervisor, not a manager signature.
            return app(\App\Http\Controllers\Api\Employee\EmpRequestFormController::class)
                ->approveBySupervisor($request, $id);
        }

        if ($requestForm->type === RequestForm::TYPE_DON_XIN_TANG_CA && $requestForm->requiresManagerApproval()
            && (in_array((string) Auth::id(), ['25060400', '22120700', '22120900', '22120300', '22122800', '22121800'], true)
                || (string) Auth::id() === (string) $requestForm->employee_id)) {
            return response()->json(['success' => false, 'message' => 'Đơn này cần quản lý nhà máy duyệt.'], 403);
        }

        try {
            DB::beginTransaction();

            // Get action from request
            $action = $request->input('action');
            $jsonData = $request->json();

            // Try to get action from JSON if input() failed
            if (! $action && $jsonData) {
                $action = $jsonData->get('action');
            }

            if ($action === 'approve') {
                $updateData = [];

                // Handle signature uploads for approval
                // Logic phân biệt:
                // - Đơn ủy quyền: User đã ký delegator/authorized, admin chỉ cần duyệt
                // - Đơn thường: Supervisor ký trước, Manager (admin) ký sau
                $signatureFields = [];
                $currentUserId = Auth::id();

                // Danh sách supervisor employee IDs
                $supervisorIds = [25060400, 22120700, 22120900, 22120300, 22122800, 22121800];

                if ($requestForm->type === RequestForm::TYPE_GIAY_UY_QUYEN) {
                    // Đơn ủy quyền: Admin không ký thêm chữ ký, chỉ duyệt
                    $signatureFields = [];
                } else {
                    // Đơn thường: Phân biệt supervisor và manager
                    if (in_array($currentUserId, $supervisorIds)) {
                        // User hiện tại là supervisor → chỉ được ký supervisor
                        $signatureFields = ['digital_signature_supervisor'];
                    } else {
                        // User hiện tại là admin (manager) → chỉ được ký manager
                        $signatureFields = ['digital_signature_manager'];
                    }
                }

                foreach ($signatureFields as $field) {
                    if ($request->hasFile($field)) {
                        $file = $request->file($field);

                        // Delete old signature if exists
                        if ($requestForm->$field && Storage::disk('public')->exists($requestForm->$field)) {
                            Storage::disk('public')->delete($requestForm->$field);
                        }

                        // Store new signature
                        $filename = time().'_'.$field.'_'.$file->getClientOriginalName();
                        $path = $file->storeAs('signatures', $filename, 'public');
                        $updateData[$field] = $path;

                        // Lưu thông tin người ký và thời gian ký
                        if ($field === 'digital_signature_supervisor') {
                            $updateData['supervisor_approved_by'] = Auth::id();
                            $updateData['supervisor_approved_at'] = now();
                        } elseif ($field === 'digital_signature_manager') {
                            $updateData['manager_approved_by'] = Auth::id();
                            $updateData['manager_approved_at'] = now();
                        }
                    }
                }

                // Cập nhật chữ ký trước
                $requestForm->update($updateData);

                // Reload để có chữ ký mới nhất
                $requestForm->refresh();

                // Kiểm tra loại đơn để áp dụng logic phê duyệt khác nhau
                if ($requestForm->type === RequestForm::TYPE_GIAY_UY_QUYEN) {
                    // Đơn ủy quyền → Admin duyệt bình thường (user đã ký trước đó)
                    // Logic: User tạo đơn → User ký (delegator/authorized) → Admin duyệt → Hoàn thành
                    $requestForm->update([
                        'status' => RequestForm::STATUS_APPROVED,
                        'approved_by' => Auth::id(),
                        'approved_at' => now(),
                    ]);
                    $message = 'Đơn ủy quyền đã được duyệt thành công';
                } else {
                    $completed = $requestForm->finalizeApproval();
                    $message = $completed
                        ? 'Đơn yêu cầu đã được duyệt thành công.'
                        : 'Đã lưu chữ ký. Đơn đang chờ đủ chữ ký duyệt.';
                }
            } else {
                $requestForm->update([
                    'status' => RequestForm::STATUS_REJECTED,
                    'approved_by' => Auth::id(),
                    'rejection_reason' => $request->input('rejection_reason'),
                    'approved_at' => now()->format('Y-m-d H:i:s'),
                ]);
                $message = 'Đơn yêu cầu đã được từ chối';
            }

            // Load relationships dựa trên loại đơn
            if ($requestForm->type === RequestForm::TYPE_GIAY_UY_QUYEN) {
                $requestForm->load(['employee', 'approvedBy', 'delegatorApprovedBy', 'authorizedApprovedBy']);
            } else {
                $requestForm->load(['employee', 'approvedBy', 'supervisorApprovedBy', 'managerApprovedBy']);
            }

            DB::commit();

            // Chuẩn bị thông tin chữ ký cho response
            $signatureInfo = [
                'has_supervisor_signature' => ! empty($requestForm->digital_signature_supervisor),
                'has_manager_signature' => ! empty($requestForm->digital_signature_manager),
                'has_delegator_signature' => ! empty($requestForm->digital_signature_delegator),
                'has_authorized_signature' => ! empty($requestForm->digital_signature_authorized),
                'digital_signature_supervisor' => $requestForm->digital_signature_supervisor,
                'digital_signature_manager' => $requestForm->digital_signature_manager,
                'digital_signature_delegator' => $requestForm->digital_signature_delegator,
                'digital_signature_authorized' => $requestForm->digital_signature_authorized,
                'signatures_complete' => $requestForm->hasRequiredApprovalSignatures(),
                'delegation_signatures_complete' => ! empty($requestForm->digital_signature_delegator) || ! empty($requestForm->digital_signature_authorized),
                'supervisor_approved_by' => $requestForm->supervisor_approved_by,
                'supervisor_approved_at' => $requestForm->supervisor_approved_at,
                'manager_approved_by' => $requestForm->manager_approved_by,
                'manager_approved_at' => $requestForm->manager_approved_at,
                'delegator_approved_by' => $requestForm->delegator_approved_by,
                'delegator_approved_at' => $requestForm->delegator_approved_at,
                'authorized_approved_by' => $requestForm->authorized_approved_by,
                'authorized_approved_at' => $requestForm->authorized_approved_at,
            ];

            // Thêm thông tin employee chỉ khi relationship đã được load
            if ($requestForm->relationLoaded('supervisorApprovedBy')) {
                $signatureInfo['supervisor_approved_by_employee'] = $requestForm->supervisorApprovedBy ? [
                    'id' => $requestForm->supervisorApprovedBy->id,
                    'name' => $requestForm->supervisorApprovedBy->name,
                ] : null;
            }

            if ($requestForm->relationLoaded('managerApprovedBy')) {
                $signatureInfo['manager_approved_by_employee'] = $requestForm->managerApprovedBy ? [
                    'id' => $requestForm->managerApprovedBy->id,
                    'name' => $requestForm->managerApprovedBy->name,
                ] : null;
            }

            if ($requestForm->relationLoaded('delegatorApprovedBy')) {
                $signatureInfo['delegator_approved_by_employee'] = $requestForm->delegatorApprovedBy ? [
                    'id' => $requestForm->delegatorApprovedBy->id,
                    'name' => $requestForm->delegatorApprovedBy->name,
                ] : null;
            }

            if ($requestForm->relationLoaded('authorizedApprovedBy')) {
                $signatureInfo['authorized_approved_by_employee'] = $requestForm->authorizedApprovedBy ? [
                    'id' => $requestForm->authorizedApprovedBy->id,
                    'name' => $requestForm->authorizedApprovedBy->name,
                ] : null;
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => array_merge($requestForm->toArray(), [
                    'signature_info' => $signatureInfo,
                ]),
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error(basename(__FILE__).' - '.__FUNCTION__.' - Error: '.$e->getMessage());
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Có lỗi xảy ra khi xử lý đơn yêu cầu',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get statistics for admin dashboard
     */
    public function getStatistics(): JsonResponse
    {
        $statistics = [
            'total' => RequestForm::count(),
            'pending' => RequestForm::where('status', RequestForm::STATUS_PENDING)->count(),
            'approved' => RequestForm::where('status', RequestForm::STATUS_APPROVED)->count(),
            'rejected' => RequestForm::where('status', RequestForm::STATUS_REJECTED)->count(),
            'by_type' => [],
        ];

        // Thống kê theo loại đơn
        foreach (RequestForm::getTypes() as $type => $typeName) {
            $statistics['by_type'][$type] = [
                'name' => $typeName,
                'count' => RequestForm::where('type', $type)->count(),
                'pending' => RequestForm::where('type', $type)->where('status', RequestForm::STATUS_PENDING)->count(),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $statistics,
        ]);
    }
}
