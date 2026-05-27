<?php

namespace App\Telegram\Commands;

use App\Models\TelegramUser;
use Telegram\Bot\Commands\Command;

class RegisterCommand extends Command
{
    protected string $name        = 'register';
    protected string $description = 'Crear una nueva cuenta de AI Companion';

    public function handle(): void
    {
        $chatId = $this->getUpdate()->getChat()->id;

        $tgUser = TelegramUser::where('telegram_id', $chatId)->first();

        if ($tgUser?->isLinked()) {
            $this->replyWithMessage([
                'text' => "✅ Ya tienes una cuenta vinculada. Usa /new para empezar una conversación.",
            ]);
            return;
        }

        TelegramUser::updateOrCreate(
            ['telegram_id' => (string) $chatId],
            [
                'first_name'        => $this->getUpdate()->getChat()->firstName ?? null,
                'telegram_username' => $this->getUpdate()->getChat()->username ?? null,
                'state'             => 'registering_name',
            ]
        );

        $this->replyWithMessage([
            'text' => "👤 ¿Cuál es tu nombre completo?",
        ]);
    }
}
