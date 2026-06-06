<?php

namespace App\Services\Integrations;

use App\Exceptions\GoogleNotConnectedException;
use App\Models\User;
use App\Models\UserIntegration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * P-02 Gmail (solo lectura): resumen de correos no leídos del usuario vía la
 * Gmail API v1.
 *
 * Usa el cliente HTTP nativo de Laravel (no google/apiclient) y delega la
 * autenticación en GoogleOAuthService::validToken() (refresca el token si hace
 * falta). El scope gmail.readonly se solicita en el consentimiento OAuth.
 */
class GmailService
{
    private const PROVIDER     = 'google';
    private const MESSAGES_URL = 'https://gmail.googleapis.com/gmail/v1/users/me/messages';

    public function __construct(
        private readonly GoogleOAuthService $oauth,
    ) {}

    /**
     * Whether the user has a connected Google integration.
     */
    public function isConnected(User $user): bool
    {
        return $this->integration($user) !== null;
    }

    /**
     * Resumen de los correos no leídos del usuario (más recientes primero).
     *
     * @return array<int, array{id: string|null, from: string, subject: string, snippet: string}>
     */
    public function unreadSummary(User $user, int $max = 5): array
    {
        if ($max < 1) {
            $max = 5;
        }

        $token = $this->tokenFor($user);

        $response = Http::withToken($token)->get(self::MESSAGES_URL, [
            'q'          => 'is:unread',
            'maxResults' => $max,
        ]);

        if (! $response->successful()) {
            $this->fail('listar correos no leídos', $response->status(), $response->body());
        }

        $messages = $response->json('messages', []);

        if (empty($messages)) {
            return [];
        }

        $summaries = [];
        foreach ($messages as $message) {
            $id = $message['id'] ?? null;
            if (! $id) {
                continue;
            }

            $summaries[] = $this->fetchMessage($token, $id);
        }

        return $summaries;
    }

    /**
     * Número de correos no leídos (útil para el briefing).
     *
     * Usa el endpoint de lista con maxResults=1 y lee resultSizeEstimate de la
     * respuesta: así NO se trae cada mensaje (evita el N+1 / quema de cuota de
     * recorrer unreadSummary solo para contar).
     */
    public function unreadCount(User $user): int
    {
        $token = $this->tokenFor($user);

        $response = Http::withToken($token)->get(self::MESSAGES_URL, [
            'q'          => 'is:unread',
            'maxResults' => 1,
        ]);

        if (! $response->successful()) {
            $this->fail('contar correos no leídos', $response->status(), $response->body());
        }

        return (int) $response->json('resultSizeEstimate', 0);
    }

    /**
     * Obtiene los metadatos (From/Subject) y el snippet de un correo.
     *
     * @return array{id: string|null, from: string, subject: string, snippet: string}
     */
    private function fetchMessage(string $token, string $id): array
    {
        // Higiene: aunque el id viene de la propia respuesta de Gmail, lo
        // codificamos para la URL para evitar path injection teórica.
        $response = Http::withToken($token)->get(self::MESSAGES_URL . '/' . rawurlencode($id), [
            'format'         => 'metadata',
            'metadataHeaders' => ['From', 'Subject'],
        ]);

        if (! $response->successful()) {
            $this->fail('leer correo', $response->status(), $response->body());
        }

        return $this->normalizeMessage($response->json());
    }

    /**
     * Normaliza un mensaje de Gmail a nuestro formato.
     *
     * @param  array<string, mixed>  $message
     * @return array{id: string|null, from: string, subject: string, snippet: string}
     */
    private function normalizeMessage(array $message): array
    {
        $headers = $message['payload']['headers'] ?? [];

        return [
            'id'      => $message['id'] ?? null,
            'from'    => $this->header($headers, 'From') ?? '(remitente desconocido)',
            'subject' => $this->header($headers, 'Subject') ?? '(sin asunto)',
            'snippet' => trim((string) ($message['snippet'] ?? '')),
        ];
    }

    /**
     * Busca el valor de un header por nombre (case-insensitive).
     *
     * @param  array<int, array{name?: string, value?: string}>  $headers
     */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $header) {
            if (isset($header['name']) && strcasecmp($header['name'], $name) === 0) {
                return $header['value'] ?? null;
            }
        }

        return null;
    }

    /**
     * Devuelve la integración google del usuario o null si no está conectada.
     */
    private function integration(User $user): ?UserIntegration
    {
        return $user->integrations()->where('provider', self::PROVIDER)->first();
    }

    /**
     * Devuelve un access_token válido o lanza si el usuario no está conectado.
     */
    private function tokenFor(User $user): string
    {
        $integration = $this->integration($user);

        if (! $integration) {
            throw new GoogleNotConnectedException();
        }

        $token = $this->oauth->validToken($integration);

        if (! $token) {
            throw new GoogleNotConnectedException('No se pudo obtener un token válido de Google; reconecta la cuenta.');
        }

        return $token;
    }

    private function fail(string $stage, int $status, string $body): void
    {
        Log::error("GmailService: fallo al {$stage} (HTTP {$status}): {$body}");

        $message = "Error de Gmail al {$stage} (HTTP {$status}).";

        // Intenta extraer el mensaje de error de Google si viene en JSON.
        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['error']['message'])) {
            $message = "Error de Gmail al {$stage}: {$decoded['error']['message']}";
        }

        throw new RuntimeException($message);
    }
}
