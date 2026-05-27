<?php

namespace App\Services\Qdrant;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class QdrantService
{
    private PendingRequest $http;
    private string $collection;

    public function __construct()
    {
        $config = config('qdrant');
        $headers = ['Content-Type' => 'application/json'];

        if ($config['api_key']) {
            $headers['api-key'] = $config['api_key'];
        }

        $this->http = Http::baseUrl($config['url'])
            ->timeout($config['timeout'])
            ->withHeaders($headers);

        $this->collection = $config['collection'];
    }

    public function ensureCollection(int $vectorSize = 1536): void
    {
        $res = $this->http->get("/collections/{$this->collection}");

        if ($res->successful()) {
            return;
        }

        $res = $this->http->put("/collections/{$this->collection}", [
            'vectors' => [
                'size'     => $vectorSize,
                'distance' => 'Cosine',
            ],
        ]);

        if (!$res->successful()) {
            throw new RuntimeException('Failed to create Qdrant collection: ' . $res->body());
        }
    }

    public function upsert(string $id, array $vector, array $payload): void
    {
        $res = $this->http->put("/collections/{$this->collection}/points", [
            'points' => [[
                'id'      => $this->toUuid($id),
                'vector'  => $vector,
                'payload' => $payload,
            ]],
        ]);

        if (!$res->successful()) {
            throw new RuntimeException('Qdrant upsert failed: ' . $res->body());
        }
    }

    public function search(array $vector, int $limit = 5, array $filter = []): array
    {
        $body = [
            'vector'      => $vector,
            'limit'       => $limit,
            'with_payload' => true,
        ];

        if (!empty($filter)) {
            $body['filter'] = $filter;
        }

        $res = $this->http->post("/collections/{$this->collection}/points/search", $body);

        if (!$res->successful()) {
            throw new RuntimeException('Qdrant search failed: ' . $res->body());
        }

        return $res->json('result') ?? [];
    }

    public function delete(string $id): void
    {
        $this->http->post("/collections/{$this->collection}/points/delete", [
            'points' => [$this->toUuid($id)],
        ]);
    }

    // Qdrant requires UUID format — derive a deterministic UUID from our integer ID
    private function toUuid(string $id): string
    {
        $hex = str_pad(dechex((int) $id), 32, '0', STR_PAD_LEFT);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
