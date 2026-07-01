<?php

namespace Tests\Feature\RedCiudadana;

use App\Models\AvisoPrivacidad;
use App\Models\Elector;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\RedCiudadana;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\CartografiaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RedCiudadanaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Municipio $municipio;

    private AvisoPrivacidad $aviso;

    protected function setUp(): void
    {
        parent::setUp();

        // La página de redes es Inertia; sin build no hay manifest de Vite.
        $this->withoutVite();

        (new CartografiaSeeder)->run(base_path('tests/Fixtures/cartografia/99-test'));
        $this->municipio = Municipio::query()->where('clave', 12)->first();
        $this->tenant = Tenant::factory()->create(['municipio_id' => $this->municipio->id]);
        TenantContext::set($this->tenant);
        $this->aviso = AvisoPrivacidad::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    /**
     * @return array{0: User, 1: Membership}
     */
    private function miembro(string $rol): array
    {
        $user = User::factory()->create();
        $membership = Membership::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'rol' => $rol]);

        return [$user, $membership];
    }

    private function seccion(int $numero = 1): Seccion
    {
        return Seccion::query()->where('municipio_id', $this->municipio->id)->where('numero', $numero)->first();
    }

    private function red(Membership $enlace): RedCiudadana
    {
        TenantContext::set($this->tenant);

        return RedCiudadana::factory()->create([
            'tenant_id' => $this->tenant->id,
            'enlace_membership_id' => $enlace->id,
        ]);
    }

    public function test_gestion_crea_red_asignando_enlace(): void
    {
        [$coordUser] = $this->miembro('coordinador');
        [, $enlace] = $this->miembro('brigadista');

        $response = $this->actingAs($coordUser)->withSession(['tenant_id' => $this->tenant->id])
            ->post('/redes-ciudadanas', [
                'nombre' => 'Red del Barrio',
                'enlace_membership_id' => $enlace->id,
            ]);

        $response->assertRedirect();

        TenantContext::set($this->tenant);
        $red = RedCiudadana::query()->first();
        $this->assertSame('Red del Barrio', $red->nombre);
        $this->assertSame($enlace->id, $red->enlace_membership_id);
    }

    public function test_brigadista_no_puede_crear_red(): void
    {
        [$brigUser, $brig] = $this->miembro('brigadista');

        $response = $this->actingAs($brigUser)->withSession(['tenant_id' => $this->tenant->id])
            ->post('/redes-ciudadanas', [
                'nombre' => 'Red Prohibida',
                'enlace_membership_id' => $brig->id,
            ]);

        $response->assertForbidden();
        TenantContext::set($this->tenant);
        $this->assertSame(0, RedCiudadana::query()->count());
    }

    public function test_captura_red_ciudadana_guarda_red_y_resuelve_seccion(): void
    {
        [$enlaceUser, $enlace] = $this->miembro('enlace');
        $red = $this->red($enlace);
        $seccion = $this->seccion();

        $response = $this->actingAs($enlaceUser)->withSession(['tenant_id' => $this->tenant->id])
            ->postJson('/api/electores', [
                'modo_captura' => 'red_ciudadana',
                'red_ciudadana_id' => $red->id,
                'seccion_id' => $seccion->id,
                'nombre' => 'Vecino Uno',
                'telefono' => '5512340001',
                'consentimiento' => true,
                'aviso_privacidad_id' => $this->aviso->id,
            ]);

        $response->assertCreated();

        TenantContext::set($this->tenant);
        $elector = Elector::query()->first();
        $this->assertSame('red_ciudadana', $elector->modo_captura);
        $this->assertSame($red->id, $elector->red_ciudadana_id);
        $this->assertSame($seccion->id, $elector->seccion_id);
    }

    public function test_rol_enlace_no_puede_capturar_en_otro_modo(): void
    {
        [$enlaceUser] = $this->miembro('enlace');
        $seccion = $this->seccion();

        $response = $this->actingAs($enlaceUser)->withSession(['tenant_id' => $this->tenant->id])
            ->postJson('/api/electores', [
                'modo_captura' => 'individual',
                'seccion_id' => $seccion->id,
                'nombre' => 'No Permitido',
                'telefono' => '5512340002',
                'consentimiento' => true,
                'aviso_privacidad_id' => $this->aviso->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('modo_captura');
    }

    public function test_no_puede_capturar_en_red_ajena(): void
    {
        [, $otroEnlace] = $this->miembro('brigadista');
        $red = $this->red($otroEnlace);

        [$brigUser] = $this->miembro('brigadista');
        $seccion = $this->seccion();

        $response = $this->actingAs($brigUser)->withSession(['tenant_id' => $this->tenant->id])
            ->postJson('/api/electores', [
                'modo_captura' => 'red_ciudadana',
                'red_ciudadana_id' => $red->id,
                'seccion_id' => $seccion->id,
                'nombre' => 'Ajeno',
                'telefono' => '5512340003',
                'consentimiento' => true,
                'aviso_privacidad_id' => $this->aviso->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('red_ciudadana_id');
    }

    public function test_enlace_ve_pii_completa_de_registros_de_su_red(): void
    {
        [$enlaceUser, $enlace] = $this->miembro('enlace');
        [, $capturador] = $this->miembro('brigadista');
        $red = $this->red($enlace);
        $seccion = $this->seccion();

        TenantContext::set($this->tenant);
        // Registro capturado por OTRA membership dentro de la red del enlace.
        $elector = Elector::factory()->create([
            'tenant_id' => $this->tenant->id,
            'seccion_id' => $seccion->id,
            'membership_id' => $capturador->id,
            'red_ciudadana_id' => $red->id,
            'modo_captura' => 'red_ciudadana',
            'aviso_privacidad_id' => $this->aviso->id,
            'telefono' => '5588887777',
        ]);

        $response = $this->actingAs($enlaceUser)->withSession(['tenant_id' => $this->tenant->id])
            ->getJson(route('redes-ciudadanas.registros', $red->id));

        $response->assertOk();
        $fila = collect($response->json('data'))->firstWhere('id', $elector->id);
        $this->assertSame('5588887777', $fila['telefono']);
    }

    public function test_enlace_no_ve_registros_de_red_ajena(): void
    {
        [$enlaceUser] = $this->miembro('enlace');
        [, $otroEnlace] = $this->miembro('brigadista');
        $redAjena = $this->red($otroEnlace);

        $response = $this->actingAs($enlaceUser)->withSession(['tenant_id' => $this->tenant->id])
            ->getJson(route('redes-ciudadanas.registros', $redAjena->id));

        $response->assertForbidden();
    }

    public function test_enlace_ve_solo_sus_redes_en_index(): void
    {
        [$enlaceUser, $enlace] = $this->miembro('enlace');
        $miRed = $this->red($enlace);
        [, $otroEnlace] = $this->miembro('brigadista');
        $this->red($otroEnlace);

        $response = $this->actingAs($enlaceUser)->withSession(['tenant_id' => $this->tenant->id])
            ->get('/redes-ciudadanas');

        $response->assertInertia(fn ($page) => $page
            ->component('RedesCiudadanas')
            ->where('esGestion', false)
            ->has('redes', 1)
            ->where('redes.0.id', $miRed->id)
            ->has('enlaces', 0)
        );
    }

    public function test_gestion_ve_todas_las_redes_y_catalogo_de_enlaces_en_index(): void
    {
        [$coordUser] = $this->miembro('coordinador');
        [, $enlaceUno] = $this->miembro('brigadista');
        [, $enlaceDos] = $this->miembro('brigadista');
        $this->red($enlaceUno);
        $this->red($enlaceDos);

        $response = $this->actingAs($coordUser)->withSession(['tenant_id' => $this->tenant->id])
            ->get('/redes-ciudadanas');

        $response->assertInertia(fn ($page) => $page
            ->component('RedesCiudadanas')
            ->where('esGestion', true)
            ->has('redes', 2)
            ->has('enlaces', 3)
        );
    }

    public function test_catalogo_de_enlaces_no_incluye_membresias_de_otro_tenant(): void
    {
        [$coordUser] = $this->miembro('coordinador');

        $otroMunicipio = Municipio::query()->where('clave', 12)->first();
        $otroTenant = Tenant::factory()->create(['municipio_id' => $otroMunicipio->id]);
        $otroUser = User::factory()->create();
        Membership::create(['tenant_id' => $otroTenant->id, 'user_id' => $otroUser->id, 'rol' => 'brigadista']);

        $response = $this->actingAs($coordUser)->withSession(['tenant_id' => $this->tenant->id])
            ->get('/redes-ciudadanas');

        $response->assertInertia(fn ($page) => $page
            ->component('RedesCiudadanas')
            ->has('enlaces', 1)
        );
    }

    public function test_no_se_puede_asignar_enlace_de_otro_tenant(): void
    {
        [$coordUser] = $this->miembro('coordinador');

        $otroMunicipio = Municipio::query()->where('clave', 12)->first();
        $otroTenant = Tenant::factory()->create(['municipio_id' => $otroMunicipio->id]);
        $otroUser = User::factory()->create();
        $ajena = Membership::create(['tenant_id' => $otroTenant->id, 'user_id' => $otroUser->id, 'rol' => 'brigadista']);

        $response = $this->actingAs($coordUser)->withSession(['tenant_id' => $this->tenant->id])
            ->post('/redes-ciudadanas', [
                'nombre' => 'Red Cruzada',
                'enlace_membership_id' => $ajena->id,
            ]);

        $response->assertSessionHasErrors('enlace_membership_id');
        TenantContext::set($this->tenant);
        $this->assertSame(0, RedCiudadana::query()->count());
    }

    public function test_no_se_puede_capturar_en_red_inactiva(): void
    {
        [$enlaceUser, $enlace] = $this->miembro('enlace');
        $red = $this->red($enlace);
        $red->update(['activa' => false]);
        $seccion = $this->seccion();

        $response = $this->actingAs($enlaceUser)->withSession(['tenant_id' => $this->tenant->id])
            ->postJson('/api/electores', [
                'modo_captura' => 'red_ciudadana',
                'red_ciudadana_id' => $red->id,
                'seccion_id' => $seccion->id,
                'nombre' => 'Vecino Inactivo',
                'telefono' => '5512340004',
                'consentimiento' => true,
                'aviso_privacidad_id' => $this->aviso->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('red_ciudadana_id');
        TenantContext::set($this->tenant);
        $this->assertSame(0, Elector::query()->count());
    }

    public function test_rol_enlace_no_accede_al_resto_del_dominio(): void
    {
        [$enlaceUser] = $this->miembro('enlace');

        $this->actingAs($enlaceUser)->withSession(['tenant_id' => $this->tenant->id])
            ->get('/mapa')->assertForbidden();

        $this->actingAs($enlaceUser)->withSession(['tenant_id' => $this->tenant->id])
            ->get('/dashboard')->assertForbidden();

        $this->actingAs($enlaceUser)->withSession(['tenant_id' => $this->tenant->id])
            ->get('/redes-ciudadanas')->assertOk();
    }
}
