<?php

namespace App\Providers;

use App\Services\AI\AIRouter;
use App\Services\Memory\MemoryService;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AIRouter::class);
        $this->app->singleton(MemoryService::class);
        $this->app->singleton(TelegramBotService::class);
    }

    public function boot(): void
    {
        // B-13: Macros para respuestas consistentes (usar en código nuevo)
        Response::macro('success', function ($data = null, int $code = 200) {
            return response()->json(['success' => true, 'data' => $data], $code);
        });

        Response::macro('failure', function (string $message, int $code = 400, ?string $key = 'error') {
            return response()->json(['success' => false, $key => $message], $code);
        });
    }
}
