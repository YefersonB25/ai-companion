<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests del endpoint GET /api/admin/usage (stats de costo).
 */
class AdminUsageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ai_pricing.models', [
            'gpt-4o' => ['input' => 2.5, 'output' => 10.0],
        ]);
        config()->set('ai_pricing.default', ['input' => 1.0, 'output' => 3.0]);
    }

    private function seedAssistantMessage(User $user, string $provider, string $model, int $in, int $out): void
    {
        $conversation = $user->conversations()->create(['channel' => 'web']);
        $conversation->messages()->create([
            'user_id' => $user->id,
            'role'    => 'user',
            'content' => 'pregunta',
        ]);
        $conversation->messages()->create([
            'user_id'       => $user->id,
            'role'          => 'assistant',
            'content'       => 'respuesta',
            'provider'      => $provider,
            'model'         => $model,
            'input_tokens'  => $in,
            'output_tokens' => $out,
        ]);
    }

    public function test_non_admin_cannot_access_usage(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/admin/usage')->assertStatus(403);
    }

    public function test_usage_returns_cost_breakdown(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->seedAssistantMessage($admin, 'openai', 'gpt-4o', 1_000_000, 1_000_000);

        $res = $this->actingAs($admin)->getJson('/api/admin/usage');

        $res->assertOk()
            ->assertJsonStructure([
                'totals'      => ['cost_usd', 'input_tokens', 'output_tokens'],
                'by_provider' => [['provider', 'count', 'input_tokens', 'output_tokens', 'cost_usd']],
                'by_model'    => [['provider', 'model', 'count', 'cost_usd']],
                'cost_by_day' => [['date', 'cost_usd']],
                'top_users'   => [['user_id', 'name', 'cost_usd']],
            ]);

        // 1M input * 2.5 + 1M output * 10 = 12.5
        $res->assertJsonPath('totals.cost_usd', 12.5)
            ->assertJsonPath('totals.input_tokens', 1000000)
            ->assertJsonPath('by_provider.0.provider', 'openai')
            ->assertJsonPath('by_provider.0.cost_usd', 12.5)
            ->assertJsonPath('top_users.0.cost_usd', 12.5);
    }

    public function test_usage_aggregates_multiple_providers(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->seedAssistantMessage($admin, 'openai', 'gpt-4o', 1_000_000, 0);   // 2.5
        $this->seedAssistantMessage($admin, 'gemini', 'desconocido', 1_000_000, 0); // default 1.0

        $res = $this->actingAs($admin)->getJson('/api/admin/usage');

        $res->assertOk()->assertJsonPath('totals.cost_usd', 3.5);
        $this->assertCount(2, $res->json('by_provider'));
    }
}
