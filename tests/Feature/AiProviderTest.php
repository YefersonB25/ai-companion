<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_their_providers(): void
    {
        $user = User::factory()->create();
        $user->aiProviders()->create([
            'provider'  => 'deepseek',
            'model'     => 'deepseek-chat',
            'api_key'   => encrypt('fake-key'),
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->getJson('/api/providers')
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_user_can_create_provider(): void
    {
        $user = User::factory()->create();

        $res = $this->actingAs($user)->postJson('/api/providers', [
            'provider' => 'deepseek',
            'model'    => 'deepseek-chat',
            'api_key'  => 'sk-test-key-123',
        ]);

        $res->assertStatus(201)->assertJsonPath('provider', 'deepseek');
        $this->assertDatabaseHas('ai_providers', ['user_id' => $user->id, 'provider' => 'deepseek']);
    }

    public function test_first_provider_becomes_default(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/providers', [
            'provider' => 'deepseek',
            'model'    => 'deepseek-chat',
            'api_key'  => 'sk-test',
        ]);

        $this->assertDatabaseHas('ai_providers', ['user_id' => $user->id, 'is_default' => true]);
    }

    public function test_user_cannot_update_another_users_provider(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $provider = $owner->aiProviders()->create([
            'provider'  => 'deepseek',
            'model'     => 'deepseek-chat',
            'api_key'   => encrypt('key'),
            'is_active' => true,
        ]);

        $this->actingAs($other)
            ->putJson("/api/providers/{$provider->id}", ['model' => 'deepseek-reasoner'])
            ->assertStatus(404);
    }

    public function test_user_can_delete_own_provider(): void
    {
        $user  = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $provider = $user->aiProviders()->create([
            'provider'  => 'deepseek',
            'model'     => 'deepseek-chat',
            'api_key'   => encrypt('key'),
            'is_active' => true,
        ]);

        $this->withoutExceptionHandling()
            ->withToken($token)
            ->deleteJson("/api/providers/{$provider->id}")
            ->assertOk();

        $this->assertDatabaseMissing('ai_providers', ['id' => $provider->id]);
    }
}
