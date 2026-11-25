<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PayrollSourceController extends Controller
{
    public function attendances(Request $request, AttendanceHistoryController $controller)
    {
        $request->merge(['include_calculation' => 1]);

        return $controller->detail($request);
    }

    public function requestForms(Request $request, AdminRequestFormController $controller)
    {
        // Payroll must never use pending or rejected applications.
        $request->merge(['status' => 'approved']);

        return $controller->index($request);
    }
}
