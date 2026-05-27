<?php

namespace App\Services\AI\Providers;

use Generator;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiProvider extends BaseProvider
{
    private string $apiKey;
    protected string $model;
    private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct(string $apiKey, string $model = 'gemini-2.5-pro')
    {
        $this->apiKey = $apiKey;
        $this->model  = $model;
    }

    public function getName(): string
    {
        return 'gemini';
    }

    public function getSupportedModels(): array
    {
        return ['gemini-2.5-pro', 'gemini-2.5-flash', 'gemini-1.5-pro'];
    }

    public function chat(array $messages, array $options = []): array
    {
        $start = microtime(true);
        $model = $options['model'] ?? $this->model;

        $contents = collect($messages)
            ->filter(fn($m) => $m['role'] !== 'system')
            ->map(fn($m) => [
                'role'  => $m['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $m['content']]],
            ])->values()->all();

        $systemInstruction = collect($messages)
            ->firstWhere('role', 'system');

        $payload = ['contents' => $contents];
        if ($systemInstruction) {
            $payload['systemInstruction'] = ['parts' => [['text' => $systemInstruction['content']]]];
        }

        $response = Http::post(
            "{$this->baseUrl}/models/{$model}:generateContent?key={$this->apiKey}",
            $payload
        );

        if ($response->failed()) {
            throw new RuntimeException("Gemini API error: " . $response->body());
        }

        $data = $response->json();
        $usage = $data['usageMetadata'] ?? [];

        return $this->buildResponse(
            $data['candidates'][0]['content']['parts'][0]['text'],
            $model,
            $usage['promptTokenCount'] ?? 0,
            $usage['candidatesTokenCount'] ?? 0,
            (int) ((microtime(true) - $start) * 1000)
        );
    }

    public function stream(array $messages, array $options = []): Generator
    {
        // Gemini streaming — yield full response for now
        $result = $this->chat($messages, $options);
        yield $result['content'];
    }
}
