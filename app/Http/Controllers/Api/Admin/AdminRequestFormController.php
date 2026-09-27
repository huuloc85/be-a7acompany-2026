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
            'signatures_complete' => ! empty($requestForm->digital_signature_supervisor) && ! empty($requestForm->digital_signature_manager),
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
                $updateData = [
                    'approved_by' => Auth::id(),
                    'approved_at' => now()->format('Y-m-d H:i:s'),
                ];

                // Handle signature uploads for approval
                // Logic phân biệt:
                // - Đơn ủy quyền: User đã ký delegator/authorized, admin chỉ cần duyệt
                // - Đơn thường: Supervisor ký trước, Manager (admin) ký sau
                $signatureFields = [];
                $currentUserId = Auth::id();

                // Danh sách supervisor employee IDs
                $supervisorIds = [19010400, 20020700, 18010900, 19010300, 20102800, 22011800];

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

                $hasNewSignatures = false;

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
                        $hasNewSignatures = true;

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
                    $requestForm->update(['status' => RequestForm::STATUS_APPROVED]);
                    $message = 'Đơn ủy quyền đã được duyệt thành công';
                } else {
                    // Đơn thường → Logic phức tạp hơn tùy thuộc vào người gửi và người duyệt
                    $hasSupervisorSignature = ! empty($requestForm->digital_signature_supervisor);
                    $hasManagerSignature = ! empty($requestForm->digital_signature_manager);

                    // Danh sách supervisor employee IDs
                    $supervisorIds = [19010400, 20020700, 18010900, 19010300, 20102800, 22011800];
                    $isCurrentUserSupervisor = in_array($currentUserId, $supervisorIds);
                    $isRequestFromSupervisor = in_array($requestForm->employee_id, $supervisorIds);

                    // Case 1: Supervisor tự gửi đơn cho chính mình
                    if ($isRequestFromSupervisor) {
                        // Supervisor không thể tự ký cho chính mình → Chỉ cần Admin ký và duyệt
                        if (! $isCurrentUserSupervisor && $hasManagerSignature) {
                            // Admin đã ký và duyệt → Set status = 'approved'
                            $requestForm->update(['status' => RequestForm::STATUS_APPROVED]);
                            $message = 'Đơn yêu cầu của supervisor đã được duyệt thành công';
                        } elseif (! $isCurrentUserSupervisor) {
                            // Admin chưa ký → Giữ pending
                            $requestForm->update(['status' => RequestForm::STATUS_PENDING]);
                            $message = $hasNewSignatures ? 'Đã ký chữ ký manager. Đơn đã được duyệt' : 'Đơn chờ admin ký và duyệt';

                            // Nếu vừa ký xong thì approve luôn
                            if ($hasNewSignatures && $hasManagerSignature) {
                                $requestForm->update(['status' => RequestForm::STATUS_APPROVED]);
                                $message = 'Đơn yêu cầu của supervisor đã được duyệt thành công';
                            }
                        } else {
                            // Supervisor không thể approve đơn của chính mình
                            $requestForm->update(['status' => RequestForm::STATUS_PENDING]);
                            $message = 'Supervisor không thể duyệt đơn của chính mình. Chỉ Admin mới có thể duyệt';
                        }
                    } else {
                        // Case 2: Nhân viên thường gửi đơn → Cần cả supervisor và manager signature
                        if ($hasSupervisorSignature && $hasManagerSignature) {
                            // Có đủ 2 chữ ký → Chỉ Manager (Admin) mới có thể duyệt
                            if (! $isCurrentUserSupervisor) {
                                // Current user là Manager (Admin) → Set status = 'approved'
                                $requestForm->update(['status' => RequestForm::STATUS_APPROVED]);
                                $message = 'Đơn yêu cầu đã được duyệt thành công với đủ 2 chữ ký';
                            } else {
                                // Current user là Supervisor → Không thể duyệt, chỉ ký
                                $requestForm->update(['status' => RequestForm::STATUS_PENDING]);
                                $message = 'Đã ký chữ ký supervisor. Đơn vẫn chờ Manager duyệt';
                            }
                        } else {
                            // Chưa đủ 2 chữ ký → Giữ status = 'pending'
                            $requestForm->update(['status' => RequestForm::STATUS_PENDING]);

                            if ($hasNewSignatures) {
                                if ($isCurrentUserSupervisor) {
                                    $message = 'Đã ký chữ ký supervisor. Đơn vẫn chờ Manager ký và duyệt';
                                } else {
                                    $missingSignature = ! $hasSupervisorSignature ? 'supervisor' : 'manager';
                                    $message = "Đã lưu chữ ký. Đơn vẫn đang chờ duyệt - thiếu chữ ký {$missingSignature}";
                                }
                            } else {
                                $message = 'Đơn vẫn đang chờ duyệt - cần có chữ ký supervisor và manager';
                            }
                        }
                    }
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
                'signatures_complete' => ! empty($requestForm->digital_signature_supervisor) && ! empty($requestForm->digital_signature_manager),
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
