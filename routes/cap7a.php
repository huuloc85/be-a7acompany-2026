<?php

use App\Http\Controllers\Api\Admin\Cap7AController;
use App\Http\Controllers\Api\Admin\Cap7AScreenController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'check.token.expiration', 'api.permission'])
    ->prefix('cap7a')->group(function () {
        Route::get('/status', [Cap7AController::class, 'status']);
        Route::get('/daily', [Cap7AController::class, 'daily']);
        Route::post('/daily/reset', [Cap7AController::class, 'resetDaily'])->middleware('throttle:6,1');
        Route::post('/commands', [Cap7AController::class, 'command'])->middleware('throttle:12,1');
        Route::post('/screen-sessions', [Cap7AScreenController::class, 'issue'])->middleware('throttle:6,1');
    });

// Gateway only: shared secret plus a short-lived, user-bound single-use ticket.
Route::post('/cap7a-screen/authorize', [Cap7AScreenController::class, 'checkAccess']);
Route::post('/cap7a-screen/register', [Cap7AScreenController::class, 'registerEndpoint'])->middleware('throttle:10,1');
