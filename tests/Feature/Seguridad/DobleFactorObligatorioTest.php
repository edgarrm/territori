<?php

namespace Tests\Feature\Seguridad;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * I8 de la auditoría: coordinador/admin concentran el acceso de más alcance
 * (export del padrón, bandeja ARCO, listas completas de capturados) y hasta
 * ahora podían llegar ahí con solo una contraseña, sin 2FA.
 *
 * El criterio es "donde hay datos personales", no "donde hay análisis": el
 * dashboard solo muestra agregados y no lleva 2FA, mientras que /capturados y
 * la lista por sección —nombre y teléfono en claro de todo el padrón— sí. Antes
 * era al revés, y paginar /api/capturados de 25 en 25 obtenía lo mismo que el
 * export sin ninguno de sus controles.
 */
class DobleFactorObligatorioTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{User, Tenant}
     */
    private function coordinadorConCampana(bool $con2fa): array
    {
        $user = $con2fa ? User::factory()->withTwoFactor()->create() : User::factory()->create();
        $tenant = Tenant::factory()->create();
        Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => 'coordinador', 'activo' => true]);

        return [$user, $tenant];
    }

    /**
     * El dashboard expone conteos, metas y próximos eventos: ni un dato
     * personal. Exigirle 2FA no protegía nada y dejaba fuera al coordinador de
     * su propia pantalla de aterrizaje.
     */
    public function test_dashboard_no_exige_2fa_porque_solo_muestra_agregados(): void
    {
        [$user, $tenant] = $this->coordinadorConCampana(con2fa: false);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_lista_de_capturados_exige_2fa_confirmada(): void
    {
        [$user, $tenant] = $this->coordinadorConCampana(con2fa: false);
        $sesion = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id]);

        // La pantalla y el endpoint que la alimenta: sin el segundo, paginar
        // el padrón por XHR seguiría siendo posible sin 2FA.
        $sesion->get(route('capturados'))->assertForbidden();
        $sesion->getJson(route('capturados.data'))->assertForbidden();
    }

    public function test_lista_de_capturados_es_accesible_con_2fa_confirmada(): void
    {
        [$user, $tenant] = $this->coordinadorConCampana(con2fa: true);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('capturados'))
            ->assertOk();
    }

    public function test_export_de_electores_exige_2fa_confirmada(): void
    {
        [$user, $tenant] = $this->coordinadorConCampana(con2fa: false);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('export.electores'))
            ->assertForbidden();
    }

    public function test_bandeja_arco_exige_2fa_confirmada(): void
    {
        [$user, $tenant] = $this->coordinadorConCampana(con2fa: false);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('solicitudes-arco.index'))
            ->assertForbidden();
    }

    /**
     * Sin 2FA, un coordinador puede seguir usando el resto de la app (dashboard,
     * brigadistas, agenda...): el bloqueo es puntual a las superficies con datos
     * personales, no una cuarentena total de la cuenta.
     */
    public function test_sin_2fa_el_resto_de_la_gestion_sigue_accesible(): void
    {
        [$user, $tenant] = $this->coordinadorConCampana(con2fa: false);
        $sesion = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id]);

        $sesion->get(route('dashboard'))->assertOk();
        $sesion->get(route('brigadistas'))->assertOk();
        $sesion->get(route('agenda'))->assertOk();
    }
}
