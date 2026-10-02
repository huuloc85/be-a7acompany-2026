<?php

namespace App\Http\Controllers\Api\Admin;

use App\Services\AttendanceImportQueue;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceImportController extends BaseController
{
    public function index()
    {
        return response()->json(DB::table('attendance_import_requests')
            ->select('id', 'start_date', 'end_date', 'status', 'completed_days', 'inserted', 'existing',
                'unmatched', 'skipped', 'unmatched_codes', 'error', 'updated_at')
            ->orderByDesc('updated_at')->limit(10)->get());
    }

    public function store(Request $request, AttendanceImportQueue $queue)
    {
        $today = CarbonImmutable::now('Asia/Ho_Chi_Minh')->toDateString();
        $data = $request->validate([
            'confirmed' => 'required|accepted',
            'start_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date', 'before_or_equal:'.$today],
        ]);
        if (CarbonImmutable::parse($data['start_date'])->diffInDays(CarbonImmutable::parse($data['end_date'])) > 30) {
            throw ValidationException::withMessages(['end_date' => 'Mỗi lần lấy tối đa 31 ngày.']);
        }
        $job = $queue->enqueue($data['start_date'], $data['end_date'], (string) $request->user()->getAuthIdentifier());

        return response()->json(['id' => $job->id, 'status' => $job->status], 202);
    }
}
