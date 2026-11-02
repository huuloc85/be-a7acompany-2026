<?php

namespace App\Models;

use App\Services\ProductInventoryService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TotalDailyQuantityPO extends Model
{
    use HasFactory;

    protected $table = 'total_daily_quantities_po';

    protected static function booted(): void
    {
        $sync = function (self $quantity) {
            if (in_array((int) $quantity->status, [3, 8], true)) {
                app(ProductInventoryService::class)->recalculate(
                    $quantity->product_id, Carbon::parse($quantity->date)->format('m-Y')
                );
            }
        };
        static::saved($sync);
        static::deleted($sync);
    }

    // fillable
    protected $fillable = [
        'product_id',                                          // ID của sản phẩm
        'status',                                              // Trạng thái nhập (*)
        'date',                                                // Ngày
        'totalQuan',                                           // Tổng số lượng/ngày
    ];

    // relationship product
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }
}
