<?php

namespace App\Services\TTS;

use App\Exceptions\TtsUnavailableException;
use App\Services\TTS\Providers\ElevenLabsTtsProvider;
use App\Services\TTS\Providers\GeminiTtsProvider;
use App\Services\TTS\Providers\OpenAiTtsProvider;
use Illuminate\Support\Facades\Log;

/**
 * Servicio de TTS multi-proveedor con fallback (Fase 5: voz neural).
 *
 * Replica el espíritu de AIRouter::withFallback para audio: intenta el proveedor
 * por defecto y, si no está configurado o lanza una excepción, cae al fallback.
 * Si ninguno está disponible, lanza TtsUnavailableException.
 */
class TtsService
{
    /** Máximo de caracteres a sintetizar; el resto se trunca (no rompe). */
    private const MAX_CHARS = 3000;

    /**
     * Sintetiza texto a bytes mp3 usando el proveedor por defecto con fallback.
     *
     * @return string Bytes mp3 (audio/mpeg).
     *
     * @throws TtsUnavailableException si ningún proveedor está configurado o todos fallan.
     */
    public function synthesize(string $text, array $opts = []): string
    {
        $clean = $this->cleanForSpeech($text);

        $default  = config('services.tts.default', 'elevenlabs');
        $fallback = config('services.tts.fallback', 'openai');

        // Si el usuario forzó un proveedor (desde sus ajustes), va primero;
        // luego default y fallback como red de seguridad (sin duplicar).
        $forced = $opts['provider'] ?? null;

        // Orden de intento: forzado → default → fallback (sin duplicar).
        $order = array_values(array_unique(array_filter([$forced, $default, $fallback])));

        $lastError = null;
        $attempted = false;

        foreach ($order as $name) {
            $provider = $this->resolve($name);

            if (! $provider || ! $provider->isConfigured()) {
                continue;
            }

            $attempted = true;

            try {
                return $provider->synthesize($clean, $opts);
            } catch (\Throwable $e) {
                $lastError = $e;
                Log::warning("TTS provider '{$name}' falló, intentando fallback.", [
                    'provider' => $name,
                    'error'    => $e->getMessage(),
                ]);
                continue;
            }
        }

        if (! $attempted) {
            throw new TtsUnavailableException(
                'No hay ningún proveedor de voz configurado (revisa ELEVENLABS_API_KEY / OPENAI_TTS_API_KEY).'
            );
        }

        throw new TtsUnavailableException(
            'Todos los proveedores de voz fallaron. Último error: ' . $lastError?->getMessage()
        );
    }

    /**
     * Devuelve el nombre del primer proveedor configurado (default o fallback),
     * o null si ninguno lo está. Útil para sondeos/healthchecks.
     */
    public function availableProvider(): ?string
    {
        $default  = config('services.tts.default', 'elevenlabs');
        $fallback = config('services.tts.fallback', 'openai');

        foreach (array_values(array_unique(array_filter([$default, $fallback]))) as $name) {
            $provider = $this->resolve($name);
            if ($provider && $provider->isConfigured()) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Devuelve la lista de nombres de proveedores configurados (con key presente).
     * Útil para que la UI solo muestre opciones válidas.
     *
     * @return array<int, string>
     */
    public function availableProviders(): array
    {
        $names = ['gemini', 'elevenlabs', 'openai'];

        return array_values(array_filter($names, function (string $name): bool {
            $provider = $this->resolve($name);

            return $provider && $provider->isConfigured();
        }));
    }

    /** Construye un proveedor por nombre a partir de su config (factory). */
    private function resolve(string $name): ?TtsProvider
    {
        return match ($name) {
            'gemini' => new GeminiTtsProvider(
                config('services.tts.gemini.api_key'),
                config('services.tts.gemini.model', 'gemini-2.5-flash-preview-tts'),
                config('services.tts.gemini.voice', 'Kore'),
            ),
            'elevenlabs' => new ElevenLabsTtsProvider(
                config('services.tts.elevenlabs.api_key'),
                config('services.tts.elevenlabs.voice_id', 'EXAVITQu4vr4xnSDxMaL'),
                config('services.tts.elevenlabs.model_id', 'eleven_flash_v2_5'),
            ),
            'openai' => new OpenAiTtsProvider(
                config('services.tts.openai.api_key'),
                config('services.tts.openai.model', 'gpt-4o-mini-tts'),
                config('services.tts.openai.voice', 'nova'),
            ),
            default => null,
        };
    }

    /**
     * Sanea el texto para que suene natural y no lea marcadores internos:
     *  - elimina bloques [ACTION]...[/ACTION] y marcadores parciales sueltos,
     *  - limpia markdown básico (*, #, backticks),
     *  - colapsa espacios y trunca al cap de caracteres.
     */
    private function cleanForSpeech(string $text): string
    {
        // Bloques de acción completos (el cliente móvil los ejecuta, no se leen).
        $text = preg_replace('/\[ACTION\].*?\[\/ACTION\]/is', '', $text);
        // Marcadores parciales sueltos (respuesta truncada a mitad de bloque).
        $text = preg_replace('/\[\/?ACTION\]/i', '', $text);

        // Markdown básico que no debe pronunciarse.
        $text = str_replace(['`', '*', '#', '_', '>'], '', $text);

        // Colapsa espacios/saltos múltiples.
        $text = trim(preg_replace('/\s+/', ' ', $text));

        // Cap de longitud: trunca sin romper.
        if (mb_strlen($text) > self::MAX_CHARS) {
            $text = mb_substr($text, 0, self::MAX_CHARS);
        }

        return $text;
    }
}
