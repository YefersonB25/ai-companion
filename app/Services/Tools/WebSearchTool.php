<?php

namespace App\Services\Tools;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebSearchTool
{
    public function execute(string $query): string
    {
        $apiKey = config('services.serper.key');

        if (! $apiKey) {
            return "Búsqueda web no disponible (sin API key configurada).";
        }

        try {
            $response = Http::withHeaders([
                'X-API-KEY'    => $apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(8)->post('https://google.serper.dev/search', [
                'q'   => $query,
                'num' => 5,
                'hl'  => 'es',
                'gl'  => 'co',
            ]);

            if ($response->failed()) {
                Log::warning("WebSearchTool: Serper API error {$response->status()} para '{$query}'");
                return "No se pudo completar la búsqueda.";
            }

            $results = $response->json('organic', []);

            if (empty($results)) {
                return "No se encontraron resultados para: {$query}";
            }

            $lines = ["Resultados de búsqueda para: \"{$query}\"", ''];

            foreach (array_slice($results, 0, 5) as $i => $r) {
                $title   = $r['title']   ?? '';
                $snippet = $r['snippet'] ?? '';
                $link    = $r['link']    ?? '';
                $lines[] = ($i + 1) . ". **{$title}**";
                if ($snippet) $lines[] = "   {$snippet}";
                if ($link)    $lines[] = "   Fuente: {$link}";
                $lines[] = '';
            }

            return implode("\n", $lines);

        } catch (\Throwable $e) {
            Log::error("WebSearchTool: excepción para '{$query}': {$e->getMessage()}");
            return "Error al realizar la búsqueda.";
        }
    }
}
