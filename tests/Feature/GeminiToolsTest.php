<?php

namespace Tests\Feature;

use App\Services\AI\Providers\GeminiProvider;
use App\Services\Tools\ToolRegistry;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Verifica el function-calling nativo de Gemini: forGemini() produce declaraciones
 * válidas, el provider soporta tools, y el loop de tools cierra correctamente
 * (functionCall -> functionResponse -> texto final).
 */
class GeminiToolsTest extends TestCase
{
    public function test_supports_tools_is_true(): void
    {
        $provider = new GeminiProvider('test-key', 'gemini-2.5-flash');
        $this->assertTrue($provider->supportsTools());
    }

    public function test_for_gemini_produces_valid_function_declarations(): void
    {
        $registry = app(ToolRegistry::class);
        $tools    = $registry->forGemini();

        $this->assertCount(1, $tools);
        $this->assertArrayHasKey('function_declarations', $tools[0]);

        $decls = $tools[0]['function_declarations'];
        $names = array_column($decls, 'name');
        $this->assertContains('get_datetime', $names);
        $this->assertContains('get_weather', $names);

        // El tool sin parámetros (get_datetime) no debe traer properties: []
        $datetime = collect($decls)->firstWhere('name', 'get_datetime');
        $this->assertInstanceOf(\stdClass::class, $datetime['parameters']['properties']);

        // Round-trip JSON: properties debe ser {} y no []
        $json = json_encode($tools);
        $this->assertStringContainsString('"properties":{}', $json);
        $this->assertStringNotContainsString('"properties":[]', $json);
    }

    public function test_chat_with_tools_returns_tool_use_on_function_call(): void
    {
        Http::fake([
            '*generateContent*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'functionCall' => ['name' => 'get_datetime', 'args' => new \stdClass()],
                    ]]],
                ]],
                'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 3],
            ]),
        ]);

        $provider = new GeminiProvider('test-key', 'gemini-2.5-flash');
        $registry = app(ToolRegistry::class);

        $response = $provider->chatWithTools(
            [['role' => 'user', 'content' => '¿Qué hora es?']],
            $registry->forGemini()
        );

        $this->assertSame('tool_use', $response['type']);
        $this->assertCount(1, $response['tool_calls']);
        $this->assertSame('get_datetime', $response['tool_calls'][0]['name']);
        $this->assertSame('call_0', $response['tool_calls'][0]['id']);
        $this->assertSame([], $response['tool_calls'][0]['input']);

        // El mensaje a appendear debe reconstruir la functionCall por buildPayload.
        $append = $response['messages_to_append'][0];
        $this->assertSame('assistant', $append['role']);
        $this->assertArrayHasKey('functionCall', $append['content'][0]);
    }

    public function test_build_tool_result_message_uses_function_response(): void
    {
        $provider = new GeminiProvider('test-key', 'gemini-2.5-flash');

        $msg = $provider->buildToolResultMessage(
            ['id' => 'call_0', 'name' => 'get_datetime', 'input' => []],
            'lunes 5 de junio de 2026, 10:00'
        );

        $this->assertSame('user', $msg['role']);
        $this->assertSame('get_datetime', $msg['content'][0]['functionResponse']['name']);
        $this->assertSame(
            'lunes 5 de junio de 2026, 10:00',
            $msg['content'][0]['functionResponse']['response']['result']
        );
    }

    public function test_chat_does_not_break_when_final_response_has_no_text_part(): void
    {
        // Respuesta de :generateContent con un candidate cuyo único part es una
        // functionCall (sin parte `text`). chat() debe extraer texto de forma
        // defensiva y no lanzar "undefined index".
        Http::fake([
            '*generateContent*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'functionCall' => ['name' => 'get_datetime', 'args' => new \stdClass()],
                    ]]],
                ]],
                'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 0],
            ]),
        ]);

        $provider = new GeminiProvider('test-key', 'gemini-2.5-flash');

        $response = $provider->chat([['role' => 'user', 'content' => '¿Qué hora es?']]);

        $this->assertSame('', $response['content']);
        $this->assertSame('gemini', $response['provider']);
    }

    public function test_chat_does_not_break_on_safety_blocked_response(): void
    {
        // finishReason SAFETY: candidate sin parts. chat() no debe reventar.
        Http::fake([
            '*generateContent*' => Http::response([
                'candidates' => [[
                    'finishReason' => 'SAFETY',
                    'content'      => [],
                ]],
                'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 0],
            ]),
        ]);

        $provider = new GeminiProvider('test-key', 'gemini-2.5-flash');

        $response = $provider->chat([['role' => 'user', 'content' => 'algo']]);

        $this->assertSame('', $response['content']);
    }

    public function test_chat_does_not_break_when_no_candidates(): void
    {
        // Respuesta bloqueada sin candidates (solo promptFeedback). No debe reventar.
        Http::fake([
            '*generateContent*' => Http::response([
                'promptFeedback' => ['blockReason' => 'SAFETY'],
            ]),
        ]);

        $provider = new GeminiProvider('test-key', 'gemini-2.5-flash');

        $response = $provider->chat([['role' => 'user', 'content' => 'algo']]);

        $this->assertSame('', $response['content']);
    }

    public function test_tool_loop_executes_tool_and_returns_final_text(): void
    {
        // 1ª llamada: Gemini pide get_weather. 2ª llamada: responde texto final.
        Http::fakeSequence()
            ->push([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'Bogotá']],
                    ]]],
                ]],
                'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 3],
            ])
            ->push([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'En Bogotá hace 18 grados.']]],
                ]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 6],
            ]);

        $provider = new GeminiProvider('test-key', 'gemini-2.5-flash');
        $registry = app(ToolRegistry::class);
        $tools    = $registry->forGemini();

        // Simula el loop de resolveTools (camino genérico via buildToolResultMessage).
        $history = [['role' => 'user', 'content' => '¿Cómo está el clima en Bogotá?']];
        $executed = [];

        $final = null;
        for ($i = 0; $i < 5; $i++) {
            $response = $provider->chatWithTools($history, $tools);

            if ($response['type'] === 'text') {
                $final = $response;
                break;
            }

            foreach ($response['messages_to_append'] as $m) {
                $history[] = $m;
            }
            foreach ($response['tool_calls'] as $call) {
                $executed[] = $call['name'];
                $history[]  = $provider->buildToolResultMessage($call, "RESULTADO:{$call['name']}");
            }
        }

        $this->assertSame(['get_weather'], $executed);
        $this->assertNotNull($final);
        $this->assertSame('text', $final['type']);
        $this->assertSame('En Bogotá hace 18 grados.', $final['content']);

        // La 2ª request debe contener la functionResponse reconstruida por buildPayload.
        Http::assertSent(function ($request) {
            $body = $request->data();
            if (! isset($body['tools'])) {
                return false;
            }
            $hasFunctionResponse = false;
            foreach ($body['contents'] as $content) {
                foreach ($content['parts'] as $part) {
                    if (isset($part['functionResponse'])) {
                        $hasFunctionResponse = true;
                    }
                }
            }
            return $hasFunctionResponse;
        });
    }
}
