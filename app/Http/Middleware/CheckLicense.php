<?php

namespace App\Http\Middleware;

use App\Models\LicenseSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckLicense
{
    public function handle(Request $request, Closure $next): Response
    {
        $settings = LicenseSetting::current();

        if (! $settings->licenses_required) {
            return $next($request);
        }

        $user = $request->user();

        // Admins are never blocked
        if ($user?->is_admin) {
            return $next($request);
        }

        if (! $user?->hasActiveLicense()) {
            return response()->json([
                'error'   => 'license_required',
                'message' => 'Se requiere una licencia activa para usar la aplicación.',
            ], 403);
        }

        return $next($request);
    }
}
