<?php

namespace App\Http\Middleware\API;

use Closure;
use Illuminate\Http\Request;

class CheckScheduleAccess
{
    public function handle(Request $request, Closure $next, string $permission = 'view_schedule')
    {
        $keys = $request->isMethodSafe() ? [$permission, 'view_team_schedule'] : [$permission];

        return app(CheckPermissionKey::class)->handle($request, $next, ...$keys);
    }
}
