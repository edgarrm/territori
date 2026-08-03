<?php

namespace Tests\Feature\Privacidad;

use App\Models\AvisoPrivacidad;
use App\Models\Elector;
use App\Models\Evento;
use App\Models\Interaccion;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Telefono;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\CartografiaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisibilidadPiiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: Municipio, 2: Seccion, 3: AvisoPrivacidad}
     */
    private function setupCampana(): array
    {
        (new CartografiaSeeder)->run(base_path('tests/Fixtures/cartografia/99-test'));
        $municipio = Municipio::query()->where('clave', 12)->first();
        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        TenantContext::set($tenant);
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();
        $aviso = AvisoPrivacidad::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $municipio, $seccion, $aviso];
    }

    private function miembro(Tenant $tenant, string $rol): array
    {
        $user = User::factory()->create();
        $membership = Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => $rol]);

        return [$user, $membership];
    }

    private function elector(Tenant $tenant, Membership $m, Seccion $s, AvisoPrivacidad $a, string $telefono, string $domicilio): Elector
    {
        TenantContext::set($tenant);

        return Elector::factory()->create([
            'tenant_id' => $tenant->id, 'seccion_id' => $s->id, 'membership_id' => $m->id,
            'aviso_privacidad_id' => $a->id, 'telefono' => $telefono, 'telefono_hash' => Telefono::hash($telefono),
            'domicilio' => $domicilio,
        ]);
    }

    public function test_brigadista_ve_pii_completa_de_su_propio_elector(): void
    {
        [$tenant, , $seccion, $aviso] = $this->setupCampana();
        [$user, $membership] = $this->miembro($tenant, 'brigadista');
        $elector = $this->elector($tenant, $membership, $seccion, $aviso, '5511112222', 'Calle Falsa 123');

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/electores/{$elector->id}");

        $response->assertOk();
        $response->assertJsonPath('telefono', '5511112222');
        $response->assertJsonPath('domicilio', 'Calle Falsa 123');
    }

    public function test_brigadista_ve_pii_enmascarada_de_elector_ajeno(): void
    {
        [$tenant, , $seccion, $aviso] = $this->setupCampana();
        [$user, $membership] = $this->miembro($tenant, 'brigadista');
        [, $otro] = $this->miembro($tenant, 'brigadista');
        // El viewer tiene la sección asignada (así puede acceder al elector), pero
        // como no lo capturó él, ve su PII enmascarada.
        $membership->secciones()->attach($seccion->id, ['tenant_id' => $tenant->id]);
        $elector = $this->elector($tenant, $otro, $seccion, $aviso, '5511112222', 'Calle Falsa 123');

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/electores/{$elector->id}");

        $response->assertOk();
        $this->assertNotSame('5511112222', $response->json('telefono'));
        $this->assertStringContainsString('2222', $response->json('telefono'));
        $this->assertStringNotContainsString('5511112222', (string) $response->json('telefono'));
        $this->assertNotSame('Calle Falsa 123', $response->json('domicilio'));
    }

    /**
     * `observaciones` es texto libre: el campo con más probabilidad de contener
     * datos sensibles del art. 9 (P5). `nombre` completo + sección + últimos 4
     * dígitos reidentifica trivialmente (P6).
     */
    public function test_brigadista_no_ve_observaciones_ni_nombre_completo_de_elector_ajeno(): void
    {
        [$tenant, , $seccion, $aviso] = $this->setupCampana();
        [$user, $membership] = $this->miembro($tenant, 'brigadista');
        [, $otro] = $this->miembro($tenant, 'brigadista');
        $membership->secciones()->attach($seccion->id, ['tenant_id' => $tenant->id]);
        $elector = $this->elector($tenant, $otro, $seccion, $aviso, '5511112222', 'Calle Falsa 123');
        $elector->forceFill([
            'nombre' => 'Juan Carlos Pérez García',
            'observaciones' => 'Diabético, vota siempre con su hermana',
            'email' => 'juan@correo.com',
        ])->save();

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/electores/{$elector->id}");

        $response->assertOk();
        $response->assertJsonPath('observaciones', '•••');
        $response->assertJsonPath('nombre', 'J. C. Pérez G.');
        $response->assertJsonPath('pii_visible', false);
        $this->assertStringNotContainsString('Diabético', $response->getContent());
        $this->assertStringNotContainsString('García', $response->getContent());
    }

    public function test_brigadista_ve_observaciones_y_nombre_de_su_propio_elector(): void
    {
        [$tenant, , $seccion, $aviso] = $this->setupCampana();
        [$user, $membership] = $this->miembro($tenant, 'brigadista');
        $elector = $this->elector($tenant, $membership, $seccion, $aviso, '5511112222', 'Calle Falsa 123');
        $elector->forceFill([
            'nombre' => 'Juan Carlos Pérez García',
            'observaciones' => 'Pidió información del programa de becas',
        ])->save();

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/electores/{$elector->id}");

        $response->assertOk();
        $response->assertJsonPath('observaciones', 'Pidió información del programa de becas');
        $response->assertJsonPath('nombre', 'Juan Carlos Pérez García');
        $response->assertJsonPath('pii_visible', true);
    }

    public function test_lista_compartida_enmascara_el_nombre_de_ajenos_para_brigadista(): void
    {
        [$tenant, , $seccion, $aviso] = $this->setupCampana();
        [$user, $membership] = $this->miembro($tenant, 'brigadista');
        [, $otro] = $this->miembro($tenant, 'brigadista');
        $membership->secciones()->attach($seccion->id, ['tenant_id' => $tenant->id]);

        $evento = Evento::factory()->create(['tenant_id' => $tenant->id, 'seccion_id' => $seccion->id]);
        $propio = $this->elector($tenant, $membership, $seccion, $aviso, '5511112222', 'Mi Calle 1');
        $ajeno = $this->elector($tenant, $otro, $seccion, $aviso, '5599998888', 'Otra Calle 2');
        Elector::query()->whereKey($propio->id)->update(['nombre' => 'Ana María López Ruiz', 'evento_id' => $evento->id]);
        Elector::query()->whereKey($ajeno->id)->update(['nombre' => 'Beto Sánchez Díaz', 'evento_id' => $evento->id]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/eventos/{$evento->id}/asistentes");

        $response->assertOk();
        $porId = collect($response->json('data'))->keyBy('id');
        $this->assertSame('Ana María López Ruiz', $porId[$propio->id]['nombre']);
        $this->assertSame('B. Sánchez D.', $porId[$ajeno->id]['nombre']);
    }

    public function test_coordinador_ve_pii_completa_de_cualquier_elector(): void
    {
        [$tenant, , $seccion, $aviso] = $this->setupCampana();
        [$user] = $this->miembro($tenant, 'coordinador');
        [, $brigadista] = $this->miembro($tenant, 'brigadista');
        $elector = $this->elector($tenant, $brigadista, $seccion, $aviso, '5511112222', 'Calle Falsa 123');

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/electores/{$elector->id}");

        $response->assertOk();
        $response->assertJsonPath('telefono', '5511112222');
        $response->assertJsonPath('domicilio', 'Calle Falsa 123');
    }

    /**
     * Los asistentes de un evento son la lista compartida que sí alcanza el
     * brigadista: ahí conviven capturas suyas y de otros miembros.
     */
    public function test_lista_compartida_enmascara_telefono_de_ajenos_para_brigadista(): void
    {
        [$tenant, , $seccion, $aviso] = $this->setupCampana();
        [$user, $membership] = $this->miembro($tenant, 'brigadista');
        [, $otro] = $this->miembro($tenant, 'brigadista');
        // El viewer necesita la sección asignada para ver la lista del evento (A2).
        $membership->secciones()->attach($seccion->id, ['tenant_id' => $tenant->id]);

        $evento = Evento::factory()->create(['tenant_id' => $tenant->id, 'seccion_id' => $seccion->id]);
        $propio = $this->elector($tenant, $membership, $seccion, $aviso, '5511112222', 'Mi Calle 1');
        $ajeno = $this->elector($tenant, $otro, $seccion, $aviso, '5599998888', 'Otra Calle 2');
        Elector::query()->whereKey([$propio->id, $ajeno->id])->update(['evento_id' => $evento->id]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/eventos/{$evento->id}/asistentes");

        $response->assertOk();
        $porId = collect($response->json('data'))->keyBy('id');
        $this->assertSame('5511112222', $porId[$propio->id]['telefono']);
        $this->assertNotSame('5599998888', $porId[$ajeno->id]['telefono']);
        $this->assertStringContainsString('8888', $porId[$ajeno->id]['telefono']);
    }

    /**
     * El acceso al timeline se decide por zona (`puedeAccederElector`), pero la
     * visibilidad de la PII se decide por propiedad (`puedeVerPii`). El timeline
     * entregaba `nota` en claro a un brigadista que veía `observaciones`
     * enmascaradas del mismo elector: la nota es texto libre del equipo de campo
     * y es donde más caen datos del art. 9.
     */
    public function test_brigadista_no_ve_la_nota_del_timeline_de_un_elector_ajeno(): void
    {
        [$tenant, , $seccion, $aviso] = $this->setupCampana();
        [$user, $membership] = $this->miembro($tenant, 'brigadista');
        [, $otro] = $this->miembro($tenant, 'brigadista');
        $membership->secciones()->attach($seccion->id, ['tenant_id' => $tenant->id]);

        $elector = $this->elector($tenant, $otro, $seccion, $aviso, '5511112222', 'Calle Falsa 123');
        Interaccion::create([
            'elector_id' => $elector->id,
            'membership_id' => $otro->id,
            'tipo' => 'visita',
            'nota' => 'Padece diabetes, atender por la tarde',
            'fecha' => now(),
        ]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/electores/{$elector->id}/interacciones");

        $response->assertOk();
        $this->assertStringNotContainsString('diabetes', json_encode($response->json()));
    }

    public function test_brigadista_si_ve_la_nota_del_timeline_de_su_propio_elector(): void
    {
        [$tenant, , $seccion, $aviso] = $this->setupCampana();
        [$user, $membership] = $this->miembro($tenant, 'brigadista');
        $membership->secciones()->attach($seccion->id, ['tenant_id' => $tenant->id]);

        $elector = $this->elector($tenant, $membership, $seccion, $aviso, '5511112222', 'Calle Falsa 123');
        Interaccion::create([
            'elector_id' => $elector->id,
            'membership_id' => $membership->id,
            'tipo' => 'visita',
            'nota' => 'Quedó de apoyar el sábado',
            'fecha' => now(),
        ]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/electores/{$elector->id}/interacciones");

        $response->assertOk();
        $response->assertJsonPath('0.nota', 'Quedó de apoyar el sábado');
    }

    /**
     * La agenda devolvía el nombre completo del elector cuando las listas de
     * capturados ya lo reducían a iniciales (P6): mismo dato, otra superficie.
     */
    public function test_la_agenda_enmascara_el_nombre_de_un_elector_ajeno(): void
    {
        [$tenant, , $seccion, $aviso] = $this->setupCampana();
        [$user, $membership] = $this->miembro($tenant, 'coordinador');
        [, $otro] = $this->miembro($tenant, 'brigadista');

        $elector = $this->elector($tenant, $otro, $seccion, $aviso, '5511112222', 'Calle Falsa 123');
        $elector->update(['nombre' => 'Juan Carlos Pérez García']);

        Interaccion::create([
            'elector_id' => $elector->id,
            'membership_id' => $otro->id,
            'tipo' => 'llamada',
            'nota' => 'Sin contestar',
            'fecha' => now()->subDays(3),
            'proximo_seguimiento' => now()->subDay()->toDateString(),
        ]);

        // El coordinador es gestión: ve la PII completa.
        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson('/api/agenda')
            ->assertOk()
            ->assertJsonPath('data.0.elector.nombre', 'Juan Carlos Pérez García');

        // El brigadista ajeno con la zona asignada, no: mismo criterio que las listas.
        [$brigadista, $suMembership] = $this->miembro($tenant, 'brigadista');
        $suMembership->secciones()->attach($seccion->id, ['tenant_id' => $tenant->id]);
        Interaccion::query()->update(['membership_id' => $suMembership->id]);

        $response = $this->actingAs($brigadista)->withSession(['tenant_id' => $tenant->id])
            ->getJson('/api/agenda');

        $response->assertOk();
        $this->assertNotSame('Juan Carlos Pérez García', $response->json('data.0.elector.nombre'));
        $this->assertSame('J. C. Pérez G.', $response->json('data.0.elector.nombre'));
    }
}
