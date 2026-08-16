<?php

namespace Tests\Feature\Seguridad;

use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HardeningTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{User, Tenant}
     */
    private function adminConCampana(): array
    {
        $user = User::factory()->withTwoFactor()->create();
        $tenant = Tenant::factory()->create();
        Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => 'admin', 'activo' => true]);

        return [$user, $tenant];
    }

    public function test_rutas_de_dominio_aplican_rate_limit(): void
    {
        [$user, $tenant] = $this->adminConCampana();

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('dashboard'));

        $response->assertOk();
        $response->assertHeader('X-RateLimit-Limit', '120');
    }

    public function test_membership_inactiva_no_es_resuelta_por_membership_en(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => 'brigadista', 'activo' => false]);

        $this->assertNull($user->membershipEn($tenant));
    }

    public function test_marca_color_no_hexadecimal_es_rechazado(): void
    {
        [$user, $tenant] = $this->adminConCampana();
        $municipio = Municipio::factory()->create();

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post(route('campanas.store'), [
                'nombre' => 'Color inválido',
                'municipio_id' => $municipio->id,
                'marca_color' => 'rojo;}',
            ]);

        $response->assertSessionHasErrors('marca_color');
        $this->assertDatabaseMissing('tenants', ['nombre' => 'Color inválido']);
    }

    public function test_marca_color_hexadecimal_valido_se_acepta(): void
    {
        [$user, $tenant] = $this->adminConCampana();
        $municipio = Municipio::factory()->create();

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post(route('campanas.store'), [
                'nombre' => 'Color válido',
                'municipio_id' => $municipio->id,
                'marca_color' => '#1d4ed8',
            ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('tenants', ['nombre' => 'Color válido', 'marca_color' => '#1d4ed8']);
    }

    public function test_cabeceras_de_seguridad_estan_presentes(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'same-origin');
        $response->assertHeader('Permissions-Policy');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp);
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9+\/=]+'/", $csp);
    }

    public function test_hsts_solo_se_envia_sobre_https(): void
    {
        $host = parse_url(config('app.url'), PHP_URL_HOST);
        $path = route('login', absolute: false);

        $this->get("http://{$host}{$path}")->assertHeaderMissing('Strict-Transport-Security');

        $this->get("https://{$host}{$path}")
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_forgot_password_aplica_throttle_tras_seis_intentos(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->post(route('password.email'), ['email' => 'nadie@example.com'])
                ->assertStatus(302);
        }

        $this->post(route('password.email'), ['email' => 'nadie@example.com'])
            ->assertStatus(429);
    }

    /**
     * A6: throttle propio (20/min) en la captura de electores, más estricto que
     * el 120/min compartido del grupo, para acotar el oráculo de enumeración del
     * 409 por teléfono duplicado.
     */
    public function test_crear_elector_aplica_throttle_propio_tras_veinte_intentos(): void
    {
        [$user, $tenant] = $this->adminConCampana();

        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
                ->postJson('/api/electores', [])
                ->assertStatus(422);
        }

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/electores', [])
            ->assertStatus(429);
    }
}
