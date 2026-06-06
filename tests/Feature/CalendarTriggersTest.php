<?php

namespace Tests\Feature;

use App\Models\ProactiveLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CalendarTriggersTest extends TestCase
{
    use RefreshDatabase;

    private const TZ      = 'America/Bogota';
    private const EXPO_URL = 'exp.host/--/api/v2/push/send';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.google.client_id', 'fake-client-id.apps.googleusercontent.com');
        config()->set('services.google.client_secret', 'fake-client-secret');
        config()->set('services.google.redirect', 'https://ai.omnirepair.online/api/integrations/google/callback');

        // Fijamos "ahora" para construir eventos dentro/fuera de la ventana.
        Carbon::setTestNow(Carbon::create(2026, 6, 5, 10, 0, 0, self::TZ));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Usuario con google conectado, device token expo y settings con timezone. */
    private function connectedUser(bool $alertsEnabled = true): User
    {
        $user = User::factory()->create();

        $user->integrations()->create([
            'provider'         => 'google',
            'access_token'     => 'ya29.valid-token',
            'refresh_token'    => '1//refresh',
            'token_expires_at' => now()->addHour(),
            'account_email'    => 'user@gmail.com',
        ]);

        $user->deviceTokens()->create([
            'token'    => 'ExponentPushToken[abc123]',
            'platform' => 'expo',
        ]);

        $user->setting()->create([
            'timezone'                => self::TZ,
            'calendar_alerts_enabled' => $alertsEnabled,
        ]);

        return $user;
    }

    /** Construye la respuesta de Calendar con un evento que arranca en $minutesFromNow. */
    private function fakeCalendar(int $minutesFromNow, string $id = 'evt-1', string $summary = 'Reunión', bool $allDay = false): void
    {
        $start = now()->copy()->addMinutes($minutesFromNow);
        $end   = $start->copy()->addHour();

        $item = $allDay
            ? [
                'id'      => $id,
                'summary' => $summary,
                'start'   => ['date' => $start->toDateString()],
                'end'     => ['date' => $end->toDateString()],
            ]
            : [
                'id'      => $id,
                'summary' => $summary,
                'start'   => ['dateTime' => $start->toRfc3339String()],
                'end'     => ['dateTime' => $end->toRfc3339String()],
            ];

        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response(['items' => [$item]], 200),
            self::EXPO_URL => Http::response(['data' => []], 200),
        ]);
    }

    public function test_event_within_window_sends_push_and_logs(): void
    {
        $user = $this->connectedUser();
        $this->fakeCalendar(minutesFromNow: 10, id: 'evt-soon', summary: 'Demo cliente');

        $this->artisan('aria:calendar-triggers')->assertSuccessful();

        Http::assertSent(fn ($req) => str_contains($req->url(), self::EXPO_URL)
            && str_contains(json_encode($req->data()), 'Demo cliente'));

        $this->assertDatabaseHas('proactive_logs', [
            'user_id' => $user->id,
            'type'    => ProactiveLog::TYPE_CALENDAR,
        ]);

        $log = $user->proactiveLogs()->where('type', ProactiveLog::TYPE_CALENDAR)->first();
        $this->assertStringContainsString('[event:evt-soon]', $log->message);
    }

    public function test_second_run_does_not_duplicate(): void
    {
        $user = $this->connectedUser();
        $this->fakeCalendar(minutesFromNow: 10, id: 'evt-soon');

        $this->artisan('aria:calendar-triggers')->assertSuccessful();
        $this->artisan('aria:calendar-triggers')->assertSuccessful();

        $this->assertEquals(
            1,
            $user->proactiveLogs()->where('type', ProactiveLog::TYPE_CALENDAR)->count()
        );
    }

    public function test_event_far_in_future_is_ignored(): void
    {
        $user = $this->connectedUser();
        $this->fakeCalendar(minutesFromNow: 120, id: 'evt-far');

        $this->artisan('aria:calendar-triggers')->assertSuccessful();

        Http::assertNotSent(fn ($req) => str_contains($req->url(), self::EXPO_URL));
        $this->assertEquals(0, $user->proactiveLogs()->count());
    }

    public function test_all_day_event_is_ignored(): void
    {
        $user = $this->connectedUser();
        $this->fakeCalendar(minutesFromNow: 10, id: 'evt-allday', allDay: true);

        $this->artisan('aria:calendar-triggers')->assertSuccessful();

        Http::assertNotSent(fn ($req) => str_contains($req->url(), self::EXPO_URL));
        $this->assertEquals(0, $user->proactiveLogs()->count());
    }

    public function test_disabled_alerts_setting_skips_user(): void
    {
        $user = $this->connectedUser(alertsEnabled: false);
        $this->fakeCalendar(minutesFromNow: 10, id: 'evt-soon');

        $this->artisan('aria:calendar-triggers')->assertSuccessful();

        Http::assertNotSent(fn ($req) => str_contains($req->url(), self::EXPO_URL));
        $this->assertEquals(0, $user->proactiveLogs()->count());
    }

    public function test_dedup_does_not_collide_on_like_wildcard_in_event_id(): void
    {
        // Los ids de eventos recurrentes de Google llevan '_', comodín de LIKE.
        // Pre-cargamos un log para un evento con id 'evtX1' y luego corremos el
        // comando con un evento DISTINTO con id 'evt_1'. Sin escapar, el tag
        // '[event:evt_1]%' haría match con '[event:evtX1]...' y suprimiría un
        // aviso legítimo. Con el escaping NO debe colisionar: debe enviarse push.
        $user = $this->connectedUser();

        $user->proactiveLogs()->create([
            'type'    => ProactiveLog::TYPE_CALENDAR,
            'message' => '[event:evtX1] Otro evento a las 09:00',
        ]);

        $this->fakeCalendar(minutesFromNow: 10, id: 'evt_1', summary: 'Recurrente');

        $this->artisan('aria:calendar-triggers')->assertSuccessful();

        // Se envió el push pese al log previo de 'evtX1' (no hubo falsa colisión).
        Http::assertSent(fn ($req) => str_contains($req->url(), self::EXPO_URL)
            && str_contains(json_encode($req->data()), 'Recurrente'));

        // Se registró un log nuevo para 'evt_1' además del de 'evtX1' precargado.
        $this->assertEquals(
            2,
            $user->proactiveLogs()->where('type', ProactiveLog::TYPE_CALENDAR)->count()
        );
        $messages = $user->proactiveLogs()
            ->where('type', ProactiveLog::TYPE_CALENDAR)
            ->pluck('message')
            ->all();
        $this->assertContains('[event:evt_1] Recurrente a las 10:10', $messages);
    }

    public function test_dedup_still_suppresses_repeat_for_same_normal_id(): void
    {
        // El escaping no debe romper el dedup normal para ids sin metacaracteres.
        $user = $this->connectedUser();
        $this->fakeCalendar(minutesFromNow: 10, id: 'evt-soon');

        $this->artisan('aria:calendar-triggers')->assertSuccessful();
        $this->artisan('aria:calendar-triggers')->assertSuccessful();

        $this->assertEquals(
            1,
            $user->proactiveLogs()->where('type', ProactiveLog::TYPE_CALENDAR)->count()
        );
    }

    public function test_user_without_google_is_skipped(): void
    {
        $user = User::factory()->create();
        $user->deviceTokens()->create(['token' => 'ExponentPushToken[x]', 'platform' => 'expo']);
        $user->setting()->create(['timezone' => self::TZ, 'calendar_alerts_enabled' => true]);

        Http::fake([
            self::EXPO_URL => Http::response(['data' => []], 200),
            'www.googleapis.com/*' => Http::response(['items' => []], 200),
        ]);

        $this->artisan('aria:calendar-triggers')->assertSuccessful();

        Http::assertNotSent(fn ($req) => str_contains($req->url(), self::EXPO_URL));
        $this->assertEquals(0, $user->proactiveLogs()->count());
    }
}
