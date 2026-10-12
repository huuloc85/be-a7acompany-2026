<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceDailyResult extends Model
{
    protected $hidden = ['sync_token'];

    protected $casts = [
        'shift' => 'integer',
        'is_schedule_change' => 'boolean',
        'total_hours' => 'float',
        'administrative_hours' => 'float',
        'overtime_hours' => 'float',
    ];
}
