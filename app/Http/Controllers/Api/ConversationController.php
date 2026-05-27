<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $conversations = $request->user()
            ->conversations()
            ->withCount('messages')
            ->latest()
            ->paginate(20);

        return response()->json($conversations);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'   => 'nullable|string|max:255',
            'channel' => 'nullable|string|in:web,mobile,whatsapp,telegram',
            'context' => 'nullable|array',
        ]);

        $conversation = $request->user()->conversations()->create([
            'title'   => $data['title'] ?? null,
            'channel' => $data['channel'] ?? 'web',
            'context' => $data['context'] ?? null,
        ]);

        return response()->json($conversation, 201);
    }

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        return response()->json($conversation->load('messages'));
    }

    public function destroy(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('delete', $conversation);

        $conversation->delete();

        return response()->json(['message' => 'Conversación eliminada.']);
    }
}
