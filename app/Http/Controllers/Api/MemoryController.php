<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MemoryNode;
use App\Services\Memory\MemoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemoryController extends Controller
{
    public function __construct(private MemoryService $memory) {}

    public function mindMap(Request $request): JsonResponse
    {
        return response()->json(
            $this->memory->getMindMap($request->user())
        );
    }

    public function index(Request $request): JsonResponse
    {
        $nodes = $request->user()
            ->memoryNodes()
            ->when($request->type, fn($q) => $q->where('type', $request->type))
            ->orderByDesc('importance')
            ->paginate(50);

        return response()->json($nodes);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'       => 'required|string|in:person,project,habit,preference,event,skill,note',
            'label'      => 'required|string|max:255',
            'content'    => 'required|string',
            'attributes' => 'nullable|array',
            'importance' => 'nullable|numeric|min:0|max:1',
            'parent_id'  => 'nullable|exists:memory_nodes,id',
        ]);

        if (!empty($data['parent_id'])) {
            $parentExists = MemoryNode::where('id', $data['parent_id'])
                ->where('user_id', $request->user()->id)
                ->exists();
            if (!$parentExists) {
                return response()->json(['error' => 'parent_id inválido'], 422);
            }
        }

        $node = $this->memory->store(
            $request->user(),
            $data['type'],
            $data['label'],
            $data['content'],
            $data['attributes'] ?? [],
            $data['parent_id'] ?? null,
            $data['importance'] ?? 0.5
        );

        return response()->json($node, 201);
    }

    public function update(Request $request, MemoryNode $memoryNode): JsonResponse
    {
        $this->authorize('update', $memoryNode);

        $data = $request->validate([
            'label'      => 'nullable|string|max:255',
            'content'    => 'nullable|string',
            'attributes' => 'nullable|array',
            'importance' => 'nullable|numeric|min:0|max:1',
            'parent_id'  => 'nullable|exists:memory_nodes,id',
        ]);

        $memoryNode->update($data);

        return response()->json($memoryNode);
    }

    public function destroy(Request $request, MemoryNode $memoryNode): JsonResponse
    {
        $this->authorize('delete', $memoryNode);

        $memoryNode->delete();

        return response()->json(['message' => 'Nodo de memoria eliminado.']);
    }

    public function search(Request $request): JsonResponse
    {
        $request->validate(['q' => 'required|string|min:2']);

        $results = $this->memory->recall($request->user(), $request->q);

        return response()->json($results);
    }
}
