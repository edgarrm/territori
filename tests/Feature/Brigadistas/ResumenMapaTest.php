<?php

namespace Tests\Feature\Brigadistas;

use App\Actions\Brigadistas\AsignarZonas;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResumenMapaTest extends TestCase
{
    use RefreshDatabase;

    public function test_resumen_lista_solo_brigadistas_activos_asignados(): void
    {
        $municipio = Municipio::factory()->create();
        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $coordUser = User::factory()->create();
        Membership::factory()->coordinador()->create(['tenant_id' => $tenant->id, 'user_id' => $coordUser->id]);

        $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id]);

        $activoAsignado = Membership::factory()->brigadista()->create([
            'tenant_id' => $tenant->id,
            'activo' => true,
            'user_id' => User::factory()->create(['name' => 'Ana Activa'])->id,
        ]);
        $inactivoAsignado = Membership::factory()->brigadista()->create(['tenant_id' => $tenant->id, 'activo' => false]);
        $activoSinZona = Membership::factory()->brigadista()->create(['tenant_id' => $tenant->id, 'activo' => true]);

        TenantContext::set($tenant);
        (new AsignarZonas)->handle($activoAsignado, [$seccion->id]);
        (new AsignarZonas)->handle($inactivoAsignado, [$seccion->id]);
        // activoSinZona no recibe sección.

        $response = $this->actingAs($coordUser)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/secciones/{$seccion->id}/resumen");

        $response->assertOk();
        $activos = $response->json('brigadistas_activos');

        $ids = collect($activos)->pluck('membership_id')->all();
        $this->assertContains($activoAsignado->id, $ids);
        $this->assertNotContains($inactivoAsignado->id, $ids);
        $this->assertNotContains($activoSinZona->id, $ids);
        $this->assertSame('Ana Activa', collect($activos)->firstWhere('membership_id', $activoAsignado->id)['nombre']);
    }
}
