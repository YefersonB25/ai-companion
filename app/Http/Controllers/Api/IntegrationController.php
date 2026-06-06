<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserIntegration;
use App\Services\Integrations\GoogleOAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class IntegrationController extends Controller
{
    public function __construct(private GoogleOAuthService $google) {}

    /**
     * Lista las integraciones del usuario sin exponer tokens.
     */
    public function index(Request $request): JsonResponse
    {
        $integrations = $request->user()->integrations()
            ->get()
            ->map(fn (UserIntegration $i) => [
                'provider'      => $i->provider,
                'account_email' => $i->account_email,
                'scopes'        => $i->scopes,
                'connected'     => true,
                'expired'       => $i->isExpired(),
                'connected_at'  => $i->created_at,
            ]);

        return response()->json(['integrations' => $integrations]);
    }

    /**
     * Devuelve la URL de consentimiento de Google.
     */
    public function googleConnect(Request $request): JsonResponse
    {
        return response()->json([
            'url' => $this->google->getAuthUrl($request->user()),
        ]);
    }

    /**
     * Callback OAuth (redirect_uri). Recibe ?code&state desde Google.
     * Google redirige el navegador aquí, así que respondemos con un redirect
     * de vuelta al panel web (no JSON crudo).
     */
    public function googleCallback(Request $request): RedirectResponse
    {
        $settingsUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/') . '/settings';

        if ($error = $request->query('error')) {
            // No reflejamos el string crudo de Google (info disclosure / XSS reflejado);
            // solo un código genérico. El detalle real queda en logs server-side.
            Log::warning('GoogleOAuthService: callback con error de Google.', [
                'error' => (string) $error,
            ]);

            $code = $error === 'access_denied' ? 'access_denied' : 'oauth_failed';

            return redirect()->away($settingsUrl . '?google=error&code=' . $code);
        }

        $data = $request->validate([
            'code'  => 'required|string',
            'state' => 'required|string',
        ]);

        try {
            $this->google->handleCallback($data['code'], $data['state']);
        } catch (\Throwable $e) {
            // No reflejamos el mensaje de la excepción en la URL; solo un código genérico.
            Log::warning('GoogleOAuthService: fallo en callback OAuth.', [
                'exception' => $e->getMessage(),
            ]);

            return redirect()->away($settingsUrl . '?google=error&code=oauth_failed');
        }

        return redirect()->away($settingsUrl . '?google=connected');
    }

    /**
     * Desconecta (elimina) la integración de Google del usuario.
     */
    public function googleDisconnect(Request $request): JsonResponse
    {
        $request->user()->integrations()->where('provider', 'google')->delete();

        return response()->json(['message' => 'Cuenta de Google desconectada.']);
    }
}
