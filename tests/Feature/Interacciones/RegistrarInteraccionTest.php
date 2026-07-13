<?php

namespace Tests\Feature\Interacciones;

use App\Models\AvisoPrivacidad;
use App\Models\Elector;
use App\Models\Interaccion;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\CartografiaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrarInteraccionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: User, 2: Membership, 3: Elector}
     */
    private function setupConElector(string $rol = 'brigadista'): array
    {
        (new CartografiaSeeder)->run(base_path('tests/Fixtures/cartografia/99-test'));
        $municipio = Municipio::query()->where('clave', 12)->first();

        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $user = User::factory()->create();
        $membership = Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => $rol]);

        TenantContext::set($tenant);
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();
        $aviso = AvisoPrivacidad::factory()->create(['tenant_id' => $tenant->id]);
        $elector = Elector::factory()->create([
            'tenant_id' => $tenant->id,
            'seccion_id' => $seccion->id,
            'membership_id' => $membership->id,
            'aviso_privacidad_id' => $aviso->id,
        ]);

        return [$tenant, $user, $membership, $elector];
    }

    public function test_registra_interaccion_con_membership_del_servidor(): void
    {
        [$tenant, $user, $membership, $elector] = $this->setupConElector();

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson("/api/electores/{$elector->id}/interacciones", [
                'tipo' => 'llamada',
                'resultado' => 'contesto',
                'membership_id' => 99999, // debe ignorarse
            ]);

        $response->assertCreated();

        TenantContext::set($tenant);
        $interaccion = Interaccion::query()->first();
        $this->assertSame($membership->id, $interaccion->membership_id);
        $this->assertSame('llamada', $interaccion->tipo);
        $this->assertSame('contesto', $interaccion->resultado);
        $this->assertNotNull($interaccion->fecha);
    }

    public function test_nota_no_lleva_resultado(): void
    {
        [$tenant, $user, , $elector] = $this->setupConElector();

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson("/api/electores/{$elector->id}/interacciones", [
                'tipo' => 'nota',
                'resultado' => 'contesto',
            ]);

        $response->assertStatus(422);
    }

    public function test_fecha_por_defecto_es_ahora(): void
    {
        [$tenant, $user, , $elector] = $this->setupConElector();

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson("/api/electores/{$elector->id}/interacciones", [
                'tipo' => 'visita',
            ])->assertCreated();

        TenantContext::set($tenant);
        $this->assertNotNull(Interaccion::query()->first()->fecha);
    }

    public function test_timeline_orden_descendente_por_fecha(): void
    {
        [$tenant, $user, $membership, $elector] = $this->setupConElector();

        Interaccion::factory()->create([
            'tenant_id' => $tenant->id, 'elector_id' => $elector->id, 'membership_id' => $membership->id,
            'tipo' => 'llamada', 'fecha' => now()->subDays(2),
        ]);
        Interaccion::factory()->create([
            'tenant_id' => $tenant->id, 'elector_id' => $elector->id, 'membership_id' => $membership->id,
            'tipo' => 'visita', 'fecha' => now(),
        ]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/electores/{$elector->id}/interacciones");

        $response->assertOk();
        $data = $response->json();
        $this->assertSame('visita', $data[0]['tipo']);
        $this->assertSame('llamada', $data[1]['tipo']);
    }

    public function test_usuario_sin_membership_no_puede_registrar(): void
    {
        [$tenant, , , $elector] = $this->setupConElector();
        $intruso = User::factory()->create();

        $this->actingAs($intruso)->withSession(['tenant_id' => $tenant->id])
            ->postJson("/api/electores/{$elector->id}/interacciones", [
                'tipo' => 'llamada',
            ])->assertForbidden();
    }

    public function test_interaccion_con_verificar_marca_al_elector(): void
    {
        [$tenant, $user, $membership, $elector] = $this->setupConElector();

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson("/api/electores/{$elector->id}/interacciones", [
                'tipo' => 'whatsapp',
                'resultado' => 'contesto',
                'verificar' => true,
            ])->assertCreated();

        $elector->refresh();
        $this->assertNotNull($elector->verificado_en);
        $this->assertSame('whatsapp', $elector->verificado_via);
        $this->assertSame($membership->id, $elector->verificado_membership_id);
    }

    public function test_verificar_con_nota_es_invalido(): void
    {
        [$tenant, $user, , $elector] = $this->setupConElector();

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson("/api/electores/{$elector->id}/interacciones", [
                'tipo' => 'nota',
                'verificar' => true,
            ])->assertStatus(422);

        $this->assertNull($elector->fresh()->verificado_en);
    }

    public function test_interaccion_sin_verificar_no_marca_al_elector(): void
    {
        [$tenant, $user, , $elector] = $this->setupConElector();

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson("/api/electores/{$elector->id}/interacciones", [
                'tipo' => 'llamada',
            ])->assertCreated();

        $this->assertNull($elector->fresh()->verificado_en);
    }

    public function test_quitar_verificacion_limpia_los_campos(): void
    {
        [$tenant, $user, $membership, $elector] = $this->setupConElector();
        $elector->update([
            'verificado_en' => now(),
            'verificado_via' => 'llamada',
            'verificado_membership_id' => $membership->id,
        ]);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->deleteJson("/api/electores/{$elector->id}/verificacion")
            ->assertOk()
            ->assertJsonPath('verificado', false);

        $elector->refresh();
        $this->assertNull($elector->verificado_en);
        $this->assertNull($elector->verificado_via);
        $this->assertNull($elector->verificado_membership_id);
    }
}
