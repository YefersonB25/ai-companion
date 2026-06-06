<?php

namespace Tests\Feature;

use App\Console\Commands\SendProactiveNotifications;
use App\Models\ProactiveLog;
use App\Models\User;
use App\Services\AI\AIRouter;
use App\Services\AI\Providers\BaseProvider;
use App\Services\BriefingService;
use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Tests de la memoria de corto plazo en la proactividad (P-05).
 * Cubre el registro de mensajes enviados (ProactiveLog) y la inyección
 * de los mensajes recientes en el prompt para evitar repeticiones.
 */
class ProactiveMemoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Provider falso que captura los mensajes recibidos en chat() para poder
     * aseverar sobre el contenido del prompt.
     */
    private function fakeProvider(string $reply = 'Mensaje proactivo nuevo'): BaseProvider
    {
        return new class($reply) extends BaseProvider {
            public array $lastMessages = [];

            public function __construct(private string $reply) {}

            public function getName(): string { return 'fake'; }
            public function getSupportedModels(): array { return ['fake-1']; }
            public function stream(array $messages, array $options = []): Generator { yield ''; }

            public function chat(array $messages, array $options = []): array
            {
                $this->lastMessages = $messages;

                return [
                    'content'       => $this->reply,
                    'provider'      => 'fake',
                    'model'         => 'fake-1',
                    'input_tokens'  => 10,
                    'output_tokens' => 5,
                    'latency_ms'    => 30,
                ];
            }
        };
    }

    private function mockRouterReturning(BaseProvider $provider): void
    {
        $router = Mockery::mock(AIRouter::class);
        $router->shouldReceive('forUser')->andReturn($provider);
        $this->app->instance(AIRouter::class, $router);
    }

    private function userWithDeviceToken(): User
    {
        $user = User::factory()->create();
        $user->deviceTokens()->create([
            'token'    => 'ExponentPushToken[abc]',
            'platform' => 'expo',
        ]);

        return $user;
    }

    // ---------------------------------------------------------------------
    // Proactivo (aria:proactive)
    // ---------------------------------------------------------------------

    public function test_proactive_command_logs_sent_message(): void
    {
        Http::fake();

        $this->mockRouterReturning($this->fakeProvider('Recuerda tu cita médica hoy'));

        $user = $this->userWithDeviceToken();
        $user->memoryNodes()->create([
            'type'       => 'event',
            'label'      => 'Cita',
            'content'    => 'Cita médica',
            'importance' => 0.9,
        ]);

        $this->artisan('aria:proactive')->assertSuccessful();

        $this->assertDatabaseHas('proactive_logs', [
            'user_id' => $user->id,
            'type'    => ProactiveLog::TYPE_PROACTIVE,
            'message' => 'Recuerda tu cita médica hoy',
        ]);
    }

    public function test_proactive_prompt_includes_recent_messages(): void
    {
        $cmd = new SendProactiveNotifications();

        $prompt = $cmd->buildPrompt(
            'lunes',
            "- [Cita]: Cita médica",
            ['Recuerda tomar agua', 'Tienes gimnasio hoy'],
        );

        $this->assertStringContainsString('YA enviaste', $prompt);
        $this->assertStringContainsString('Recuerda tomar agua', $prompt);
        $this->assertStringContainsString('Tienes gimnasio hoy', $prompt);
    }

    public function test_proactive_prompt_without_history_omits_section(): void
    {
        $cmd = new SendProactiveNotifications();

        $prompt = $cmd->buildPrompt('lunes', "- [Cita]: Cita médica", []);

        $this->assertStringNotContainsString('YA enviaste', $prompt);
        // El prompt base sigue siendo válido
        $this->assertStringContainsString('generate ONE brief', $prompt);
    }

    public function test_proactive_command_works_without_history(): void
    {
        Http::fake();

        $this->mockRouterReturning($this->fakeProvider('Primer mensaje proactivo'));

        $user = $this->userWithDeviceToken();
        $user->memoryNodes()->create([
            'type'       => 'habit',
            'label'      => 'Agua',
            'content'    => 'Tomar agua',
            'importance' => 0.7,
        ]);

        $this->assertSame(0, $user->proactiveLogs()->count());

        $this->artisan('aria:proactive')->assertSuccessful();

        $this->assertSame(1, $user->proactiveLogs()->count());
    }

    // ---------------------------------------------------------------------
    // Briefing (BriefingService)
    // ---------------------------------------------------------------------

    public function test_briefing_logs_sent_message(): void
    {
        Http::fake();

        $this->mockRouterReturning($this->fakeProvider('Buenos días, hoy luce despejado'));

        $user = $this->userWithDeviceToken();
        $user->setting()->create(['briefing_enabled' => true]);

        $this->app->make(BriefingService::class)->sendForUser($user);

        $this->assertDatabaseHas('proactive_logs', [
            'user_id' => $user->id,
            'type'    => ProactiveLog::TYPE_BRIEFING,
            'message' => 'Buenos días, hoy luce despejado',
        ]);
    }

    public function test_briefing_prompt_includes_recent_briefings(): void
    {
        $provider = $this->fakeProvider('Briefing nuevo y distinto');
        $this->mockRouterReturning($provider);

        $user = $this->userWithDeviceToken();
        $user->setting()->create(['briefing_enabled' => true]);

        ProactiveLog::create([
            'user_id' => $user->id,
            'type'    => ProactiveLog::TYPE_BRIEFING,
            'message' => 'Briefing de ayer sobre tu reunión',
        ]);

        $this->app->make(BriefingService::class)->generate($user);

        $systemPrompt = collect($provider->lastMessages)
            ->firstWhere('role', 'system')['content'] ?? '';

        $this->assertStringContainsString('YA enviaste', $systemPrompt);
        $this->assertStringContainsString('Briefing de ayer sobre tu reunión', $systemPrompt);
    }

    public function test_briefing_generate_works_without_history(): void
    {
        $provider = $this->fakeProvider('Briefing inicial');
        $this->mockRouterReturning($provider);

        $user = $this->userWithDeviceToken();
        $user->setting()->create(['briefing_enabled' => true]);

        $result = $this->app->make(BriefingService::class)->generate($user);

        $systemPrompt = collect($provider->lastMessages)
            ->firstWhere('role', 'system')['content'] ?? '';

        $this->assertSame('Briefing inicial', $result);
        $this->assertStringNotContainsString('YA enviaste', $systemPrompt);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
