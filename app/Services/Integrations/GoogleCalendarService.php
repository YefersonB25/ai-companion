<?php

namespace App\Services\Integrations;

use App\Exceptions\GoogleNotConnectedException;
use App\Models\User;
use App\Models\UserIntegration;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * P-01 Google Calendar: lectura/creación de eventos del calendario primario del
 * usuario vía la Calendar API v3.
 *
 * Usa el cliente HTTP nativo de Laravel (no google/apiclient) y delega la
 * autenticación en GoogleOAuthService::validToken() (refresca el token si hace
 * falta).
 */
class GoogleCalendarService
{
    private const PROVIDER  = 'google';
    private const BASE_URL  = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';

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
     * Lista los próximos eventos del calendario primario del usuario.
     *
     * @return array<int, array{id: string|null, summary: string, start: string|null, end: string|null, location: string|null, allDay: bool}>
     */
    public function upcomingEvents(User $user, int $maxResults = 10, int $daysAhead = 7): array
    {
        $token    = $this->tokenFor($user);
        $timezone = $this->timezoneFor($user);
        $now      = Carbon::now($timezone);

        $response = Http::withToken($token)->get(self::BASE_URL, [
            'timeMin'      => $now->toRfc3339String(),
            'timeMax'      => $now->copy()->addDays($daysAhead)->toRfc3339String(),
            'singleEvents' => 'true',
            'orderBy'      => 'startTime',
            'maxResults'   => $maxResults,
            'timeZone'     => $timezone,
        ]);

        if (! $response->successful()) {
            $this->fail('listar eventos', $response->status(), $response->body());
        }

        return array_map(
            fn (array $event) => $this->normalizeEvent($event),
            $response->json('items', []),
        );
    }

    /**
     * Crea un evento en el calendario primario del usuario.
     *
     * @return array{id: string|null, summary: string, start: string|null, end: string|null, location: string|null, allDay: bool, htmlLink?: string|null}
     */
    public function createEvent(
        User $user,
        string $summary,
        string $startIso,
        string $endIso,
        ?string $description = null,
        ?string $location = null,
    ): array {
        $token    = $this->tokenFor($user);
        $timezone = $this->timezoneFor($user);

        $body = [
            'summary' => $summary,
            'start'   => ['dateTime' => $startIso, 'timeZone' => $timezone],
            'end'     => ['dateTime' => $endIso,   'timeZone' => $timezone],
        ];

        if ($description !== null && $description !== '') {
            $body['description'] = $description;
        }

        if ($location !== null && $location !== '') {
            $body['location'] = $location;
        }

        $response = Http::withToken($token)->post(self::BASE_URL, $body);

        if (! $response->successful()) {
            $this->fail('crear evento', $response->status(), $response->body());
        }

        $event = $this->normalizeEvent($response->json());
        $event['htmlLink'] = $response->json('htmlLink');

        return $event;
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

    private function timezoneFor(User $user): string
    {
        return $user->setting?->timezone ?? 'America/Bogota';
    }

    /**
     * Normaliza un evento de Calendar a nuestro formato.
     *
     * @param  array<string, mixed>  $event
     * @return array{id: string|null, summary: string, start: string|null, end: string|null, location: string|null, allDay: bool}
     */
    private function normalizeEvent(array $event): array
    {
        // Los eventos de día completo usan `date`; los de hora usan `dateTime`.
        $start  = $event['start'] ?? [];
        $end    = $event['end'] ?? [];
        $allDay = isset($start['date']) && ! isset($start['dateTime']);

        return [
            'id'       => $event['id'] ?? null,
            'summary'  => $event['summary'] ?? '(sin título)',
            'start'    => $start['dateTime'] ?? $start['date'] ?? null,
            'end'      => $end['dateTime'] ?? $end['date'] ?? null,
            'location' => $event['location'] ?? null,
            'allDay'   => $allDay,
        ];
    }

    private function fail(string $stage, int $status, string $body): void
    {
        Log::error("GoogleCalendarService: fallo al {$stage} (HTTP {$status}): {$body}");

        $message = "Error de Google Calendar al {$stage} (HTTP {$status}).";

        // Intenta extraer el mensaje de error de Google si viene en JSON.
        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['error']['message'])) {
            $message = "Error de Google Calendar al {$stage}: {$decoded['error']['message']}";
        }

        throw new RuntimeException($message);
    }
}
