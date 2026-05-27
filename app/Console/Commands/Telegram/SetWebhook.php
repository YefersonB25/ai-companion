<?php

namespace App\Console\Commands\Telegram;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Telegram\Bot\Laravel\Facades\Telegram;

#[Signature('telegram:set-webhook {url? : Webhook URL (uses TELEGRAM_WEBHOOK_URL from .env by default)}')]
#[Description('Register the Telegram webhook URL with the bot')]
class SetWebhook extends Command
{
    public function handle(): void
    {
        $url = $this->argument('url') ?? config('telegram.bots.mybot.webhook_url');

        if (!$url) {
            $this->error('No webhook URL provided. Set TELEGRAM_WEBHOOK_URL in .env or pass it as argument.');
            return;
        }

        $response = Telegram::setWebhook(['url' => $url]);

        if ($response) {
            $this->info("✅ Webhook registrado: {$url}");
        } else {
            $this->error('❌ Error al registrar el webhook.');
        }
    }
}
