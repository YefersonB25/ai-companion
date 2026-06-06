<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BriefingService;
use App\Services\Integrations\GmailService;
use App\Services\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.google.client_id', 'fake-client-id.apps.googleusercontent.com');
        config()->set('services.google.client_secret', 'fake-client-secret');
        config()->set('services.google.redirect', 'https://ai.omnirepair.online/api/integrations/google/callback');
        config()->set('services.google.scopes', [
            'https://www.googleapis.com/auth/gmail.readonly',
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

    /** Fake típico: lista de 2 ids + metadatos por mensaje. */
    private function fakeWithTwoUnread(): void
    {
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response([
                'messages' => [
                    ['id' => 'msg-1', 'threadId' => 't1'],
                    ['id' => 'msg-2', 'threadId' => 't2'],
                ],
            ], 200),
            'gmail.googleapis.com/gmail/v1/users/me/messages/msg-1*' => Http::response([
                'id'      => 'msg-1',
                'snippet' => 'Recordatorio de tu cita médica del lunes',
                'payload' => [
                    'headers' => [
                        ['name' => 'From', 'value' => 'Clínica Salud <citas@clinica.com>'],
                        ['name' => 'Subject', 'value' => 'Tu cita está confirmada'],
                    ],
                ],
            ], 200),
            'gmail.googleapis.com/gmail/v1/users/me/messages/msg-2*' => Http::response([
                'id'      => 'msg-2',
                'snippet' => 'Tu factura de junio ya está disponible',
                'payload' => [
                    'headers' => [
                        ['name' => 'From', 'value' => 'Facturación <billing@isp.com>'],
                        ['name' => 'Subject', 'value' => 'Factura junio'],
                    ],
                ],
            ], 200),
        ]);
    }

    public function test_unread_summary_parses_and_normalizes_response(): void
    {
        $user = $this->connectedUser();
        $this->fakeWithTwoUnread();

        $emails = app(GmailService::class)->unreadSummary($user, 5);

        $this->assertCount(2, $emails);

        $this->assertSame('msg-1', $emails[0]['id']);
        $this->assertSame('Clínica Salud <citas@clinica.com>', $emails[0]['from']);
        $this->assertSame('Tu cita está confirmada', $emails[0]['subject']);
        $this->assertSame('Recordatorio de tu cita médica del lunes', $emails[0]['snippet']);

        $this->assertSame('msg-2', $emails[1]['id']);
        $this->assertSame('Factura junio', $emails[1]['subject']);
    }

    public function test_unread_count_uses_result_size_estimate_without_fetching_messages(): void
    {
        $user = $this->connectedUser();

        Http::fake([
            // Lista con maxResults=1 que devuelve resultSizeEstimate.
            'gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response([
                'messages'            => [['id' => 'msg-1', 'threadId' => 't1']],
                'resultSizeEstimate'  => 7,
            ], 200),
            // Cualquier fetch de mensaje individual NO debe ocurrir.
            'gmail.googleapis.com/gmail/v1/users/me/messages/*' => Http::response([
                'id' => 'msg-1',
            ], 200),
        ]);

        $count = app(GmailService::class)->unreadCount($user);

        $this->assertSame(7, $count);

        // Solo se llamó al endpoint de lista; ningún fetchMessage individual.
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/messages/'));
    }

    public function test_unread_summary_returns_empty_when_no_unread(): void
    {
        $user = $this->connectedUser();

        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response([], 200),
        ]);

        $this->assertSame([], app(GmailService::class)->unreadSummary($user));
    }

    public function test_unread_summary_throws_when_not_connected(): void
    {
        $user = User::factory()->create(); // sin integración

        $this->expectException(\App\Exceptions\GoogleNotConnectedException::class);

        app(GmailService::class)->unreadSummary($user);
    }

    public function test_is_connected_helper(): void
    {
        $svc = app(GmailService::class);

        $this->assertFalse($svc->isConnected(User::factory()->create()));
        $this->assertTrue($svc->isConnected($this->connectedUser()));
    }

    public function test_get_unread_emails_tool_returns_guidance_when_not_connected(): void
    {
        $user = User::factory()->create(); // sin integración

        $result = app(ToolRegistry::class)->execute('get_unread_emails', [], $user);

        $this->assertStringContainsString('no ha conectado', $result);
        $this->assertStringContainsString('Ajustes', $result);
    }

    public function test_get_unread_emails_tool_returns_readable_summary_when_connected(): void
    {
        $user = $this->connectedUser();
        $this->fakeWithTwoUnread();

        $result = app(ToolRegistry::class)->execute('get_unread_emails', ['max' => 5], $user);

        $this->assertStringContainsString('2 correo', $result);
        $this->assertStringContainsString('Clínica Salud', $result);
        $this->assertStringContainsString('Tu cita está confirmada', $result);
        $this->assertStringContainsString('Factura junio', $result);
    }

    public function test_get_unread_emails_tool_reports_empty_inbox(): void
    {
        $user = $this->connectedUser();

        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response([], 200),
        ]);

        $result = app(ToolRegistry::class)->execute('get_unread_emails', [], $user);

        $this->assertStringContainsString('No tienes correos sin leer', $result);
    }

    public function test_briefing_includes_gmail_block_when_connected_with_unread(): void
    {
        $user = $this->connectedUser();
        $user->setting()->create([
            'timezone'         => 'America/Bogota',
            'briefing_enabled' => true,
        ]);

        Http::fake([
            // Sin eventos de calendario para aislar el bloque de Gmail.
            'www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response(['items' => []], 200),
            'gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response([
                'messages' => [['id' => 'msg-1']],
            ], 200),
            'gmail.googleapis.com/gmail/v1/users/me/messages/msg-1*' => Http::response([
                'id'      => 'msg-1',
                'snippet' => 'Propuesta lista para revisión',
                'payload' => [
                    'headers' => [
                        ['name' => 'From', 'value' => 'Ana <ana@empresa.com>'],
                        ['name' => 'Subject', 'value' => 'Propuesta comercial'],
                    ],
                ],
            ], 200),
        ]);

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
        $this->assertStringContainsString('Correos sin leer', $systemPrompt);
        $this->assertStringContainsString('Propuesta comercial', $systemPrompt);
    }
}
