<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginHistory extends Model
{
    // paginate
    public const paginate = 200;

    // Tên bảng trong cơ sở dữ liệu
    protected $table = 'login_history';

    protected $hidden = ['activity_rollup'];

    protected $fillable = ['employee_id', 'employee_code', 'employee_name', 'login_count',  'month_history', 'activity_type', 'description', 'date'];

    // Định nghĩa mối quan hệ với model Employee
    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'id', 'employee_code');
    }
}
