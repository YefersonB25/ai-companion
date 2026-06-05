<?php

namespace Tests\Unit;

use App\Services\AI\PricingService;
use Tests\TestCase;

/**
 * Tests del cálculo de costo a partir de tokens y la tabla config/ai_pricing.php.
 * Extiende Tests\TestCase (no PHPUnit\TestCase) para tener acceso a config().
 */
class PricingServiceTest extends TestCase
{
    private PricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricing = new PricingService();

        // Fijar precios conocidos para que el test no dependa de las tarifas reales
        config()->set('ai_pricing.models', [
            'gpt-4o'          => ['input' => 2.5, 'output' => 10.0],
            'claude-haiku-4'  => ['input' => 1.0, 'output' => 5.0],
        ]);
        config()->set('ai_pricing.default', ['input' => 1.0, 'output' => 3.0]);
    }

    public function test_exact_model_cost(): void
    {
        // 1M input * 2.5 + 1M output * 10 = 12.5
        $this->assertEqualsWithDelta(12.5, $this->pricing->costFor('gpt-4o', 1_000_000, 1_000_000), 0.0001);
    }

    public function test_prefix_match_for_dated_variant(): void
    {
        // "claude-haiku-4-5-20251001" hereda de "claude-haiku-4"
        $cost = $this->pricing->costFor('claude-haiku-4-5-20251001', 1_000_000, 0);
        $this->assertEqualsWithDelta(1.0, $cost, 0.0001);
    }

    public function test_unknown_model_uses_default(): void
    {
        // default: 1.0 / 3.0  → 0.5M*1 + 0.5M*3 = 0.5 + 1.5 = 2.0
        $cost = $this->pricing->costFor('modelo-inexistente', 500_000, 500_000);
        $this->assertEqualsWithDelta(2.0, $cost, 0.0001);
    }

    public function test_null_model_uses_default(): void
    {
        $this->assertEqualsWithDelta(1.0, $this->pricing->costFor(null, 1_000_000, 0), 0.0001);
    }

    public function test_breakdown_splits_input_and_output(): void
    {
        $b = $this->pricing->breakdown('gpt-4o', 1_000_000, 2_000_000);
        $this->assertEqualsWithDelta(2.5, $b['input'], 0.0001);
        $this->assertEqualsWithDelta(20.0, $b['output'], 0.0001);
        $this->assertEqualsWithDelta(22.5, $b['total'], 0.0001);
    }

    public function test_zero_tokens_costs_nothing(): void
    {
        $this->assertSame(0.0, $this->pricing->costFor('gpt-4o', 0, 0));
    }
}
