<?php

namespace App\Services\Tools;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebSearchTool
{
    public function execute(string $query): string
    {
        $apiKey = config('services.brave_search.key');

        if (! $apiKey) {
            return "Búsqueda web no disponible (sin API key configurada).";
        }

        try {
            $response = Http::withHeaders([
                'Accept'                  => 'application/json',
                'Accept-Encoding'         => 'gzip',
                'X-Subscription-Token'    => $apiKey,
            ])->timeout(8)->get('https://api.search.brave.com/res/v1/web/search', [
                'q'              => $query,
                'count'          => 5,
                'search_lang'    => 'es',
                'text_decorations' => false,
            ]);

            if ($response->failed()) {
                Log::warning("WebSearchTool: Brave API error {$response->status()} para '{$query}'");
                return "No se pudo completar la búsqueda.";
            }

            $results = $response->json('web.results', []);

            if (empty($results)) {
                return "No se encontraron resultados para: {$query}";
            }

            $lines = ["Resultados de búsqueda para: \"{$query}\"", ''];

            foreach (array_slice($results, 0, 5) as $i => $r) {
                $title       = $r['title']       ?? '';
                $description = $r['description'] ?? '';
                $url         = $r['url']         ?? '';
                $lines[] = ($i + 1) . ". **{$title}**";
                if ($description) $lines[] = "   {$description}";
                if ($url)         $lines[] = "   Fuente: {$url}";
                $lines[] = '';
            }

            return implode("\n", $lines);

        } catch (\Throwable $e) {
            Log::error("WebSearchTool: excepción para '{$query}': {$e->getMessage()}");
            return "Error al realizar la búsqueda.";
        }
    }
}
