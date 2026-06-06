<?php

namespace App\Services\TTS\Providers;

use App\Services\TTS\TtsProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Adaptador de Gemini TTS (voz neural multilingüe, Fase 5).
 *
 * POST https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key={apiKey}
 * con responseModalities=["AUDIO"]. La respuesta trae audio PCM crudo en base64
 * (inlineData.data) con un mimeType tipo `audio/L16;rate=24000`.
 *
 * Como el PCM crudo no es reproducible por el MediaPlayer del móvil, lo envolvemos
 * en un contenedor WAV (RIFF) antes de devolver los bytes.
 */
class GeminiTtsProvider implements TtsProvider
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct(
        private ?string $apiKey,
        private string $model = 'gemini-2.5-flash-preview-tts',
        private string $voice = 'Kore',
    ) {}

    public function getName(): string
    {
        return 'gemini';
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    public function synthesize(string $text, array $opts = []): string
    {
        $voice = $opts['voice'] ?? $this->voice;

        $response = Http::post(
            self::BASE_URL . "/models/{$this->model}:generateContent?key={$this->apiKey}",
            [
                'contents' => [
                    ['parts' => [['text' => $text]]],
                ],
                'generationConfig' => [
                    'responseModalities' => ['AUDIO'],
                    'speechConfig' => [
                        'voiceConfig' => [
                            'prebuiltVoiceConfig' => ['voiceName' => $voice],
                        ],
                    ],
                ],
            ]
        );

        if ($response->failed()) {
            // No filtramos la api_key en el mensaje de error.
            throw new RuntimeException("Gemini TTS error (HTTP {$response->status()}).");
        }

        $part = $response->json('candidates.0.content.parts.0.inlineData');

        if (! is_array($part) || empty($part['data'])) {
            throw new RuntimeException('Gemini TTS error: respuesta sin datos de audio.');
        }

        $pcm = base64_decode($part['data'], true);

        if ($pcm === false) {
            throw new RuntimeException('Gemini TTS error: audio base64 inválido.');
        }

        $sampleRate = $this->parseSampleRate($part['mimeType'] ?? '');

        return $this->pcmToWav($pcm, $sampleRate);
    }

    /**
     * Extrae el sample rate del mimeType (`audio/L16;rate=24000`).
     * Si no se encuentra, asume 24000 (default de Gemini TTS).
     */
    private function parseSampleRate(string $mimeType): int
    {
        if (preg_match('/rate=(\d+)/', $mimeType, $m)) {
            return (int) $m[1];
        }

        return 24000;
    }

    /**
     * Antepone un header WAV (RIFF) al PCM crudo para que sea reproducible.
     * Formato: PCM 16-bit, mono.
     */
    private function pcmToWav(string $pcm, int $sampleRate): string
    {
        $channels      = 1;
        $bitsPerSample = 16;
        $byteRate      = $sampleRate * $channels * ($bitsPerSample / 8);
        $blockAlign    = $channels * ($bitsPerSample / 8);
        $dataSize      = strlen($pcm);

        $header  = 'RIFF';
        $header .= pack('V', 36 + $dataSize);   // ChunkSize
        $header .= 'WAVE';
        $header .= 'fmt ';
        $header .= pack('V', 16);                // Subchunk1Size (PCM)
        $header .= pack('v', 1);                 // AudioFormat (1 = PCM)
        $header .= pack('v', $channels);
        $header .= pack('V', $sampleRate);
        $header .= pack('V', (int) $byteRate);
        $header .= pack('v', (int) $blockAlign);
        $header .= pack('v', $bitsPerSample);
        $header .= 'data';
        $header .= pack('V', $dataSize);

        return $header . $pcm;
    }
}
