<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::defaultView('vendor.pagination.bootstrap-4');

        $layout_dashboard = config('app.layout');

        // validate if layout files exist
        if (! File::exists(resource_path("views/layouts/{$layout_dashboard}.blade.php"))) {
            $layout_dashboard = 'layout';
        }

        View::share('layout', $layout_dashboard);
    }
}
