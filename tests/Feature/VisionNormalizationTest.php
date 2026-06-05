<?php

namespace Tests\Feature;

use App\Services\AI\Providers\GeminiProvider;
use App\Services\AI\Providers\OpenAIProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Verifica que el contenido multimodal (imagen + texto) se transforma al formato
 * nativo de cada proveedor antes de enviarse. Usa Http::fake() — sin red real.
 */
class VisionNormalizationTest extends TestCase
{
    private function imageMessage(): array
    {
        return [[
            'role'    => 'user',
            'content' => [
                ['type' => 'image', 'source' => ['type' => 'url', 'url' => 'https://img.test/foto.png']],
                ['type' => 'text',  'text' => '¿Qué ves en la imagen?'],
            ],
        ]];
    }

    public function test_openai_converts_image_to_image_url(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'Veo un gato.']]],
                'model'   => 'gpt-4o',
                'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ]),
        ]);

        (new OpenAIProvider('test-key', 'gpt-4o'))->chat($this->imageMessage());

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'];
            return $content[0] === ['type' => 'image_url', 'image_url' => ['url' => 'https://img.test/foto.png']]
                && $content[1] === ['type' => 'text', 'text' => '¿Qué ves en la imagen?'];
        });
    }

    public function test_gemini_fetches_image_as_inline_base64(): void
    {
        Http::fake([
            'img.test/*' => Http::response('FAKEBYTES', 200, ['Content-Type' => 'image/png']),
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates'    => [['content' => ['parts' => [['text' => 'Veo un gato.']]]]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
            ]),
        ]);

        (new GeminiProvider('test-key', 'gemini-2.5-flash'))->chat($this->imageMessage());

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'generativelanguage')) {
                return false;
            }
            $parts = $request->data()['contents'][0]['parts'];
            return $parts[0] === ['inline_data' => ['mime_type' => 'image/png', 'data' => base64_encode('FAKEBYTES')]]
                && $parts[1] === ['text' => '¿Qué ves en la imagen?'];
        });
    }

    public function test_plain_text_messages_are_unchanged_openai(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'Hola']]],
                'model'   => 'gpt-4o',
                'usage'   => ['prompt_tokens' => 1, 'completion_tokens' => 1],
            ]),
        ]);

        (new OpenAIProvider('test-key', 'gpt-4o'))->chat([
            ['role' => 'user', 'content' => 'Hola, ¿cómo estás?'],
        ]);

        Http::assertSent(fn ($request) => $request->data()['messages'][0]['content'] === 'Hola, ¿cómo estás?');
    }
}
