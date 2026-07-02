<?php

namespace Tests\Feature\Loterias;

use App\Models\AvisoPrivacidad;
use App\Models\Loteria;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\CartografiaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnfitrionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: User, 2: Membership, 3: Municipio, 4: AvisoPrivacidad}
     */
    private function setupAnfitrion(): array
    {
        (new CartografiaSeeder)->run(base_path('tests/Fixtures/cartografia/99-test'));
        $municipio = Municipio::query()->where('clave', 12)->first();
        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $user = User::factory()->create();
        $membership = Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => 'anfitrion']);
        TenantContext::set($tenant);
        $aviso = AvisoPrivacidad::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $user, $membership, $municipio, $aviso];
    }

    private function asignarZona(Membership $membership, Seccion $seccion): void
    {
        $membership->secciones()->attach($seccion->id, ['tenant_id' => $membership->tenant_id]);
    }

    public function test_anfitrion_no_accede_al_resto_del_dominio(): void
    {
        [$tenant, $user] = $this->setupAnfitrion();

        $sesion = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id]);

        $sesion->get('/dashboard')->assertForbidden();
        $sesion->get('/captura')->assertForbidden();
        $sesion->get('/mapa')->assertForbidden();
        $sesion->get('/eventos')->assertForbidden();
        $sesion->get('/loterias')->assertOk();
    }

    public function test_anfitrion_solo_ve_sus_loterias(): void
    {
        [$tenant, $user, $membership, $municipio] = $this->setupAnfitrion();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();

        Loteria::factory()->create([
            'tenant_id' => $tenant->id,
            'membership_id' => $membership->id,
            'seccion_id' => $seccion->id,
        ]);

        // Lotería de otro miembro: no debe verla.
        $otro = User::factory()->create();
        $otroM = Membership::create(['tenant_id' => $tenant->id, 'user_id' => $otro->id, 'rol' => 'brigadista']);
        Loteria::factory()->create([
            'tenant_id' => $tenant->id,
            'membership_id' => $otroM->id,
            'seccion_id' => $seccion->id,
        ]);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get('/loterias')
            ->assertInertia(fn ($page) => $page
                ->component('Loterias')
                ->has('loterias', 1)
            );
    }

    public function test_anfitrion_crea_loteria_en_su_zona(): void
    {
        [$tenant, $user, $membership, $municipio] = $this->setupAnfitrion();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();
        $this->asignarZona($membership, $seccion);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post('/loterias', [
                'nombre' => 'Lotería del Anfitrión',
                'fecha' => now()->toDateString(),
                'seccion_id' => $seccion->id,
            ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('loterias', [
            'tenant_id' => $tenant->id,
            'membership_id' => $membership->id,
            'creada_por_membership_id' => $membership->id,
            'nombre' => 'Lotería del Anfitrión',
        ]);
    }

    public function test_anfitrion_no_crea_loteria_fuera_de_su_zona(): void
    {
        [$tenant, $user, $membership, $municipio] = $this->setupAnfitrion();
        $zona = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();
        $ajena = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 2)->first();
        $this->asignarZona($membership, $zona);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post('/loterias', [
                'nombre' => 'Lotería Fuera de Zona',
                'fecha' => now()->toDateString(),
                'seccion_id' => $ajena->id,
            ])->assertSessionHasErrors('seccion_id');

        TenantContext::set($tenant);
        $this->assertSame(0, Loteria::query()->count());
    }

    public function test_anfitrion_captura_en_su_loteria(): void
    {
        [$tenant, $user, $membership, $municipio, $aviso] = $this->setupAnfitrion();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();
        $this->asignarZona($membership, $seccion);

        $loteria = Loteria::factory()->create([
            'tenant_id' => $tenant->id,
            'membership_id' => $membership->id,
            'seccion_id' => $seccion->id,
        ]);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/electores', [
                'modo_captura' => 'loteria',
                'loteria_id' => $loteria->id,
                'nombre' => 'Invitado Lotería',
                'telefono' => '5512345678',
                'consentimiento' => true,
                'aviso_privacidad_id' => $aviso->id,
            ])->assertCreated();

        TenantContext::set($tenant);
        $this->assertDatabaseHas('electores', [
            'tenant_id' => $tenant->id,
            'loteria_id' => $loteria->id,
            'seccion_id' => $seccion->id,
            'modo_captura' => 'loteria',
        ]);
    }

    public function test_anfitrion_no_captura_en_otros_modos(): void
    {
        [$tenant, $user, $membership, $municipio, $aviso] = $this->setupAnfitrion();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();
        $this->asignarZona($membership, $seccion);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/electores', [
                'modo_captura' => 'enlace_seccional',
                'seccion_id' => $seccion->id,
                'nombre' => 'Fuera de Modo',
                'telefono' => '5512345678',
                'consentimiento' => true,
                'aviso_privacidad_id' => $aviso->id,
            ])->assertStatus(422);
    }

    public function test_loteria_asignada_por_gestion_es_visible_para_el_anfitrion(): void
    {
        [$tenant, $user, $membership, $municipio] = $this->setupAnfitrion();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();
        $this->asignarZona($membership, $seccion);

        $coordinador = User::factory()->create();
        $coordinadorM = Membership::create(['tenant_id' => $tenant->id, 'user_id' => $coordinador->id, 'rol' => 'coordinador']);

        $this->actingAs($coordinador)->withSession(['tenant_id' => $tenant->id])
            ->post('/loterias', [
                'nombre' => 'Lotería Delegada',
                'fecha' => now()->toDateString(),
                'seccion_id' => $seccion->id,
                'asignado_membership_id' => $membership->id,
            ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('loterias', [
            'membership_id' => $membership->id,
            'creada_por_membership_id' => $coordinadorM->id,
        ]);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get('/loterias')
            ->assertInertia(fn ($page) => $page
                ->component('Loterias')
                ->has('loterias', 1)
            );
    }

    public function test_gestion_no_asigna_fuera_de_las_zonas_del_anfitrion(): void
    {
        [$tenant, , $membership, $municipio] = $this->setupAnfitrion();
        $zona = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();
        $ajena = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 2)->first();
        $this->asignarZona($membership, $zona);

        $coordinador = User::factory()->create();
        Membership::create(['tenant_id' => $tenant->id, 'user_id' => $coordinador->id, 'rol' => 'coordinador']);

        $this->actingAs($coordinador)->withSession(['tenant_id' => $tenant->id])
            ->post('/loterias', [
                'nombre' => 'Lotería Mal Asignada',
                'fecha' => now()->toDateString(),
                'seccion_id' => $ajena->id,
                'asignado_membership_id' => $membership->id,
            ])->assertSessionHasErrors('seccion_id');
    }
}
