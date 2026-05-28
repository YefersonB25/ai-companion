<?php

namespace App\Services;

use App\Models\User;
use App\Services\AI\AIRouter;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class BriefingService
{
    public function __construct(
        private readonly AIRouter              $router,
        private readonly WeatherService        $weather,
        private readonly PushNotificationService $push,
    ) {}

    public function sendForUser(User $user): void
    {
        $settings = $user->setting;

        if (! $settings?->briefing_enabled) {
            return;
        }

        try {
            $briefing = $this->generate($user);

            $this->push->notifyUser($user, 'Buenos días 🌅', $briefing, [
                'type'   => 'briefing',
                'screen' => 'chat',
            ]);
        } catch (\Throwable $e) {
            Log::error("BriefingService: error para user {$user->id}: {$e->getMessage()}");
        }
    }

    public function generate(User $user): string
    {
        $settings  = $user->setting;
        $timezone  = $settings?->timezone ?? 'America/Bogota';
        $now       = Carbon::now($timezone);
        $dayNames  = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $monthNames = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
                       'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        $dayName   = $dayNames[$now->dayOfWeek];
        $date      = "{$dayName} {$now->day} de {$monthNames[$now->month]}";
        $hour      = $now->hour;
        $greeting  = match(true) {
            $hour < 12 => 'Buenos días',
            $hour < 18 => 'Buenas tardes',
            default    => 'Buenas noches',
        };

        // Clima
        $weatherText = '';
        if ($settings?->briefing_city) {
            $weatherData = $this->weather->forCity($settings->briefing_city);
            if ($weatherData) {
                $weatherText = "Clima en {$settings->briefing_city}: " . $this->weather->format($weatherData) . ".";
            }
        }

        // Memorias del usuario (máximo 20 recientes)
        $memories = $user->memoryNodes()
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn($n) => "[{$n->type}] {$n->label}: {$n->content}")
            ->implode("\n");

        $memoryBlock = $memories
            ? "Lo que sé sobre el usuario:\n{$memories}"
            : "Aún no tengo mucha información sobre el usuario.";

        $personaName = $settings?->persona['name'] ?? 'JARVIS';

        $systemPrompt = <<<PROMPT
Eres {$personaName}, el asistente personal inteligente del usuario. Generas briefings matutinos personalizados, naturales y proactivos — como lo haría JARVIS con Tony Stark.

{$memoryBlock}
PROMPT;

        $userMessage = <<<MSG
{$greeting}, {$user->name}. Hoy es {$date}.
{$weatherText}

Genera un briefing personal para mí. Sé natural, directo y personalizado basándote en lo que sabes de mí. Incluye:
- Un saludo cálido acorde a la hora
- Mención del clima si lo tienes
- Insights o recordatorios relevantes basados en mis memorias y rutinas
- Una nota motivacional o algo útil para mi día

Máximo 120 palabras. Habla en primera persona hacia mí, no describas lo que vas a hacer.
MSG;

        $messages = [
            ['role' => 'system',    'content' => $systemPrompt],
            ['role' => 'user',      'content' => $userMessage],
        ];

        $provider = $this->router->forUser($user);
        $result   = $provider->chat($messages);

        return trim($result['content']);
    }
}
