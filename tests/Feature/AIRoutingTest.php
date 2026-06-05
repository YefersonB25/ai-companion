<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AI\AIRouter;
use App\Services\AI\Providers\ClaudeProvider;
use App\Services\AI\Providers\GeminiProvider;
use App\Services\AI\Providers\OpenAIProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests del enrutamiento inteligente por tipo de tarea (AIRouter::forUser con content).
 */
class AIRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function userWithProviders(): User
    {
        $user = User::factory()->create();

        // claude es el default; openai y gemini disponibles para enrutar
        $user->aiProviders()->create([
            'provider'   => 'claude',
            'model'      => 'claude-sonnet-4-6',
            'api_key'    => encrypt('claude-key'),
            'is_active'  => true,
            'is_default' => true,
            'priority'   => 0,
        ]);
        $user->aiProviders()->create([
            'provider'  => 'openai',
            'model'     => 'gpt-4o',
            'api_key'   => encrypt('openai-key'),
            'is_active' => true,
            'priority'  => 1,
        ]);
        $user->aiProviders()->create([
            'provider'  => 'gemini',
            'model'     => 'gemini-2.0-flash',
            'api_key'   => encrypt('gemini-key'),
            'is_active' => true,
            'priority'  => 2,
        ]);

        return $user;
    }

    private function setRules(User $user, array $rules): void
    {
        $user->setting()->create([
            'routing_rules' => $rules,
        ]);
    }

    public function test_code_task_routes_to_configured_provider(): void
    {
        $user = $this->userWithProviders();
        $this->setRules($user, [
            ['task' => 'code', 'provider' => 'openai'],
            ['task' => 'chat', 'provider' => 'gemini'],
        ]);

        $provider = app(AIRouter::class)->forUser($user, null, '¿Cómo arreglo este bug en mi función de Laravel?');

        $this->assertInstanceOf(OpenAIProvider::class, $provider);
    }

    public function test_chat_task_routes_to_configured_provider(): void
    {
        $user = $this->userWithProviders();
        $this->setRules($user, [
            ['task' => 'code', 'provider' => 'openai'],
            ['task' => 'chat', 'provider' => 'gemini'],
        ]);

        $provider = app(AIRouter::class)->forUser($user, null, 'Hola Aria');

        $this->assertInstanceOf(GeminiProvider::class, $provider);
    }

    public function test_unmatched_task_falls_back_to_default(): void
    {
        $user = $this->userWithProviders();
        // Solo regla para code; un mensaje 'chat' no tiene regla → default (claude)
        $this->setRules($user, [
            ['task' => 'code', 'provider' => 'openai'],
        ]);

        $provider = app(AIRouter::class)->forUser($user, null, 'Hola Aria');

        $this->assertInstanceOf(ClaudeProvider::class, $provider);
    }

    public function test_rule_pointing_to_unavailable_provider_falls_back_to_default(): void
    {
        $user = $this->userWithProviders();
        // Regla apunta a mistral, que el usuario NO tiene → default (claude)
        $this->setRules($user, [
            ['task' => 'code', 'provider' => 'mistral'],
        ]);

        $provider = app(AIRouter::class)->forUser($user, null, 'Arregla este bug de python');

        $this->assertInstanceOf(ClaudeProvider::class, $provider);
    }

    public function test_no_content_uses_default_provider(): void
    {
        $user = $this->userWithProviders();
        $this->setRules($user, [
            ['task' => 'chat', 'provider' => 'gemini'],
        ]);

        // Sin content (ej. briefing, títulos) → no se clasifica → default
        $provider = app(AIRouter::class)->forUser($user);

        $this->assertInstanceOf(ClaudeProvider::class, $provider);
    }

    public function test_preferred_provider_overrides_routing_rules(): void
    {
        $user = $this->userWithProviders();
        $this->setRules($user, [
            ['task' => 'code', 'provider' => 'openai'],
        ]);

        // El usuario pidió explícitamente gemini, aunque la regla diría openai
        $provider = app(AIRouter::class)->forUser($user, 'gemini', 'Arregla este bug');

        $this->assertInstanceOf(GeminiProvider::class, $provider);
    }
}
