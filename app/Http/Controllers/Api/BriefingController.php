<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BriefingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BriefingController extends Controller
{
    public function __construct(private BriefingService $briefings) {}

    /**
     * GET /api/briefing/today
     *
     * Returns today's personalized briefing for the authenticated user.
     * Mobile client calls this when scheduling/showing the morning local notification.
     */
    public function today(Request $request): JsonResponse
    {
        $user = $request->user();
        $settings = $user->setting;

        if (! $settings?->briefing_enabled) {
            return response()->json([
                'enabled' => false,
                'message' => 'El briefing diario está deshabilitado en tu configuración.',
            ], 200);
        }

        try {
            $content = $this->briefings->generate($user);
            return response()->json([
                'enabled' => true,
                'title'   => 'Buenos días 🌅',
                'content' => $content,
                'time'    => $settings->briefing_time ?? '08:00',
                'city'    => $settings->briefing_city,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'enabled' => true,
                'error'   => 'No se pudo generar el briefing en este momento.',
                'detail'  => config('app.debug') ? $e->getMessage() : null,
            ], 502);
        }
    }
}
