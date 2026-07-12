<?php

namespace Tests\Feature\Campana;

use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Partido;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CampanaSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const CATEGORIAS_DEFAULT = [
        ['nombre' => 'Ganada franca', 'color' => '#15803d', 'umbral' => 30],
        ['nombre' => 'Competida', 'color' => '#f59e0b', 'umbral' => 1],
        ['nombre' => 'Empatada', 'color' => '#6b7280', 'umbral' => 0],
        ['nombre' => 'Perdida', 'color' => '#dc2626', 'umbral' => null],
    ];

    private function tenantConMiembro(string $rol): array
    {
        $municipio = Municipio::factory()->create();
        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $user = User::factory()->create();
        Membership::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => $rol]);

        return [$tenant, $user];
    }

    private function actuandoEn(User $user, Tenant $tenant): static
    {
        return $this->actingAs($user)->withSession(['tenant_id' => $tenant->id]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'partido_id' => null,
            'umbral_alfa' => 1000,
            'umbral_beta' => 500,
            'modo_calculo_competitividad' => 'votos',
            'categorias_competitividad' => self::CATEGORIAS_DEFAULT,
            'indicadores' => [
                'competitividad' => true,
                'tipo_seccion' => true,
                'indice_neutral' => true,
                'oportunidad' => true,
            ],
        ], $overrides);
    }

    public function test_admin_ve_defaults_sin_settings_guardados(): void
    {
        [$tenant, $admin] = $this->tenantConMiembro('admin');

        $this->actuandoEn($admin, $tenant)->get('/settings/campana')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Campana')
                ->where('partidoId', null)
                ->where('configuracion.umbral_alfa', 1000)
                ->where('configuracion.umbral_beta', 500)
                ->where('configuracion.modo_calculo_competitividad', 'votos')
                ->where('configuracion.categorias_competitividad.0.nombre', 'Ganada franca')
                ->where('configuracion.indicadores.competitividad', true)
            );
    }

    public function test_admin_guarda_partido_y_umbrales(): void
    {
        [$tenant, $admin] = $this->tenantConMiembro('admin');
        $morena = Partido::factory()->create(['siglas' => 'MORENA']);

        $this->actuandoEn($admin, $tenant)
            ->patch('/settings/campana', $this->payload([
                'partido_id' => $morena->id,
                'umbral_alfa' => 1200,
            ]))
            ->assertRedirect();

        $tenant->refresh();
        $this->assertSame($morena->id, $tenant->partido_id);
        $this->assertSame(1200, $tenant->configuracion()['umbral_alfa']);
        $this->assertSame(500, $tenant->configuracion()['umbral_beta']);
    }

    public function test_admin_agrega_quita_y_reordena_categorias(): void
    {
        [$tenant, $admin] = $this->tenantConMiembro('admin');

        $categorias = [
            ['nombre' => 'Arrasadora', 'color' => '#065f46', 'umbral' => 100],
            ['nombre' => 'Ganada franca', 'color' => '#15803d', 'umbral' => 30],
            ['nombre' => 'Perdida', 'color' => '#dc2626', 'umbral' => null],
        ];

        $this->actuandoEn($admin, $tenant)
            ->patch('/settings/campana', $this->payload(['categorias_competitividad' => $categorias]))
            ->assertRedirect();

        $persistidas = $tenant->refresh()->configuracion()['categorias_competitividad'];

        $this->assertCount(3, $persistidas);
        $this->assertSame(['Arrasadora', 'Ganada franca', 'Perdida'], array_column($persistidas, 'nombre'));
        $this->assertSame([100, 30, null], array_column($persistidas, 'umbral'));
    }

    public function test_catch_all_siempre_se_persiste_sin_umbral(): void
    {
        [$tenant, $admin] = $this->tenantConMiembro('admin');

        $categorias = self::CATEGORIAS_DEFAULT;
        $categorias[3]['umbral'] = 999; // el form debería deshabilitar este input, pero si llega, se ignora

        $this->actuandoEn($admin, $tenant)
            ->patch('/settings/campana', $this->payload(['categorias_competitividad' => $categorias]))
            ->assertRedirect();

        $persistidas = $tenant->refresh()->configuracion()['categorias_competitividad'];
        $this->assertNull($persistidas[3]['umbral']);
    }

    public function test_admin_cambia_modo_de_calculo_a_porcentaje(): void
    {
        [$tenant, $admin] = $this->tenantConMiembro('admin');

        $this->actuandoEn($admin, $tenant)
            ->patch('/settings/campana', $this->payload(['modo_calculo_competitividad' => 'porcentaje']))
            ->assertRedirect();

        $this->assertSame('porcentaje', $tenant->refresh()->configuracion()['modo_calculo_competitividad']);
    }

    public function test_configuracion_efectiva_se_comparte_como_prop_inertia(): void
    {
        [$tenant, $admin] = $this->tenantConMiembro('admin');
        $morena = Partido::factory()->create(['siglas' => 'MORENA']);
        $tenant->update(['partido_id' => $morena->id, 'settings' => ['umbral_alfa' => 1200]]);

        $this->actuandoEn($admin, $tenant)->get('/settings/campana')
            ->assertInertia(fn (Assert $page) => $page
                ->where('campana.partido.siglas', 'MORENA')
                ->where('campana.umbral_alfa', 1200)
                ->where('campana.umbral_beta', 500)
                ->where('campana.categorias_competitividad.0.slug', 'ganada-franca')
                ->where('campana.indicadores.competitividad', true)
            );
    }

    public function test_coordinador_no_accede(): void
    {
        [$tenant, $coordinador] = $this->tenantConMiembro('coordinador');

        $this->actuandoEn($coordinador, $tenant)->get('/settings/campana')->assertForbidden();
        $this->actuandoEn($coordinador, $tenant)->patch('/settings/campana', $this->payload())->assertForbidden();
    }

    public function test_brigadista_no_accede(): void
    {
        [$tenant, $brigadista] = $this->tenantConMiembro('brigadista');

        $this->actuandoEn($brigadista, $tenant)->get('/settings/campana')->assertForbidden();
        $this->actuandoEn($brigadista, $tenant)->patch('/settings/campana', $this->payload())->assertForbidden();
    }

    public function test_umbral_beta_mayor_o_igual_a_alfa_falla_validacion(): void
    {
        [$tenant, $admin] = $this->tenantConMiembro('admin');

        $this->actuandoEn($admin, $tenant)
            ->from('/settings/campana')
            ->patch('/settings/campana', $this->payload(['umbral_alfa' => 500, 'umbral_beta' => 500]))
            ->assertRedirect('/settings/campana')
            ->assertSessionHasErrors('umbral_alfa');
    }

    public function test_umbrales_no_positivos_fallan_validacion(): void
    {
        [$tenant, $admin] = $this->tenantConMiembro('admin');

        $this->actuandoEn($admin, $tenant)
            ->from('/settings/campana')
            ->patch('/settings/campana', $this->payload(['umbral_alfa' => 0]))
            ->assertRedirect('/settings/campana')
            ->assertSessionHasErrors('umbral_alfa');
    }

    public function test_nombre_de_categoria_duplicado_falla_validacion(): void
    {
        [$tenant, $admin] = $this->tenantConMiembro('admin');

        $categorias = [
            ['nombre' => 'Ganada franca', 'color' => '#15803d', 'umbral' => 30],
            ['nombre' => 'ganada franca', 'color' => '#f59e0b', 'umbral' => null],
        ];

        $this->actuandoEn($admin, $tenant)
            ->from('/settings/campana')
            ->patch('/settings/campana', $this->payload(['categorias_competitividad' => $categorias]))
            ->assertRedirect('/settings/campana')
            ->assertSessionHasErrors('categorias_competitividad');
    }

    public function test_color_no_hexadecimal_falla_validacion(): void
    {
        [$tenant, $admin] = $this->tenantConMiembro('admin');

        $categorias = [
            ['nombre' => 'Ganada franca', 'color' => 'verde', 'umbral' => null],
        ];

        $this->actuandoEn($admin, $tenant)
            ->from('/settings/campana')
            ->patch('/settings/campana', $this->payload(['categorias_competitividad' => $categorias]))
            ->assertRedirect('/settings/campana')
            ->assertSessionHasErrors('categorias_competitividad.0.color');
    }

    public function test_umbrales_no_descendentes_fallan_validacion(): void
    {
        [$tenant, $admin] = $this->tenantConMiembro('admin');

        $categorias = [
            ['nombre' => 'Franca', 'color' => '#15803d', 'umbral' => 10],
            ['nombre' => 'Competida', 'color' => '#f59e0b', 'umbral' => 20],
            ['nombre' => 'Perdida', 'color' => '#dc2626', 'umbral' => null],
        ];

        $this->actuandoEn($admin, $tenant)
            ->from('/settings/campana')
            ->patch('/settings/campana', $this->payload(['categorias_competitividad' => $categorias]))
            ->assertRedirect('/settings/campana')
            ->assertSessionHasErrors('categorias_competitividad.1.umbral');
    }

    public function test_categoria_no_ultima_sin_umbral_falla_validacion(): void
    {
        [$tenant, $admin] = $this->tenantConMiembro('admin');

        $categorias = [
            ['nombre' => 'Franca', 'color' => '#15803d', 'umbral' => null],
            ['nombre' => 'Perdida', 'color' => '#dc2626', 'umbral' => null],
        ];

        $this->actuandoEn($admin, $tenant)
            ->from('/settings/campana')
            ->patch('/settings/campana', $this->payload(['categorias_competitividad' => $categorias]))
            ->assertRedirect('/settings/campana')
            ->assertSessionHasErrors('categorias_competitividad.0.umbral');
    }

    public function test_lista_de_categorias_vacia_falla_validacion(): void
    {
        [$tenant, $admin] = $this->tenantConMiembro('admin');

        $this->actuandoEn($admin, $tenant)
            ->from('/settings/campana')
            ->patch('/settings/campana', $this->payload(['categorias_competitividad' => []]))
            ->assertRedirect('/settings/campana')
            ->assertSessionHasErrors('categorias_competitividad');
    }

    public function test_modo_de_calculo_invalido_falla_validacion(): void
    {
        [$tenant, $admin] = $this->tenantConMiembro('admin');

        $this->actuandoEn($admin, $tenant)
            ->from('/settings/campana')
            ->patch('/settings/campana', $this->payload(['modo_calculo_competitividad' => 'otro']))
            ->assertRedirect('/settings/campana')
            ->assertSessionHasErrors('modo_calculo_competitividad');
    }
}
