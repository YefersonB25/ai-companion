<?php

namespace App\Services\Tools;

use App\Models\User;
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
    ];

    public function __construct(
        private readonly WebSearchTool $search,
        private readonly WeatherService $weather,
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

    /** Execute a tool by name and return a string result */
    public function execute(string $name, array $args, ?User $user = null): string
    {
        return match ($name) {
            'web_search'   => $this->search->execute($args['query'] ?? ''),
            'get_weather'  => $this->executeWeather($args),
            'get_datetime' => $this->executeDateTime($user),
            default        => "Herramienta desconocida: {$name}",
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
}
