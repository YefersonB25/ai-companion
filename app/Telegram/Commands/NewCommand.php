<?php

namespace App\Telegram\Commands;

use App\Models\Conversation;
use App\Models\TelegramUser;
use Telegram\Bot\Commands\Command;

class NewCommand extends Command
{
    protected string $name        = 'new';
    protected string $description = 'Iniciar una nueva conversación';

    public function handle(): void
    {
        $chatId = $this->getUpdate()->getChat()->id;
        $tgUser = TelegramUser::where('telegram_id', $chatId)->with('user')->first();

        if (!$tgUser?->isLinked()) {
            $this->replyWithMessage(['text' => "❌ Primero vincula tu cuenta con /login"]);
            return;
        }

        $conversation = $tgUser->user->conversations()->create([
            'channel' => 'telegram',
            'title'   => 'Conversación de Telegram',
        ]);

        $tgUser->update(['active_conversation_id' => $conversation->id, 'state' => 'idle']);

        $this->replyWithMessage([
            'text'       => "✨ *Nueva conversación iniciada*\n\nYa puedes escribir tu mensaje.",
            'parse_mode' => 'Markdown',
        ]);
    }
}
