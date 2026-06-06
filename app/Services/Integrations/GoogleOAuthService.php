<?php

namespace App\Services\Integrations;

use App\Models\User;
use App\Models\UserIntegration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Scaffolding OAuth para Google (base de P-01 Google Calendar y P-02 Gmail).
 *
 * Usa el cliente HTTP nativo de Laravel (no google/apiclient). El usuario solo
 * debe pegar GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET / GOOGLE_REDIRECT_URI en el
 * .env para activarlo.
 */
class GoogleOAuthService
{
    private const PROVIDER     = 'google';
    private const AUTH_URL     = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL    = 'https://oauth2.googleapis.com/token';
    private const USERINFO_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';

    private const STATE_CACHE_PREFIX = 'oauth:google:state:';
    private const STATE_TTL_MINUTES  = 10;

    /**
     * Construye la URL de consentimiento de Google ligada al usuario.
     */
    public function getAuthUrl(User $user): string
    {
        $params = [
            'client_id'     => (string) config('services.google.client_id'),
            'redirect_uri'  => (string) config('services.google.redirect'),
            'response_type' => 'code',
            'scope'         => implode(' ', config('services.google.scopes', [])),
            'access_type'   => 'offline',     // necesario para recibir refresh_token
            'prompt'        => 'consent',     // fuerza a Google a re-emitir refresh_token
            'include_granted_scopes' => 'true',
            'state'         => $this->makeState($user),
        ];

        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Intercambia el authorization code por tokens, resuelve el email de la
     * cuenta y persiste (updateOrCreate) el UserIntegration.
     */
    public function handleCallback(string $code, string $state): UserIntegration
    {
        $user = $this->resolveUserFromState($state);

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'code'          => $code,
            'client_id'     => (string) config('services.google.client_id'),
            'client_secret' => (string) config('services.google.client_secret'),
            'redirect_uri'  => (string) config('services.google.redirect'),
            'grant_type'    => 'authorization_code',
        ]);

        if (! $response->ok()) {
            $this->fail('intercambio de code', $response->status(), $response->body());
        }

        $tokens = $response->json();

        $accessToken = $tokens['access_token'] ?? null;
        if (! $accessToken) {
            throw new RuntimeException('Google no devolvió access_token.');
        }

        $email  = $this->fetchAccountEmail($accessToken);
        $scopes = isset($tokens['scope']) ? explode(' ', $tokens['scope']) : config('services.google.scopes', []);

        $attributes = [
            'access_token'     => $accessToken,
            'token_expires_at' => isset($tokens['expires_in'])
                ? now()->addSeconds((int) $tokens['expires_in'])
                : null,
            'scopes'           => $scopes,
            'account_email'    => $email,
        ];

        // Google solo envía refresh_token la primera vez (o con prompt=consent).
        // No lo sobreescribimos con null si Google no lo reenvía.
        if (! empty($tokens['refresh_token'])) {
            $attributes['refresh_token'] = $tokens['refresh_token'];
        }

        return UserIntegration::updateOrCreate(
            ['user_id' => $user->id, 'provider' => self::PROVIDER],
            $attributes,
        );
    }

    /**
     * Renueva el access_token si está expirado y hay refresh_token disponible.
     */
    public function refreshIfNeeded(UserIntegration $integration): UserIntegration
    {
        if (! $integration->isExpired()) {
            return $integration;
        }

        if (! $integration->refresh_token) {
            throw new RuntimeException('Token expirado y sin refresh_token; reconecta la cuenta de Google.');
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id'     => (string) config('services.google.client_id'),
            'client_secret' => (string) config('services.google.client_secret'),
            'refresh_token' => $integration->refresh_token,
            'grant_type'    => 'refresh_token',
        ]);

        if (! $response->ok()) {
            $this->fail('refresh de token', $response->status(), $response->body());
        }

        $tokens = $response->json();

        $integration->access_token = $tokens['access_token'] ?? $integration->access_token;
        // Si Google no envía expires_in en el refresh, usamos un default conservador
        // (NUNCA null: con null isExpired() devuelve false y el token jamás se refrescaría).
        $integration->token_expires_at = isset($tokens['expires_in'])
            ? now()->addSeconds((int) $tokens['expires_in'])
            : now()->addMinutes(55);

        // Google puede (raramente) rotar el refresh_token.
        if (! empty($tokens['refresh_token'])) {
            $integration->refresh_token = $tokens['refresh_token'];
        }

        $integration->save();

        return $integration;
    }

    /**
     * Devuelve un access_token válido, refrescando si hace falta.
     */
    public function validToken(UserIntegration $integration): ?string
    {
        $integration = $this->refreshIfNeeded($integration);

        return $integration->access_token;
    }

    /**
     * STUB (Fase 4): listar próximos eventos del Google Calendar del usuario.
     * No está enganchado todavía; se implementará cuando haya credenciales y
     * el tool correspondiente en ToolRegistry.
     */
    public function fetchUpcomingCalendarEvents(UserIntegration $integration, int $maxResults = 10): array
    {
        throw new RuntimeException('No implementado: tool de calendario pendiente de Fase 4.');
    }

    /**
     * Resuelve el email de la cuenta conectada vía el endpoint userinfo.
     */
    private function fetchAccountEmail(string $accessToken): ?string
    {
        $response = Http::withToken($accessToken)->get(self::USERINFO_URL);

        if (! $response->ok()) {
            Log::warning('GoogleOAuthService: no se pudo obtener userinfo: ' . $response->status());
            return null;
        }

        return $response->json('email');
    }

    /**
     * Genera un state opaco (nonce) ligado al usuario en cache (CSRF + single-use).
     *
     * El nonce se guarda en cache con el user_id como valor y un TTL corto. La
     * fuente de verdad del user_id es la cache, NO el contenido del state: así un
     * atacante no puede fabricar/compartir un state para atar su cuenta Google a
     * otro usuario, y el nonce expira y se consume una sola vez (anti-replay).
     */
    private function makeState(User $user): string
    {
        $nonce = Str::random(40);

        Cache::put(
            self::STATE_CACHE_PREFIX . $nonce,
            $user->id,
            now()->addMinutes(self::STATE_TTL_MINUTES),
        );

        return encrypt($nonce);
    }

    /**
     * Valida el state, lo consume (single-use) y devuelve el usuario al que pertenece.
     */
    private function resolveUserFromState(string $state): User
    {
        try {
            $nonce = decrypt($state);
        } catch (\Throwable $e) {
            throw new RuntimeException('State OAuth inválido.');
        }

        // pull() consume el nonce: expira tras un uso (anti-replay).
        $userId = Cache::pull(self::STATE_CACHE_PREFIX . $nonce);

        $user = $userId ? User::find($userId) : null;

        if (! $user) {
            throw new RuntimeException('State OAuth inválido, expirado o ya usado.');
        }

        return $user;
    }

    private function fail(string $stage, int $status, string $body): void
    {
        Log::error("GoogleOAuthService: fallo en {$stage} (HTTP {$status}): {$body}");

        throw new RuntimeException("Error de Google durante {$stage} (HTTP {$status}).");
    }
}
