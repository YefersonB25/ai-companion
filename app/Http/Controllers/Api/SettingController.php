<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json($request->user()->setting);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'default_provider' => 'nullable|string|in:claude,openai,deepseek,gemini,mistral',
            'default_model'    => 'nullable|string',
            'language'         => 'nullable|string|max:10',
            'timezone'         => 'nullable|timezone',
            'memory_enabled'   => 'nullable|boolean',
            'auto_title'       => 'nullable|boolean',
            'stream_responses' => 'nullable|boolean',
            'routing_rules'    => 'nullable|array',
            'persona'          => 'nullable|array',
            'persona.name'     => 'nullable|string',
            'persona.prompt'   => 'nullable|string',
        ]);

        $request->user()->setting()->updateOrCreate(
            ['user_id' => $request->user()->id],
            $data
        );

        return response()->json($request->user()->fresh('setting')->setting);
    }
}
