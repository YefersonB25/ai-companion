<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class EmbeddingService
{
    private string $provider;
    private string $apiKey;
    private string $model;
    private int $dimensions;

    public function __construct()
    {
        $this->provider   = config('embedding.provider', 'openai');
        $this->apiKey     = config('embedding.api_key', '');
        $this->model      = config('embedding.model', 'text-embedding-3-small');
        $this->dimensions = config('embedding.dimensions', 1536);
    }

    public function embed(string $text, string $taskType = 'RETRIEVAL_DOCUMENT'): array
    {
        return match ($this->provider) {
            'openai'  => $this->embedOpenAI($text),
            'gemini'  => $this->embedGemini($text, $taskType),
            default   => throw new RuntimeException("Unknown embedding provider: {$this->provider}"),
        };
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    private function embedOpenAI(string $text): array
    {
        $res = Http::withToken($this->apiKey)
            ->timeout(15)
            ->post('https://api.openai.com/v1/embeddings', [
                'model' => $this->model,
                'input' => $this->truncate($text, 8000),
            ]);

        if (!$res->successful()) {
            throw new RuntimeException('OpenAI embedding failed: ' . $res->body());
        }

        return $res->json('data.0.embedding');
    }

    private function embedGemini(string $text, string $taskType = 'RETRIEVAL_DOCUMENT'): array
    {
        $res = Http::timeout(15)
            ->post("https://generativelanguage.googleapis.com/v1/models/{$this->model}:embedContent?key={$this->apiKey}", [
                'taskType' => $taskType,
                'content'  => ['parts' => [['text' => $this->truncate($text, 2000)]]],
            ]);

        if (!$res->successful()) {
            throw new RuntimeException('Gemini embedding failed: ' . $res->body());
        }

        return $res->json('embedding.values');
    }

    private function truncate(string $text, int $maxChars): string
    {
        return mb_substr($text, 0, $maxChars);
    }
}
