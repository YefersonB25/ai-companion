<?php

namespace App\Services\Tools;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebSearchTool
{
    public function execute(string $query): string
    {
        return $this->searchSerper($query)
            ?? $this->searchTavily($query)
            ?? "No se pudo completar la búsqueda.";
    }

    private function searchSerper(string $query): ?string
    {
        $apiKey = config('services.serper.key');
        if (! $apiKey) return null;

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
                Log::warning("WebSearchTool: Serper error {$response->status()} para '{$query}' — usando Tavily como fallback");
                return null;
            }

            $results = $response->json('organic', []);
            if (empty($results)) return null;

            return $this->formatResults($query, array_map(fn($r) => [
                'title'   => $r['title']   ?? '',
                'snippet' => $r['snippet'] ?? '',
                'url'     => $r['link']    ?? '',
            ], array_slice($results, 0, 5)));

        } catch (\Throwable $e) {
            Log::warning("WebSearchTool: Serper excepción para '{$query}': {$e->getMessage()} — usando Tavily como fallback");
            return null;
        }
    }

    private function searchTavily(string $query): ?string
    {
        $apiKey = config('services.tavily.key');
        if (! $apiKey) return null;

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->timeout(10)->post('https://api.tavily.com/search', [
                'api_key'      => $apiKey,
                'query'        => $query,
                'max_results'  => 5,
                'search_depth' => 'basic',
            ]);

            if ($response->failed()) {
                Log::warning("WebSearchTool: Tavily error {$response->status()} para '{$query}'");
                return null;
            }

            $results = $response->json('results', []);
            if (empty($results)) return null;

            return $this->formatResults($query, array_map(fn($r) => [
                'title'   => $r['title']   ?? '',
                'snippet' => $r['content'] ?? '',
                'url'     => $r['url']     ?? '',
            ], array_slice($results, 0, 5)));

        } catch (\Throwable $e) {
            Log::error("WebSearchTool: Tavily excepción para '{$query}': {$e->getMessage()}");
            return null;
        }
    }

    private function formatResults(string $query, array $results): string
    {
        $lines = ["Resultados de búsqueda para: \"{$query}\"", ''];

        foreach ($results as $i => $r) {
            $lines[] = ($i + 1) . ". **{$r['title']}**";
            if ($r['snippet']) $lines[] = "   {$r['snippet']}";
            if ($r['url'])     $lines[] = "   Fuente: {$r['url']}";
            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}
