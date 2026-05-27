<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppVersionController extends Controller
{
    /**
     * GET /api/app/version?platform=android&version_code=1
     *
     * Returns the latest version for the platform.
     * If the client's version_code is lower, update_available = true.
     */
    public function check(Request $request): JsonResponse
    {
        $platform    = $request->query('platform', 'android');
        $clientCode  = (int) $request->query('version_code', 0);

        $latest = AppVersion::where('platform', $platform)
            ->orderByDesc('version_code')
            ->first();

        if (! $latest) {
            return response()->json(['update_available' => false]);
        }

        $updateAvailable = $latest->version_code > $clientCode;

        return response()->json([
            'update_available' => $updateAvailable,
            'version'          => $latest->version,
            'version_code'     => $latest->version_code,
            'changelog'        => $latest->changelog,
            'download_url'     => $latest->download_url,
            'is_required'      => $latest->is_required,
        ]);
    }

    /**
     * POST /api/app/version  (admin / internal use)
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform'     => 'required|in:android,ios',
            'version'      => 'required|string',
            'version_code' => 'required|integer|min:1',
            'changelog'    => 'required|array|min:1',
            'changelog.*'  => 'string',
            'download_url' => 'nullable|url',
            'is_required'  => 'nullable|boolean',
        ]);

        $version = AppVersion::updateOrCreate(
            ['platform' => $data['platform'], 'version' => $data['version']],
            $data
        );

        return response()->json($version, 201);
    }
}
