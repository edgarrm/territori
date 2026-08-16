<?php

namespace Tests\Feature\Settings;

use App\Models\ConexionGoogle;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Flujo OAuth por tenant del canal Google Sheets (F9), criterios 1-6 y 13.
 * Todas las llamadas a Google se simulan con Http::fake().
 */
class GoogleConexionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: User, 2: Membership}
     */
    private function setupCampana(string $rol = 'coordinador'): array
    {
        $this->withoutVite();

        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $membership = Membership::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'rol' => $rol,
        ]);
        TenantContext::set($tenant);

        return [$tenant, $user, $membership];
    }

    private function actuandoEn(User $user, Tenant $tenant): static
    {
        return $this->actingAs($user)->withSession(['tenant_id' => $tenant->id]);
    }

    // --- 1. Sin conexión: estado "no conectado" ---
    public function test_coordinador_sin_conexion_ve_estado_no_conectado(): void
    {
        [$tenant, $user] = $this->setupCampana();

        $this->actuandoEn($user, $tenant)
            ->get('/settings/google')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/Google')
                ->where('conectado', false)
            );
    }

    // --- 2. state mismatch en el callback: 403, nada guardado ---
    public function test_callback_con_state_incorrecto_se_rechaza_sin_guardar_conexion(): void
    {
        [$tenant, $user] = $this->setupCampana();

        $this->actuandoEn($user, $tenant)
            ->withSession(['google_oauth_state' => 'estado-correcto'])
            ->get('/settings/google/callback?state=estado-incorrecto&code=abc')
            ->assertForbidden();

        TenantContext::set($tenant);
        $this->assertSame(0, ConexionGoogle::query()->count());
    }

    // --- 3. Callback válido: crea la conexión con tokens cifrados + email ---
    public function test_callback_valido_crea_conexion_con_tokens_cifrados_y_email(): void
    {
        [$tenant, $user] = $this->setupCampana();

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-token-real',
                'refresh_token' => 'refresh-token-real',
                'expires_in' => 3600,
            ]),
            'www.googleapis.com/oauth2/v2/userinfo' => Http::response([
                'email' => 'coordinador@campana.example',
            ]),
        ]);

        $this->actuandoEn($user, $tenant)
            ->withSession(['google_oauth_state' => 'estado-valido'])
            ->get('/settings/google/callback?state=estado-valido&code=abc')
            ->assertRedirect();

        TenantContext::set($tenant);
        $conexion = ConexionGoogle::query()->firstOrFail();

        $this->assertSame($tenant->id, $conexion->tenant_id);
        $this->assertSame('coordinador@campana.example', $conexion->cuenta_email);
        $this->assertSame('access-token-real', $conexion->access_token);
        $this->assertSame('refresh-token-real', $conexion->refresh_token);

        // Cifrado en reposo: la columna cruda en BD nunca es el texto plano.
        $crudo = DB::table('conexiones_google')->where('tenant_id', $tenant->id)->first();
        $this->assertNotSame('access-token-real', $crudo->access_token);
        $this->assertNotSame('refresh-token-real', $crudo->refresh_token);
    }

    // --- 4. Reconectar: upsert por tenant_id, no duplica ---
    public function test_reconectar_actualiza_la_fila_existente_sin_duplicar(): void
    {
        [$tenant, $user] = $this->setupCampana();

        // Un solo Http::fake con secuencia: llamarlo dos veces con patrones de
        // URL repetidos no reemplaza el primer stub, lo apila (el primero
        // registrado gana), así que ambas respuestas van en una secuencia.
        Http::fake([
            'oauth2.googleapis.com/token' => Http::sequence()
                ->push(['access_token' => 'access-token-1', 'refresh_token' => 'refresh-token-1', 'expires_in' => 3600])
                ->push(['access_token' => 'access-token-2', 'refresh_token' => 'refresh-token-2', 'expires_in' => 3600]),
            'www.googleapis.com/oauth2/v2/userinfo' => Http::sequence()
                ->push(['email' => 'uno@campana.example'])
                ->push(['email' => 'dos@campana.example']),
        ]);

        $this->actuandoEn($user, $tenant)
            ->withSession(['google_oauth_state' => 'estado-1'])
            ->get('/settings/google/callback?state=estado-1&code=abc')
            ->assertRedirect();

        $this->actuandoEn($user, $tenant)
            ->withSession(['google_oauth_state' => 'estado-2'])
            ->get('/settings/google/callback?state=estado-2&code=def')
            ->assertRedirect();

        TenantContext::set($tenant);
        $this->assertSame(1, ConexionGoogle::query()->count());
        $conexion = ConexionGoogle::query()->firstOrFail();
        $this->assertSame('dos@campana.example', $conexion->cuenta_email);
        $this->assertSame('access-token-2', $conexion->access_token);
    }

    // --- 6. Desconectar: borra la fila + llama a revoke ---
    public function test_desconectar_borra_conexion_y_llama_a_revoke(): void
    {
        [$tenant, $user] = $this->setupCampana();

        TenantContext::set($tenant);
        ConexionGoogle::factory()->create();

        Http::fake([
            'oauth2.googleapis.com/revoke' => Http::response([], 200),
        ]);

        $this->actuandoEn($user, $tenant)
            ->post('/settings/google/desconectar')
            ->assertRedirect();

        TenantContext::set($tenant);
        $this->assertSame(0, ConexionGoogle::query()->count());

        Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth2.googleapis.com/revoke'));
    }

    // --- 13. Aislamiento: la desconexión de un tenant no toca la del otro ---
    public function test_conexiones_de_google_estan_aisladas_entre_tenants(): void
    {
        [$tenantA, $userA] = $this->setupCampana();
        [$tenantB] = $this->setupCampana();

        TenantContext::set($tenantA);
        ConexionGoogle::factory()->create(['cuenta_email' => 'a@campana.example']);

        TenantContext::set($tenantB);
        ConexionGoogle::factory()->create(['cuenta_email' => 'b@campana.example']);

        Http::fake(['oauth2.googleapis.com/revoke' => Http::response([], 200)]);

        $this->actuandoEn($userA, $tenantA)
            ->post('/settings/google/desconectar')
            ->assertRedirect();

        TenantContext::set($tenantA);
        $this->assertSame(0, ConexionGoogle::query()->count());

        TenantContext::set($tenantB);
        $this->assertSame(1, ConexionGoogle::query()->count());
        $this->assertSame('b@campana.example', ConexionGoogle::query()->firstOrFail()->cuenta_email);
    }
}
