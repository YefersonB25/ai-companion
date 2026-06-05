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
        return ['gemini-2.5-pro', 'gemini-2.5-flash', 'gemini-1.5-pro'];
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

        return $this->buildResponse(
            $data['candidates'][0]['content']['parts'][0]['text'],
            $model,
            $usage['promptTokenCount'] ?? 0,
            $usage['candidatesTokenCount'] ?? 0,
            (int) ((microtime(true) - $start) * 1000)
        );
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
