<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class CheckEmployee extends Model
{
    use HasFactory;

    protected $table = 'check_employees';

    protected $fillable = [
        'product_id',
        'employee_id',
        'date',
        'shift',
        'status',
    ];

    public static function loadDailyQuantities(Collection $assignments, array $statuses): void
    {
        if ($assignments->isEmpty()) {
            return;
        }

        $quantitiesByAssignment = DailyQuantity::query()
            ->join('check_employees', function ($join) {
                $join->on('check_employees.employee_id', '=', 'daily_quantities.employee_id')
                    ->on('check_employees.product_id', '=', 'daily_quantities.product_id')
                    ->on('check_employees.date', '=', 'daily_quantities.date');
            })
            ->whereIntegerInRaw('check_employees.id', $assignments->modelKeys())
            ->whereIn('daily_quantities.status', $statuses)
            ->orderBy('daily_quantities.id')
            ->get(['daily_quantities.*', 'check_employees.id as assignment_id'])
            ->groupBy('assignment_id');

        foreach ($assignments as $assignment) {
            $assignment->date = Carbon::parse($assignment->date);
            $assignment->dailyQuantities = $quantitiesByAssignment->get($assignment->id, collect())->each(function (DailyQuantity $quantity) {
                unset($quantity->assignment_id);
                $quantity->created_at_formatted = Carbon::parse($quantity->created_at)->format('H:i:s');
            });
        }
    }

    // Định nghĩa quan hệ với model Product
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // Định nghĩa quan hệ với model Employee
    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
