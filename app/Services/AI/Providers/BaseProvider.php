<?php

namespace App\Services\AI\Providers;

use Generator;

abstract class BaseProvider
{
    abstract public function getName(): string;

    abstract public function chat(array $messages, array $options = []): array;

    abstract public function stream(array $messages, array $options = []): Generator;

    abstract public function getSupportedModels(): array;

    public function getModel(): string
    {
        return property_exists($this, 'model') ? $this->model : '';
    }

    protected function buildResponse(string $content, string $model, int $inputTokens, int $outputTokens, int $latencyMs): array
    {
        return [
            'content'       => $content,
            'model'         => $model,
            'provider'      => $this->getName(),
            'input_tokens'  => $inputTokens,
            'output_tokens' => $outputTokens,
            'latency_ms'    => $latencyMs,
        ];
    }
}
