<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\User;
use App\Services\AI\AIRouter;
use App\Services\AI\Providers\BaseProvider;
use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Tests del flujo POST /conversations/{id}/messages.
 * El proveedor de IA se mockea para no hacer llamadas de red reales y
 * congelar el contrato de la respuesta (R-02 del roadmap).
 */
class MessageSendTest extends TestCase
{
    use RefreshDatabase;

    private function fakeProvider(): BaseProvider
    {
        return new class extends BaseProvider {
            public function getName(): string { return 'fake'; }
            public function getSupportedModels(): array { return ['fake-1']; }
            public function stream(array $messages, array $options = []): Generator { yield ''; }

            public function chat(array $messages, array $options = []): array
            {
                return [
                    'content'       => 'Hola, soy Aria. ¿En qué te ayudo?',
                    'provider'      => 'fake',
                    'model'         => 'fake-1',
                    'input_tokens'  => 12,
                    'output_tokens' => 8,
                    'latency_ms'    => 42,
                ];
            }
        };
    }

    private function mockRouterReturning(BaseProvider $provider): void
    {
        $router = Mockery::mock(AIRouter::class);
        $router->shouldReceive('forUser')->andReturn($provider);
        $this->app->instance(AIRouter::class, $router);
    }

    public function test_send_message_returns_assistant_message_contract(): void
    {
        Http::fake();   // bloquea cualquier salida (push notifications)
        Queue::fake();  // evita correr GenerateConversationTitle sincrónicamente

        $this->mockRouterReturning($this->fakeProvider());

        $user = User::factory()->create();
        $conversation = $user->conversations()->create(['channel' => 'web']);

        $res = $this->actingAs($user)->postJson(
            "/api/conversations/{$conversation->id}/messages",
            ['content' => 'Hola', 'stream' => false]
        );

        $res->assertOk()
            ->assertJsonStructure([
                'id', 'conversation_id', 'user_id', 'role', 'content',
                'provider', 'model', 'input_tokens', 'output_tokens', 'latency_ms',
            ])
            ->assertJsonPath('role', 'assistant')
            ->assertJsonPath('content', 'Hola, soy Aria. ¿En qué te ayudo?')
            ->assertJsonPath('provider', 'fake');
    }

    public function test_send_message_persists_user_and_assistant_messages(): void
    {
        Http::fake();
        Queue::fake();

        $this->mockRouterReturning($this->fakeProvider());

        $user = User::factory()->create();
        $conversation = $user->conversations()->create(['channel' => 'web']);

        $this->actingAs($user)->postJson(
            "/api/conversations/{$conversation->id}/messages",
            ['content' => 'Hola', 'stream' => false]
        )->assertOk();

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role'            => 'user',
            'content'         => 'Hola',
        ]);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role'            => 'assistant',
            'provider'        => 'fake',
        ]);
    }

    public function test_cannot_send_to_another_users_conversation(): void
    {
        $this->mockRouterReturning($this->fakeProvider());

        $owner = User::factory()->create();
        $other = User::factory()->create();
        $conversation = $owner->conversations()->create(['channel' => 'web']);

        $this->actingAs($other)->postJson(
            "/api/conversations/{$conversation->id}/messages",
            ['content' => 'Hola', 'stream' => false]
        )->assertStatus(403);
    }

    public function test_content_is_required(): void
    {
        $this->mockRouterReturning($this->fakeProvider());

        $user = User::factory()->create();
        $conversation = $user->conversations()->create(['channel' => 'web']);

        $this->actingAs($user)->postJson(
            "/api/conversations/{$conversation->id}/messages",
            ['stream' => false]
        )->assertStatus(422);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
