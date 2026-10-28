<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\EmployeePreviewService;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class EmployeePreviewController extends Controller
{
    public function store(Request $request, EmployeePreviewService $service)
    {
        $actor = $request->user();
        $parent = $actor?->currentAccessToken();
        abort_unless($actor instanceof Employee && $parent instanceof PersonalAccessToken && $service->isSuperAdmin($actor), 403);
        $data = $request->validate(['employee_id' => ['required', 'string'], 'password' => ['required', 'string', 'max:255']]);

        return response()->json($service->start($actor, $parent, $data['employee_id'], $data['password']));
    }

    public function destroy()
    {
        // Valid preview credentials are handled before normal authentication.
        abort(403, 'Không có phiên xem để kết thúc.');
    }
}
