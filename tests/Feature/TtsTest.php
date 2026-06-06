<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests del endpoint POST /api/tts (Fase 5: voz neural premium).
 *
 * Las APIs externas se mockean con Http::fake (sin keys reales): la config de
 * los proveedores se inyecta en setUp con config()->set, así no dependemos del
 * .env del entorno de tests.
 */
class TtsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.tts.default', 'elevenlabs');
        config()->set('services.tts.fallback', 'openai');
        config()->set('services.tts.elevenlabs', [
            'api_key'  => 'el-test-key',
            'voice_id' => 'voice-xyz',
            'model_id' => 'eleven_flash_v2_5',
        ]);
        config()->set('services.tts.openai', [
            'api_key' => 'oa-test-key',
            'model'   => 'gpt-4o-mini-tts',
            'voice'   => 'nova',
        ]);
    }

    public function test_synthesizes_with_elevenlabs_and_returns_mp3(): void
    {
        $fakeMp3 = 'FAKE_MP3_BYTES';

        Http::fake([
            'api.elevenlabs.io/*' => Http::response($fakeMp3, 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $user = User::factory()->create();

        $res = $this->actingAs($user)->postJson('/api/tts', ['text' => 'Hola Aria']);

        $res->assertStatus(200);
        $this->assertSame('audio/mpeg', $res->headers->get('Content-Type'));
        $this->assertSame($fakeMp3, $res->getContent());
    }

    public function test_falls_back_to_openai_when_default_fails(): void
    {
        $fakeMp3 = 'OPENAI_MP3';

        Http::fake([
            'api.elevenlabs.io/*' => Http::response('boom', 500),
            'api.openai.com/*'    => Http::response($fakeMp3, 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $user = User::factory()->create();

        $res = $this->actingAs($user)->postJson('/api/tts', ['text' => 'Buenos días']);

        $res->assertStatus(200);
        $this->assertSame($fakeMp3, $res->getContent());

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.elevenlabs.io'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.openai.com'));
    }

    public function test_returns_503_when_no_provider_configured(): void
    {
        config()->set('services.tts.elevenlabs.api_key', '');
        config()->set('services.tts.openai.api_key', '');

        Http::fake();

        $user = User::factory()->create();

        $res = $this->actingAs($user)->postJson('/api/tts', ['text' => 'Hola']);

        $res->assertStatus(503)->assertJsonStructure(['error']);
        Http::assertNothingSent();
    }

    public function test_strips_action_block_before_synthesis(): void
    {
        Http::fake([
            'api.elevenlabs.io/*' => Http::response('mp3', 200),
        ]);

        $user = User::factory()->create();

        $text = 'Voy en camino [ACTION]{"type":"send_sms","contact":"María"}[/ACTION]';

        $this->actingAs($user)->postJson('/api/tts', ['text' => $text])->assertStatus(200);

        Http::assertSent(function ($request) {
            $sent = $request->data()['text'] ?? '';
            return ! str_contains($sent, '[ACTION]')
                && ! str_contains($sent, 'send_sms')
                && str_contains($sent, 'Voy en camino');
        });
    }

    public function test_truncates_text_over_cap_without_error(): void
    {
        Http::fake([
            'api.elevenlabs.io/*' => Http::response('mp3', 200),
        ]);

        $user = User::factory()->create();

        // 5000 caracteres válidos para la validación; el servicio trunca a 3000.
        $longText = str_repeat('a', 5000);

        $this->actingAs($user)->postJson('/api/tts', ['text' => $longText])->assertStatus(200);

        Http::assertSent(function ($request) {
            $sent = $request->data()['text'] ?? '';
            return mb_strlen($sent) <= 3000;
        });
    }

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/tts', ['text' => 'Hola'])->assertStatus(401);
    }
}
