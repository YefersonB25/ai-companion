<?php

namespace Tests\Feature;

use App\Services\AI\Providers\GeminiProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Verifica que GeminiProvider::stream() parsea el SSE de streamGenerateContent
 * y emite los deltas de texto incrementalmente.
 */
class GeminiStreamTest extends TestCase
{
    public function test_stream_yields_incremental_text_deltas(): void
    {
        $sse = implode("\n", [
            'data: ' . json_encode(['candidates' => [['content' => ['parts' => [['text' => 'Hola ']]]]]]),
            '',
            'data: ' . json_encode(['candidates' => [['content' => ['parts' => [['text' => 'mundo']]]]]]),
            '',
            'data: [DONE]',
            '',
        ]);

        Http::fake([
            '*streamGenerateContent*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream']),
        ]);

        $provider = new GeminiProvider('test-key', 'gemini-2.0-flash');
        $chunks = iterator_to_array($provider->stream([
            ['role' => 'system', 'content' => 'Eres Aria.'],
            ['role' => 'user', 'content' => 'Saluda'],
        ]));

        $this->assertSame(['Hola ', 'mundo'], $chunks);
    }

    public function test_stream_falls_back_to_full_response_on_failure(): void
    {
        Http::fake([
            '*streamGenerateContent*' => Http::response('error', 500),
            '*generateContent*'       => Http::response([
                'candidates'    => [['content' => ['parts' => [['text' => 'Respuesta completa']]]]],
                'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 2],
            ]),
        ]);

        $provider = new GeminiProvider('test-key', 'gemini-2.0-flash');
        $chunks = iterator_to_array($provider->stream([
            ['role' => 'user', 'content' => 'Hola'],
        ]));

        $this->assertSame(['Respuesta completa'], $chunks);
    }
}
