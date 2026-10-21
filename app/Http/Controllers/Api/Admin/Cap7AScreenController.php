<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Cap7AScreenSessions;
use App\Services\Cap7ASystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class Cap7AScreenController extends Controller
{
    public function registerEndpoint(Request $request, string $system)
    {
        $sessions = app()->makeWith(Cap7AScreenSessions::class, ['system' => new Cap7ASystem($system)]);
        $key = $sessions->system->config('screen_gateway_key') ?? '';
        abort_unless(strlen($key) >= 32 && hash_equals($key, $request->header('X-Cap7A-Gateway-Key', '')), 403);
        abort_unless($sessions->enabled() && $sessions->system->config('screen_dynamic_url'), 403);
        $data = $request->validate(['websocket_url' => ['required', 'string', 'max:200',
            'regex:~^wss://[a-z0-9]+(?:-[a-z0-9]+)*\.trycloudflare\.com/screen$~']]);
        // Never fetch this URL server-side. The trusted Jetson renews its lease every 30s.
        Cache::put($sessions->endpointCacheKey(), $data['websocket_url'], 90);

        return response()->json(['registered' => true])->header('Cache-Control', 'no-store');
    }

    public function issue(Request $request, string $system)
    {
        $sessions = app()->makeWith(Cap7AScreenSessions::class, ['system' => new Cap7ASystem($system)]);
        $request->validate(['confirmed' => ['required', 'accepted']]);
        $result = $sessions->issue($request->user());
        Log::info('Cap7A screen session issued', ['system' => $system, 'actor' => $request->user()->getAuthIdentifier()]);

        return response()->json($result)->header('Cache-Control', 'no-store');
    }

    public function checkAccess(Request $request, string $system)
    {
        $sessions = app()->makeWith(Cap7AScreenSessions::class, ['system' => new Cap7ASystem($system)]);
        $key = $sessions->system->config('screen_gateway_key') ?? '';
        abort_unless(strlen($key) >= 32 && hash_equals($key, $request->header('X-Cap7A-Gateway-Key', '')), 403);
        $data = $request->validate(['ticket' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'lease' => ['required', 'uuid'], 'opening' => ['required', 'boolean']]);

        return response()->json($sessions->authorize($data['ticket'], $data['lease'], $data['opening']))
            ->header('Cache-Control', 'no-store');
    }
}
