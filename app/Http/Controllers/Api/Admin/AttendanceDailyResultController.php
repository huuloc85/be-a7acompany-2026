<?php

namespace App\Http\Controllers\Api\Admin;

use App\Services\AttendanceDailyResultStore;
use Illuminate\Http\Request;

class AttendanceDailyResultController extends BaseController
{
    public function index(Request $request, AttendanceDailyResultStore $store)
    {
        $request->validate([
            'filter.date_between' => ['nullable', 'regex:/^\d{4}-\d{2}-\d{2},\d{4}-\d{2}-\d{2}$/'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        return response()->json($store->query($request)
            ->defaultSort('date', 'employee_id')
            ->allowedSorts(['date', 'employee_id', 'name', 'calendar_category_id'])
            ->paginate($request->integer('limit', 50))->withQueryString());
    }
}
