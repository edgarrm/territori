<?php

namespace Tests\Feature\Campana;

use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El cierre de campaña es el disparador de la retención por finalidad agotada
 * (LFPDPPP art. 11): sin él, `estado = 'cerrada'` solo se podía poner con un
 * UPDATE a mano, y un control legal que depende de que alguien recuerde correr
 * SQL no se ejecuta.
 */
class CerrarCampanaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Tenant}
     */
    private function adminConCampana(string $rol = 'admin', bool $con2fa = true): array
    {
        $municipio = Municipio::factory()->create();
        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id, 'estado' => 'activa']);
        $user = $con2fa ? User::factory()->withTwoFactor()->create() : User::factory()->create();
        Membership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'rol' => $rol,
            'activo' => true,
        ]);

        return [$user, $tenant];
    }

    public function test_admin_cierra_la_campana_y_arranca_el_reloj_de_retencion(): void
    {
        [$user, $tenant] = $this->adminConCampana();

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'auth.password_confirmed_at' => time()])
            ->patch(route('campanas.cerrar'))
            ->assertRedirect(route('campanas.seleccionar'));

        $tenant->refresh();
        $this->assertSame('cerrada', $tenant->estado);
        $this->assertNotNull($tenant->cerrada_en);
    }

    public function test_cerrar_deja_traza_en_la_bitacora(): void
    {
        [$user, $tenant] = $this->adminConCampana();

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'auth.password_confirmed_at' => time()])
            ->patch(route('campanas.cerrar'));

        $this->assertDatabaseHas('bitacora_accesos', [
            'tenant_id' => $tenant->id,
            'accion' => 'campana.cerrada',
            'recurso_tipo' => 'tenant',
            'recurso_id' => $tenant->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_cerrar_es_idempotente_y_no_reinicia_el_reloj(): void
    {
        [$user, $tenant] = $this->adminConCampana();
        $sesion = fn () => $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'auth.password_confirmed_at' => time()]);

        $sesion()->patch(route('campanas.cerrar'));
        $cerradaEn = $tenant->refresh()->cerrada_en;

        // Recerrar no debe empujar la fecha hacia adelante: eso aplazaría el
        // borrado de la PII, que es justo lo que este control persigue.
        $this->travel(2)->days();
        $sesion()->patch(route('campanas.cerrar'));

        $this->assertEquals($cerradaEn, $tenant->refresh()->cerrada_en);
    }

    public function test_un_coordinador_no_puede_cerrar_la_campana(): void
    {
        [$user, $tenant] = $this->adminConCampana('coordinador');

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'auth.password_confirmed_at' => time()])
            ->patch(route('campanas.cerrar'))
            ->assertForbidden();

        $this->assertSame('activa', $tenant->refresh()->estado);
    }

    public function test_cerrar_exige_2fa_confirmada(): void
    {
        [$user, $tenant] = $this->adminConCampana(con2fa: false);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'auth.password_confirmed_at' => time()])
            ->patch(route('campanas.cerrar'))
            ->assertForbidden();

        $this->assertSame('activa', $tenant->refresh()->estado);
    }

    public function test_cerrar_exige_reconfirmar_la_contrasena(): void
    {
        [$user, $tenant] = $this->adminConCampana();

        // Sin `auth.password_confirmed_at` en sesión.
        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->patch(route('campanas.cerrar'))
            ->assertRedirect(route('password.confirm'));

        $this->assertSame('activa', $tenant->refresh()->estado);
    }
}
