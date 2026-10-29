<?php

use App\Http\Controllers\Api\Admin\Cap7AController;
use App\Http\Controllers\Api\Admin\Cap7AMailSettingsController;
use App\Http\Controllers\Api\Admin\Cap7AScreenController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'check.token.expiration', 'api.permission'])
    ->prefix('cap7a/production-mail')->group(function () {
        Route::get('/settings', [Cap7AMailSettingsController::class, 'show']);
        Route::post('/test', [Cap7AMailSettingsController::class, 'sendTest'])->middleware('throttle:2,1');
        Route::put('/settings', [Cap7AMailSettingsController::class, 'update'])->middleware('throttle:10,1');
    });

Route::get('/cap7a/tail/logs', [Cap7AController::class, 'tailLogs'])
    ->middleware(['auth:sanctum', 'check.token.expiration', 'api.permission']);

Route::get('/cap7a/tail/launcher', [Cap7AController::class, 'launcherStatus'])
    ->middleware(['auth:sanctum', 'check.token.expiration', 'api.permission']);
Route::post('/cap7a/tail/launcher/start', [Cap7AController::class, 'startApplication'])
    ->middleware(['auth:sanctum', 'check.token.expiration', 'api.permission', 'throttle:4,1,tail-launcher-start:']);
Route::get('/cap7a/spike/launcher', [Cap7AController::class, 'launcherStatus'])->defaults('system', 'spike')
    ->middleware(['auth:sanctum', 'check.token.expiration', 'api.permission']);
Route::post('/cap7a/spike/launcher/start', [Cap7AController::class, 'startApplication'])->defaults('system', 'spike')
    ->middleware(['auth:sanctum', 'check.token.expiration', 'api.permission', 'throttle:4,1,spike-launcher-start:']);

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
