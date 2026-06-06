<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserIntegration;
use App\Services\BriefingService;
use App\Services\Integrations\GoogleCalendarService;
use App\Services\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.google.client_id', 'fake-client-id.apps.googleusercontent.com');
        config()->set('services.google.client_secret', 'fake-client-secret');
        config()->set('services.google.redirect', 'https://ai.omnirepair.online/api/integrations/google/callback');
        config()->set('services.google.scopes', [
            'https://www.googleapis.com/auth/calendar.events',
            'openid', 'email', 'profile',
        ]);
    }

    /** Crea un usuario con integración google conectada (token válido futuro). */
    private function connectedUser(): User
    {
        $user = User::factory()->create();
        $user->integrations()->create([
            'provider'         => 'google',
            'access_token'     => 'ya29.valid-token',
            'refresh_token'    => '1//refresh',
            'token_expires_at' => now()->addHour(),
            'account_email'    => 'user@gmail.com',
        ]);

        return $user;
    }

    public function test_upcoming_events_parses_and_normalizes_response(): void
    {
        $user = $this->connectedUser();

        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
                'items' => [
                    [
                        'id'      => 'evt-timed',
                        'summary' => 'Reunión con equipo',
                        'location' => 'Oficina',
                        'start'   => ['dateTime' => '2026-06-10T15:00:00-05:00'],
                        'end'     => ['dateTime' => '2026-06-10T16:00:00-05:00'],
                    ],
                    [
                        'id'      => 'evt-allday',
                        'summary' => 'Cumpleaños',
                        'start'   => ['date' => '2026-06-11'],
                        'end'     => ['date' => '2026-06-12'],
                    ],
                ],
            ], 200),
        ]);

        $events = app(GoogleCalendarService::class)->upcomingEvents($user, 10, 7);

        $this->assertCount(2, $events);

        $this->assertSame('evt-timed', $events[0]['id']);
        $this->assertSame('Reunión con equipo', $events[0]['summary']);
        $this->assertSame('2026-06-10T15:00:00-05:00', $events[0]['start']);
        $this->assertSame('2026-06-10T16:00:00-05:00', $events[0]['end']);
        $this->assertSame('Oficina', $events[0]['location']);
        $this->assertFalse($events[0]['allDay']);

        $this->assertTrue($events[1]['allDay']);
        $this->assertSame('2026-06-11', $events[1]['start']);
        $this->assertNull($events[1]['location']);
    }

    public function test_create_event_sends_correct_body_and_returns_event(): void
    {
        $user = $this->connectedUser();

        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response([
                'id'       => 'created-1',
                'summary'  => 'Cita médica',
                'location' => 'Clínica',
                'htmlLink' => 'https://calendar.google.com/event?eid=abc',
                'start'    => ['dateTime' => '2026-06-10T09:00:00-05:00'],
                'end'      => ['dateTime' => '2026-06-10T10:00:00-05:00'],
            ], 200),
        ]);

        $event = app(GoogleCalendarService::class)->createEvent(
            $user,
            'Cita médica',
            '2026-06-10T09:00:00-05:00',
            '2026-06-10T10:00:00-05:00',
            'Llevar exámenes',
            'Clínica',
        );

        $this->assertSame('created-1', $event['id']);
        $this->assertSame('Cita médica', $event['summary']);
        $this->assertSame('https://calendar.google.com/event?eid=abc', $event['htmlLink']);
        $this->assertFalse($event['allDay']);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST') {
                return false;
            }
            $body = $request->data();

            return $body['summary'] === 'Cita médica'
                && $body['start']['dateTime'] === '2026-06-10T09:00:00-05:00'
                && $body['end']['dateTime'] === '2026-06-10T10:00:00-05:00'
                && $body['description'] === 'Llevar exámenes'
                && $body['location'] === 'Clínica'
                && isset($body['start']['timeZone']);
        });
    }

    public function test_get_calendar_events_tool_returns_guidance_when_not_connected(): void
    {
        $user = User::factory()->create(); // sin integración

        $result = app(ToolRegistry::class)->execute('get_calendar_events', [], $user);

        $this->assertStringContainsString('no ha conectado', $result);
        $this->assertStringContainsString('Ajustes', $result);
    }

    public function test_create_calendar_event_tool_returns_guidance_when_not_connected(): void
    {
        $user = User::factory()->create();

        $result = app(ToolRegistry::class)->execute('create_calendar_event', [
            'summary' => 'X',
            'start'   => '2026-06-10T09:00:00-05:00',
            'end'     => '2026-06-10T10:00:00-05:00',
        ], $user);

        $this->assertStringContainsString('no ha conectado', $result);
    }

    public function test_create_calendar_event_tool_confirms_creation_when_connected(): void
    {
        $user = $this->connectedUser();

        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response([
                'id'      => 'created-2',
                'summary' => 'Almuerzo',
                'start'   => ['dateTime' => '2026-06-10T13:00:00-05:00'],
                'end'     => ['dateTime' => '2026-06-10T14:00:00-05:00'],
            ], 200),
        ]);

        $result = app(ToolRegistry::class)->execute('create_calendar_event', [
            'summary' => 'Almuerzo',
            'start'   => '2026-06-10T13:00:00-05:00',
            'end'     => '2026-06-10T14:00:00-05:00',
        ], $user);

        $this->assertStringContainsString("Almuerzo", $result);
        $this->assertStringContainsString('agendado', $result);
    }

    public function test_get_calendar_events_tool_lists_events_when_connected(): void
    {
        $user = $this->connectedUser();

        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
                'items' => [
                    [
                        'id'      => 'e1',
                        'summary' => 'Standup',
                        'start'   => ['dateTime' => '2026-06-10T09:00:00-05:00'],
                        'end'     => ['dateTime' => '2026-06-10T09:15:00-05:00'],
                    ],
                ],
            ], 200),
        ]);

        $result = app(ToolRegistry::class)->execute('get_calendar_events', ['days_ahead' => 3], $user);

        $this->assertStringContainsString('Standup', $result);
        $this->assertStringContainsString('Próximos eventos', $result);
    }

    public function test_is_connected_helper(): void
    {
        $svc = app(GoogleCalendarService::class);

        $this->assertFalse($svc->isConnected(User::factory()->create()));
        $this->assertTrue($svc->isConnected($this->connectedUser()));
    }

    public function test_briefing_includes_calendar_block_when_connected_with_events(): void
    {
        $user = $this->connectedUser();
        $user->setting()->create([
            'timezone'         => 'America/Bogota',
            'briefing_enabled' => true,
        ]);

        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
                'items' => [
                    [
                        'id'      => 'e1',
                        'summary' => 'Demo con cliente',
                        'start'   => ['dateTime' => '2026-06-05T16:00:00-05:00'],
                        'end'     => ['dateTime' => '2026-06-05T17:00:00-05:00'],
                    ],
                ],
            ], 200),
        ]);

        // Capturamos los messages enviados al provider espiando AIRouter.
        $captured = [];
        $provider = \Mockery::mock(\App\Services\AI\Providers\BaseProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function (array $messages) use (&$captured) {
            $captured = $messages;
            return ['content' => 'Buenos días, tu briefing.'];
        });

        $router = \Mockery::mock(\App\Services\AI\AIRouter::class);
        $router->shouldReceive('forUser')->andReturn($provider);
        $this->app->instance(\App\Services\AI\AIRouter::class, $router);

        app(BriefingService::class)->generate($user->fresh());

        $systemPrompt = $captured[0]['content'] ?? '';
        $this->assertStringContainsString('Demo con cliente', $systemPrompt);
        $this->assertStringContainsString('Eventos próximos en el calendario', $systemPrompt);
    }
}
