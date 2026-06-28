<?php

namespace Tests\Feature\MapaCobertura;

use App\Models\Elector;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeccionDetalleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: User, 2: Municipio}
     */
    private function tenantConMiembro(string $rol = 'brigadista'): array
    {
        $municipio = Municipio::factory()->create();
        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $user = User::factory()->create();
        Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => $rol]);

        return [$tenant, $user, $municipio];
    }

    public function test_pagina_detalle_renderiza_para_miembro(): void
    {
        [$tenant, $user, $municipio] = $this->tenantConMiembro();
        $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get("/secciones/{$seccion->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Seccion')
            ->where('seccion.id', $seccion->id)
            ->where('seccion.numero', $seccion->numero));
    }

    public function test_electores_por_seccion_solo_devuelve_los_de_esa_seccion(): void
    {
        [$tenant, $user, $municipio] = $this->tenantConMiembro();
        $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id]);
        $otra = Seccion::factory()->create(['municipio_id' => $municipio->id]);

        TenantContext::set($tenant);
        $mio = Elector::factory()->create(['tenant_id' => $tenant->id, 'seccion_id' => $seccion->id]);
        Elector::factory()->create(['tenant_id' => $tenant->id, 'seccion_id' => $otra->id]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/secciones/{$seccion->id}/electores");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $mio->id);
        $response->assertJsonPath('data.0.nombre', $mio->nombre);
        $response->assertJsonPath('data.0.telefono', $mio->telefono);
    }

    public function test_electores_scoped_por_tenant(): void
    {
        [$tenantA, $userA, $municipio] = $this->tenantConMiembro();
        [$tenantB, $userB] = $this->tenantConMiembro();
        Tenant::query()->whereKey($tenantB->id)->update(['municipio_id' => $municipio->id]);

        $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id]);

        TenantContext::set($tenantA);
        Elector::factory()->create(['tenant_id' => $tenantA->id, 'seccion_id' => $seccion->id]);

        $response = $this->actingAs($userB)->withSession(['tenant_id' => $tenantB->id])
            ->getJson("/api/secciones/{$seccion->id}/electores");

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }
}
