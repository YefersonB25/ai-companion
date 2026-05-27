<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PushNotificationService
{
    private const EXPO_PUSH_URL = 'https://exp.host/--/api/v2/push/send';

    public function notifyUser(User $user, string $title, string $body, array $data = []): void
    {
        $tokens = $user->deviceTokens()
            ->where('platform', 'expo')
            ->pluck('token')
            ->toArray();

        if (empty($tokens)) {
            return;
        }

        $messages = array_map(fn($token) => [
            'to'    => $token,
            'title' => $title,
            'body'  => $body,
            'sound' => 'default',
            'data'  => $data,
        ], $tokens);

        try {
            $res = Http::withHeaders([
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ])->post(self::EXPO_PUSH_URL, $messages);

            if (!$res->successful()) {
                Log::warning('Expo push notification failed', ['response' => $res->body()]);
            }
        } catch (\Throwable $e) {
            Log::warning('Push notification error', ['error' => $e->getMessage()]);
        }
    }

    public function notifyMessage(User $user, string $aiContent, int $conversationId, string $provider): void
    {
        $preview = mb_strlen($aiContent) > 120
            ? mb_substr($aiContent, 0, 120) . '…'
            : $aiContent;

        $this->notifyUser(
            user: $user,
            title: "AI Companion · {$provider}",
            body: $preview,
            data: ['conversation_id' => $conversationId, 'screen' => 'chat'],
        );
    }
}
