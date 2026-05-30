<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AiProviderController;
use App\Http\Controllers\Api\AppVersionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BriefingController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\MemoryController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Telegram\WebhookController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

// Broadcasting auth via Sanctum Bearer token (for Laravel Echo / Reverb)
Broadcast::routes(['middleware' => ['auth:sanctum']]);

// Telegram webhook (no auth — Telegram calls this)
Route::post('/telegram/webhook', [WebhookController::class, 'handle']);

// Public
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('/auth/login',    [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::get('/providers/supported', [AiProviderController::class, 'supportedProviders']);
Route::get('/app/version', [AppVersionController::class, 'check']);

// Authenticated
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me',      [AuthController::class, 'me']);

    // Conversations
    Route::apiResource('conversations', ConversationController::class);
    Route::post('conversations/{conversation}/messages', [MessageController::class, 'send'])->middleware('throttle:60,1');
    Route::get('conversations/{conversation}/messages',  [ConversationController::class, 'messages']);
    Route::get('conversations/{conversation}/export',    [ConversationController::class, 'export']);

    // AI Providers
    Route::apiResource('providers', AiProviderController::class);

    // Memory
    Route::get('/memory/mindmap', [MemoryController::class, 'mindMap']);
    Route::get('/memory/search',  [MemoryController::class, 'search']);
    Route::apiResource('memory',  MemoryController::class);

    // Settings
    Route::get('/settings',  [SettingController::class, 'show']);
    Route::put('/settings',  [SettingController::class, 'update']);
    Route::patch('/settings', [SettingController::class, 'update']);

    // Briefing on-demand (mobile fetches this for local notification)
    Route::get('/briefing/today', [BriefingController::class, 'today']);

    // Profile
    Route::get('/profile',  [ProfileController::class, 'show']);
    Route::put('/profile',  [ProfileController::class, 'update']);
    Route::patch('/profile', [ProfileController::class, 'update']);

    // Push notifications — device token registration
    Route::post('/device-tokens',   [DeviceTokenController::class, 'store']);
    Route::delete('/device-tokens', [DeviceTokenController::class, 'destroy']);
});

// Admin routes
Route::middleware(['auth:sanctum', 'is_admin'])
    ->prefix('admin')
    ->group(function () {
        Route::get('/dashboard',                        [AdminController::class, 'dashboard']);
        Route::get('/users',                            [AdminController::class, 'users']);
        Route::get('/users/{user}',                     [AdminController::class, 'userDetail']);
        Route::post('/users/{user}/toggle-admin',       [AdminController::class, 'toggleAdmin']);
        Route::get('/memory',                           [AdminController::class, 'globalMemory']);
        Route::get('/insights',                         [AdminController::class, 'insights']);
        Route::post('/app/version',                     [AppVersionController::class, 'store']);
    });
