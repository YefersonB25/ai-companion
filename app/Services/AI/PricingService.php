<?php

namespace App\Services\AI;

/**
 * Estima el costo (USD) de las respuestas de IA a partir de los tokens
 * registrados en cada mensaje y la tabla de precios en config/ai_pricing.php.
 */
class PricingService
{
    /**
     * Devuelve la tarifa [input, output] (USD por 1M tokens) para un modelo.
     * Match: clave exacta → prefijo más largo que coincida → 'default'.
     */
    public function rateFor(?string $model): array
    {
        $models  = config('ai_pricing.models', []);
        $default = config('ai_pricing.default', ['input' => 1.0, 'output' => 3.0]);

        if (! $model) {
            return $default;
        }

        if (isset($models[$model])) {
            return $models[$model];
        }

        // Prefijo más largo que coincida (ej. "claude-haiku-4-5-2025..." → "claude-haiku-4")
        $bestKey = null;
        foreach ($models as $key => $rate) {
            if (str_starts_with($model, $key) && (
                $bestKey === null || mb_strlen($key) > mb_strlen($bestKey)
            )) {
                $bestKey = $key;
            }
        }

        return $bestKey !== null ? $models[$bestKey] : $default;
    }

    /**
     * Costo total en USD para un mensaje dado su modelo y tokens.
     */
    public function costFor(?string $model, int $inputTokens, int $outputTokens): float
    {
        $b = $this->breakdown($model, $inputTokens, $outputTokens);
        return $b['total'];
    }

    /**
     * Desglose de costo: input, output y total en USD.
     */
    public function breakdown(?string $model, int $inputTokens, int $outputTokens): array
    {
        $rate = $this->rateFor($model);

        $inputCost  = ($inputTokens  / 1_000_000) * $rate['input'];
        $outputCost = ($outputTokens / 1_000_000) * $rate['output'];

        return [
            'input'  => round($inputCost, 6),
            'output' => round($outputCost, 6),
            'total'  => round($inputCost + $outputCost, 6),
        ];
    }
}
