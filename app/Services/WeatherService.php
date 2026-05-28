<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WeatherService
{
    public function forCity(string $city): ?array
    {
        try {
            $response = Http::timeout(5)->get("https://wttr.in/{$city}", [
                'format' => 'j1',
                'lang'   => 'es',
            ]);

            if (! $response->ok()) {
                return null;
            }

            $data    = $response->json();
            $current = $data['current_condition'][0] ?? null;

            if (! $current) {
                return null;
            }

            $desc = collect($current['weatherDesc'] ?? [])->first()['value'] ?? 'desconocido';

            return [
                'temp_c'      => $current['temp_C'],
                'feels_like'  => $current['FeelsLikeC'],
                'humidity'    => $current['humidity'],
                'description' => mb_strtolower($desc),
                'wind_kmph'   => $current['windspeedKmph'],
            ];
        } catch (\Throwable $e) {
            Log::warning("WeatherService: no se pudo obtener clima para '{$city}': {$e->getMessage()}");
            return null;
        }
    }

    public function format(?array $weather): string
    {
        if (! $weather) {
            return '';
        }

        return "{$weather['description']}, {$weather['temp_c']}°C (sensación {$weather['feels_like']}°C), humedad {$weather['humidity']}%";
    }
}
