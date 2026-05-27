<?php

namespace App\Telegram\Commands;

use App\Models\TelegramUser;
use Telegram\Bot\Commands\Command;

class MemoryCommand extends Command
{
    protected string $name        = 'memory';
    protected string $description = 'Ver tu memoria personal';

    public function handle(): void
    {
        $chatId = $this->getUpdate()->getChat()->id;
        $tgUser = TelegramUser::where('telegram_id', $chatId)->with('user.memoryNodes')->first();

        if (!$tgUser?->isLinked()) {
            $this->replyWithMessage(['text' => "❌ Primero vincula tu cuenta con /login"]);
            return;
        }

        $nodes = $tgUser->user->memoryNodes()
            ->orderByDesc('importance')
            ->limit(10)
            ->get();

        if ($nodes->isEmpty()) {
            $this->replyWithMessage(['text' => "🧠 Aún no tienes nodos de memoria. ¡Empieza a chatear!"]);
            return;
        }

        $icons = [
            'person' => '👤', 'project' => '📁', 'habit' => '🔄',
            'preference' => '⭐', 'skill' => '🛠', 'event' => '📅', 'note' => '📝',
        ];

        $text = "🧠 *Tu Memoria Personal* (top 10)\n\n";
        foreach ($nodes as $node) {
            $icon = $icons[$node->type] ?? '🔵';
            $text .= "{$icon} *{$node->label}*\n";
            $text .= "   _{$node->content}_\n\n";
        }

        $this->replyWithMessage(['text' => $text, 'parse_mode' => 'Markdown']);
    }
}
