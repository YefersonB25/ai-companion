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

    public function forUser(User $user, ?string $preferredProvider = null, ?string $content = null): BaseProvider
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

        // Apply routing rules from user settings (needs the message content to classify the task)
        $settings = $user->setting;
        if ($settings?->routing_rules) {
            $routed = $this->applyRoutingRules($providers, $settings->routing_rules, $content);
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

    /**
     * Selecciona un proveedor según reglas de enrutamiento por tipo de tarea.
     *
     * Reglas: [{"task": "code", "provider": "openai"}, {"task": "chat", "provider": "gemini"}]
     * Clasifica el contenido del usuario en una categoría y devuelve el proveedor
     * configurado para esa categoría (si el usuario lo tiene activo). Devuelve null
     * para caer al proveedor por defecto cuando no hay contenido, no hay regla que
     * aplique, o el proveedor de la regla no está disponible.
     */
    private function applyRoutingRules(mixed $providers, array $rules, ?string $content): ?AiProvider
    {
        if ($content === null || trim($content) === '' || empty($rules)) {
            return null;
        }

        $task = $this->classifyTask($content);

        foreach ($rules as $rule) {
            if (($rule['task'] ?? null) !== $task) {
                continue;
            }

            $providerName = $rule['provider'] ?? null;
            if (! $providerName) {
                continue;
            }

            $match = $providers->firstWhere('provider', $providerName);
            if ($match) {
                return $match;
            }
        }

        return null;
    }

    /**
     * Clasifica el contenido del usuario en una categoría de tarea para enrutamiento.
     *
     * Heurística determinista (sin llamadas a IA): rápida, gratis y fácil de testear.
     * Categorías: 'code', 'analysis', 'chat', 'general'.
     */
    public function classifyTask(string $content): string
    {
        // Bloques de código → claramente programación
        if (str_contains($content, '```')) {
            return 'code';
        }

        $text = mb_strtolower(trim($content));
        $len  = mb_strlen($text);

        $codeSignals = [
            'función', 'funcion', 'function', 'código', 'codigo', 'compil',
            'stacktrace', 'stack trace', 'excepción', 'excepcion', 'regex',
            'sql', 'select ', 'php', 'python', 'javascript', 'typescript',
            'kotlin', 'laravel', 'react', 'docker', 'endpoint', 'bug',
            'depura', 'debug', 'refactor', 'algoritmo', 'npm', 'composer', 'git ',
        ];
        foreach ($codeSignals as $kw) {
            if (str_contains($text, $kw)) {
                return 'code';
            }
        }

        $analysisSignals = [
            'analiza', 'análisis', 'analisis', 'compara', 'comparación', 'comparacion',
            'resume', 'resumen', 'detalladamente', 'en detalle', 'ensayo', 'estrategia',
            'investiga', 'pros y contras', 'ventajas y desventajas', 'evalúa', 'evalua', 'razona',
        ];
        foreach ($analysisSignals as $kw) {
            if (str_contains($text, $kw)) {
                return 'analysis';
            }
        }

        // Textos largos suelen requerir más razonamiento
        if ($len > 600) {
            return 'analysis';
        }

        // Mensajes cortos y conversacionales (típico de modo voz) → modelo rápido/barato
        if ($len <= 80) {
            return 'chat';
        }

        return 'general';
    }
}
