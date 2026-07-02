<?php

namespace Tests\Feature\Captura;

use App\Models\AvisoPrivacidad;
use App\Models\Elector;
use App\Models\Loteria;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\CartografiaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AsignaZonas;
use Tests\TestCase;

class LoteriaTest extends TestCase
{
    use AsignaZonas;
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: User, 2: Membership, 3: Municipio, 4: AvisoPrivacidad}
     */
    private function setupCampana(): array
    {
        (new CartografiaSeeder)->run(base_path('tests/Fixtures/cartografia/99-test'));
        $municipio = Municipio::query()->where('clave', 12)->first();
        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $user = User::factory()->create();
        $membership = Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => 'brigadista']);
        $this->asignarTodasLasZonas($membership, $municipio->id);
        TenantContext::set($tenant);
        $aviso = AvisoPrivacidad::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $user, $membership, $municipio, $aviso];
    }

    public function test_crear_loteria_persiste_nombre_fecha_encargado_y_creador(): void
    {
        [$tenant, $user, $membership, $municipio] = $this->setupCampana();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post('/loterias', [
                'nombre' => 'Lotería Centro',
                'fecha' => now()->toDateString(),
                'seccion_id' => $seccion->id,
            ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('loterias', [
            'tenant_id' => $tenant->id,
            'membership_id' => $membership->id,
            'creada_por_membership_id' => $membership->id,
            'seccion_id' => $seccion->id,
            'nombre' => 'Lotería Centro',
        ]);
    }

    public function test_gestion_crea_loteria_asignada_a_otro_miembro(): void
    {
        [$tenant, , $membership, $municipio] = $this->setupCampana();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();

        $coordinador = User::factory()->create();
        $coordinadorM = Membership::create(['tenant_id' => $tenant->id, 'user_id' => $coordinador->id, 'rol' => 'coordinador']);

        $this->actingAs($coordinador)->withSession(['tenant_id' => $tenant->id])
            ->post('/loterias', [
                'nombre' => 'Lotería Asignada',
                'fecha' => now()->toDateString(),
                'seccion_id' => $seccion->id,
                'asignado_membership_id' => $membership->id,
            ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('loterias', [
            'tenant_id' => $tenant->id,
            'membership_id' => $membership->id,
            'creada_por_membership_id' => $coordinadorM->id,
            'nombre' => 'Lotería Asignada',
        ]);
    }

    public function test_brigadista_no_puede_asignar_a_otro_miembro(): void
    {
        [$tenant, $user, , $municipio] = $this->setupCampana();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();

        $otro = User::factory()->create();
        $otroM = Membership::create(['tenant_id' => $tenant->id, 'user_id' => $otro->id, 'rol' => 'brigadista']);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post('/loterias', [
                'nombre' => 'Lotería Intrusa',
                'fecha' => now()->toDateString(),
                'seccion_id' => $seccion->id,
                'asignado_membership_id' => $otroM->id,
            ])->assertSessionHasErrors('asignado_membership_id');

        TenantContext::set($tenant);
        $this->assertSame(0, Loteria::query()->count());
    }

    public function test_gestion_no_puede_asignar_a_membership_de_otro_tenant(): void
    {
        [$tenant, , , $municipio] = $this->setupCampana();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();

        $coordinador = User::factory()->create();
        Membership::create(['tenant_id' => $tenant->id, 'user_id' => $coordinador->id, 'rol' => 'coordinador']);

        $otroTenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $ajeno = User::factory()->create();
        $ajenoM = Membership::create(['tenant_id' => $otroTenant->id, 'user_id' => $ajeno->id, 'rol' => 'brigadista']);

        TenantContext::set($tenant);
        $this->actingAs($coordinador)->withSession(['tenant_id' => $tenant->id])
            ->post('/loterias', [
                'nombre' => 'Lotería Cruzada',
                'fecha' => now()->toDateString(),
                'seccion_id' => $seccion->id,
                'asignado_membership_id' => $ajenoM->id,
            ])->assertSessionHasErrors('asignado_membership_id');
    }

    public function test_index_incluye_las_loterias_asignadas_aunque_no_las_creo(): void
    {
        [$tenant, $user, $membership, $municipio] = $this->setupCampana();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();

        $coordinador = User::factory()->create();
        $coordinadorM = Membership::create(['tenant_id' => $tenant->id, 'user_id' => $coordinador->id, 'rol' => 'coordinador']);

        // Asignada al brigadista pero creada por el coordinador.
        Loteria::factory()->create([
            'tenant_id' => $tenant->id,
            'membership_id' => $membership->id,
            'creada_por_membership_id' => $coordinadorM->id,
            'seccion_id' => $seccion->id,
        ]);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get('/loterias')
            ->assertInertia(fn ($page) => $page
                ->component('Loterias')
                ->has('loterias', 1)
            );
    }

    public function test_index_limita_secciones_a_las_zonas_del_brigadista(): void
    {
        [$tenant, $user, $membership, $municipio] = $this->setupCampana();

        // Deja al brigadista con una sola zona (setup le asigna todas).
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();
        $membership->secciones()->sync([$seccion->id => ['tenant_id' => $tenant->id]]);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get('/loterias')
            ->assertInertia(fn ($page) => $page
                ->component('Loterias')
                ->has('secciones', 1)
                ->where('secciones.0.id', $seccion->id)
                ->where('esGestion', false)
            );
    }

    public function test_captura_en_loteria_ajena_falla(): void
    {
        [$tenant, , $membership, $municipio, $aviso] = $this->setupCampana();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();

        $loteria = Loteria::factory()->create([
            'tenant_id' => $tenant->id,
            'membership_id' => $membership->id,
            'creada_por_membership_id' => $membership->id,
            'seccion_id' => $seccion->id,
        ]);

        // Otro brigadista (con la zona asignada) que NO es encargado ni creador.
        $otro = User::factory()->create();
        $otroM = Membership::create(['tenant_id' => $tenant->id, 'user_id' => $otro->id, 'rol' => 'brigadista']);
        $otroM->secciones()->attach($seccion->id, ['tenant_id' => $tenant->id]);

        $this->actingAs($otro)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/electores', [
                'modo_captura' => 'loteria',
                'loteria_id' => $loteria->id,
                'nombre' => 'Intruso Lotería',
                'telefono' => '5512345678',
                'consentimiento' => true,
                'aviso_privacidad_id' => $aviso->id,
            ])->assertStatus(422);
    }

    public function test_crear_loteria_en_seccion_no_asignada_falla(): void
    {
        [$tenant, , , $municipio] = $this->setupCampana();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();

        // Brigadista sin zonas asignadas: no puede crear lotería en ninguna sección.
        $otro = User::factory()->create();
        Membership::create(['tenant_id' => $tenant->id, 'user_id' => $otro->id, 'rol' => 'brigadista']);

        $this->actingAs($otro)->withSession(['tenant_id' => $tenant->id])
            ->post('/loterias', [
                'nombre' => 'Lotería Ajena',
                'fecha' => now()->toDateString(),
                'seccion_id' => $seccion->id,
            ])->assertSessionHasErrors('seccion_id');

        TenantContext::set($tenant);
        $this->assertSame(0, Loteria::query()->count());
    }

    public function test_index_brigadista_solo_ve_sus_loterias(): void
    {
        [$tenant, $user, $membership, $municipio] = $this->setupCampana();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();

        Loteria::factory()->create([
            'tenant_id' => $tenant->id,
            'membership_id' => $membership->id,
            'seccion_id' => $seccion->id,
        ]);

        // Lotería de otro brigadista del mismo tenant: no debe verla.
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

    public function test_captura_modo_loteria_hereda_seccion_y_loteria(): void
    {
        [$tenant, $user, $membership, $municipio, $aviso] = $this->setupCampana();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 2)->first();

        $loteria = Loteria::factory()->create([
            'tenant_id' => $tenant->id,
            'membership_id' => $membership->id,
            'seccion_id' => $seccion->id,
        ]);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/electores', [
                'modo_captura' => 'loteria',
                'loteria_id' => $loteria->id,
                'nombre' => 'Voto Lotería',
                'telefono' => '5512345678',
                'consentimiento' => true,
                'aviso_privacidad_id' => $aviso->id,
            ])->assertCreated();

        TenantContext::set($tenant);
        $elector = Elector::query()->first();
        $this->assertSame('loteria', $elector->modo_captura);
        $this->assertSame($seccion->id, $elector->seccion_id);
        $this->assertSame($loteria->id, $elector->loteria_id);
    }

    public function test_captura_modo_loteria_persiste_domicilio_y_observaciones(): void
    {
        [$tenant, $user, $membership, $municipio, $aviso] = $this->setupCampana();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 2)->first();

        $loteria = Loteria::factory()->create([
            'tenant_id' => $tenant->id,
            'membership_id' => $membership->id,
            'seccion_id' => $seccion->id,
        ]);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/electores', [
                'modo_captura' => 'loteria',
                'loteria_id' => $loteria->id,
                'nombre' => 'Voto Con Domicilio',
                'telefono' => '5512345678',
                'domicilio' => 'Calle Falsa 123',
                'observaciones' => 'Simpatizante activo',
                'consentimiento' => true,
                'aviso_privacidad_id' => $aviso->id,
            ])->assertCreated();

        TenantContext::set($tenant);
        $elector = Elector::query()->first();
        $this->assertSame('Calle Falsa 123', $elector->domicilio);
        $this->assertSame('Simpatizante activo', $elector->observaciones);
    }

    public function test_modo_loteria_sin_loteria_id_falla(): void
    {
        [$tenant, $user, , , $aviso] = $this->setupCampana();

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/electores', [
                'modo_captura' => 'loteria',
                'nombre' => 'Sin Lotería',
                'telefono' => '5512345678',
                'consentimiento' => true,
                'aviso_privacidad_id' => $aviso->id,
            ])->assertStatus(422);
    }

    public function test_modo_loteria_con_loteria_inexistente_falla(): void
    {
        [$tenant, $user, , , $aviso] = $this->setupCampana();

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/electores', [
                'modo_captura' => 'loteria',
                'loteria_id' => 999999,
                'nombre' => 'Lotería Fantasma',
                'telefono' => '5512345678',
                'consentimiento' => true,
                'aviso_privacidad_id' => $aviso->id,
            ])->assertStatus(422);
    }

    public function test_electores_lista_los_capturados_de_la_loteria(): void
    {
        [$tenant, $user, $membership, $municipio, $aviso] = $this->setupCampana();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();
        $loteria = Loteria::factory()->create([
            'tenant_id' => $tenant->id,
            'membership_id' => $membership->id,
            'seccion_id' => $seccion->id,
        ]);

        foreach (['Ana Capturada', 'Beto Capturado'] as $i => $nombre) {
            $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
                ->postJson('/api/electores', [
                    'modo_captura' => 'loteria',
                    'loteria_id' => $loteria->id,
                    'nombre' => $nombre,
                    'telefono' => '551234567'.$i,
                    'consentimiento' => true,
                    'aviso_privacidad_id' => $aviso->id,
                ])->assertCreated();
        }

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/loterias/{$loteria->id}/electores");

        $response->assertOk();
        $response->assertJsonPath('total', 2);
        $response->assertJsonCount(2, 'data');
        $response->assertJsonFragment(['nombre' => 'Ana Capturada']);
        $response->assertJsonFragment(['nombre' => 'Beto Capturado']);
        // El brigadista que capturó ve el teléfono completo.
        $response->assertJsonFragment(['telefono' => '5512345670']);
    }

    public function test_electores_enmascara_telefono_para_quien_no_capturo(): void
    {
        [$tenant, $user, $membership, $municipio, $aviso] = $this->setupCampana();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();
        $loteria = Loteria::factory()->create([
            'tenant_id' => $tenant->id,
            'membership_id' => $membership->id,
            'seccion_id' => $seccion->id,
        ]);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/electores', [
                'modo_captura' => 'loteria',
                'loteria_id' => $loteria->id,
                'nombre' => 'Carmen Capturada',
                'telefono' => '5512345670',
                'consentimiento' => true,
                'aviso_privacidad_id' => $aviso->id,
            ])->assertCreated();

        // Otro brigadista del mismo tenant que no capturó a estos electores.
        $otro = User::factory()->create();
        Membership::create(['tenant_id' => $tenant->id, 'user_id' => $otro->id, 'rol' => 'brigadista']);

        $response = $this->actingAs($otro)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/loterias/{$loteria->id}/electores");

        $response->assertOk();
        $response->assertJsonFragment(['telefono' => '••••••5670']);
        $response->assertJsonMissing(['telefono' => '5512345670']);
    }

    public function test_electores_solo_devuelve_los_de_su_loteria(): void
    {
        [$tenant, $user, $membership, $municipio] = $this->setupCampana();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();
        $loteria = Loteria::factory()->create([
            'tenant_id' => $tenant->id,
            'membership_id' => $membership->id,
            'seccion_id' => $seccion->id,
        ]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/loterias/{$loteria->id}/electores");

        $response->assertOk();
        $response->assertJsonPath('total', 0);
        $response->assertJsonCount(0, 'data');
    }
}
