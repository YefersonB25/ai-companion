<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_device_token(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/device-tokens', [
                'token'    => 'ExponentPushToken[abc123]',
                'platform' => 'expo',
            ])->assertOk()->assertJsonPath('ok', true);

        $this->assertDatabaseHas('device_tokens', [
            'user_id'  => $user->id,
            'token'    => 'ExponentPushToken[abc123]',
            'platform' => 'expo',
        ]);
    }

    public function test_registering_same_token_twice_does_not_duplicate(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/device-tokens', ['token' => 'ExponentPushToken[abc]']);
        $this->actingAs($user)->postJson('/api/device-tokens', ['token' => 'ExponentPushToken[abc]']);

        $this->assertEquals(1, $user->deviceTokens()->count());
    }

    public function test_user_can_remove_device_token(): void
    {
        $user = User::factory()->create();
        $user->deviceTokens()->create(['token' => 'ExponentPushToken[xyz]', 'platform' => 'expo']);

        $this->actingAs($user)
            ->deleteJson('/api/device-tokens', ['token' => 'ExponentPushToken[xyz]'])
            ->assertOk();

        $this->assertDatabaseMissing('device_tokens', ['token' => 'ExponentPushToken[xyz]']);
    }

    public function test_unauthenticated_cannot_register_token(): void
    {
        $this->postJson('/api/device-tokens', ['token' => 'ExponentPushToken[abc]'])
            ->assertStatus(401);
    }
}
