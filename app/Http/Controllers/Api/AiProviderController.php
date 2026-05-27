<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiProvider;
use App\Services\AI\AIRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiProviderController extends Controller
{
    public function __construct(private AIRouter $router) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $request->user()->aiProviders()->orderByDesc('is_default')->orderBy('priority')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider'  => 'required|string|in:claude,openai,deepseek,gemini,mistral',
            'model'     => 'required|string',
            'api_key'   => 'required|string',
            'base_url'  => 'nullable|url',
            'is_active' => 'nullable|boolean',
            'priority'  => 'nullable|integer|min:0',
            'config'    => 'nullable|array',
        ]);

        $data['api_key'] = encrypt($data['api_key']);
        $data['is_active'] = $data['is_active'] ?? true;

        $user = $request->user();

        // First provider becomes default automatically
        if (!$user->aiProviders()->exists()) {
            $data['is_default'] = true;
        }

        $provider = $user->aiProviders()->create($data);

        return response()->json($provider, 201);
    }

    public function update(Request $request, int $provider): JsonResponse
    {
        $aiProvider = $request->user()->aiProviders()->findOrFail($provider);

        $data = $request->validate([
            'model'      => 'nullable|string',
            'api_key'    => 'nullable|string',
            'base_url'   => 'nullable|url',
            'is_active'  => 'nullable|boolean',
            'is_default' => 'nullable|boolean',
            'priority'   => 'nullable|integer|min:0',
            'config'     => 'nullable|array',
        ]);

        if (isset($data['api_key'])) {
            $data['api_key'] = encrypt($data['api_key']);
        }

        if ($data['is_default'] ?? false) {
            $request->user()->aiProviders()->update(['is_default' => false]);
        }

        $aiProvider->update($data);

        return response()->json($aiProvider);
    }

    public function destroy(Request $request, int $provider): JsonResponse
    {
        $aiProvider = $request->user()->aiProviders()->findOrFail($provider);

        $aiProvider->delete();

        return response()->json(['message' => 'Proveedor eliminado.']);
    }

    public function supportedProviders(): JsonResponse
    {
        return response()->json([
            'providers' => [
                ['name' => 'claude',   'label' => 'Anthropic Claude', 'models' => ['claude-opus-4-7', 'claude-sonnet-4-6', 'claude-haiku-4-5-20251001']],
                ['name' => 'openai',   'label' => 'OpenAI GPT',       'models' => ['gpt-4o', 'gpt-4o-mini', 'o1', 'o1-mini']],
                ['name' => 'deepseek', 'label' => 'DeepSeek',         'models' => ['deepseek-chat', 'deepseek-reasoner']],
                ['name' => 'gemini',   'label' => 'Google Gemini',    'models' => ['gemini-2.5-pro', 'gemini-2.5-flash']],
                ['name' => 'mistral',  'label' => 'Mistral AI',       'models' => ['mistral-large-latest', 'codestral-latest']],
            ],
        ]);
    }
}
