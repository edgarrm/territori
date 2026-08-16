<?php

namespace Tests\Feature\GoogleSheets;

use App\Exceptions\ConexionGoogleInvalida;
use App\Exceptions\ConexionGoogleNoEncontrada;
use App\Models\ConexionGoogle;
use App\Models\Tenant;
use App\Support\GoogleSheets\ClienteSheets;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ClienteSheets (F9): refresco automático de access_token vencido
 * (criterio 5) + manejo defensivo de un refresh_token revocado.
 */
class ClienteSheetsTest extends TestCase
{
    use RefreshDatabase;

    // --- 5. access_token vencido + refresh_token válido: refresca antes de llamar a Sheets ---
    public function test_refresca_access_token_automaticamente_cuando_esta_vencido(): void
    {
        $tenant = Tenant::factory()->create();
        TenantContext::set($tenant);

        ConexionGoogle::factory()->vencida()->create([
            'access_token' => 'access-viejo',
            'refresh_token' => 'refresh-valido',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-nuevo',
                'expires_in' => 3600,
            ]),
            'sheets.googleapis.com/*' => Http::response([
                'values' => [['seccion', 'apoya_obra'], ['101', 'Sí']],
            ]),
        ]);

        $cliente = new ClienteSheets($tenant->id);
        $valores = $cliente->obtenerValores('sheet-123', 'Respuestas');

        $this->assertSame([['seccion', 'apoya_obra'], ['101', 'Sí']], $valores);

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'sheets.googleapis.com')) {
                return false;
            }

            return ($request->header('Authorization')[0] ?? '') === 'Bearer access-nuevo';
        });

        TenantContext::set($tenant);
        $actualizada = ConexionGoogle::query()->firstOrFail();
        $this->assertSame('access-nuevo', $actualizada->access_token);
        $this->assertTrue($actualizada->expira_en->isFuture());
    }

    public function test_access_token_vigente_no_dispara_refresco(): void
    {
        $tenant = Tenant::factory()->create();
        TenantContext::set($tenant);

        ConexionGoogle::factory()->create([
            'access_token' => 'access-vigente',
            'expira_en' => now()->addHour(),
        ]);

        Http::fake([
            'sheets.googleapis.com/*' => Http::response(['values' => []]),
        ]);

        $cliente = new ClienteSheets($tenant->id);
        $cliente->obtenerValores('sheet-123', 'Respuestas');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'oauth2.googleapis.com/token'));
    }

    public function test_tenant_sin_conexion_lanza_excepcion_clara(): void
    {
        $tenant = Tenant::factory()->create();

        $this->expectException(ConexionGoogleNoEncontrada::class);

        (new ClienteSheets($tenant->id))->obtenerValores('sheet-123', 'Respuestas');
    }

    // --- Refresco defensivo: refresh_token revocado fuera de la app ---
    public function test_refresh_token_revocado_borra_la_conexion_y_lanza_error_claro(): void
    {
        $tenant = Tenant::factory()->create();
        TenantContext::set($tenant);
        ConexionGoogle::factory()->vencida()->create();

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $cliente = new ClienteSheets($tenant->id);

        try {
            $cliente->obtenerValores('sheet-123', 'Respuestas');
            $this->fail('Se esperaba ConexionGoogleInvalida.');
        } catch (ConexionGoogleInvalida) {
            // esperado
        }

        TenantContext::set($tenant);
        $this->assertSame(0, ConexionGoogle::query()->count());
    }
}
