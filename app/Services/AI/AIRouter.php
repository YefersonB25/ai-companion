<?php

namespace App\Services\AI;

use App\Models\AiProvider;
use App\Models\User;
use App\Services\AI\Providers\BaseProvider;
use App\Services\AI\Providers\ClaudeProvider;
use App\Services\AI\Providers\DeepSeekProvider;
use App\Services\AI\Providers\GeminiProvider;
use App\Services\AI\Providers\MistralProvider;
use App\Services\AI\Providers\OpenAIProvider;
use Generator;
use RuntimeException;

class AIRouter
{
    private array $providerMap = [
        'claude'   => ClaudeProvider::class,
        'openai'   => OpenAIProvider::class,
        'deepseek' => DeepSeekProvider::class,
        'gemini'   => GeminiProvider::class,
        'mistral'  => MistralProvider::class,
    ];

    public function forUser(User $user, ?string $preferredProvider = null): BaseProvider
    {
        $providers = $user->aiProviders()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('priority')
            ->get();

        if ($providers->isEmpty()) {
            throw new RuntimeException("No active AI providers configured for this user.");
        }

        // Use preferred provider if requested and available
        if ($preferredProvider) {
            $provider = $providers->firstWhere('provider', $preferredProvider);
            if ($provider) {
                return $this->buildProvider($provider);
            }
        }

        // Apply routing rules from user settings
        $settings = $user->setting;
        if ($settings?->routing_rules) {
            $routed = $this->applyRoutingRules($providers, $settings->routing_rules);
            if ($routed) {
                return $this->buildProvider($routed);
            }
        }

        // Fall back to default provider
        $default = $providers->firstWhere('is_default', true) ?? $providers->first();
        return $this->buildProvider($default);
    }

    public function resolve(string $providerName, string $apiKey, string $model, ?string $baseUrl = null): BaseProvider
    {
        if (!isset($this->providerMap[$providerName])) {
            throw new RuntimeException("Unknown provider: {$providerName}");
        }

        $class = $this->providerMap[$providerName];

        return match ($providerName) {
            'openai'   => new OpenAIProvider($apiKey, $model, $baseUrl ?? 'https://api.openai.com/v1'),
            'deepseek' => new DeepSeekProvider($apiKey, $model),
            'mistral'  => new MistralProvider($apiKey, $model),
            default    => new $class($apiKey, $model),
        };
    }

    public function withFallback(User $user, array $messages, array $options = []): array
    {
        $providers = $user->aiProviders()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('priority')
            ->get();

        $lastError = null;

        foreach ($providers as $providerRecord) {
            try {
                $provider = $this->buildProvider($providerRecord);
                return $provider->chat($messages, $options);
            } catch (\Throwable $e) {
                $lastError = $e;
                continue;
            }
        }

        throw new RuntimeException("All providers failed. Last error: " . $lastError?->getMessage());
    }

    public function getSupportedProviders(): array
    {
        return array_keys($this->providerMap);
    }

    private function buildProvider(AiProvider $record): BaseProvider
    {
        return $this->resolve(
            $record->provider,
            $record->getDecryptedApiKey(),
            $record->model,
            $record->base_url
        );
    }

    private function applyRoutingRules(mixed $providers, array $rules): ?AiProvider
    {
        // Rules example: [{"task": "code", "provider": "openai"}, {"task": "analysis", "provider": "claude"}]
        // For now returns null — to be extended with task classification
        return null;
    }
}
