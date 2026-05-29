<?php

namespace App\Services\Memory;

use App\Events\MemoryNodeSaved;
use App\Models\MemoryNode;
use App\Models\User;
use App\Services\AI\AIRouter;
use App\Services\AI\EmbeddingService;
use App\Services\AI\Providers\ClaudeProvider;
use App\Services\Qdrant\QdrantService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class MemoryService
{
    public function __construct(
        private QdrantService    $qdrant,
        private EmbeddingService $embedding,
        private AIRouter         $router,
    ) {}

    public function store(
        User $user,
        string $type,
        string $label,
        string $content,
        array $attributes = [],
        ?int $parentId = null,
        float $importance = 0.5,
    ): MemoryNode {
        $node = $user->memoryNodes()->updateOrCreate(
            ['type' => $type, 'label' => $label],
            [
                'content'    => $content,
                'attributes' => $attributes,
                'importance' => $importance,
                'parent_id'  => $parentId,
            ]
        );

        $this->indexInQdrant($node);

        broadcast(new MemoryNodeSaved($node));

        return $node;
    }

    public function recall(User $user, string $query, int $limit = 5): Collection
    {
        try {
            return $this->recallSemantic($user, $query, $limit);
        } catch (\Throwable $e) {
            Log::warning('Qdrant recall failed, falling back to keyword search', ['error' => $e->getMessage()]);
            return $this->recallKeyword($user, $query, $limit);
        }
    }

    public function getMindMap(User $user): array
    {
        $nodes = $user->memoryNodes()
            ->orderByDesc('importance')
            ->get();

        return [
            'nodes' => $nodes->map(fn(MemoryNode $n) => [
                'id'         => $n->id,
                'type'       => $n->type,
                'label'      => $n->label,
                'importance' => $n->importance,
                'parent_id'  => $n->parent_id,
                'attributes' => $n->attributes,
            ])->values()->all(),
            'edges' => $nodes->filter(fn($n) => $n->parent_id)
                ->map(fn(MemoryNode $n) => [
                    'source' => $n->parent_id,
                    'target' => $n->id,
                ])->values()->all(),
        ];
    }

    public function buildContextPrompt(User $user, string $userMessage): string
    {
        $relevant = $this->recall($user, $userMessage);

        if ($relevant->isEmpty()) {
            return '';
        }

        $context = $relevant->map(fn(MemoryNode $n) =>
            "[{$n->type}] {$n->label}: {$n->content}"
        )->implode("\n");

        return "Contexto relevante del usuario:\n{$context}\n";
    }

    public function extractWithAI(User $user, string $text, ?int $conversationId = null): void
    {
        try {
            $provider = $this->resolveExtractionProvider($user);

            if ($provider === null) {
                Log::debug('Memory AI extraction skipped: no active provider for user', ['user_id' => $user->id]);
                return;
            }

            $extractionPrompt = <<<'PROMPT'
Extract all facts, preferences, habits, relationships, goals and important information about the user from this text. Return ONLY a JSON array of objects, each with: {"type": "person|preference|habit|event|project|skill|health|note", "label": "short unique key", "content": "detailed description", "importance": 0.1-1.0}. Only include items worth remembering long-term. Return [] if nothing is worth storing.
PROMPT;

            $messages = [
                ['role' => 'system', 'content' => $extractionPrompt],
                ['role' => 'user', 'content' => $text],
            ];

            $response = $provider->chat($messages, ['max_tokens' => 1024]);
            $rawContent = trim($response['content'] ?? '');

            // Strip optional markdown code fences (```json ... ```)
            $rawContent = preg_replace('/^```(?:json)?\s*/i', '', $rawContent);
            $rawContent = preg_replace('/\s*```$/', '', $rawContent);

            $items = json_decode(trim($rawContent), true);

            if (! is_array($items)) {
                Log::debug('Memory AI extraction: response was not valid JSON', [
                    'user_id'  => $user->id,
                    'response' => mb_substr($rawContent, 0, 200),
                ]);
                return;
            }

            $stored = 0;
            foreach ($items as $item) {
                $importance = (float) ($item['importance'] ?? 0);

                if ($importance < 0.3) {
                    continue;
                }

                $type    = $item['type']    ?? 'note';
                $label   = $item['label']   ?? '';
                $content = $item['content'] ?? '';

                if ($label === '' || $content === '') {
                    continue;
                }

                $validTypes = ['person', 'preference', 'habit', 'event', 'project', 'skill', 'health', 'note'];
                if (! in_array($type, $validTypes, true)) {
                    $type = 'note';
                }

                $this->store($user, $type, $label, $content, [], null, $importance);
                $stored++;
            }

            Log::debug('Memory AI extraction completed', [
                'user_id'         => $user->id,
                'conversation_id' => $conversationId,
                'extracted'       => count($items),
                'stored'          => $stored,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Memory AI extraction failed', [
                'user_id'         => $user->id,
                'conversation_id' => $conversationId,
                'error'           => $e->getMessage(),
            ]);
        }
    }

    public function extractAndStore(User $user, string $assistantResponse): void
    {
        $patterns = [
            'preference' => ['/me gusta\s+(.+)/i', '/prefiero\s+(.+)/i'],
            'project'    => ['/proyecto\s+(.+)/i', '/estoy trabajando en\s+(.+)/i'],
            'skill'      => ['/sé\s+(.+)/i', '/trabajo con\s+(.+)/i'],
        ];

        foreach ($patterns as $type => $regexList) {
            foreach ($regexList as $regex) {
                if (preg_match($regex, $assistantResponse, $matches)) {
                    $this->store($user, $type, trim($matches[1]), trim($matches[1]));
                }
            }
        }
    }

    public function reindexAll(User $user): int
    {
        $this->qdrant->ensureCollection($this->embedding->dimensions());
        $count = 0;

        $user->memoryNodes()->chunkById(100, function ($nodes) use (&$count) {
            foreach ($nodes as $node) {
                $this->indexInQdrant($node);
                $count++;
            }
        });

        return $count;
    }

    /**
     * Resolve the cheapest available provider for memory extraction.
     * Prefers a Claude Haiku instance; falls back to the user's default provider.
     */
    private function resolveExtractionProvider(User $user): ?\App\Services\AI\Providers\BaseProvider
    {
        // Prefer a Claude provider so we can force the haiku model
        $claudeRecord = $user->aiProviders()
            ->where('is_active', true)
            ->where('provider', 'claude')
            ->first();

        if ($claudeRecord) {
            return new ClaudeProvider(
                $claudeRecord->getDecryptedApiKey(),
                'claude-haiku-4-5-20251001'
            );
        }

        // Fall back to whichever provider the router would pick
        try {
            return $this->router->forUser($user);
        } catch (\Throwable) {
            return null;
        }
    }

    private function indexInQdrant(MemoryNode $node): void
    {
        try {
            $this->qdrant->ensureCollection($this->embedding->dimensions());

            $text   = "{$node->label}: {$node->content}";
            $vector = $this->embedding->embed($text, 'RETRIEVAL_DOCUMENT');

            $this->qdrant->upsert((string) $node->id, $vector, [
                'user_id'        => $node->user_id,
                'memory_node_id' => $node->id,
                'type'           => $node->type,
                'label'          => $node->label,
                'importance'     => $node->importance,
            ]);

            $node->updateQuietly(['qdrant_id' => (string) $node->id]);
        } catch (\Throwable $e) {
            Log::warning('Failed to index memory node in Qdrant', [
                'node_id' => $node->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    private function recallSemantic(User $user, string $query, int $limit): Collection
    {
        $vector  = $this->embedding->embed($query, 'RETRIEVAL_QUERY');
        $results = $this->qdrant->search($vector, $limit, [
            'must' => [[
                'key'   => 'user_id',
                'match' => ['value' => $user->id],
            ]],
        ]);

        if (empty($results)) {
            return collect();
        }

        $ids = array_column(array_column($results, 'payload'), 'memory_node_id');

        return MemoryNode::whereIn('id', $ids)
            ->get()
            ->sortBy(fn($node) => array_search($node->id, $ids))
            ->values()
            ->each(fn(MemoryNode $node) => $node->recordAccess());
    }

    private function recallKeyword(User $user, string $query, int $limit): Collection
    {
        return $user->memoryNodes()
            ->where(function ($q) use ($query) {
                $q->where('label', 'like', "%{$query}%")
                  ->orWhere('content', 'like', "%{$query}%");
            })
            ->orderByDesc('importance')
            ->orderByDesc('last_accessed_at')
            ->limit($limit)
            ->get()
            ->each(fn(MemoryNode $node) => $node->recordAccess());
    }
}
