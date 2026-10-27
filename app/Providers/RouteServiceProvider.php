<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            // Separate each polling endpoint from the ordinary API/RPC budget.
            if ($request->isMethod('GET') && preg_match(
                '~^api/cap7a/(?:(?:tail|spike)/(?:status|daily)|tail/(?:launcher|logs))$~',
                $request->path()
            )) {
                return Limit::perMinute(120)->by('cap7a-read:'.$request->path().':'.($request->user()?->id ?: $request->ip()));
            }

            if ($request->is('api/check-po/export')) {
                return Limit::perMinute(600)->by('check-po-export:'.($request->user()?->id ?: $request->ip()));
            }

            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
