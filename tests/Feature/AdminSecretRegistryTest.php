<?php

namespace Tests\Feature;

use App\Models\SecretRegistry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests del registro de secretos (DOCUMENTACIÓN — sin valores).
 *
 * Aserta el principio de seguridad CRÍTICO: el VALOR del secreto nunca se guarda
 * ni se devuelve. La API solo expone metadatos + `configured`/`last4` calculados
 * al vuelo leyendo env().
 */
class AdminSecretRegistryTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_ENV_VAR = 'TEST_SECRET_REG_KEY';
    private const FULL_SECRET  = 'super-secret-value-ABCD9876';

    protected function setUp(): void
    {
        parent::setUp();

        // Setea una env de prueba (igual que la app lee secretos reales en producción).
        putenv(self::TEST_ENV_VAR . '=' . self::FULL_SECRET);
        $_ENV[self::TEST_ENV_VAR]    = self::FULL_SECRET;
        $_SERVER[self::TEST_ENV_VAR] = self::FULL_SECRET;
    }

    protected function tearDown(): void
    {
        putenv(self::TEST_ENV_VAR);
        unset($_ENV[self::TEST_ENV_VAR], $_SERVER[self::TEST_ENV_VAR]);

        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function makeSecret(array $overrides = []): SecretRegistry
    {
        return SecretRegistry::create(array_merge([
            'env_var'     => self::TEST_ENV_VAR,
            'label'       => 'Test Secret',
            'app'         => 'backend',
            'provider'    => 'TestProvider',
            'description' => 'Una clave de prueba.',
            'status'      => 'active',
            'sort_order'  => 5,
        ], $overrides));
    }

    // ─── Auth ─────────────────────────────────────

    public function test_unauthenticated_gets_401(): void
    {
        $this->getJson('/api/admin/secrets')->assertStatus(401);
    }

    public function test_non_admin_gets_403(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)->getJson('/api/admin/secrets')->assertStatus(403);
    }

    // ─── index ────────────────────────────────────

    public function test_index_returns_metadata_with_configured_and_last4(): void
    {
        $this->makeSecret();
        // Un secreto NO configurado en el entorno → configured=false, last4=null.
        $this->makeSecret([
            'env_var'    => 'TEST_SECRET_REG_MISSING',
            'label'      => 'Missing Secret',
            'sort_order' => 6,
        ]);

        $res = $this->actingAs($this->admin())->getJson('/api/admin/secrets');

        $res->assertOk()
            ->assertJsonStructure([
                ['id', 'env_var', 'label', 'app', 'provider', 'description', 'used_in',
                 'rotation_url', 'last_rotated_at', 'status', 'notes', 'sort_order',
                 'configured', 'last4'],
            ]);

        // Secreto configurado: configured=true, last4 = últimos 4 chars.
        $res->assertJsonPath('0.env_var', self::TEST_ENV_VAR)
            ->assertJsonPath('0.configured', true)
            ->assertJsonPath('0.last4', substr(self::FULL_SECRET, -4)); // "9876"

        // Secreto ausente: configured=false, last4=null.
        $res->assertJsonPath('1.configured', false)
            ->assertJsonPath('1.last4', null);
    }

    public function test_index_never_leaks_full_secret_value(): void
    {
        $this->makeSecret();

        $res = $this->actingAs($this->admin())->getJson('/api/admin/secrets');

        $res->assertOk();

        // CRÍTICO: el valor completo NUNCA debe aparecer en la respuesta. Solo el last4.
        $res->assertDontSee(self::FULL_SECRET, false);
        $body = $res->getContent();
        $this->assertStringNotContainsString(self::FULL_SECRET, $body);
        $this->assertStringContainsString('9876', $body); // sí aparece el last4

        // Ningún campo de la respuesta contiene el valor (ni 'value').
        $row = $res->json('0');
        $this->assertArrayNotHasKey('value', $row);
        $this->assertNotContains(self::FULL_SECRET, $row);
    }

    public function test_index_ordered_by_sort_order(): void
    {
        $this->makeSecret(['env_var' => 'A_KEY', 'label' => 'A', 'sort_order' => 20]);
        $this->makeSecret(['env_var' => 'B_KEY', 'label' => 'B', 'sort_order' => 1]);

        $res = $this->actingAs($this->admin())->getJson('/api/admin/secrets');

        $res->assertOk()->assertJsonPath('0.env_var', 'B_KEY');
    }

    // ─── store ────────────────────────────────────

    public function test_admin_can_store_secret_metadata(): void
    {
        $res = $this->actingAs($this->admin())->postJson('/api/admin/secrets', [
            'env_var'     => 'NEW_DOC_KEY',
            'label'       => 'Nueva clave',
            'app'         => 'backend',
            'provider'    => 'Proveedor',
            'description' => 'Descripción de la nueva clave.',
            'status'      => 'active',
            // value malicioso → debe ignorarse, no existe columna.
            'value'       => 'no-debe-guardarse',
        ]);

        $res->assertCreated()->assertJsonPath('env_var', 'NEW_DOC_KEY');

        $this->assertDatabaseHas('secret_registry', [
            'env_var' => 'NEW_DOC_KEY',
            'label'   => 'Nueva clave',
        ]);
        // No hay columna value en la tabla; el body 'value' jamás se persiste.
        $row = SecretRegistry::where('env_var', 'NEW_DOC_KEY')->first();
        $this->assertArrayNotHasKey('value', $row->getAttributes());
    }

    // ─── update ───────────────────────────────────

    public function test_admin_can_update_metadata_and_value_is_ignored(): void
    {
        $secret = $this->makeSecret();

        $res = $this->actingAs($this->admin())->putJson("/api/admin/secrets/{$secret->id}", [
            'description' => 'Descripción actualizada.',
            'status'      => 'needs_rotation',
            // value en el body → se ignora (no existe tal columna).
            'value'       => 'intento-de-inyectar-valor',
        ]);

        $res->assertOk()
            ->assertJsonPath('description', 'Descripción actualizada.')
            ->assertJsonPath('status', 'needs_rotation');

        $secret->refresh();
        $this->assertSame('Descripción actualizada.', $secret->description);
        $this->assertSame('needs_rotation', $secret->status);
        $this->assertArrayNotHasKey('value', $secret->getAttributes());
    }

    public function test_non_admin_cannot_update(): void
    {
        $secret = $this->makeSecret();
        $user   = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->putJson("/api/admin/secrets/{$secret->id}", ['description' => 'x'])
            ->assertStatus(403);
    }

    // ─── destroy ──────────────────────────────────

    public function test_admin_can_destroy_secret_doc(): void
    {
        $secret = $this->makeSecret();

        $this->actingAs($this->admin())
            ->deleteJson("/api/admin/secrets/{$secret->id}")
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertDatabaseMissing('secret_registry', ['id' => $secret->id]);
    }
}
