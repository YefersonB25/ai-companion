<?php

namespace App\Services\AI\Providers;

// DeepSeek uses OpenAI-compatible API
class DeepSeekProvider extends OpenAIProvider
{
    public function __construct(string $apiKey, string $model = 'deepseek-chat')
    {
        parent::__construct($apiKey, $model, 'https://api.deepseek.com/v1');
    }

    public function getName(): string
    {
        return 'deepseek';
    }

    public function getSupportedModels(): array
    {
        return ['deepseek-chat', 'deepseek-reasoner'];
    }
}
