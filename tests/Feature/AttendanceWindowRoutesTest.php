<?php

namespace Tests\Feature;

use App\Http\Middleware\RecentAttendanceWindow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AttendanceWindowRoutesTest extends TestCase
{
    public function test_live_routes_are_restricted_but_archive_remains_available_with_permission(): void
    {
        foreach (['/api/attendances', '/api/attendances/calculate', '/api/attendances/history', '/api/employee/attendances', '/api/employee/attendances/history'] as $uri) {
            $route = Route::getRoutes()->match(Request::create($uri, 'GET'));
            $this->assertContains(RecentAttendanceWindow::class, $route->gatherMiddleware());
        }
        $archive = Route::getRoutes()->match(Request::create('/api/attendances/daily-results', 'GET'));
        $this->assertNotContains(RecentAttendanceWindow::class, $archive->gatherMiddleware());
        $this->assertContains('api.can:view_attendance', $archive->gatherMiddleware());
    }
}
