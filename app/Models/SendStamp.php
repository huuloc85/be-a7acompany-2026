<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SendStamp extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'manual_product',
        'manual_product_key',
        'employee_id',
        'manager_id',
        'date',
        'shift',
        'binCount',
        'binStart',
        'type',
        'status',
        'manager_time',
        'purpose',
    ];

    protected $casts = [
        'manual_product' => 'array',
    ];

    // Quan hệ với Employee
    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    // Quan hệ với Product
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function manager()
    {
        return $this->belongsTo(Employee::class);
    }
}
