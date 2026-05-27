<?php

namespace App\Services\AI\Providers;

use Generator;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ClaudeProvider extends BaseProvider
{
    private string $apiKey;
    protected string $model;
    private string $baseUrl = 'https://api.anthropic.com/v1';

    public function __construct(string $apiKey, string $model = 'claude-sonnet-4-6')
    {
        $this->apiKey = $apiKey;
        $this->model  = $model;
    }

    public function getName(): string
    {
        return 'claude';
    }

    public function getSupportedModels(): array
    {
        return [
            'claude-opus-4-7',
            'claude-sonnet-4-6',
            'claude-haiku-4-5-20251001',
        ];
    }

    public function chat(array $messages, array $options = []): array
    {
        $start = microtime(true);

        [$system, $chatMessages] = $this->prepareMessages($messages);

        $payload = array_merge([
            'model'      => $options['model'] ?? $this->model,
            'max_tokens' => $options['max_tokens'] ?? 8096,
            'messages'   => $chatMessages,
        ], $system ? ['system' => $system] : []);

        $response = Http::withHeaders([
            'x-api-key'         => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->post("{$this->baseUrl}/messages", $payload);

        if ($response->failed()) {
            throw new RuntimeException("Claude API error: " . $response->body());
        }

        $data = $response->json();

        return $this->buildResponse(
            $data['content'][0]['text'],
            $data['model'],
            $data['usage']['input_tokens'],
            $data['usage']['output_tokens'],
            (int) ((microtime(true) - $start) * 1000)
        );
    }

    public function stream(array $messages, array $options = []): Generator
    {
        [$system, $chatMessages] = $this->prepareMessages($messages);

        $payload = array_merge([
            'model'      => $options['model'] ?? $this->model,
            'max_tokens' => $options['max_tokens'] ?? 8096,
            'stream'     => true,
            'messages'   => $chatMessages,
        ], $system ? ['system' => $system] : []);

        $response = Http::withHeaders([
            'x-api-key'         => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->withOptions(['stream' => true])->post("{$this->baseUrl}/messages", $payload);

        foreach (explode("\n", $response->body()) as $line) {
            if (str_starts_with($line, 'data: ')) {
                $json = json_decode(substr($line, 6), true);
                if (isset($json['delta']['text'])) {
                    yield $json['delta']['text'];
                }
            }
        }
    }

    private function prepareMessages(array $messages): array
    {
        $system = null;
        $chatMessages = [];

        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $system = $msg['content'];
            } else {
                $chatMessages[] = $msg;
            }
        }

        return [$system, $chatMessages];
    }
}
