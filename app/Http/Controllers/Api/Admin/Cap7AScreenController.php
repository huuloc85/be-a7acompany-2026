<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Cap7AScreenSessions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class Cap7AScreenController extends Controller
{
    public function issue(Request $request, Cap7AScreenSessions $sessions)
    {
        $request->validate(['confirmed' => ['required', 'accepted']]);
        $result = $sessions->issue($request->user());
        Log::info('Cap7A screen session issued', ['actor' => $request->user()->getAuthIdentifier()]);

        return response()->json($result)->header('Cache-Control', 'no-store');
    }

    public function checkAccess(Request $request, Cap7AScreenSessions $sessions)
    {
        $key = config('cap7a.screen_gateway_key') ?? '';
        abort_unless(strlen($key) >= 32 && hash_equals($key, $request->header('X-Cap7A-Gateway-Key', '')), 403);
        $data = $request->validate(['ticket' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'lease' => ['required', 'uuid'], 'opening' => ['required', 'boolean']]);

        return response()->json($sessions->authorize($data['ticket'], $data['lease'], $data['opening']))
            ->header('Cache-Control', 'no-store');
    }
}
