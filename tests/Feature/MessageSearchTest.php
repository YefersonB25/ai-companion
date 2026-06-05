<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests de GET /api/conversations/search — búsqueda en el historial del usuario.
 */
class MessageSearchTest extends TestCase
{
    use RefreshDatabase;

    private function seedMessage(User $user, string $content): void
    {
        $conversation = $user->conversations()->create(['channel' => 'web', 'title' => 'Charla']);
        $conversation->messages()->create([
            'user_id' => $user->id,
            'role'    => 'user',
            'content' => $content,
        ]);
    }

    public function test_finds_matching_messages(): void
    {
        $user = User::factory()->create();
        $this->seedMessage($user, 'Recuérdame comprar pan mañana');
        $this->seedMessage($user, 'Hablemos de astronomía');

        $res = $this->actingAs($user)->getJson('/api/conversations/search?q=pan');

        $res->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.conversation_title', 'Charla');
        $this->assertStringContainsString('pan', $res->json('data.0.snippet'));
    }

    public function test_search_is_scoped_to_user(): void
    {
        $user  = User::factory()->create();
        $other = User::factory()->create();
        $this->seedMessage($other, 'secreto del otro usuario');

        $res = $this->actingAs($user)->getJson('/api/conversations/search?q=secreto');

        $res->assertOk()->assertJsonPath('total', 0);
    }

    public function test_query_is_required_with_min_length(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/conversations/search?q=a')->assertStatus(422);
        $this->actingAs($user)->getJson('/api/conversations/search')->assertStatus(422);
    }

    public function test_excludes_deleted_conversations(): void
    {
        $user = User::factory()->create();
        $conversation = $user->conversations()->create(['channel' => 'web', 'title' => 'Vieja']);
        $conversation->messages()->create([
            'user_id' => $user->id,
            'role'    => 'user',
            'content' => 'mensaje en conversación borrada xyzzy',
        ]);
        $conversation->delete();

        $res = $this->actingAs($user)->getJson('/api/conversations/search?q=xyzzy');

        $res->assertOk()->assertJsonPath('total', 0);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/conversations/search?q=hola')->assertStatus(401);
    }
}
