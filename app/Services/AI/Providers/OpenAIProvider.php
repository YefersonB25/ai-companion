<?php

namespace App\Services\AI\Providers;

use Generator;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAIProvider extends BaseProvider
{
    private string $apiKey;
    protected string $model;
    private string $baseUrl;

    public function __construct(string $apiKey, string $model = 'gpt-4o', string $baseUrl = 'https://api.openai.com/v1')
    {
        $this->apiKey  = $apiKey;
        $this->model   = $model;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function getName(): string
    {
        return 'openai';
    }

    public function getSupportedModels(): array
    {
        return ['gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo', 'o1', 'o1-mini'];
    }

    public function chat(array $messages, array $options = []): array
    {
        $start = microtime(true);

        $response = Http::withToken($this->apiKey)
            ->post("{$this->baseUrl}/chat/completions", [
                'model'       => $options['model'] ?? $this->model,
                'messages'    => $messages,
                'max_tokens'  => $options['max_tokens'] ?? 8096,
                'temperature' => $options['temperature'] ?? 0.7,
            ]);

        if ($response->failed()) {
            throw new RuntimeException("OpenAI API error: " . $response->body());
        }

        $data = $response->json();

        return $this->buildResponse(
            $data['choices'][0]['message']['content'],
            $data['model'],
            $data['usage']['prompt_tokens'],
            $data['usage']['completion_tokens'],
            (int) ((microtime(true) - $start) * 1000)
        );
    }

    public function stream(array $messages, array $options = []): Generator
    {
        $response = Http::withToken($this->apiKey)
            ->withOptions(['stream' => true])
            ->post("{$this->baseUrl}/chat/completions", [
                'model'    => $options['model'] ?? $this->model,
                'messages' => $messages,
                'stream'   => true,
            ]);

        foreach (explode("\n", $response->body()) as $line) {
            if (str_starts_with($line, 'data: ') && $line !== 'data: [DONE]') {
                $json = json_decode(substr($line, 6), true);
                if (isset($json['choices'][0]['delta']['content'])) {
                    yield $json['choices'][0]['delta']['content'];
                }
            }
        }
    }
}
