<?php

namespace App\Services\TTS\Providers;

use App\Services\TTS\TtsProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Adaptador de OpenAI TTS (fallback de la voz neural, Fase 5).
 *
 * POST https://api.openai.com/v1/audio/speech con `Authorization: Bearer`.
 * La respuesta es el binario mp3 (audio/mpeg).
 */
class OpenAiTtsProvider implements TtsProvider
{
    private const ENDPOINT = 'https://api.openai.com/v1/audio/speech';

    public function __construct(
        private ?string $apiKey,
        private string $model = 'gpt-4o-mini-tts',
        private string $voice = 'nova',
    ) {}

    public function getName(): string
    {
        return 'openai';
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    public function synthesize(string $text, array $opts = []): string
    {
        $response = Http::withToken($this->apiKey)
            ->post(self::ENDPOINT, [
                'model'           => $this->model,
                'input'           => $text,
                'voice'           => $opts['voice'] ?? $this->voice,
                'response_format' => 'mp3',
            ]);

        if ($response->failed()) {
            throw new RuntimeException("OpenAI TTS error: " . $response->body());
        }

        return $response->body();
    }
}
