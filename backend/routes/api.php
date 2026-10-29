<?php

use App\Http\Controllers\Api\AdminAssignController;
use App\Http\Controllers\Api\AdminBotController;
use App\Http\Controllers\Api\AdminCodeSeriesController;
use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AdminEmployeeController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DayController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\TelegramWebhookController;
use App\Http\Middleware\EnsureManager;
use Illuminate\Support\Facades\Route;

Route::post('/v1/telegram/webhook', TelegramWebhookController::class);

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::get('/days/today', [DayController::class, 'today']);
        Route::post('/days/today/plan/submit', [DayController::class, 'submitPlan']);
        Route::post('/days/today/close', [DayController::class, 'closeDay']);
        Route::get('/users/{userId}/days', [DayController::class, 'history']);

        Route::post('/tasks', [TaskController::class, 'store']);
        Route::patch('/tasks/{task}', [TaskController::class, 'update']);
        Route::post('/tasks/{task}/carry-over', [TaskController::class, 'carryOver']);

        Route::get('/team/today', [TeamController::class, 'today']);
        Route::get('/settings', [SettingsController::class, 'show']);

        Route::middleware(EnsureManager::class)->group(function () {
            Route::put('/settings', [SettingsController::class, 'update']);
            Route::post('/holidays', [SettingsController::class, 'storeHoliday']);
            Route::get('/users', [TeamController::class, 'users']);
            Route::get('/exports/{table}.csv', [ExportController::class, 'csv']);

            Route::get('/admin/dashboard', [AdminDashboardController::class, 'show']);
            Route::get('/admin/employees', [AdminEmployeeController::class, 'index']);
            Route::get('/admin/employees/next-code', [AdminEmployeeController::class, 'nextCode']);
            Route::post('/admin/employees', [AdminEmployeeController::class, 'store']);
            Route::patch('/admin/employees/{user}', [AdminEmployeeController::class, 'update']);
            Route::delete('/admin/employees/{user}', [AdminEmployeeController::class, 'destroy']);
            Route::post('/admin/assign-task', [AdminAssignController::class, 'store']);

            Route::get('/admin/code-series', [AdminCodeSeriesController::class, 'index']);
            Route::post('/admin/code-series', [AdminCodeSeriesController::class, 'store']);
            Route::patch('/admin/code-series/{code_series}', [AdminCodeSeriesController::class, 'update']);
            Route::delete('/admin/code-series/{code_series}', [AdminCodeSeriesController::class, 'destroy']);
            Route::get('/admin/code-series/{code_series}/preview', [AdminCodeSeriesController::class, 'preview']);

            Route::get('/admin/bot/status', [AdminBotController::class, 'status']);
            Route::post('/admin/bot/morning', [AdminBotController::class, 'sendMorning']);
            Route::post('/admin/bot/evening', [AdminBotController::class, 'sendEvening']);
            Route::post('/admin/days/{day}/unlock', [AdminBotController::class, 'unlockEdit']);
            Route::patch('/admin/users/{user}/telegram', [AdminBotController::class, 'updateUserTelegram']);
        });
    });
});
