<?php

namespace App\Http\Controllers\Api\Employee;

use App\Http\Controllers\Controller;
use App\Jobs\SendRequestFormNotificationJob;
use App\Models\RequestForm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class EmpRequestFormController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        // Employee chỉ xem được đơn của mình
        $query = RequestForm::query()->where('employee_id', Auth::id());

        // Lọc theo loại đơn
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Lọc theo trạng thái
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Lọc theo ngày tạo
        if ($request->has('from_date')) {
            $query->whereDate('submitted_at', '>=', $request->from_date);
        }

        if ($request->has('to_date')) {
            $query->whereDate('submitted_at', '<=', $request->to_date);
        }

        // ✅ Select TẤT CẢ columns cần thiết cho response (theo yêu cầu FE)
        $query->select([
            'id',
            'employee_id',
            'type',
            'title',
            'content',
            'form_data',
            'status',
            'rejection_reason',
            'submitted_at',
            'approved_at',
            'rejected_at',
            'created_at',
            'updated_at',
            // Giấy ủy quyền fields
            'authorized_employee_id',
            'digital_signature_delegator',
            'digital_signature_authorized',
            'delegator_approved_by',
            'delegator_approved_at',
            'authorized_approved_by',
            'authorized_approved_at',
            // Đơn thường fields
            'supervisor_id',
            'digital_signature_applicant',
            'digital_signature_supervisor',
            'digital_signature_manager',
            'supervisor_approved_by',
            'supervisor_approved_at',
            'manager_approved_by',
            'manager_approved_at',
            'approved_by',
        ]);

        // ✅ Eager load TẤT CẢ relationships với FULL FIELDS (id, name, email, phone) theo yêu cầu FE
        // Load tất cả để đảm bảo không bị thiếu dữ liệu khi không filter type
        $query->with([
            'employee:id,name,email,phone',
            // Giấy ủy quyền relationships
            'delegatorApprovedBy:id,name,email,phone',
            'authorizedApprovedBy:id,name,email,phone',
            'authorizedEmployee:id,name,email,phone',
            // Đơn thường relationships
            'approvedBy:id,name,email,phone',
            'supervisor:id,name,email',
            'supervisorApprovedBy:id,name,email,phone',
            'managerApprovedBy:id,name,email,phone',
        ]);

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
     * Get request forms as supervisor (for specific supervisor users)
     * Endpoint: GET /api/employee/request-forms/as-supervisor
     */
    public function getAsSupervisor(Request $request): JsonResponse
    {
        $currentUserId = Auth::id();

        // Danh sách supervisor employee IDs được phép truy cập
        $supervisorIds = ['25060400', '22120700', '22120900', '22120300', '22122800', '22121800'];

        // Kiểm tra user hiện tại có phải supervisor không
        if (! in_array($currentUserId, $supervisorIds)) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền truy cập danh sách này',
            ], 403);
        }

        // Query các đơn có supervisor_id = user hiện tại
        $query = RequestForm::query()
            ->where('supervisor_id', $currentUserId)
            ->where('employee_id', '!=', $currentUserId); // Loại bỏ đơn do chính mình tạo

        // Lọc theo loại đơn (nếu có)
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Luôn loại bỏ đơn giấy ủy quyền
        $query->where('type', '!=', RequestForm::TYPE_GIAY_UY_QUYEN);

        // Lọc theo trạng thái (nếu có)
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Lọc theo employee_id (nếu có)
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

        // Select các columns cần thiết
        $query->select([
            'id',
            'employee_id',
            'type',
            'title',
            'content',
            'form_data',
            'status',
            'rejection_reason',
            'submitted_at',
            'approved_at',
            'rejected_at',
            'created_at',
            'updated_at',
            'supervisor_id',
            'digital_signature_applicant',
            'digital_signature_supervisor',
            'digital_signature_manager',
            'supervisor_approved_by',
            'supervisor_approved_at',
            'manager_approved_by',
            'manager_approved_at',
            'approved_by',
        ]);

        // Eager load relationships
        $query->with([
            'employee:id,name,email,phone',
            'approvedBy:id,name,email,phone',
            'supervisor:id,name,email',
            'supervisorApprovedBy:id,name,email,phone',
            'managerApprovedBy:id,name,email,phone',
        ]);

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
     * Get detail of a request form as supervisor
     * Endpoint: GET /api/employee/request-forms/as-supervisor/{id}
     */
    public function getDetailAsSupervisor(string $id): JsonResponse
    {
        $currentUserId = Auth::id();

        // Danh sách supervisor employee IDs được phép truy cập
        $supervisorIds = ['25060400', '22120700', '22120900', '22120300', '22122800', '22121800'];

        // Kiểm tra user hiện tại có phải supervisor không
        if (! in_array($currentUserId, $supervisorIds)) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền truy cập',
            ], 403);
        }

        $requestForm = RequestForm::with([
            'employee:id,name,email,phone',
            'approvedBy:id,name,email,phone',
            'supervisor:id,name,email,phone',
            'supervisorApprovedBy:id,name,email,phone',
            'managerApprovedBy:id,name,email,phone',
        ])->find($id);

        if (! $requestForm) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đơn yêu cầu',
            ], 404);
        }

        // Kiểm tra đơn này có thuộc quyền quản lý của supervisor không
        if ($requestForm->supervisor_id !== $currentUserId) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền xem đơn này',
            ], 403);
        }

        // Không cho phép xem đơn giấy ủy quyền
        if ($requestForm->type === RequestForm::TYPE_GIAY_UY_QUYEN) {
            return response()->json([
                'success' => false,
                'message' => 'Không có quyền xem loại đơn này',
            ], 403);
        }

        // Chuẩn bị thông tin chữ ký cho response
        $signatureInfo = [
            'has_applicant_signature' => ! empty($requestForm->digital_signature_applicant),
            'has_supervisor_signature' => ! empty($requestForm->digital_signature_supervisor),
            'has_manager_signature' => ! empty($requestForm->digital_signature_manager),
            'digital_signature_applicant' => $requestForm->digital_signature_applicant,
            'digital_signature_supervisor' => $requestForm->digital_signature_supervisor,
            'digital_signature_manager' => $requestForm->digital_signature_manager,
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
     * Approve request form by supervisor with digital signature
     * Endpoint: POST /api/employee/request-forms/as-supervisor/{id}/approve
     */
    public function approveBySupervisor(Request $request, string $id): JsonResponse
    {
        $currentUserId = Auth::id();

        // Danh sách supervisor employee IDs được phép truy cập
        $supervisorIds = ['25060400', '22120700', '22120900', '22120300', '22122800', '22121800'];

        // Kiểm tra user hiện tại có phải supervisor không
        if (! in_array($currentUserId, $supervisorIds)) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền duyệt đơn',
            ], 403);
        }

        $requestForm = RequestForm::find($id);

        if (! $requestForm) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đơn yêu cầu',
            ], 404);
        }

        // Kiểm tra quyền
        if ($requestForm->supervisor_id !== $currentUserId) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền duyệt đơn này',
            ], 403);
        }

        // Kiểm tra trạng thái đơn
        if ($requestForm->status !== RequestForm::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'Đơn này đã được xử lý',
            ], 403);
        }

        // Không cho phép duyệt đơn giấy ủy quyền
        if ($requestForm->type === RequestForm::TYPE_GIAY_UY_QUYEN) {
            return response()->json([
                'success' => false,
                'message' => 'Không có quyền duyệt loại đơn này',
            ], 403);
        }

        // Kiểm tra đã có chữ ký supervisor chưa
        if (! empty($requestForm->digital_signature_supervisor)) {
            return response()->json([
                'success' => false,
                'message' => 'Đơn này đã được supervisor ký',
            ], 403);
        }

        try {
            DB::beginTransaction();

            $updateData = [];

            // Xử lý upload chữ ký điện tử supervisor
            if ($request->hasFile('digital_signature_supervisor')) {
                // Case 1: File upload (multipart/form-data)
                $file = $request->file('digital_signature_supervisor');
                $filename = 'digital_signature_supervisor_'.$currentUserId.'_'.time().'.'.$file->getClientOriginalExtension();
                $path = $file->storeAs('digital_signatures', $filename, 'public');
                $updateData['digital_signature_supervisor'] = $path;
            } elseif ($request->has('digital_signature_supervisor') && is_string($request->input('digital_signature_supervisor'))) {
                // Case 2: Base64 string (từ frontend canvas/signature pad)
                $base64Data = $request->input('digital_signature_supervisor');

                if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $matches)) {
                    $extension = $matches[1];
                    $base64Data = preg_replace('/^data:image\/\w+;base64,/', '', $base64Data);
                    $base64Data = str_replace(' ', '+', $base64Data);
                    $imageData = base64_decode($base64Data);

                    if ($imageData !== false) {
                        $filename = 'digital_signature_supervisor_'.$currentUserId.'_'.time().'.'.$extension;
                        $path = 'digital_signatures/'.$filename;
                        Storage::disk('public')->put($path, $imageData);
                        $updateData['digital_signature_supervisor'] = $path;
                    }
                }
            }

            if (empty($updateData['digital_signature_supervisor'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vui lòng cung cấp chữ ký điện tử',
                ], 400);
            }

            // Cập nhật thông tin người ký và thời gian ký
            $updateData['supervisor_approved_by'] = $currentUserId;
            $updateData['supervisor_approved_at'] = now();

            $requestForm->update($updateData);
            $requestForm->refresh();
            $requestForm->load(['employee', 'supervisor', 'supervisorApprovedBy']);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Đã ký duyệt đơn thành công. Đơn sẽ được chuyển cho quản lý nhà máy duyệt tiếp.',
                'data' => $requestForm,
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error(basename(__FILE__).' - '.__FUNCTION__.' - Error: '.$e->getMessage());
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Có lỗi xảy ra khi duyệt đơn',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reject request form by supervisor
     * Endpoint: POST /api/employee/request-forms/as-supervisor/{id}/reject
     */
    public function rejectBySupervisor(Request $request, string $id): JsonResponse
    {
        $currentUserId = Auth::id();

        // Danh sách supervisor employee IDs được phép truy cập
        $supervisorIds = ['25060400', '22120700', '22120900', '22120300', '22122800', '22121800'];

        // Kiểm tra user hiện tại có phải supervisor không
        if (! in_array($currentUserId, $supervisorIds)) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền từ chối đơn',
            ], 403);
        }

        $requestForm = RequestForm::find($id);

        if (! $requestForm) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đơn yêu cầu',
            ], 404);
        }

        // Kiểm tra quyền
        if ($requestForm->supervisor_id !== $currentUserId) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền từ chối đơn này',
            ], 403);
        }

        // Kiểm tra trạng thái đơn
        if ($requestForm->status !== RequestForm::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'Đơn này đã được xử lý',
            ], 403);
        }

        // Không cho phép từ chối đơn giấy ủy quyền
        if ($requestForm->type === RequestForm::TYPE_GIAY_UY_QUYEN) {
            return response()->json([
                'success' => false,
                'message' => 'Không có quyền từ chối loại đơn này',
            ], 403);
        }

        // Validate rejection reason
        $request->validate([
            'rejection_reason' => 'required|string|min:10|max:500',
        ]);

        try {
            DB::beginTransaction();

            // Update request form
            $requestForm->update([
                'status' => RequestForm::STATUS_REJECTED,
                'rejection_reason' => $request->rejection_reason,
                'rejected_at' => now(),
            ]);

            $requestForm->refresh();
            $requestForm->load(['employee', 'supervisor']);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Đã từ chối đơn',
                'data' => [
                    'id' => $requestForm->id,
                    'status' => $requestForm->status,
                    'rejection_reason' => $requestForm->rejection_reason,
                    'rejected_at' => $requestForm->rejected_at,
                ],
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error(basename(__FILE__).' - '.__FUNCTION__.' - Error: '.$e->getMessage());
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Có lỗi xảy ra khi từ chối đơn',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            DB::beginTransaction();

            // Chuẩn bị data để tạo đơn
            $createData = [
                'employee_id' => Auth::id(),
                'type' => $request->type,
                'title' => $request->title,
                'content' => $request->content,
                'form_data' => $request->form_data ?? [],
                'status' => RequestForm::STATUS_PENDING,
                'submitted_at' => now(),
            ];

            // Nếu là đơn ủy quyền, lưu thêm authorized_employee_id
            if ($request->type === RequestForm::TYPE_GIAY_UY_QUYEN) {
                $formData = is_array($request->form_data) ? $request->form_data : json_decode($request->form_data, true);
                if (! empty($formData['authorized_employee_id'])) {
                    $createData['authorized_employee_id'] = $formData['authorized_employee_id'];
                }
            } else {
                // Nếu là đơn thường, lưu supervisor_id
                if ($request->has('supervisor_id')) {
                    $createData['supervisor_id'] = $request->supervisor_id;
                }
            }

            // Tạo đơn
            $requestForm = RequestForm::create($createData);

            // Lấy thông tin supervisor để gửi email thông báo (nếu có)
            $supervisor = null;
            if (! empty($createData['supervisor_id'])) {
                $supervisor = \App\Models\Employee::select('id', 'name', 'email', 'phone')
                    ->find($createData['supervisor_id']);
            }

            // Xử lý upload chữ ký điện tử theo loại đơn
            // Hỗ trợ cả 2 format: file upload (multipart) VÀ base64 string
            $signatureFields = $requestForm->getDigitalSignatureFields();
            $updateData = [];

            foreach ($signatureFields as $fieldName => $fieldLabel) {
                if ($request->hasFile($fieldName)) {
                    // Case 1: File upload (multipart/form-data)
                    $file = $request->file($fieldName);
                    $filename = $fieldName.'_'.Auth::id().'_'.time().'.'.$file->getClientOriginalExtension();
                    $path = $file->storeAs('digital_signatures', $filename, 'public');
                    $updateData[$fieldName] = $path;
                } elseif ($request->has($fieldName) && is_string($request->input($fieldName))) {
                    // Case 2: Base64 string (từ frontend canvas/signature pad)
                    $base64Data = $request->input($fieldName);

                    // Kiểm tra và xử lý base64 data
                    if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $matches)) {
                        $extension = $matches[1]; // png, jpeg, etc.
                        $base64Data = preg_replace('/^data:image\/\w+;base64,/', '', $base64Data);
                        $base64Data = str_replace(' ', '+', $base64Data);
                        $imageData = base64_decode($base64Data);

                        if ($imageData !== false) {
                            $filename = $fieldName.'_'.Auth::id().'_'.time().'.'.$extension;
                            $path = 'digital_signatures/'.$filename;
                            Storage::disk('public')->put($path, $imageData);
                            $updateData[$fieldName] = $path;
                        }
                    }
                }

                // Lưu thông tin người ký và thời gian ký (nếu có chữ ký mới)
                if (isset($updateData[$fieldName])) {
                    if ($fieldName === 'digital_signature_delegator') {
                        $updateData['delegator_approved_by'] = Auth::id();
                        $updateData['delegator_approved_at'] = now();
                    } elseif ($fieldName === 'digital_signature_authorized') {
                        $updateData['authorized_approved_by'] = Auth::id();
                        $updateData['authorized_approved_at'] = now();
                    } elseif ($fieldName === 'digital_signature_applicant') {
                        // Tracking: Applicant đã ký khi tạo đơn
                        Log::info('✅ Đã lưu chữ ký applicant', [
                            'request_form_id' => $requestForm->id,
                            'employee_id' => Auth::id(),
                        ]);
                    }
                }
            }

            // Cập nhật chữ ký nếu có
            if (! empty($updateData)) {
                $requestForm->update($updateData);
            }

            // Load relationships dựa trên loại đơn
            if ($requestForm->type === RequestForm::TYPE_GIAY_UY_QUYEN) {
                $requestForm->load(['employee', 'approvedBy', 'delegatorApprovedBy', 'authorizedApprovedBy']);
            } else {
                $requestForm->load(['employee', 'approvedBy', 'supervisor']);
            }

            DB::commit();

            // Tạo URL để xem danh sách đơn cần duyệt
            $frontendUrl = config('app.frontend_url', 'https://a7acompany.com');
            $approvalUrlSupervisor = $frontendUrl.'/employee/request-forms';
            $approvalUrlManager = $frontendUrl.'/request-forms';

            // Gửi email thông báo cho supervisor (nếu có)
            if ($supervisor && $supervisor->email) {
                try {
                    // Dispatch job để gửi email (chạy bất đồng bộ qua queue)
                    SendRequestFormNotificationJob::dispatch($requestForm, $supervisor, $approvalUrlSupervisor);

                    Log::info('📤 Đã thêm job gửi email cho supervisor vào queue', [
                        'supervisor_id' => $supervisor->id,
                        'supervisor_email' => $supervisor->email,
                        'supervisor_name' => $supervisor->name,
                        'request_form_id' => $requestForm->id,
                        'request_form_type' => $requestForm->type,
                        'request_form_title' => $requestForm->title,
                        'approval_url' => $approvalUrlSupervisor,
                    ]);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error(basename(__FILE__).' - '.__FUNCTION__.' - Error: '.$e->getMessage());
                    Log::error('❌ Lỗi khi thêm job gửi email cho supervisor vào queue: '.$e->getMessage());
                }
            }

            // Gửi email thông báo cho quản lý nhà máy
            try {
                $factoryManagerEmail = 'ctyvinhvinhphat1@gmail.com';
                $factoryManager = (object) [
                    'name' => 'Quản lý nhà máy',
                    'email' => $factoryManagerEmail,
                ];

                // Dispatch job để gửi email cho quản lý nhà máy với URL riêng
                SendRequestFormNotificationJob::dispatch($requestForm, $factoryManager, $approvalUrlManager);

                Log::info('📤 Đã thêm job gửi email cho quản lý nhà máy vào queue', [
                    'manager_email' => $factoryManagerEmail,
                    'request_form_id' => $requestForm->id,
                    'request_form_type' => $requestForm->type,
                    'request_form_title' => $requestForm->title,
                    'approval_url' => $approvalUrlManager,
                ]);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error(basename(__FILE__).' - '.__FUNCTION__.' - Error: '.$e->getMessage());
                Log::error('❌ Lỗi khi thêm job gửi email cho quản lý nhà máy vào queue: '.$e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Đơn yêu cầu đã được tạo thành công'.($supervisor ? ' và đã gửi thông báo cho tổ trưởng' : ''),
                'data' => $requestForm,
                'supervisor_notified' => $supervisor ? [
                    'id' => $supervisor->id,
                    'name' => $supervisor->name,
                    'email' => $supervisor->email,
                ] : null,
            ], 201);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error(basename(__FILE__).' - '.__FUNCTION__.' - Error: '.$e->getMessage());
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Có lỗi xảy ra khi tạo đơn yêu cầu',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): JsonResponse
    {
        $requestForm = RequestForm::with([
            'employee',
            'approvedBy',
            'authorizedEmployee',
            'delegatorApprovedBy',
            'authorizedApprovedBy',
            'supervisorApprovedBy',
            'managerApprovedBy',
        ])->find($id);

        if (! $requestForm) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đơn yêu cầu',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $requestForm,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $requestForm = RequestForm::find($id);

        if (! $requestForm) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đơn yêu cầu',
            ], 404);
        }

        // Chỉ cho phép cập nhật đơn chưa được duyệt và của chính mình
        if ($requestForm->status !== RequestForm::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'Không thể cập nhật đơn đã được xử lý',
            ], 403);
        }

        if ($requestForm->employee_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền cập nhật đơn này',
            ], 403);
        }

        try {
            $updateData = [
                'title' => $request->title ?? $requestForm->title,
                'content' => $request->content ?? $requestForm->content,
                'form_data' => $request->form_data ?? $requestForm->form_data,
            ];

            // Nếu là đơn ủy quyền và có cập nhật authorized_employee_id
            if ($requestForm->type === RequestForm::TYPE_GIAY_UY_QUYEN && $request->has('form_data')) {
                $formData = is_array($request->form_data) ? $request->form_data : json_decode($request->form_data, true);
                if (! empty($formData['authorized_employee_id'])) {
                    $updateData['authorized_employee_id'] = $formData['authorized_employee_id'];
                }
            }

            // Xử lý cập nhật chữ ký điện tử theo loại đơn
            // Hỗ trợ cả 2 format: file upload (multipart) VÀ base64 string
            $signatureFields = $requestForm->getDigitalSignatureFields();

            foreach ($signatureFields as $fieldName => $fieldLabel) {
                if ($request->hasFile($fieldName)) {
                    // Case 1: File upload (multipart/form-data)
                    // Xóa file cũ nếu có
                    if ($requestForm->$fieldName && Storage::disk('public')->exists($requestForm->$fieldName)) {
                        Storage::disk('public')->delete($requestForm->$fieldName);
                    }

                    $file = $request->file($fieldName);
                    $filename = $fieldName.'_'.Auth::id().'_'.time().'.'.$file->getClientOriginalExtension();
                    $updateData[$fieldName] = $file->storeAs('digital_signatures', $filename, 'public');
                } elseif ($request->has($fieldName) && is_string($request->input($fieldName))) {
                    // Case 2: Base64 string (từ frontend canvas/signature pad)
                    $base64Data = $request->input($fieldName);

                    if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $matches)) {
                        // Xóa file cũ nếu có
                        if ($requestForm->$fieldName && Storage::disk('public')->exists($requestForm->$fieldName)) {
                            Storage::disk('public')->delete($requestForm->$fieldName);
                        }

                        $extension = $matches[1];
                        $base64Data = preg_replace('/^data:image\/\w+;base64,/', '', $base64Data);
                        $base64Data = str_replace(' ', '+', $base64Data);
                        $imageData = base64_decode($base64Data);

                        if ($imageData !== false) {
                            $filename = $fieldName.'_'.Auth::id().'_'.time().'.'.$extension;
                            $path = 'digital_signatures/'.$filename;
                            Storage::disk('public')->put($path, $imageData);
                            $updateData[$fieldName] = $path;
                        }
                    }
                }

                // Lưu thông tin người ký và thời gian ký (nếu có chữ ký mới)
                if (isset($updateData[$fieldName])) {
                    if ($fieldName === 'digital_signature_delegator') {
                        $updateData['delegator_approved_by'] = Auth::id();
                        $updateData['delegator_approved_at'] = now();
                    } elseif ($fieldName === 'digital_signature_authorized') {
                        $updateData['authorized_approved_by'] = Auth::id();
                        $updateData['authorized_approved_at'] = now();
                    }
                }
            }

            $requestForm->update($updateData);

            // Load relationships dựa trên loại đơn
            if ($requestForm->type === RequestForm::TYPE_GIAY_UY_QUYEN) {
                $requestForm->load(['employee', 'approvedBy', 'delegatorApprovedBy', 'authorizedApprovedBy']);
            } else {
                $requestForm->load(['employee', 'approvedBy']);
            }

            return response()->json([
                'success' => true,
                'message' => 'Đơn yêu cầu đã được cập nhật thành công',
                'data' => $requestForm,
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error(basename(__FILE__).' - '.__FUNCTION__.' - Error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Có lỗi xảy ra khi cập nhật đơn yêu cầu',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        $requestForm = RequestForm::find($id);

        if (! $requestForm) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đơn yêu cầu',
            ], 404);
        }

        // Chỉ cho phép xóa đơn chưa được duyệt và của chính mình
        if ($requestForm->status !== RequestForm::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'Không thể xóa đơn đã được xử lý',
            ], 403);
        }

        if ($requestForm->employee_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền xóa đơn này',
            ], 403);
        }

        try {
            // Xóa tất cả file chữ ký điện tử nếu có
            $uploadedSignatures = $requestForm->getUploadedSignatures();
            foreach ($uploadedSignatures as $field => $path) {
                if (Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                }
            }

            $requestForm->delete();

            return response()->json([
                'success' => true,
                'message' => 'Đơn yêu cầu đã được xóa thành công',
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error(basename(__FILE__).' - '.__FUNCTION__.' - Error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Có lỗi xảy ra khi xóa đơn yêu cầu',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Lấy danh sách các loại đơn và trạng thái
     */
    public function getFormTypes(): JsonResponse
    {
        $data = Cache::remember('request_form_types_statuses', 86400, function () {
            return [
                'types' => RequestForm::getTypes(),
                'statuses' => RequestForm::getStatuses(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Lấy thông tin các loại chữ ký theo loại đơn
     */
    public function getSignatureFields(Request $request): JsonResponse
    {
        $type = $request->get('type');

        if (! $type || ! array_key_exists($type, RequestForm::getTypes())) {
            return response()->json([
                'success' => false,
                'message' => 'Loại đơn không hợp lệ',
            ], 400);
        }

        // Tạo instance tạm để lấy signature fields
        $tempForm = new RequestForm(['type' => $type]);
        $signatureFields = $tempForm->getDigitalSignatureFields();

        return response()->json([
            'success' => true,
            'data' => [
                'type' => $type,
                'type_name' => RequestForm::getTypes()[$type],
                'signature_fields' => $signatureFields,
            ],
        ]);
    }

    /**
     * Lấy danh sách nhân viên có thể được ủy quyền
     * (loại trừ admin, co admin, super admin và deleted)
     */
    public function getAuthorizableEmployees(): JsonResponse
    {
        $employees = Cache::remember('authorizable_employees', 3600, function () {
            return \App\Models\Employee::with('role:id,role_name')
                ->whereHas('role', function ($query) {
                    $query->whereNotIn('role_name', ['admin', 'co admin', 'super admin']);
                })
                ->whereNull('deleted_at')
                ->select('id', 'name', 'role_id', 'gender')
                ->orderBy('name')
                ->get()
                ->map(function ($employee) {
                    return [
                        'id' => $employee->id,
                        'name' => $employee->name,
                        'gender' => $employee->gender,
                        'role_name' => $employee->role ? $employee->role->role_name : null,
                    ];
                });
        });

        return response()->json([
            'success' => true,
            'data' => $employees,
        ]);
    }

    /**
     * User ký chữ ký cho đơn ủy quyền (delegator hoặc authorized)
     */
    public function signDelegation(Request $request, $id): JsonResponse
    {
        $requestForm = RequestForm::findOrFail($id);

        // Kiểm tra quyền: chỉ người tạo đơn hoặc người được ủy quyền mới có thể ký
        $currentUserId = Auth::id();
        $authorizedEmployeeId = $requestForm->authorized_employee_id;

        if ($currentUserId != $requestForm->employee_id && $currentUserId != $authorizedEmployeeId) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền ký đơn này',
            ], 403);
        }

        // Kiểm tra đơn phải là loại ủy quyền
        if ($requestForm->type !== RequestForm::TYPE_GIAY_UY_QUYEN) {
            return response()->json([
                'success' => false,
                'message' => 'Chỉ có thể ký chữ ký cho đơn ủy quyền',
            ], 400);
        }

        // Kiểm tra đơn phải ở trạng thái pending
        if ($requestForm->status !== RequestForm::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'Chỉ có thể ký chữ ký cho đơn đang chờ duyệt',
            ], 400);
        }

        try {
            DB::beginTransaction();

            $updateData = [];
            $hasNewSignature = false;
            $signatureType = '';

            // Xác định loại chữ ký dựa trên user hiện tại
            if ($currentUserId == $requestForm->employee_id) {
                // Người tạo đơn = delegator
                if ($request->hasFile('digital_signature_delegator')) {
                    $file = $request->file('digital_signature_delegator');

                    // Delete old signature if exists
                    if ($requestForm->digital_signature_delegator && Storage::disk('public')->exists($requestForm->digital_signature_delegator)) {
                        Storage::disk('public')->delete($requestForm->digital_signature_delegator);
                    }

                    // Store new signature
                    $filename = time().'_delegator_'.$file->getClientOriginalName();
                    $path = $file->storeAs('signatures', $filename, 'public');

                    $updateData['digital_signature_delegator'] = $path;
                    $updateData['delegator_approved_by'] = $currentUserId;
                    $updateData['delegator_approved_at'] = now();
                    $hasNewSignature = true;
                    $signatureType = 'delegator';
                }
            } elseif ($currentUserId == $authorizedEmployeeId) {
                // Người được ủy quyền = authorized
                if ($request->hasFile('digital_signature_authorized')) {
                    $file = $request->file('digital_signature_authorized');

                    // Delete old signature if exists
                    if ($requestForm->digital_signature_authorized && Storage::disk('public')->exists($requestForm->digital_signature_authorized)) {
                        Storage::disk('public')->delete($requestForm->digital_signature_authorized);
                    }

                    // Store new signature
                    $filename = time().'_authorized_'.$file->getClientOriginalName();
                    $path = $file->storeAs('signatures', $filename, 'public');

                    $updateData['digital_signature_authorized'] = $path;
                    $updateData['authorized_approved_by'] = $currentUserId;
                    $updateData['authorized_approved_at'] = now();
                    $hasNewSignature = true;
                    $signatureType = 'authorized';
                }
            }

            if (! $hasNewSignature) {
                return response()->json([
                    'success' => false,
                    'message' => 'Không tìm thấy file chữ ký để upload',
                ], 400);
            }

            $requestForm->update($updateData);
            $requestForm->refresh();

            // Load relationships
            $requestForm->load(['employee', 'delegatorApprovedBy', 'authorizedApprovedBy']);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Đã ký chữ ký {$signatureType} thành công. Đơn sẽ được gửi để admin duyệt.",
                'data' => [
                    'id' => $requestForm->id,
                    'type' => $requestForm->type,
                    'status' => $requestForm->status,
                    'has_delegator_signature' => ! empty($requestForm->digital_signature_delegator),
                    'has_authorized_signature' => ! empty($requestForm->digital_signature_authorized),
                    'delegator_approved_by_employee' => $requestForm->delegatorApprovedBy ? [
                        'id' => $requestForm->delegatorApprovedBy->id,
                        'name' => $requestForm->delegatorApprovedBy->name,
                    ] : null,
                    'authorized_approved_by_employee' => $requestForm->authorizedApprovedBy ? [
                        'id' => $requestForm->authorizedApprovedBy->id,
                        'name' => $requestForm->authorizedApprovedBy->name,
                    ] : null,
                ],
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error(basename(__FILE__).' - '.__FUNCTION__.' - Error: '.$e->getMessage());
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Có lỗi xảy ra khi ký chữ ký',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Lấy danh sách các đơn mà user hiện tại được ủy quyền
     */
    public function getAuthorizedToMe(Request $request): JsonResponse
    {
        $currentUserId = Auth::id();

        // ✅ Select TẤT CẢ columns cần thiết cho Giấy Ủy Quyền (theo yêu cầu FE)
        $query = RequestForm::query()
            ->select([
                'id',
                'employee_id',
                'type',
                'title',
                'content',
                'form_data',
                'status',
                'rejection_reason',
                'authorized_employee_id',
                'digital_signature_delegator',
                'digital_signature_authorized',
                'delegator_approved_by',
                'delegator_approved_at',
                'authorized_approved_by',
                'authorized_approved_at',
                'submitted_at',
                'approved_at',
                'rejected_at',
                'created_at',
                'updated_at',
            ])
            ->where('type', RequestForm::TYPE_GIAY_UY_QUYEN)
            ->where('authorized_employee_id', $currentUserId);

        // ✅ Eager load với FULL FIELDS (id, name, email, phone) theo yêu cầu FE
        $query->with([
            'employee:id,name,email,phone',
            'delegatorApprovedBy:id,name,email,phone',
            'authorizedApprovedBy:id,name,email,phone',
            'authorizedEmployee:id,name,email,phone',
        ]);

        // Filter theo status
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Sắp xếp theo ngày tạo mới nhất
        $query->orderBy('created_at', 'desc');

        // Pagination
        $limit = $request->get('limit', 10);
        $requestForms = $query->paginate($limit);

        return response()->json([
            'success' => true,
            'data' => $requestForms->items(),
            'total' => $requestForms->total(),
            'page' => $requestForms->currentPage(),
            'limit' => $requestForms->perPage(),
            'last_page' => $requestForms->lastPage(),
        ]);
    }

    /**
     * Người được ủy quyền duyệt đơn và upload chữ ký điện tử
     */
    public function approveAsAuthorized(Request $request, string $id): JsonResponse
    {
        $requestForm = RequestForm::find($id);

        if (! $requestForm) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đơn yêu cầu',
            ], 404);
        }

        // Kiểm tra user hiện tại có phải là người được ủy quyền không
        $currentUserId = Auth::id();
        $authorizedEmployeeId = $requestForm->authorized_employee_id;

        if ($currentUserId != $authorizedEmployeeId) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền duyệt đơn này',
            ], 403);
        }

        // Kiểm tra đơn phải là loại ủy quyền
        if ($requestForm->type !== RequestForm::TYPE_GIAY_UY_QUYEN) {
            return response()->json([
                'success' => false,
                'message' => 'Chỉ có thể duyệt đơn ủy quyền',
            ], 400);
        }

        // Kiểm tra status phải là pending
        if ($requestForm->status !== RequestForm::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'Chỉ có thể duyệt đơn đang chờ xử lý',
            ], 400);
        }

        // Validate file upload
        $request->validate([
            'digital_signature_authorized' => 'required|file|mimes:png,jpg,jpeg|max:2048',
        ]);

        try {
            DB::beginTransaction();

            $updateData = [];

            // Xử lý upload file chữ ký
            if ($request->hasFile('digital_signature_authorized')) {
                $file = $request->file('digital_signature_authorized');

                // Delete old signature if exists
                if ($requestForm->digital_signature_authorized && Storage::disk('public')->exists($requestForm->digital_signature_authorized)) {
                    Storage::disk('public')->delete($requestForm->digital_signature_authorized);
                }

                // Store new signature
                $filename = time().'_authorized_'.$file->getClientOriginalName();
                $path = $file->storeAs('signatures', $filename, 'public');

                $updateData['digital_signature_authorized'] = $path;
                $updateData['status'] = RequestForm::STATUS_AUTHORIZED_APPROVED;
                $updateData['authorized_approved_by'] = $currentUserId;
                $updateData['authorized_approved_at'] = now();
            }

            // Update request form
            $requestForm->update($updateData);

            $requestForm->refresh();
            $requestForm->load(['employee', 'authorizedApprovedBy']);

            // TODO: Gửi notification cho delegator
            // Có thể implement sau với event/notification system

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Đã duyệt đơn thành công',
                'data' => [
                    'id' => $requestForm->id,
                    'status' => $requestForm->status,
                    'digital_signature_authorized' => $requestForm->digital_signature_authorized,
                    'authorized_at' => $requestForm->authorized_approved_at,
                ],
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error(basename(__FILE__).' - '.__FUNCTION__.' - Error: '.$e->getMessage());
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Có lỗi xảy ra khi duyệt đơn',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Người được ủy quyền từ chối đơn với lý do
     */
    public function rejectAsAuthorized(Request $request, string $id): JsonResponse
    {
        $requestForm = RequestForm::find($id);

        if (! $requestForm) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đơn yêu cầu',
            ], 404);
        }

        // Kiểm tra user hiện tại có phải là người được ủy quyền không
        $currentUserId = Auth::id();
        $authorizedEmployeeId = $requestForm->authorized_employee_id;

        if ($currentUserId != $authorizedEmployeeId) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền từ chối đơn này',
            ], 403);
        }

        // Kiểm tra đơn phải là loại ủy quyền
        if ($requestForm->type !== RequestForm::TYPE_GIAY_UY_QUYEN) {
            return response()->json([
                'success' => false,
                'message' => 'Chỉ có thể từ chối đơn ủy quyền',
            ], 400);
        }

        // Kiểm tra status phải là pending
        if ($requestForm->status !== RequestForm::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'Chỉ có thể từ chối đơn đang chờ xử lý',
            ], 400);
        }

        // Validate rejection reason
        $request->validate([
            'rejection_reason' => 'required|string|min:10|max:500',
        ], [
            'rejection_reason.required' => 'Vui lòng nhập lý do từ chối',
            'rejection_reason.min' => 'Lý do từ chối phải có ít nhất 10 ký tự',
            'rejection_reason.max' => 'Lý do từ chối không được quá 500 ký tự',
        ]);

        try {
            DB::beginTransaction();

            // Update request form
            $requestForm->update([
                'status' => RequestForm::STATUS_REJECTED,
                'rejection_reason' => $request->rejection_reason,
                'rejected_at' => now(),
            ]);

            $requestForm->refresh();
            $requestForm->load(['employee']);

            // TODO: Gửi notification cho delegator về việc đơn bị từ chối
            // Có thể implement sau với event/notification system

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Đã từ chối đơn',
                'data' => [
                    'id' => $requestForm->id,
                    'status' => $requestForm->status,
                    'rejection_reason' => $requestForm->rejection_reason,
                    'rejected_at' => $requestForm->rejected_at,
                ],
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error(basename(__FILE__).' - '.__FUNCTION__.' - Error: '.$e->getMessage());
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Có lỗi xảy ra khi từ chối đơn',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
