<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Memory\MemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class MemoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_memory_nodes(): void
    {
        $user = User::factory()->create();
        $user->memoryNodes()->create([
            'type'       => 'skill',
            'label'      => 'Laravel',
            'content'    => 'Expert in Laravel',
            'importance' => 0.9,
        ]);

        $this->actingAs($user)
            ->getJson('/api/memory')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_user_can_get_mindmap(): void
    {
        $user = User::factory()->create();
        $user->memoryNodes()->createMany([
            ['type' => 'skill',   'label' => 'PHP',     'content' => 'Backend dev', 'importance' => 0.8],
            ['type' => 'project', 'label' => 'AI SaaS', 'content' => 'Main project', 'importance' => 1.0],
        ]);

        $this->actingAs($user)
            ->getJson('/api/memory/mindmap')
            ->assertOk()
            ->assertJsonStructure(['nodes', 'edges'])
            ->assertJsonCount(2, 'nodes');
    }

    public function test_user_can_create_memory_node(): void
    {
        // Mock MemoryService to avoid Qdrant/embedding calls in tests
        $mock = Mockery::mock(MemoryService::class);
        $mock->shouldReceive('store')->once()->andReturn(
            (new \App\Models\MemoryNode())->forceFill([
                'id' => 1, 'type' => 'skill', 'label' => 'PHP',
                'content' => 'Backend', 'importance' => 0.8,
            ])
        );
        $this->app->instance(MemoryService::class, $mock);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/memory', [
                'type'       => 'skill',
                'label'      => 'PHP',
                'content'    => 'Backend developer',
                'importance' => 0.8,
            ])->assertStatus(201);
    }

    public function test_memory_search_returns_results(): void
    {
        $mock = Mockery::mock(MemoryService::class);
        $mock->shouldReceive('recall')->once()->andReturn(collect());
        $this->app->instance(MemoryService::class, $mock);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/memory/search?q=laravel')
            ->assertOk()
            ->assertJsonIsArray();
    }
}
