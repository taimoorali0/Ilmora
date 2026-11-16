<?php

use App\Http\Controllers\Api\ActionsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PlatformController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Middleware\ResolveDevice;
use App\Http\Middleware\SuperAdminOnly;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware(['auth:sanctum', ResolveDevice::class])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('sync/pull', [ActionsController::class, 'pull']);
        Route::post('sync/upload', [SyncController::class, 'upload']);
        Route::post('sync/heartbeat', [SyncController::class, 'heartbeat']);

        // Server-only actions (Finalize level, D1)
        Route::post('finance/voucher-run', [ActionsController::class, 'voucherRun']);
        Route::post('payroll/draft', [ActionsController::class, 'payrollDraft']);
        Route::post('payroll/{run}/approve', [ActionsController::class, 'payrollApprove'])->whereUuid('run');
        Route::post('payroll/{run}/pay', [ActionsController::class, 'payrollPay'])->whereUuid('run');
        Route::post('staff-leaves/{leave}/decide', [ActionsController::class, 'decideLeave'])->whereUuid('leave');
        Route::post('users', [ActionsController::class, 'saveUser']);
    });

    // Platform console: Ilmora super admin only.
    Route::post('platform/login', [PlatformController::class, 'login'])->middleware('throttle:10,1');
    Route::middleware(['auth:sanctum', SuperAdminOnly::class])->prefix('platform')->group(function () {
        Route::get('overview', [PlatformController::class, 'overview']);
        Route::post('schools', [PlatformController::class, 'createSchool']);
        Route::patch('schools/{school}', [PlatformController::class, 'updateSchool']);
    });
});
