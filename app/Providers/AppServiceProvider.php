<?php

namespace App\Providers;

use App\Services\AI\AIRouter;
use App\Services\Memory\MemoryService;
use App\Services\Telegram\TelegramBotService;
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
        //
    }
}
