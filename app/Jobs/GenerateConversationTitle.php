<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\User;
use App\Services\AI\AIRouter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateConversationTitle implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 30;

    public function __construct(
        private readonly int $conversationId,
        private readonly int $userId,
        private readonly string $firstUserMessage,
    ) {}

    public function handle(AIRouter $router): void
    {
        $conversation = Conversation::find($this->conversationId);
        $user         = User::find($this->userId);

        if (! $conversation || ! $user) {
            return;
        }

        // Double-check title hasn't been set already (race condition guard)
        if ($conversation->title) {
            return;
        }

        try {
            $provider = $router->forUser($user);
        } catch (\RuntimeException) {
            return;
        }

        $messages = [
            [
                'role'    => 'user',
                'content' => "Basándote en el siguiente mensaje del usuario, genera un título conciso de 3 a 6 palabras en español. Devuelve ÚNICAMENTE el título, sin comillas ni puntuación al final.\n\nMensaje: " . $this->firstUserMessage,
            ],
        ];

        try {
            $response = $provider->chat($messages, ['max_tokens' => 30]);
            $title    = trim($response['content']);

            if ($title) {
                $conversation->update(['title' => $title]);
            }
        } catch (\Throwable) {
            // Silently fail — title generation is non-critical
        }
    }
}
