<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token'    => 'required|string',
            'platform' => 'nullable|string|in:expo,apns,fcm',
        ]);

        $request->user()->deviceTokens()->updateOrCreate(
            ['token' => $data['token']],
            [
                'platform'       => $data['platform'] ?? 'expo',
                'last_active_at' => now(),
            ]
        );

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => 'required|string']);

        $request->user()->deviceTokens()->where('token', $data['token'])->delete();

        return response()->json(['ok' => true]);
    }
}
