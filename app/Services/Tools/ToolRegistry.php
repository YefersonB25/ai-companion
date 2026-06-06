<?php

namespace App\Services\Tools;

use App\Exceptions\GoogleNotConnectedException;
use App\Models\User;
use App\Services\Integrations\GmailService;
use App\Services\Integrations\GoogleCalendarService;
use App\Services\SystemPromptBuilder;
use App\Services\WeatherService;
use Carbon\Carbon;

class ToolRegistry
{
    /** Tool schemas in normalized format (converted per-provider at call time) */
    private array $schemas = [
        [
            'name'        => 'web_search',
            'description' => 'Busca información actualizada en internet. Úsala para: noticias recientes, precios de vuelos y hoteles, eventos, información sobre lugares, restaurantes, recetas, productos, y cualquier dato que pueda haber cambiado.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'query' => [
                        'type'        => 'string',
                        'description' => 'La consulta de búsqueda. Sé específico para obtener mejores resultados.',
                    ],
                ],
                'required' => ['query'],
            ],
        ],
        [
            'name'        => 'get_weather',
            'description' => 'Obtiene el clima actual de cualquier ciudad del mundo.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'city' => [
                        'type'        => 'string',
                        'description' => 'Nombre de la ciudad (en inglés o español).',
                    ],
                ],
                'required' => ['city'],
            ],
        ],
        [
            'name'        => 'get_datetime',
            'description' => 'Obtiene la fecha y hora actual. Úsala cuando el usuario pregunte por la hora, el día, la fecha o necesite contexto temporal.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [],
                'required'   => [],
                '_empty_properties' => true, // marker, normalized by forClaude/forOpenAI below
            ],
        ],
        [
            'name'        => 'get_calendar_events',
            'description' => 'Lista los próximos eventos del Google Calendar real del usuario. Úsala cuando el usuario pregunte por su agenda, citas, reuniones o qué tiene programado.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'days_ahead' => [
                        'type'        => 'integer',
                        'description' => 'Cuántos días hacia adelante consultar (por defecto 7).',
                    ],
                ],
                'required' => [],
            ],
        ],
        [
            'name'        => 'create_calendar_event',
            'description' => 'Crea un evento en el Google Calendar real del usuario. Úsala cuando el usuario pida agendar una cita, reunión o evento en su calendario. Las fechas `start` y `end` deben ser cadenas ISO 8601 absolutas (ej. "2026-06-10T15:00:00-05:00"); usa `get_datetime` primero si necesitas la fecha/hora actual para calcular expresiones como "mañana a las 3pm".',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'summary' => [
                        'type'        => 'string',
                        'description' => 'Título del evento.',
                    ],
                    'start' => [
                        'type'        => 'string',
                        'description' => 'Inicio del evento en ISO 8601 absoluto (ej. "2026-06-10T15:00:00-05:00").',
                    ],
                    'end' => [
                        'type'        => 'string',
                        'description' => 'Fin del evento en ISO 8601 absoluto (ej. "2026-06-10T16:00:00-05:00").',
                    ],
                    'description' => [
                        'type'        => 'string',
                        'description' => 'Descripción o notas del evento (opcional).',
                    ],
                    'location' => [
                        'type'        => 'string',
                        'description' => 'Ubicación del evento (opcional).',
                    ],
                ],
                'required' => ['summary', 'start', 'end'],
            ],
        ],
        [
            'name'        => 'get_unread_emails',
            'description' => 'Resume los correos no leídos del Gmail real del usuario. Úsala cuando el usuario pregunte por sus correos, su bandeja de entrada, qué emails tiene pendientes o sin leer.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'max' => [
                        'type'        => 'integer',
                        'description' => 'Cuántos correos no leídos resumir como máximo (por defecto 5).',
                    ],
                ],
                'required' => [],
            ],
        ],
    ];

    public function __construct(
        private readonly WebSearchTool $search,
        private readonly WeatherService $weather,
        private readonly GoogleCalendarService $calendar,
        private readonly GmailService $gmail,
        private readonly SystemPromptBuilder $promptBuilder,
    ) {}

    /** Normalize parameters: empty `properties` arrays become stdClass so they JSON-encode as {} not [] */
    private function normalizeParameters(array $params): array
    {
        if (($params['_empty_properties'] ?? false) === true) {
            unset($params['_empty_properties']);
            $params['properties'] = new \stdClass();
        }
        return $params;
    }

    /** Returns schemas formatted for Claude (input_schema key) */
    public function forClaude(): array
    {
        return array_map(fn($t) => [
            'name'         => $t['name'],
            'description'  => $t['description'],
            'input_schema' => $this->normalizeParameters($t['parameters']),
        ], $this->schemas);
    }

    /** Returns schemas formatted for OpenAI-compatible APIs */
    public function forOpenAI(): array
    {
        return array_map(fn($t) => [
            'type'     => 'function',
            'function' => [
                'name'        => $t['name'],
                'description' => $t['description'],
                'parameters'  => $this->normalizeParameters($t['parameters']),
            ],
        ], $this->schemas);
    }

    /** Returns schemas formatted for Gemini native function calling */
    public function forGemini(): array
    {
        $declarations = array_map(fn($t) => [
            'name'        => $t['name'],
            'description' => $t['description'],
            'parameters'  => $this->normalizeParameters($t['parameters']),
        ], $this->schemas);

        return [['function_declarations' => $declarations]];
    }

    /** Execute a tool by name and return a string result */
    public function execute(string $name, array $args, ?User $user = null): string
    {
        return match ($name) {
            'web_search'            => $this->search->execute($args['query'] ?? ''),
            'get_weather'           => $this->executeWeather($args),
            'get_datetime'          => $this->executeDateTime($user),
            'get_calendar_events'   => $this->executeGetCalendarEvents($args, $user),
            'create_calendar_event' => $this->executeCreateCalendarEvent($args, $user),
            'get_unread_emails'     => $this->executeGetUnreadEmails($args, $user),
            default                 => "Herramienta desconocida: {$name}",
        };
    }

    private function executeWeather(array $args): string
    {
        $city = $args['city'] ?? '';
        if (! $city) return 'Ciudad no especificada.';

        $data = $this->weather->forCity($city);
        if (! $data) return "No se pudo obtener el clima para {$city}.";

        return "Clima en {$city}: " . $this->weather->format($data)
            . " | Viento: {$data['wind_kmph']} km/h";
    }

    private function executeDateTime(?User $user): string
    {
        $tz  = $user?->setting?->timezone ?? 'America/Bogota';
        $now = Carbon::now($tz);

        $days   = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $months = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
                   'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        return sprintf(
            "%s %d de %s de %d, %s (zona horaria: %s)",
            $days[$now->dayOfWeek],
            $now->day,
            $months[$now->month],
            $now->year,
            $now->format('H:i'),
            $tz
        );
    }

    private const NOT_CONNECTED_MSG = 'El usuario no ha conectado su Google Calendar. Dile que lo conecte en Ajustes.';

    private function executeGetCalendarEvents(array $args, ?User $user): string
    {
        if (! $user) return self::NOT_CONNECTED_MSG;

        $daysAhead = (int) ($args['days_ahead'] ?? 7);
        if ($daysAhead < 1) $daysAhead = 7;

        try {
            $events = $this->calendar->upcomingEvents($user, 10, $daysAhead);
        } catch (GoogleNotConnectedException $e) {
            return self::NOT_CONNECTED_MSG;
        } catch (\Throwable $e) {
            return "No se pudieron obtener los eventos del calendario: {$e->getMessage()}";
        }

        if (empty($events)) {
            return "No hay eventos próximos en los siguientes {$daysAhead} día(s).";
        }

        $tz    = $user->setting?->timezone ?? 'America/Bogota';
        $lines = array_map(fn ($e) => '- ' . $this->formatEventLine($e, $tz), $events);

        return "Próximos eventos en el calendario:\n" . implode("\n", $lines);
    }

    private function executeCreateCalendarEvent(array $args, ?User $user): string
    {
        if (! $user) return self::NOT_CONNECTED_MSG;

        $summary = trim((string) ($args['summary'] ?? ''));
        $start   = trim((string) ($args['start'] ?? ''));
        $end     = trim((string) ($args['end'] ?? ''));

        if ($summary === '' || $start === '' || $end === '') {
            return 'Faltan datos para crear el evento: necesito al menos título, inicio y fin (fechas ISO 8601 absolutas).';
        }

        try {
            $event = $this->calendar->createEvent(
                $user,
                $summary,
                $start,
                $end,
                $args['description'] ?? null,
                $args['location'] ?? null,
            );
        } catch (GoogleNotConnectedException $e) {
            return self::NOT_CONNECTED_MSG;
        } catch (\Throwable $e) {
            return "No se pudo crear el evento en el calendario: {$e->getMessage()}";
        }

        $tz       = $user->setting?->timezone ?? 'America/Bogota';
        $when     = $this->formatEventLine($event, $tz);
        $eventName = $this->promptBuilder->sanitize($event['summary'] ?? $summary);

        return "Evento '{$eventName}' agendado para {$when}.";
    }

    private const GMAIL_NOT_CONNECTED_MSG = 'El usuario no ha conectado su Gmail. Dile que conecte su cuenta de Google en Ajustes.';

    private function executeGetUnreadEmails(array $args, ?User $user): string
    {
        if (! $user) return self::GMAIL_NOT_CONNECTED_MSG;

        $max = (int) ($args['max'] ?? 5);
        if ($max < 1) $max = 5;

        try {
            $emails = $this->gmail->unreadSummary($user, $max);
        } catch (GoogleNotConnectedException $e) {
            return self::GMAIL_NOT_CONNECTED_MSG;
        } catch (\Throwable $e) {
            return "No se pudieron obtener los correos no leídos: {$e->getMessage()}";
        }

        if (empty($emails)) {
            return 'No tienes correos sin leer.';
        }

        $count = count($emails);
        $lines = [];
        foreach ($emails as $i => $email) {
            $n = $i + 1;
            // M-2: from/subject/snippet provienen de terceros → neutralizar delimitadores.
            $from    = $this->promptBuilder->sanitize($email['from'] ?? '(remitente desconocido)');
            $subject = $this->promptBuilder->sanitize($email['subject'] ?? '(sin asunto)');
            $snippet = $this->promptBuilder->sanitize($email['snippet'] ?? '');

            $line = "{$n}) De {$from} — {$subject}";
            if ($snippet !== '') {
                $line .= ": {$snippet}";
            }
            $lines[] = $line;
        }

        return "Tienes {$count} correo(s) sin leer:\n" . implode("\n", $lines);
    }

    /** Formatea un evento normalizado en una línea legible en español. */
    private function formatEventLine(array $event, string $tz): string
    {
        $days   = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $months = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
                   'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        // M-2: summary/location provienen de Google (datos de terceros) → neutralizar
        // cualquier intento de inyectar/cerrar el delimitador antes de devolverlos.
        $summary = $this->promptBuilder->sanitize($event['summary'] ?? '(sin título)');

        if (empty($event['start'])) {
            return $summary;
        }

        $start = Carbon::parse($event['start'])->setTimezone($tz);
        $date  = sprintf('%s %d de %s', $days[$start->dayOfWeek], $start->day, $months[$start->month]);

        if (! empty($event['allDay'])) {
            $when = "{$date} (todo el día)";
        } else {
            $when = "{$date} a las " . $start->format('H:i');
        }

        if (! empty($event['location'])) {
            $when .= ' en ' . $this->promptBuilder->sanitize($event['location']);
        }

        return "{$summary}: {$when}";
    }
}
