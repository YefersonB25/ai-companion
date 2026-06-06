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
        config()->set('services.tts.gemini', [
            'api_key' => 'ge-test-key',
            'model'   => 'gemini-2.5-flash-preview-tts',
            'voice'   => 'Kore',
        ]);
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

    public function test_gemini_provider_wraps_pcm_in_wav(): void
    {
        // PCM crudo de ejemplo (no es relevante el contenido).
        $pcm     = str_repeat("\x01\x02", 100);
        $b64      = base64_encode($pcm);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'inlineData' => [
                            'mimeType' => 'audio/L16;rate=24000',
                            'data'     => $b64,
                        ],
                    ]]],
                ]],
            ], 200),
        ]);

        $provider = new \App\Services\TTS\Providers\GeminiTtsProvider('ge-test-key');
        $wav = $provider->synthesize('Hola Aria');

        // Empieza con la firma RIFF y contiene WAVE.
        $this->assertSame('RIFF', substr($wav, 0, 4));
        $this->assertSame('WAVE', substr($wav, 8, 4));

        // Sample rate del header (offset 24, little-endian uint32) = 24000.
        $sampleRate = unpack('V', substr($wav, 24, 4))[1];
        $this->assertSame(24000, $sampleRate);
    }

    public function test_user_provider_gemini_uses_gemini(): void
    {
        $pcm = str_repeat("\x00\x01", 50);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'inlineData' => [
                            'mimeType' => 'audio/L16;rate=24000',
                            'data'     => base64_encode($pcm),
                        ],
                    ]]],
                ]],
            ], 200),
        ]);

        $user = User::factory()->create();
        $user->setting()->create([
            'user_id'      => $user->id,
            'tts_provider' => 'gemini',
        ]);

        $res = $this->actingAs($user)->postJson('/api/tts', ['text' => 'Hola Aria']);

        $res->assertStatus(200);
        $this->assertSame('RIFF', substr($res->getContent(), 0, 4));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'generativelanguage.googleapis.com'));
    }

    public function test_user_provider_elevenlabs_uses_elevenlabs(): void
    {
        $fakeMp3 = 'EL_USER_MP3';

        Http::fake([
            'api.elevenlabs.io/*'                  => Http::response($fakeMp3, 200, ['Content-Type' => 'audio/mpeg']),
            'generativelanguage.googleapis.com/*'  => Http::response('should-not-be-called', 200),
        ]);

        $user = User::factory()->create();
        $user->setting()->create([
            'user_id'      => $user->id,
            'tts_provider' => 'elevenlabs',
        ]);

        $res = $this->actingAs($user)->postJson('/api/tts', ['text' => 'Buenos días']);

        $res->assertStatus(200);
        $this->assertSame($fakeMp3, $res->getContent());

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.elevenlabs.io'));
    }

    public function test_forced_provider_not_configured_falls_back(): void
    {
        // El usuario eligió gemini, pero no hay key de gemini → cae al default/fallback.
        config()->set('services.tts.gemini.api_key', '');

        $fakeMp3 = 'FALLBACK_MP3';

        Http::fake([
            'api.elevenlabs.io/*' => Http::response($fakeMp3, 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $user = User::factory()->create();
        $user->setting()->create([
            'user_id'      => $user->id,
            'tts_provider' => 'gemini',
        ]);

        $res = $this->actingAs($user)->postJson('/api/tts', ['text' => 'Hola']);

        $res->assertStatus(200);
        $this->assertSame($fakeMp3, $res->getContent());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'generativelanguage.googleapis.com'));
    }

    public function test_providers_endpoint_lists_only_configured(): void
    {
        // Sin key de openai → no debe aparecer.
        config()->set('services.tts.openai.api_key', '');
        config()->set('services.tts.default', 'gemini');

        $user = User::factory()->create();
        $user->setting()->create([
            'user_id'      => $user->id,
            'tts_provider' => 'gemini',
        ]);

        $res = $this->actingAs($user)->getJson('/api/tts/providers');

        $res->assertStatus(200);
        $res->assertJson([
            'providers' => ['gemini', 'elevenlabs'],
            'default'   => 'gemini',
            'selected'  => 'gemini',
        ]);
    }

    public function test_providers_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/tts/providers')->assertStatus(401);
    }
}
