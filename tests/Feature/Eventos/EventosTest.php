<?php

namespace Tests\Feature\Eventos;

use App\Models\AvisoPrivacidad;
use App\Models\Elector;
use App\Models\Evento;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\CartografiaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventosTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: User, 2: Membership, 3: Municipio, 4: AvisoPrivacidad}
     */
    private function setupCampana(string $rol = 'brigadista'): array
    {
        (new CartografiaSeeder)->run(base_path('tests/Fixtures/cartografia/99-test'));
        $municipio = Municipio::query()->where('clave', 12)->first();
        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $user = User::factory()->create();
        $membership = Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => $rol]);
        TenantContext::set($tenant);
        $aviso = AvisoPrivacidad::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $user, $membership, $municipio, $aviso];
    }

    public function test_captura_modo_evento_hereda_seccion_del_evento(): void
    {
        [$tenant, $user, $m, $municipio, $aviso] = $this->setupCampana();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();
        $evento = Evento::factory()->create(['tenant_id' => $tenant->id, 'seccion_id' => $seccion->id]);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/electores', [
                'modo_captura' => 'evento',
                'evento_id' => $evento->id,
                'nombre' => 'Asistente Uno',
                'telefono' => '5512345678',
                'consentimiento' => true,
                'aviso_privacidad_id' => $aviso->id,
            ])->assertCreated();

        TenantContext::set($tenant);
        $elector = Elector::query()->first();
        $this->assertSame($evento->id, $elector->evento_id);
        $this->assertSame($seccion->id, $elector->seccion_id);
        $this->assertSame('evento', $elector->modo_captura);
    }

    public function test_captura_evento_sin_seccion_usa_seccion_explicita(): void
    {
        [$tenant, $user, $m, $municipio, $aviso] = $this->setupCampana();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 2)->first();
        $evento = Evento::factory()->create(['tenant_id' => $tenant->id, 'seccion_id' => null]);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/electores', [
                'modo_captura' => 'evento',
                'evento_id' => $evento->id,
                'seccion_id' => $seccion->id,
                'nombre' => 'Asistente Dos',
                'telefono' => '5598765432',
                'consentimiento' => true,
                'aviso_privacidad_id' => $aviso->id,
            ])->assertCreated();

        TenantContext::set($tenant);
        $elector = Elector::query()->first();
        $this->assertSame($evento->id, $elector->evento_id);
        $this->assertSame($seccion->id, $elector->seccion_id);
    }

    public function test_no_se_captura_contra_evento_de_otro_tenant(): void
    {
        [$tenant, $user, $m, $municipio, $aviso] = $this->setupCampana();
        $otroTenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $eventoAjeno = Evento::factory()->create(['tenant_id' => $otroTenant->id]);

        TenantContext::set($tenant);
        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/electores', [
                'modo_captura' => 'evento',
                'evento_id' => $eventoAjeno->id,
                'nombre' => 'Intruso',
                'telefono' => '5511112222',
                'consentimiento' => true,
                'aviso_privacidad_id' => $aviso->id,
            ])->assertStatus(422);
    }

    public function test_crear_evento_con_seccion_de_otro_municipio_falla(): void
    {
        [$tenant, $user] = $this->setupCampana('coordinador');
        // Sección de un municipio distinto al del tenant.
        $otroMunicipio = Municipio::query()->where('clave', '!=', 12)->first();
        $seccionAjena = Seccion::query()->where('municipio_id', $otroMunicipio->id)->first();

        // /eventos es un endpoint Inertia (form): el rechazo redirige con errores de sesión.
        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post('/eventos', [
                'nombre' => 'Mitin Centro',
                'tipo' => 'mitin',
                'fecha' => now()->toDateTimeString(),
                'seccion_id' => $seccionAjena->id,
            ])->assertSessionHasErrors('seccion_id');

        TenantContext::set($tenant);
        $this->assertSame(0, Evento::query()->count());
    }

    public function test_index_envia_las_secciones_del_municipio(): void
    {
        [$tenant, $user, , $municipio] = $this->setupCampana('coordinador');
        $totalSecciones = Seccion::query()->where('municipio_id', $municipio->id)->count();

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get('/eventos')
            ->assertInertia(fn ($page) => $page
                ->component('Eventos')
                ->has('secciones', $totalSecciones)
                ->has('secciones.0', fn ($s) => $s->has('id')->has('numero'))
            );
    }

    public function test_crear_evento_con_seccion_persiste_la_sede(): void
    {
        [$tenant, $user, , $municipio] = $this->setupCampana('coordinador');
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post('/eventos', [
                'nombre' => 'Mitin con Sede',
                'tipo' => 'mitin',
                'fecha' => now()->toDateTimeString(),
                'seccion_id' => $seccion->id,
            ])->assertSessionHasNoErrors();

        TenantContext::set($tenant);
        $this->assertSame($seccion->id, Evento::query()->first()->seccion_id);
    }

    public function test_crear_evento_sin_seccion_queda_sin_sede(): void
    {
        [$tenant, $user] = $this->setupCampana('coordinador');

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post('/eventos', [
                'nombre' => 'Evento Abierto',
                'tipo' => 'reunion',
                'fecha' => now()->toDateTimeString(),
                'seccion_id' => null,
            ])->assertSessionHasNoErrors();

        TenantContext::set($tenant);
        $this->assertNull(Evento::query()->first()->seccion_id);
    }

    public function test_asistentes_solo_del_evento_y_tenant(): void
    {
        [$tenant, $user, $m, $municipio, $aviso] = $this->setupCampana();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();
        $evento = Evento::factory()->create(['tenant_id' => $tenant->id, 'seccion_id' => $seccion->id]);
        $otro = Evento::factory()->create(['tenant_id' => $tenant->id, 'seccion_id' => $seccion->id]);

        Elector::factory()->count(2)->create([
            'tenant_id' => $tenant->id, 'seccion_id' => $seccion->id, 'membership_id' => $m->id,
            'aviso_privacidad_id' => $aviso->id, 'evento_id' => $evento->id, 'modo_captura' => 'evento',
        ]);
        Elector::factory()->create([
            'tenant_id' => $tenant->id, 'seccion_id' => $seccion->id, 'membership_id' => $m->id,
            'aviso_privacidad_id' => $aviso->id, 'evento_id' => $otro->id, 'modo_captura' => 'evento',
        ]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/eventos/{$evento->id}/asistentes");
        $response->assertOk();
        $this->assertCount(2, $response->json('asistentes'));
    }

    public function test_evento_de_otro_tenant_da_404_en_asistentes(): void
    {
        [$tenant, $user, $m, $municipio] = $this->setupCampana();
        $otroTenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $eventoAjeno = Evento::factory()->create(['tenant_id' => $otroTenant->id]);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/eventos/{$eventoAjeno->id}/asistentes")
            ->assertNotFound();
    }
}
