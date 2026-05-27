<?php

namespace App\Services\AI\Providers;

// Mistral uses OpenAI-compatible API
class MistralProvider extends OpenAIProvider
{
    public function __construct(string $apiKey, string $model = 'mistral-large-latest')
    {
        parent::__construct($apiKey, $model, 'https://api.mistral.ai/v1');
    }

    public function getName(): string
    {
        return 'mistral';
    }

    public function getSupportedModels(): array
    {
        return ['mistral-large-latest', 'mistral-medium-latest', 'codestral-latest'];
    }
}
