<?php

namespace Tests\Feature\Captura;

use App\Models\AvisoPrivacidad;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvisoPrivacidadTest extends TestCase
{
    use RefreshDatabase;

    public function test_devuelve_el_aviso_vigente_del_tenant(): void
    {
        $municipio = Municipio::factory()->create();
        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $user = User::factory()->create();
        Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => 'brigadista']);

        TenantContext::set($tenant);
        AvisoPrivacidad::factory()->create(['tenant_id' => $tenant->id, 'version' => '0.9', 'vigente_desde' => now()->subWeek()]);
        AvisoPrivacidad::factory()->create(['tenant_id' => $tenant->id, 'version' => '1.0', 'vigente_desde' => now()->subDay()]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson('/api/avisos-privacidad/vigente');

        $response->assertOk();
        $response->assertJsonPath('aviso.version', '1.0');
    }

    public function test_aviso_vigente_no_se_filtra_de_otro_tenant(): void
    {
        $municipio = Municipio::factory()->create();
        $tenantA = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $tenantB = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $userB = User::factory()->create();
        Membership::create(['tenant_id' => $tenantB->id, 'user_id' => $userB->id, 'rol' => 'brigadista']);

        TenantContext::set($tenantA);
        AvisoPrivacidad::factory()->create(['tenant_id' => $tenantA->id, 'version' => 'solo-A']);

        $response = $this->actingAs($userB)->withSession(['tenant_id' => $tenantB->id])
            ->getJson('/api/avisos-privacidad/vigente');

        $response->assertOk();
        $response->assertJsonPath('aviso', null);
    }
}
