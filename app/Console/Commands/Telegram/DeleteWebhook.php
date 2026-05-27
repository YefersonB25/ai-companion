<?php

namespace App\Console\Commands\Telegram;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Telegram\Bot\Laravel\Facades\Telegram;

#[Signature('telegram:delete-webhook')]
#[Description('Remove the Telegram webhook from the bot')]
class DeleteWebhook extends Command
{
    public function handle(): void
    {
        $response = Telegram::deleteWebhook();

        if ($response) {
            $this->info('✅ Webhook eliminado correctamente.');
        } else {
            $this->error('❌ Error al eliminar el webhook.');
        }
    }
}
