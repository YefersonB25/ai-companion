<?php

namespace App\Services;

use App\Models\ProactiveLog;
use App\Models\User;
use App\Services\AI\AIRouter;
use App\Services\Integrations\GmailService;
use App\Services\Integrations\GoogleCalendarService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use App\Services\ProfileService;
use App\Services\SystemPromptBuilder;

class BriefingService
{
    public function __construct(
        private readonly AIRouter              $router,
        private readonly WeatherService        $weather,
        private readonly PushNotificationService $push,
        private readonly ProfileService        $profileService,
        private readonly GoogleCalendarService $calendar,
        private readonly GmailService          $gmail,
        private readonly SystemPromptBuilder   $promptBuilder,
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

            // Registrar el envío para la memoria de corto plazo (anti-repetición)
            $user->proactiveLogs()->create([
                'type'    => ProactiveLog::TYPE_BRIEFING,
                'message' => $briefing,
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
            ? "Recuerdos del usuario:\n{$memories}"
            : '';

        $profileBlock = $this->profileService->buildContextBlock($user);
        $personaName  = $settings?->persona['name'] ?? 'JARVIS';

        // Memoria de corto plazo: briefings recientes para no repetirlos
        $recentBriefings = ProactiveLog::recentMessagesFor($user, ProactiveLog::TYPE_BRIEFING);
        $recentBlock = $recentBriefings->isNotEmpty()
            ? "Briefings que YA enviaste recientemente (NO los repitas ni digas algo casi idéntico; aporta algo nuevo y distinto):\n"
                . $recentBriefings->map(fn ($m) => "- {$m}")->implode("\n")
            : '';

        // P-03: eventos del calendario de hoy/mañana si el usuario lo tiene conectado.
        $calendarBlock = $this->buildCalendarBlock($user, $timezone);

        // P-02: resumen de correos no leídos si el usuario tiene Gmail conectado.
        $gmailBlock = $this->buildGmailBlock($user);

        // M-2: los bloques de Google (calendario/gmail) contienen datos de terceros
        // (asuntos, remitentes, títulos, ubicaciones) NO confiables: van envueltos en
        // un delimitador con preámbulo de seguridad y con sus campos neutralizados.
        $externalParts = array_filter([$calendarBlock, $gmailBlock]);
        $externalBlock = $externalParts
            ? $this->promptBuilder->wrapUntrusted(implode("\n\n", $externalParts))
            : '';

        $contextParts = array_filter([$profileBlock, $memoryBlock, $externalBlock, $recentBlock]);
        $contextBlock = $contextParts
            ? implode("\n\n", $contextParts)
            : "Aún no tengo mucha información sobre el usuario.";

        $systemPrompt = <<<PROMPT
Eres {$personaName}, el asistente personal inteligente del usuario. Generas briefings matutinos personalizados, naturales y proactivos — como lo haría JARVIS con Tony Stark.

{$contextBlock}
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

    /**
     * P-03: bloque de eventos de hoy del Google Calendar del usuario.
     * Si no está conectado o falla la API, devuelve '' y el briefing sigue normal.
     */
    private function buildCalendarBlock(User $user, string $timezone): string
    {
        if (! $this->calendar->isConnected($user)) {
            return '';
        }

        try {
            $events = $this->calendar->upcomingEvents($user, 10, 1);
        } catch (\Throwable $e) {
            Log::warning("BriefingService: no se pudo leer el calendario de user {$user->id}: {$e->getMessage()}");
            return '';
        }

        if (empty($events)) {
            return '';
        }

        $days   = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $months = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
                   'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        $lines = [];
        foreach ($events as $event) {
            // M-2: summary/location vienen de terceros → neutralizar delimitadores.
            $summary = $this->promptBuilder->sanitize($event['summary'] ?? '(sin título)');

            if (empty($event['start'])) {
                $lines[] = "- {$summary}";
                continue;
            }

            $start = Carbon::parse($event['start'])->setTimezone($timezone);
            $date  = sprintf('%s %d de %s', $days[$start->dayOfWeek], $start->day, $months[$start->month]);

            if (! empty($event['allDay'])) {
                $when = "{$date} (todo el día)";
            } else {
                $when = "{$date} a las " . $start->format('H:i');
            }

            $location = ! empty($event['location'])
                ? ' en ' . $this->promptBuilder->sanitize($event['location'])
                : '';
            $lines[]  = "- {$summary}: {$when}{$location}";
        }

        return "Eventos próximos en el calendario del usuario (menciónalos si son relevantes para su día):\n"
            . implode("\n", $lines);
    }

    /**
     * P-02: bloque de resumen de correos no leídos del Gmail del usuario.
     * Si no está conectado o falla la API, devuelve '' y el briefing sigue normal.
     */
    private function buildGmailBlock(User $user): string
    {
        if (! $this->gmail->isConnected($user)) {
            return '';
        }

        try {
            $emails = $this->gmail->unreadSummary($user, 5);
        } catch (\Throwable $e) {
            Log::warning("BriefingService: no se pudo leer Gmail de user {$user->id}: {$e->getMessage()}");
            return '';
        }

        if (empty($emails)) {
            return '';
        }

        $count = count($emails);
        $lines = [];
        foreach ($emails as $email) {
            // M-2: from/subject vienen de terceros → neutralizar delimitadores.
            $from    = $this->promptBuilder->sanitize($email['from'] ?? '(remitente desconocido)');
            $subject = $this->promptBuilder->sanitize($email['subject'] ?? '(sin asunto)');
            $lines[] = "- De {$from} — {$subject}";
        }

        return "Correos sin leer (resumen, {$count} en total; menciónalos si son relevantes):\n"
            . implode("\n", $lines);
    }
}
