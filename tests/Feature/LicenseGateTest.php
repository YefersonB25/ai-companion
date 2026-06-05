<?php

namespace Tests\Feature;

use App\Models\LicenseSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests del middleware check_license que protege las conversaciones.
 */
class LicenseGateTest extends TestCase
{
    use RefreshDatabase;

    private function requireLicenses(bool $required): void
    {
        $settings = LicenseSetting::current();
        $settings->update(['licenses_required' => $required]);
    }

    private function giveActiveLicense(User $user): void
    {
        $user->licenses()->create([
            'type'       => 'monthly',
            'status'     => 'active',
            'starts_at'  => now(),
            'expires_at' => now()->addMonth(),
        ]);
    }

    public function test_access_allowed_when_licenses_not_required(): void
    {
        $this->requireLicenses(false);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/conversations')
            ->assertOk();
    }

    public function test_access_blocked_without_active_license_when_required(): void
    {
        $this->requireLicenses(true);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/conversations')
            ->assertStatus(403)
            ->assertJsonPath('error', 'license_required');
    }

    public function test_access_allowed_with_active_license(): void
    {
        $this->requireLicenses(true);
        $user = User::factory()->create();
        $this->giveActiveLicense($user);

        $this->actingAs($user)
            ->getJson('/api/conversations')
            ->assertOk();
    }

    public function test_expired_license_is_blocked(): void
    {
        $this->requireLicenses(true);
        $user = User::factory()->create();
        $user->licenses()->create([
            'type'       => 'monthly',
            'status'     => 'active',
            'starts_at'  => now()->subMonths(2),
            'expires_at' => now()->subDay(), // vencida
        ]);

        $this->actingAs($user)
            ->getJson('/api/conversations')
            ->assertStatus(403)
            ->assertJsonPath('error', 'license_required');
    }

    public function test_admin_is_never_blocked(): void
    {
        $this->requireLicenses(true);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->getJson('/api/conversations')
            ->assertOk();
    }
}
