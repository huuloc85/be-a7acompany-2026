<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestForm extends Model
{
    use HasFactory;

    // Các loại đơn
    const TYPE_GIAY_UY_QUYEN = 'giay_uy_quyen';

    const TYPE_DON_XIN_TU_CHUC = 'don_xin_tu_chuc';

    const TYPE_DON_XIN_NGHI_VIEC = 'don_xin_nghi_viec';

    const TYPE_DON_XIN_NGHI_PHEP = 'don_xin_nghi_phep';

    const TYPE_DON_XIN_DI_TRE_VE_SOM = 'don_xin_di_tre_ve_som';

    const TYPE_DON_XIN_TANG_CA = 'don_xin_tang_ca';

    // Trạng thái đơn
    const STATUS_PENDING = 'pending';

    const STATUS_APPROVED = 'approved';

    const STATUS_REJECTED = 'rejected';

    const STATUS_AUTHORIZED_APPROVED = 'authorized_approved';

    protected $fillable = [
        'employee_id',
        'authorized_employee_id',
        'supervisor_id',
        'type',
        'title',
        'content',
        'form_data',
        'digital_signature_delegator',
        'digital_signature_authorized',
        'digital_signature_applicant',
        'digital_signature_supervisor',
        'digital_signature_manager',
        'status',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'submitted_at',
        'supervisor_approved_by',
        'supervisor_approved_at',
        'manager_approved_by',
        'manager_approved_at',
        'delegator_approved_by',
        'delegator_approved_at',
        'authorized_approved_by',
        'authorized_approved_at',
    ];

    protected $casts = [
        'form_data' => 'array',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'supervisor_approved_at' => 'datetime',
        'manager_approved_at' => 'datetime',
        'delegator_approved_at' => 'datetime',
        'authorized_approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    /**
     * Danh sách các loại đơn
     */
    public static function getTypes(): array
    {
        return [
            self::TYPE_GIAY_UY_QUYEN => 'Giấy ủy quyền',
            self::TYPE_DON_XIN_TU_CHUC => 'Đơn xin từ chức',
            self::TYPE_DON_XIN_NGHI_VIEC => 'Đơn xin nghỉ việc',
            self::TYPE_DON_XIN_NGHI_PHEP => 'Đơn xin nghỉ phép',
            self::TYPE_DON_XIN_DI_TRE_VE_SOM => 'Đơn xin đi trễ - về sớm',
            self::TYPE_DON_XIN_TANG_CA => 'Đơn xin tăng ca',
        ];
    }

    /**
     * Danh sách trạng thái
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_PENDING => 'Chờ duyệt',
            self::STATUS_APPROVED => 'Đã duyệt',
            self::STATUS_REJECTED => 'Từ chối',
            self::STATUS_AUTHORIZED_APPROVED => 'Người được ủy quyền đã duyệt',
        ];
    }

    public function hasRequiredApprovalSignatures(): bool
    {
        if ($this->type === self::TYPE_DON_XIN_TANG_CA) {
            return ! empty($this->digital_signature_applicant)
                && ! empty($this->digital_signature_supervisor)
                && ! empty($this->supervisor_id)
                && (string) $this->supervisor_approved_by === (string) $this->supervisor_id
                && (string) $this->employee_id !== (string) $this->supervisor_id;
        }
        if ($this->type === self::TYPE_GIAY_UY_QUYEN || ! $this->digital_signature_manager) {
            return false;
        }
        $managerOnlyApplicants = ['25060400', '22120700', '22120900', '22120300', '22122800', '22121800',
            '20122900', '23030100', '17031400'];

        return in_array((string) $this->employee_id, $managerOnlyApplicants, true)
            || ! empty($this->digital_signature_supervisor);
    }

    /** Finalize pending forms using the required approver for their type. */
    public function finalizeApproval(): bool
    {
        $approver = $this->type === self::TYPE_DON_XIN_TANG_CA ? $this->supervisor_approved_by : $this->manager_approved_by;
        if ($this->status !== self::STATUS_PENDING || ! $this->hasRequiredApprovalSignatures()
            || ! $approver) {
            return false;
        }
        $this->update([
            'status' => self::STATUS_APPROVED,
            'approved_by' => $approver,
            'approved_at' => now(),
        ]);

        return true;
    }

    /**
     * Relationship với Employee (người tạo đơn)
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Relationship với Employee (người duyệt đơn)
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by');
    }

    /**
     * Relationship với Employee (supervisor được chọn)
     */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }

    /**
     * Relationship với Employee (supervisor đã ký)
     */
    public function supervisorApprovedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_approved_by');
    }

    /**
     * Relationship với Employee (manager đã ký)
     */
    public function managerApprovedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_approved_by');
    }

    /**
     * Relationship với Employee (người ủy quyền đã ký)
     */
    public function delegatorApprovedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'delegator_approved_by');
    }

    /**
     * Relationship với Employee (người được ủy quyền đã ký)
     */
    public function authorizedApprovedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'authorized_approved_by');
    }

    /**
     * Relationship với Employee (người được ủy quyền - người nhận ủy quyền)
     */
    public function authorizedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'authorized_employee_id');
    }

    /**
     * Scope để lọc theo loại đơn
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope để lọc theo trạng thái
     */
    public function scopeOfStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope để lọc đơn chờ duyệt
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope để lọc đơn đã duyệt
     */
    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    /**
     * Scope để lọc đơn bị từ chối
     */
    public function scopeRejected($query)
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    /**
     * Lấy danh sách các trường chữ ký theo loại đơn
     */
    public function getDigitalSignatureFields(): array
    {
        if ($this->type === self::TYPE_DON_XIN_TANG_CA) {
            return [
                'digital_signature_applicant' => 'Chữ ký người làm đơn',
                'digital_signature_supervisor' => 'Chữ ký tổ trưởng',
            ];
        }
        if ($this->type === self::TYPE_GIAY_UY_QUYEN) {
            // Đơn ủy quyền - 2 chữ ký
            return [
                'digital_signature_delegator' => 'Chữ ký bên ủy quyền',
                'digital_signature_authorized' => 'Chữ ký bên được ủy quyền',
            ];
        } else {
            // Các đơn khác - 3 chữ ký
            return [
                'digital_signature_applicant' => 'Chữ ký người làm đơn',
                'digital_signature_supervisor' => 'Chữ ký tổ trưởng/giám sát',
                'digital_signature_manager' => 'Chữ ký quản lý/phê duyệt',
            ];
        }
    }

    /**
     * Kiểm tra xem có chữ ký nào được upload không
     */
    public function hasDigitalSignatures(): bool
    {
        $fields = array_keys($this->getDigitalSignatureFields());

        foreach ($fields as $field) {
            if (! empty($this->$field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lấy tất cả chữ ký đã upload
     */
    public function getUploadedSignatures(): array
    {
        $fields = array_keys($this->getDigitalSignatureFields());
        $uploaded = [];

        foreach ($fields as $field) {
            if (! empty($this->$field)) {
                $uploaded[$field] = $this->$field;
            }
        }

        return $uploaded;
    }
}
