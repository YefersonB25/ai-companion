<?php

use App\Http\Controllers\Api\AiProviderController;
use App\Http\Controllers\Api\AppVersionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\MemoryController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Telegram\WebhookController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

// Broadcasting auth via Sanctum Bearer token (for Laravel Echo / Reverb)
Broadcast::routes(['middleware' => ['auth:sanctum']]);

// Telegram webhook (no auth — Telegram calls this)
Route::post('/telegram/webhook', [WebhookController::class, 'handle']);

// Public
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login',    [AuthController::class, 'login']);
Route::get('/providers/supported', [AiProviderController::class, 'supportedProviders']);
Route::get('/app/version',    [AppVersionController::class, 'check']);
Route::post('/app/version',   [AppVersionController::class, 'store']);

// Authenticated
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me',      [AuthController::class, 'me']);

    // Conversations
    Route::apiResource('conversations', ConversationController::class);
    Route::post('conversations/{conversation}/messages', [MessageController::class, 'send']);

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

    // Push notifications — device token registration
    Route::post('/device-tokens',   [DeviceTokenController::class, 'store']);
    Route::delete('/device-tokens', [DeviceTokenController::class, 'destroy']);
});
