<?php

namespace App\Console\Commands;

use App\Models\ProactiveLog;
use App\Models\User;
use App\Services\Integrations\GoogleCalendarService;
use App\Services\PushNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * P-04 Fase 4: disparadores contextuales por evento próximo de calendario.
 *
 * Notificación proactiva push poco antes de que arranque un evento del
 * calendario del usuario (complementa el proactivo fijo de las 9:00 y el
 * briefing). Se ejecuta cada 5 minutos desde el scheduler.
 *
 * NOTA: el disparador por geofence (proximidad GPS a un lugar) NO se implementa
 * en backend porque requiere la ubicación del cliente móvil; queda como trabajo
 * futuro del cliente. Este comando cubre el disparador por proximidad temporal
 * de calendario.
 */
class SendCalendarTriggers extends Command
{
    protected $signature   = 'aria:calendar-triggers';
    protected $description = 'Send push notifications for calendar events starting soon';

    /**
     * Ventana de aviso: solo se notifican eventos que arrancan en los próximos
     * N minutos (y que aún no hayan empezado). Se ejecuta cada 5 min, así que
     * 15 min cubre cada evento ~3 veces dentro de la ventana, pero el dedup por
     * event id evita repetir el aviso.
     */
    private const LEAD_MINUTES = 15;

    public function handle(GoogleCalendarService $calendar, PushNotificationService $push): int
    {
        $usersProcessed    = 0;
        $notificationsSent = 0;

        User::query()
            ->whereHas('deviceTokens', fn ($q) => $q->where('platform', 'expo'))
            ->with('setting')
            ->chunk(50, function ($users) use (
                $calendar,
                $push,
                &$usersProcessed,
                &$notificationsSent
            ) {
                foreach ($users as $user) {
                    // Setting de control: por defecto activado.
                    if ($user->setting && $user->setting->calendar_alerts_enabled === false) {
                        continue;
                    }

                    if (! $calendar->isConnected($user)) {
                        continue;
                    }

                    $usersProcessed++;

                    try {
                        $notificationsSent += $this->processUser($user, $calendar, $push);
                    } catch (\Throwable $e) {
                        $this->warn("  ✗ {$user->name}: {$e->getMessage()}");
                        Log::warning('aria:calendar-triggers failed for user', [
                            'user_id' => $user->id,
                            'error'   => $e->getMessage(),
                        ]);
                    }
                }
            });

        $this->info("Done. Processed: {$usersProcessed} users, sent: {$notificationsSent} notifications.");

        Log::info('aria:calendar-triggers completed', [
            'users_processed'    => $usersProcessed,
            'notifications_sent' => $notificationsSent,
        ]);

        return self::SUCCESS;
    }

    /**
     * Procesa un usuario conectado y devuelve cuántas notificaciones envió.
     */
    private function processUser(
        User $user,
        GoogleCalendarService $calendar,
        PushNotificationService $push
    ): int {
        $timezone = $user->setting?->timezone ?? 'America/Bogota';
        $now      = Carbon::now($timezone);
        $windowEnd = $now->copy()->addMinutes(self::LEAD_MINUTES);

        // Solo eventos del próximo día; con maxResults bajo basta para la ventana.
        $events = $calendar->upcomingEvents($user, 10, 1);

        $sent = 0;

        foreach ($events as $event) {
            // Ignora eventos de día completo y eventos sin id (no se pueden dedup).
            if ($event['allDay'] || empty($event['id']) || empty($event['start'])) {
                continue;
            }

            $start = Carbon::parse($event['start'])->setTimezone($timezone);

            // Fuera de la ventana: ya pasó o aún muy lejano.
            if ($start->lt($now) || $start->gt($windowEnd)) {
                continue;
            }

            if ($this->alreadyNotified($user, $event['id'])) {
                continue;
            }

            $localTime = $start->format('H:i');
            $summary   = $event['summary'] ?: '(sin título)';

            $push->notifyUser(
                $user,
                'Evento próximo',
                "{$summary} a las {$localTime}",
                ['type' => ProactiveLog::TYPE_CALENDAR, 'event_id' => $event['id']],
            );

            // Dedup: guardamos el event id dentro del message para poder consultar
            // "¿ya notifiqué este evento?" en las próximas 24h.
            $user->proactiveLogs()->create([
                'type'    => ProactiveLog::TYPE_CALENDAR,
                'message' => $this->logMessage($event['id'], $summary, $localTime),
            ]);

            $this->info("  → {$user->name}: \"{$summary}\" @ {$localTime}");
            $sent++;
        }

        return $sent;
    }

    /**
     * ¿Ya se notificó este evento en las últimas 24h? (dedup por event id).
     */
    private function alreadyNotified(User $user, string $eventId): bool
    {
        // Escapamos los metacaracteres de LIKE (% _ \) del tag: los ids de
        // eventos recurrentes de Google llevan '_', que en SQL LIKE es comodín
        // de "un carácter" y podría hacer colisionar dos eventos distintos,
        // suprimiendo un aviso legítimo. Con addcslashes basta para MySQL/SQLite
        // (usan '\' como carácter de escape por defecto en LIKE).
        $tag = addcslashes($this->eventTag($eventId), '%_\\');

        return $user->proactiveLogs()
            ->where('type', ProactiveLog::TYPE_CALENDAR)
            ->where('created_at', '>=', now()->subDay())
            ->where('message', 'like', $tag . '%')
            ->exists();
    }

    /**
     * Mensaje del log; empieza con un tag inequívoco que contiene el event id
     * para que el dedup pueda buscarlo con un LIKE prefijo.
     */
    private function logMessage(string $eventId, string $summary, string $localTime): string
    {
        return $this->eventTag($eventId) . " {$summary} a las {$localTime}";
    }

    private function eventTag(string $eventId): string
    {
        return "[event:{$eventId}]";
    }
}
