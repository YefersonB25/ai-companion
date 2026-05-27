<?php

namespace App\Telegram\Commands;

use App\Models\TelegramUser;
use Telegram\Bot\Commands\Command;

class LoginCommand extends Command
{
    protected string $name        = 'login';
    protected string $description = 'Vincular tu cuenta de AI Companion';

    public function handle(): void
    {
        $chatId = $this->getUpdate()->getChat()->id;

        $tgUser = TelegramUser::where('telegram_id', $chatId)->first();

        if ($tgUser?->isLinked()) {
            $this->replyWithMessage([
                'text' => "✅ Ya tienes tu cuenta vinculada como *{$tgUser->user->name}*.\n\nEscribe un mensaje para chatear con tu asistente.",
                'parse_mode' => 'Markdown',
            ]);
            return;
        }

        // Start login flow
        TelegramUser::updateOrCreate(
            ['telegram_id' => (string) $chatId],
            [
                'first_name'       => $this->getUpdate()->getChat()->firstName ?? null,
                'last_name'        => $this->getUpdate()->getChat()->lastName ?? null,
                'telegram_username'=> $this->getUpdate()->getChat()->username ?? null,
                'state'            => 'awaiting_email',
            ]
        );

        $this->replyWithMessage([
            'text' => "📧 Por favor escribe tu *email* de AI Companion:",
            'parse_mode' => 'Markdown',
        ]);
    }
}
