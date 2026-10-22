<?php

use App\Http\Controllers\Api\Admin\Cap7AController;
use App\Http\Controllers\Api\Admin\Cap7AScreenController;
use Illuminate\Support\Facades\Route;

Route::get('/cap7a/tail/logs', [Cap7AController::class, 'tailLogs'])
    ->middleware(['auth:sanctum', 'check.token.expiration', 'api.permission', 'throttle:30,1']);

Route::middleware(['auth:sanctum', 'check.token.expiration', 'api.permission'])
    ->prefix('cap7a/{system}')->where(['system' => 'tail|spike'])->group(function () {
        Route::get('/status', [Cap7AController::class, 'status']);
        Route::get('/daily', [Cap7AController::class, 'daily']);
        Route::post('/daily/reset', [Cap7AController::class, 'resetDaily'])->middleware('throttle:6,1');
        Route::post('/commands', [Cap7AController::class, 'command'])->middleware('throttle:12,1');
        // A Jetson reboot can make the FE renew several short-lived tickets while
        // the gateway comes back. Keep a limit, but do not block that recovery.
        Route::post('/screen-sessions', [Cap7AScreenController::class, 'issue'])->middleware('throttle:30,1');
    });

// Gateway only: shared secret plus a short-lived, user-bound single-use ticket.
Route::post('/cap7a/{system}/screen/authorize', [Cap7AScreenController::class, 'checkAccess'])->where('system', 'tail|spike');
Route::post('/cap7a/{system}/screen/register', [Cap7AScreenController::class, 'registerEndpoint'])->where('system', 'tail|spike')->middleware('throttle:10,1');
