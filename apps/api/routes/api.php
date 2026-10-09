<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Middleware\ResolveDevice;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware(['auth:sanctum', ResolveDevice::class])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::get('sync/token', [SyncController::class, 'token']);
        Route::post('sync/upload', [SyncController::class, 'upload']);
        Route::post('sync/heartbeat', [SyncController::class, 'heartbeat']);
    });
});
