<?php

namespace App\Services\AI\Providers;

use Generator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GeminiProvider extends BaseProvider
{
    private string $apiKey;
    protected string $model;
    private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct(string $apiKey, string $model = 'gemini-2.5-pro')
    {
        $this->apiKey = $apiKey;
        $this->model  = $model;
    }

    public function getName(): string
    {
        return 'gemini';
    }

    public function getSupportedModels(): array
    {
        return ['gemini-2.5-pro', 'gemini-2.5-flash', 'gemini-2.0-flash', 'gemini-2.0-flash-001', 'gemini-1.5-pro'];
    }

    /** Construye el payload de Gemini (contents + systemInstruction) desde los mensajes. */
    private function buildPayload(array $messages): array
    {
        $contents = collect($messages)
            ->filter(fn($m) => $m['role'] !== 'system')
            ->map(fn($m) => [
                'role'  => $m['role'] === 'assistant' ? 'model' : 'user',
                'parts' => $this->toParts($m['content']),
            ])->values()->all();

        $systemInstruction = collect($messages)->firstWhere('role', 'system');

        $payload = ['contents' => $contents];
        if ($systemInstruction) {
            $payload['systemInstruction'] = ['parts' => [['text' => $systemInstruction['content']]]];
        }

        return $payload;
    }

    public function chat(array $messages, array $options = []): array
    {
        $start = microtime(true);
        $model = $options['model'] ?? $this->model;

        $response = Http::post(
            "{$this->baseUrl}/models/{$model}:generateContent?key={$this->apiKey}",
            $this->buildPayload($messages)
        );

        if ($response->failed()) {
            throw new RuntimeException("Gemini API error: " . $response->body());
        }

        $data = $response->json();
        $usage = $data['usageMetadata'] ?? [];

        // Extracción defensiva: concatena todas las partes `text` (igual que
        // chatWithTools). La respuesta puede no traer parte `text` (otra
        // functionCall, finishReason SAFETY/MAX_TOKENS sin parts, o sin
        // candidates si quedó bloqueada) y un acceso directo reventaría.
        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        $text  = implode('', array_map(fn($p) => $p['text'] ?? '', $parts));

        return $this->buildResponse(
            $text,
            $model,
            $usage['promptTokenCount'] ?? 0,
            $usage['candidatesTokenCount'] ?? 0,
            (int) ((microtime(true) - $start) * 1000)
        );
    }

    public function supportsTools(): bool
    {
        return true;
    }

    public function chatWithTools(array $messages, array $tools, array $options = []): array
    {
        $start = microtime(true);
        $model = $options['model'] ?? $this->model;

        $payload          = $this->buildPayload($messages);
        $payload['tools'] = $tools;

        $response = Http::post(
            "{$this->baseUrl}/models/{$model}:generateContent?key={$this->apiKey}",
            $payload
        );

        if ($response->failed()) {
            throw new RuntimeException("Gemini API error: " . $response->body());
        }

        $data    = $response->json();
        $usage   = $data['usageMetadata'] ?? [];
        $parts   = $data['candidates'][0]['content']['parts'] ?? [];
        $latency = (int) ((microtime(true) - $start) * 1000);

        // Collect any functionCall parts (Gemini may emit several at once).
        $functionCalls = [];
        foreach ($parts as $part) {
            if (isset($part['functionCall'])) {
                $functionCalls[] = $part['functionCall'];
            }
        }

        if (! empty($functionCalls)) {
            $toolCalls    = [];
            $assistantContent = [];
            foreach ($functionCalls as $i => $fc) {
                $toolCalls[] = [
                    'id'    => "call_{$i}",
                    'name'  => $fc['name'] ?? '',
                    'input' => $fc['args'] ?? [],
                ];
                // Stash the raw Gemini part so toParts() can re-emit it verbatim.
                $assistantContent[] = ['functionCall' => [
                    'name' => $fc['name'] ?? '',
                    'args' => empty($fc['args']) ? new \stdClass() : $fc['args'],
                ]];
            }

            return [
                'type'               => 'tool_use',
                'tool_calls'         => $toolCalls,
                'messages_to_append' => [
                    ['role' => 'assistant', 'content' => $assistantContent],
                ],
            ];
        }

        // Plain text response — same shape as chat() + type.
        $text = '';
        foreach ($parts as $part) {
            $text .= $part['text'] ?? '';
        }

        return array_merge(
            $this->buildResponse(
                $text,
                $model,
                $usage['promptTokenCount'] ?? 0,
                $usage['candidatesTokenCount'] ?? 0,
                $latency
            ),
            ['type' => 'text']
        );
    }

    /**
     * Build the internal tool-result message the loop appends. For Gemini the
     * result is a `user` content with a `functionResponse` part; toParts()
     * forwards it verbatim.
     */
    public function buildToolResultMessage(array $toolCall, string $result): array
    {
        return [
            'role'    => 'user',
            'content' => [
                ['functionResponse' => [
                    'name'     => $toolCall['name'],
                    'response' => ['result' => $result],
                ]],
            ],
        ];
    }

    public function stream(array $messages, array $options = []): Generator
    {
        $model = $options['model'] ?? $this->model;

        try {
            $response = Http::withOptions(['stream' => true])->post(
                "{$this->baseUrl}/models/{$model}:streamGenerateContent?alt=sse&key={$this->apiKey}",
                $this->buildPayload($messages)
            );

            if ($response->failed()) {
                // Fallback: respuesta completa de una sola vez
                yield $this->chat($messages, $options)['content'];
                return;
            }

            $body   = $response->toPsrResponse()->getBody();
            $buffer = '';

            while (! $body->eof()) {
                $buffer .= $body->read(2048);

                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line   = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);

                    if ($line === '' || ! str_starts_with($line, 'data:')) {
                        continue;
                    }

                    $json = trim(substr($line, 5));
                    if ($json === '[DONE]') {
                        return;
                    }

                    $data = json_decode($json, true);
                    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
                    if ($text !== '') {
                        yield $text;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Gemini stream falló, usando respuesta completa: {$e->getMessage()}");
            yield $this->chat($messages, $options)['content'];
        }
    }

    /**
     * Convierte el contenido de un mensaje (string o formato interno tipo Claude)
     * en el array de "parts" que espera Gemini. Las imágenes por URL se descargan
     * y se envían como inline_data en base64 (Gemini no acepta URLs públicas).
     */
    private function toParts(mixed $content): array
    {
        if (is_string($content)) {
            return [['text' => $content]];
        }

        $parts = [];
        foreach ($content as $part) {
            // Raw Gemini parts (function calling) are forwarded verbatim so the
            // tool-use round-trip survives buildPayload().
            if (isset($part['functionCall']) || isset($part['functionResponse'])) {
                $parts[] = $part;
                continue;
            }

            $type = $part['type'] ?? null;

            if ($type === 'text') {
                $parts[] = ['text' => $part['text'] ?? ''];
            } elseif ($type === 'image' && isset($part['source']['url'])) {
                $inline = $this->fetchInlineImage($part['source']['url']);
                if ($inline) {
                    $parts[] = ['inline_data' => $inline];
                }
            }
        }

        return $parts ?: [['text' => '']];
    }

    /** Descarga una imagen y la devuelve como inline_data base64 (o null si falla). */
    private function fetchInlineImage(string $url): ?array
    {
        try {
            $response = Http::timeout(10)->get($url);
            if ($response->failed()) {
                return null;
            }

            $mime = trim(explode(';', (string) $response->header('Content-Type'))[0]);

            return [
                'mime_type' => $mime ?: 'image/jpeg',
                'data'      => base64_encode($response->body()),
            ];
        } catch (\Throwable $e) {
            Log::warning("Gemini: no se pudo cargar la imagen {$url}: {$e->getMessage()}");
            return null;
        }
    }
}
