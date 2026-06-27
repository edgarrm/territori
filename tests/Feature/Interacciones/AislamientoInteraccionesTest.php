<?php

namespace Tests\Feature\Interacciones;

use App\Models\AvisoPrivacidad;
use App\Models\Elector;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\CartografiaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AislamientoInteraccionesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Un elector del tenant B no debe ser alcanzable desde una sesión del tenant A.
     */
    public function test_elector_de_otro_tenant_da_404_en_interacciones_y_edicion(): void
    {
        (new CartografiaSeeder)->run(base_path('tests/Fixtures/cartografia/99-test'));
        $municipio = Municipio::query()->where('clave', 12)->first();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();

        // Tenant B con su elector.
        $tenantB = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $userB = User::factory()->create();
        $membershipB = Membership::create(['tenant_id' => $tenantB->id, 'user_id' => $userB->id, 'rol' => 'coordinador']);
        TenantContext::set($tenantB);
        $avisoB = AvisoPrivacidad::factory()->create(['tenant_id' => $tenantB->id]);
        $electorB = Elector::factory()->create([
            'tenant_id' => $tenantB->id, 'seccion_id' => $seccion->id, 'membership_id' => $membershipB->id,
            'aviso_privacidad_id' => $avisoB->id,
        ]);

        // Tenant A: usuario distinto, sesión en A.
        $tenantA = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $userA = User::factory()->create();
        Membership::create(['tenant_id' => $tenantA->id, 'user_id' => $userA->id, 'rol' => 'coordinador']);

        $sesion = fn () => $this->actingAs($userA)->withSession(['tenant_id' => $tenantA->id]);

        $sesion()->getJson("/api/electores/{$electorB->id}/interacciones")->assertNotFound();
        $sesion()->postJson("/api/electores/{$electorB->id}/interacciones", ['tipo' => 'llamada'])->assertNotFound();
        $sesion()->putJson("/api/electores/{$electorB->id}", ['nombre' => 'Hackeado'])->assertNotFound();
    }
}
