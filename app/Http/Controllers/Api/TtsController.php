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

        try {
            $opts  = isset($data['voice']) ? ['voice' => $data['voice']] : [];
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
}
