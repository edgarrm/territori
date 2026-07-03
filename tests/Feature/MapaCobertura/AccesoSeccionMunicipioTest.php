<?php

namespace Tests\Feature\MapaCobertura;

use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Seccion no está tenant-scoped: los endpoints de sección deben rechazar (404)
 * secciones de un municipio distinto al de la campaña activa, aun para gestión.
 */
class AccesoSeccionMunicipioTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Tenant, 2: Municipio}
     */
    private function adminEnMunicipio(): array
    {
        $municipio = Municipio::factory()->create();
        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $user = User::factory()->create();
        Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => 'admin']);

        return [$user, $tenant, $municipio];
    }

    public function test_seccion_de_otro_municipio_da_404(): void
    {
        [$user, $tenant] = $this->adminEnMunicipio();
        $otroMunicipio = Municipio::factory()->create();
        $ajena = Seccion::factory()->create(['municipio_id' => $otroMunicipio->id]);

        $sesion = fn () => $this->actingAs($user)->withSession(['tenant_id' => $tenant->id]);

        $sesion()->get("/secciones/{$ajena->id}")->assertNotFound();
        $sesion()->getJson("/api/secciones/{$ajena->id}/resumen")->assertNotFound();
        $sesion()->getJson("/api/secciones/{$ajena->id}/electores")->assertNotFound();
    }

    public function test_seccion_del_propio_municipio_sigue_accesible(): void
    {
        [$user, $tenant, $municipio] = $this->adminEnMunicipio();
        $propia = Seccion::factory()->create(['municipio_id' => $municipio->id]);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get("/secciones/{$propia->id}")
            ->assertOk();
    }
}
