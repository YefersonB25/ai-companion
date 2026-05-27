<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_conversation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/conversations', ['channel' => 'web'])
            ->assertStatus(201)
            ->assertJsonStructure(['id', 'channel', 'user_id']);
    }

    public function test_user_can_list_own_conversations(): void
    {
        $user  = User::factory()->create();
        $other = User::factory()->create();

        $user->conversations()->create(['channel' => 'web']);
        $other->conversations()->create(['channel' => 'web']);

        $res = $this->actingAs($user)->getJson('/api/conversations');

        $res->assertOk();
        // Only own conversations returned (paginated response)
        collect($res->json('data'))->each(fn($c) => $this->assertEquals($user->id, $c['user_id']));
    }

    public function test_user_cannot_view_another_users_conversation(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $conv = $owner->conversations()->create(['channel' => 'web']);

        $this->actingAs($other)
            ->getJson("/api/conversations/{$conv->id}")
            ->assertStatus(403);
    }

    public function test_user_can_delete_own_conversation(): void
    {
        $user = User::factory()->create();
        $conv = $user->conversations()->create(['channel' => 'web']);

        $this->actingAs($user)
            ->deleteJson("/api/conversations/{$conv->id}")
            ->assertOk();

        $this->assertSoftDeleted('conversations', ['id' => $conv->id]);
    }
}
