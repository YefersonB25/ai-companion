<?php

namespace App\Services\Telegram;

use App\Models\Conversation;
use App\Models\TelegramUser;
use App\Models\User;
use App\Models\UserSetting;
use App\Services\AI\AIRouter;
use App\Services\Memory\MemoryService;
use App\Services\PushNotificationService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram;
use Throwable;

class TelegramBotService
{
    public function __construct(
        private AIRouter $router,
        private MemoryService $memory,
        private PushNotificationService $push,
    ) {}

    public function handleUpdate(array $update): void
    {
        $message = $update['message'] ?? $update['edited_message'] ?? null;
        if (!$message) return;

        $chatId  = $message['chat']['id'];
        $text    = $message['text'] ?? '';

        // Commands are handled by the SDK — here we only handle free text
        if (str_starts_with($text, '/')) return;

        $tgUser = TelegramUser::where('telegram_id', (string) $chatId)->first();

        if (!$tgUser) {
            $this->sendMessage($chatId, "👋 Usa /start para comenzar.");
            return;
        }

        // Route based on state machine
        match ($tgUser->state) {
            'awaiting_email'     => $this->handleEmail($tgUser, $chatId, $text),
            'awaiting_password'  => $this->handlePassword($tgUser, $chatId, $text),
            'registering_name'   => $this->handleRegisterName($tgUser, $chatId, $text),
            'registering_email'  => $this->handleRegisterEmail($tgUser, $chatId, $text),
            'registering_pass'   => $this->handleRegisterPass($tgUser, $chatId, $text),
            default              => $this->handleChat($tgUser, $chatId, $text),
        };
    }

    // ─── Auth flows ────────────────────────────────────────────────────────────

    private function handleEmail(TelegramUser $tgUser, int $chatId, string $text): void
    {
        if (!filter_var($text, FILTER_VALIDATE_EMAIL)) {
            $this->sendMessage($chatId, "❌ Email inválido. Intenta de nuevo:");
            return;
        }

        $tgUser->update([
            'state'      => 'awaiting_password',
            'state_data' => ['email' => $text],
        ]);

        $this->sendMessage($chatId, "🔑 Ahora escribe tu *contraseña*:", 'Markdown');
    }

    private function handlePassword(TelegramUser $tgUser, int $chatId, string $text): void
    {
        $email = $tgUser->state_data['email'] ?? null;
        $user  = User::where('email', $email)->first();

        if (!$user || !Hash::check($text, $user->password)) {
            $tgUser->update(['state' => 'idle', 'state_data' => null]);
            $this->sendMessage($chatId, "❌ Credenciales incorrectas. Usa /login para intentar de nuevo.");
            return;
        }

        $tgUser->update([
            'user_id'    => $user->id,
            'state'      => 'idle',
            'state_data' => null,
        ]);

        $this->sendMessage(
            $chatId,
            "✅ *¡Cuenta vinculada!*\n\nBienvenido, *{$user->name}*.\n\nEscribe un mensaje para chatear con tu asistente 🧠",
            'Markdown'
        );
    }

    private function handleRegisterName(TelegramUser $tgUser, int $chatId, string $text): void
    {
        $tgUser->update([
            'state'      => 'registering_email',
            'state_data' => ['name' => $text],
        ]);
        $this->sendMessage($chatId, "📧 Escribe tu *email*:", 'Markdown');
    }

    private function handleRegisterEmail(TelegramUser $tgUser, int $chatId, string $text): void
    {
        if (!filter_var($text, FILTER_VALIDATE_EMAIL)) {
            $this->sendMessage($chatId, "❌ Email inválido. Intenta de nuevo:");
            return;
        }

        if (User::where('email', $text)->exists()) {
            $this->sendMessage($chatId, "❌ Ese email ya está registrado. Usa /login.");
            $tgUser->update(['state' => 'idle', 'state_data' => null]);
            return;
        }

        $tgUser->update([
            'state'      => 'registering_pass',
            'state_data' => array_merge($tgUser->state_data ?? [], ['email' => $text]),
        ]);
        $this->sendMessage($chatId, "🔑 Elige una *contraseña* (mínimo 8 caracteres):", 'Markdown');
    }

    private function handleRegisterPass(TelegramUser $tgUser, int $chatId, string $text): void
    {
        if (strlen($text) < 8) {
            $this->sendMessage($chatId, "❌ La contraseña debe tener al menos 8 caracteres.");
            return;
        }

        $data = $tgUser->state_data ?? [];
        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($text),
        ]);

        UserSetting::create(['user_id' => $user->id]);

        $tgUser->update([
            'user_id'    => $user->id,
            'state'      => 'idle',
            'state_data' => null,
        ]);

        $this->sendMessage(
            $chatId,
            "🎉 *¡Cuenta creada!*\n\nBienvenido, *{$user->name}*.\n\nEscribe un mensaje para chatear con tu asistente 🧠",
            'Markdown'
        );
    }

    // ─── Chat flow ─────────────────────────────────────────────────────────────

    private function handleChat(TelegramUser $tgUser, int $chatId, string $text): void
    {
        if (!$tgUser->isLinked()) {
            $this->sendMessage($chatId, "❌ Primero vincula tu cuenta con /login");
            return;
        }

        $user = $tgUser->user;

        // Get or create active conversation
        $conversation = $tgUser->active_conversation_id
            ? $tgUser->activeConversation
            : null;

        if (!$conversation) {
            $conversation = $user->conversations()->create([
                'channel' => 'telegram',
                'title'   => mb_substr($text, 0, 50),
            ]);
            $tgUser->update(['active_conversation_id' => $conversation->id]);
        }

        // Save user message
        $conversation->messages()->create([
            'user_id' => $user->id,
            'role'    => 'user',
            'content' => $text,
        ]);

        // Show typing indicator
        Telegram::sendChatAction(['chat_id' => $chatId, 'action' => 'typing']);

        // Build history
        $history = $conversation->messages()
            ->orderBy('created_at')
            ->get()
            ->map(fn($m) => ['role' => $m->role, 'content' => $m->content])
            ->toArray();

        // Inject memory context
        $settings = $user->setting;
        if ($settings?->memory_enabled) {
            $ctx = $this->memory->buildContextPrompt($user, $text);
            if ($ctx) array_unshift($history, ['role' => 'system', 'content' => $ctx]);
        }

        try {
            $response = $this->router->withFallback($user, $history);

            $conversation->messages()->create([
                'user_id'       => $user->id,
                'role'          => 'assistant',
                'content'       => $response['content'],
                'provider'      => $response['provider'],
                'model'         => $response['model'],
                'input_tokens'  => $response['input_tokens'],
                'output_tokens' => $response['output_tokens'],
                'latency_ms'    => $response['latency_ms'],
            ]);

            $conversation->increment('token_count', $response['input_tokens'] + $response['output_tokens']);

            if ($settings?->memory_enabled) {
                $this->memory->extractAndStore($user, $text);
            }

            // Telegram max message length is 4096 chars
            $reply = $response['content'];
            $footer = "\n\n_— {$response['provider']} · {$response['model']}_";

            if (mb_strlen($reply) + mb_strlen($footer) <= 4000) {
                $reply .= $footer;
            }

            $this->sendMessage($chatId, $reply, 'Markdown');

            // Notify mobile if the user has registered a device
            $this->push->notifyMessage(
                user: $user,
                aiContent: $response['content'],
                conversationId: $conversation->id,
                provider: $response['provider'],
            );
        } catch (Throwable $e) {
            Log::error("Telegram AI error: " . $e->getMessage());
            $this->sendMessage(
                $chatId,
                "❌ Error al contactar la IA. Verifica que tienes un proveedor activo en tu cuenta."
            );
        }
    }

    // ─── Helper ────────────────────────────────────────────────────────────────

    private function sendMessage(int $chatId, string $text, string $parseMode = ''): void
    {
        $params = ['chat_id' => $chatId, 'text' => $text];
        if ($parseMode) $params['parse_mode'] = $parseMode;

        try {
            Telegram::sendMessage($params);
        } catch (Throwable $e) {
            // Fallback sin parseMode si falla el markdown
            try {
                Telegram::sendMessage(['chat_id' => $chatId, 'text' => strip_tags($text)]);
            } catch (Throwable) {}
        }
    }
}
