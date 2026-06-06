<?php

namespace App\Services\TTS\Providers;

use App\Services\TTS\TtsProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Adaptador de ElevenLabs (voz neural premium por defecto, Fase 5).
 *
 * POST https://api.elevenlabs.io/v1/text-to-speech/{voice_id}?output_format=mp3_44100_128
 * con header `xi-api-key`. La respuesta es el binario mp3 (audio/mpeg).
 */
class ElevenLabsTtsProvider implements TtsProvider
{
    private const BASE_URL = 'https://api.elevenlabs.io/v1';

    public function __construct(
        private ?string $apiKey,
        private string $voiceId,
        private string $modelId = 'eleven_flash_v2_5',
    ) {}

    public function getName(): string
    {
        return 'elevenlabs';
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    public function synthesize(string $text, array $opts = []): string
    {
        $voiceId = $opts['voice'] ?? $this->voiceId;

        $response = Http::withHeaders([
            'xi-api-key' => $this->apiKey,
            'Accept'     => 'audio/mpeg',
        ])->post(self::BASE_URL . "/text-to-speech/{$voiceId}?output_format=mp3_44100_128", [
            'text'           => $text,
            'model_id'       => $this->modelId,
            'voice_settings' => [
                'stability'        => $opts['stability'] ?? 0.5,
                'similarity_boost' => $opts['similarity_boost'] ?? 0.75,
            ],
        ]);

        if ($response->failed()) {
            throw new RuntimeException("ElevenLabs TTS error: " . $response->body());
        }

        return $response->body();
    }
}
