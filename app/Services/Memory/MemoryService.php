<?php

namespace App\Services\Memory;

use App\Events\MemoryNodeSaved;
use App\Models\MemoryNode;
use App\Models\User;
use App\Services\AI\EmbeddingService;
use App\Services\Qdrant\QdrantService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class MemoryService
{
    public function __construct(
        private QdrantService  $qdrant,
        private EmbeddingService $embedding,
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
