<?php

namespace Tests\Feature\Seguridad;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * I8 de la auditoría: coordinador/admin concentran el acceso de más alcance
 * (dashboard de análisis, export del padrón, bandeja ARCO) y hasta ahora
 * podían llegar ahí con solo una contraseña, sin 2FA.
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

    public function test_dashboard_exige_2fa_confirmada(): void
    {
        [$user, $tenant] = $this->coordinadorConCampana(con2fa: false);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('dashboard'))
            ->assertForbidden();
    }

    public function test_dashboard_es_accesible_con_2fa_confirmada(): void
    {
        [$user, $tenant] = $this->coordinadorConCampana(con2fa: true);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('dashboard'))
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
     * Sin 2FA, un admin puede seguir usando el resto de la app (capturados,
     * brigadistas...): el bloqueo es puntual a las pantallas más sensibles,
     * no una cuarentena total de la cuenta.
     */
    public function test_sin_2fa_el_resto_de_la_gestion_sigue_accesible(): void
    {
        [$user, $tenant] = $this->coordinadorConCampana(con2fa: false);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('capturados'))
            ->assertOk();
    }
}
