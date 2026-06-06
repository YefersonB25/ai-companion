<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\TtsUnavailableException;
use App\Http\Controllers\Controller;
use App\Services\TTS\TtsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/tts — voz neural premium (Fase 5).
 *
 * Recibe texto y devuelve el audio mp3 sintetizado (audio/mpeg) que el móvil
 * reproduce en lugar del TTS robótico del sistema. Multi-proveedor con fallback.
 */
class TtsController extends Controller
{
    public function __construct(private TtsService $tts) {}

    public function __invoke(Request $request): Response|JsonResponse
    {
        $data = $request->validate([
            'text'  => 'required|string|max:5000',
            'voice' => 'nullable|string|max:100',
        ]);

        // Preferencias del usuario (ajustes): proveedor y voz elegidos.
        $setting  = $request->user()?->setting;
        $provider = $setting?->tts_provider;
        $voice    = $data['voice'] ?? $setting?->tts_voice;

        $opts = [];
        if (! empty($provider)) {
            $opts['provider'] = $provider;
        }
        if (! empty($voice)) {
            $opts['voice'] = $voice;
        }

        try {
            $audio = $this->tts->synthesize($data['text'], $opts);
        } catch (TtsUnavailableException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 503);
        }

        return response($audio, 200)
            ->header('Content-Type', 'audio/mpeg')
            ->header('Content-Disposition', 'inline; filename="speech.mp3"');
    }

    /**
     * GET /api/tts/providers — proveedores de TTS configurados (con key presente).
     *
     * Permite a la UI mostrar solo opciones válidas, el default global y la
     * selección actual del usuario.
     */
    public function providers(Request $request): JsonResponse
    {
        return response()->json([
            'providers' => $this->tts->availableProviders(),
            'default'   => config('services.tts.default', 'gemini'),
            'selected'  => $request->user()?->setting?->tts_provider,
        ]);
    }
}
