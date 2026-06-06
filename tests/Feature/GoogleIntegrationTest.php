<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserIntegration;
use App\Services\Integrations\GoogleOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleIntegrationTest extends TestCase
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
            'https://www.googleapis.com/auth/gmail.readonly',
            'openid',
            'email',
            'profile',
        ]);
    }

    public function test_connect_returns_google_auth_url_with_required_params(): void
    {
        $user = User::factory()->create();

        $res = $this->actingAs($user)->getJson('/api/integrations/google/connect')->assertOk();

        $url = $res->json('url');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        $this->assertStringContainsString('client_id=fake-client-id', $url);
        $this->assertStringContainsString('access_type=offline', $url);
        $this->assertStringContainsString('prompt=consent', $url);
        $this->assertStringContainsString('calendar.events', urldecode($url));
        $this->assertStringContainsString('gmail.readonly', urldecode($url));
        $this->assertStringContainsString('state=', $url);
    }

    public function test_callback_creates_encrypted_integration_and_hides_tokens(): void
    {
        $user = User::factory()->create();

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token'  => 'ya29.access-token-abc',
                'refresh_token' => '1//refresh-token-xyz',
                'expires_in'    => 3600,
                'scope'         => 'https://www.googleapis.com/auth/calendar.events openid email profile',
                'token_type'    => 'Bearer',
            ], 200),
            'www.googleapis.com/oauth2/v2/userinfo' => Http::response([
                'email' => 'user@gmail.com',
                'id'    => '12345',
            ], 200),
        ]);

        $state = app(GoogleOAuthService::class)->getAuthUrl($user);
        parse_str(parse_url($state, PHP_URL_QUERY), $params);
        $state = $params['state'];

        // SIN actingAs: el callback es público (Google redirige el navegador sin token Sanctum).
        // El usuario se identifica por el `state` firmado.
        $res = $this->get('/api/integrations/google/callback?code=auth-code-123&state=' . urlencode($state));

        // El callback redirige el navegador al panel web con ?google=connected.
        $res->assertRedirect();
        $this->assertStringContainsString('google=connected', $res->headers->get('Location'));

        // Tokens must NOT leak in the redirect URL.
        $this->assertStringNotContainsString('ya29.access-token-abc', (string) $res->headers->get('Location'));
        $this->assertStringNotContainsString('1//refresh-token-xyz', (string) $res->headers->get('Location'));

        $integration = UserIntegration::where('user_id', $user->id)->where('provider', 'google')->firstOrFail();

        // Stored value decrypts back to the original token.
        $this->assertSame('ya29.access-token-abc', $integration->access_token);
        $this->assertSame('1//refresh-token-xyz', $integration->refresh_token);
        $this->assertSame('user@gmail.com', $integration->account_email);

        // Raw DB column is encrypted (not equal to plaintext).
        $raw = \DB::table('user_integrations')->where('id', $integration->id)->value('access_token');
        $this->assertNotSame('ya29.access-token-abc', $raw);
    }

    public function test_callback_rejects_invalid_state(): void
    {
        $user = User::factory()->create();

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'x'], 200),
        ]);

        $res = $this->get('/api/integrations/google/callback?code=auth-code-123&state=not-a-valid-state');

        // Estado inválido → redirige al panel con ?google=error (no crea integración).
        $res->assertRedirect();
        $this->assertStringContainsString('google=error', $res->headers->get('Location'));

        $this->assertDatabaseCount('user_integrations', 0);
    }

    public function test_callback_rejects_reused_state_single_use(): void
    {
        $user = User::factory()->create();

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token'  => 'ya29.access-token-abc',
                'refresh_token' => '1//refresh-token-xyz',
                'expires_in'    => 3600,
                'scope'         => 'https://www.googleapis.com/auth/calendar.events openid email profile',
                'token_type'    => 'Bearer',
            ], 200),
            'www.googleapis.com/oauth2/v2/userinfo' => Http::response([
                'email' => 'user@gmail.com',
                'id'    => '12345',
            ], 200),
        ]);

        $authUrl = app(GoogleOAuthService::class)->getAuthUrl($user);
        parse_str(parse_url($authUrl, PHP_URL_QUERY), $params);
        $state = $params['state'];

        // Primer uso: válido, crea la integración.
        $first = $this->get('/api/integrations/google/callback?code=auth-code-123&state=' . urlencode($state));
        $first->assertRedirect();
        $this->assertStringContainsString('google=connected', $first->headers->get('Location'));
        $this->assertDatabaseCount('user_integrations', 1);

        // Replay: el mismo state ya fue consumido (single-use) → rechazado.
        $replay = $this->get('/api/integrations/google/callback?code=auth-code-123&state=' . urlencode($state));
        $replay->assertRedirect();
        $this->assertStringContainsString('google=error', $replay->headers->get('Location'));

        // No se crea una segunda integración.
        $this->assertDatabaseCount('user_integrations', 1);
    }

    public function test_callback_error_redirect_does_not_leak_exception_detail(): void
    {
        $user = User::factory()->create();

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'x'], 200),
        ]);

        $res = $this->get('/api/integrations/google/callback?code=auth-code-123&state=not-a-valid-state');

        $res->assertRedirect();
        $location = (string) $res->headers->get('Location');

        // Solo código genérico, nunca el mensaje de la excepción ni un parámetro message.
        $this->assertStringContainsString('google=error', $location);
        $this->assertStringContainsString('code=oauth_failed', $location);
        $this->assertStringNotContainsString('message=', $location);
        $this->assertStringNotContainsString('State OAuth', $location);
        $this->assertStringNotContainsString('inv%C3%A1lido', $location);
    }

    public function test_callback_google_access_denied_maps_to_generic_code(): void
    {
        $res = $this->get('/api/integrations/google/callback?error=access_denied');

        $res->assertRedirect();
        $location = (string) $res->headers->get('Location');

        $this->assertStringContainsString('google=error', $location);
        $this->assertStringContainsString('code=access_denied', $location);
        $this->assertStringNotContainsString('message=', $location);
    }

    public function test_disconnect_removes_integration(): void
    {
        $user = User::factory()->create();
        $user->integrations()->create([
            'provider'      => 'google',
            'access_token'  => 'tok',
            'account_email' => 'user@gmail.com',
        ]);

        $this->actingAs($user)
            ->deleteJson('/api/integrations/google')
            ->assertOk();

        $this->assertDatabaseCount('user_integrations', 0);
    }

    public function test_index_lists_integrations_without_tokens(): void
    {
        $user = User::factory()->create();
        $user->integrations()->create([
            'provider'      => 'google',
            'access_token'  => 'secret-token',
            'account_email' => 'user@gmail.com',
        ]);

        $res = $this->actingAs($user)->getJson('/api/integrations')
            ->assertOk()
            ->assertJsonPath('integrations.0.provider', 'google')
            ->assertJsonPath('integrations.0.account_email', 'user@gmail.com')
            ->assertJsonPath('integrations.0.connected', true);

        $this->assertStringNotContainsString('secret-token', $res->getContent());
    }

    public function test_refresh_if_needed_renews_expired_token(): void
    {
        $user = User::factory()->create();
        $integration = $user->integrations()->create([
            'provider'         => 'google',
            'access_token'     => 'old-token',
            'refresh_token'    => 'refresh-abc',
            'token_expires_at' => now()->subMinutes(5),
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'new-fresh-token',
                'expires_in'   => 3600,
                'token_type'   => 'Bearer',
            ], 200),
        ]);

        $refreshed = app(GoogleOAuthService::class)->refreshIfNeeded($integration->fresh());

        $this->assertSame('new-fresh-token', $refreshed->access_token);
        $this->assertFalse($refreshed->isExpired());
        $this->assertSame('new-fresh-token', $integration->fresh()->access_token);
    }

    public function test_refresh_without_expires_in_sets_future_expiry_not_null(): void
    {
        $user = User::factory()->create();
        $integration = $user->integrations()->create([
            'provider'         => 'google',
            'access_token'     => 'old-token',
            'refresh_token'    => 'refresh-abc',
            'token_expires_at' => now()->subMinutes(5),
        ]);

        // Refresh sin expires_in: token_expires_at NUNCA debe quedar en null,
        // o isExpired() devolvería false para siempre y el token jamás se refrescaría.
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'new-fresh-token',
                'token_type'   => 'Bearer',
            ], 200),
        ]);

        $refreshed = app(GoogleOAuthService::class)->refreshIfNeeded($integration->fresh());

        $this->assertSame('new-fresh-token', $refreshed->access_token);
        $this->assertNotNull($refreshed->token_expires_at);
        $this->assertTrue($refreshed->token_expires_at->isFuture());
        $this->assertFalse($refreshed->isExpired());
    }
}
