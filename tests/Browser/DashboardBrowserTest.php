<?php

namespace Tests\Browser;

use App\Models\CoberturaSeccion;
use App\Models\Seccion;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class DashboardBrowserTest extends DuskTestCase
{
    /**
     * El admin ve las tarjetas KPI de la campaña con datos reales.
     */
    public function test_admin_ve_las_tarjetas_kpi(): void
    {
        [$tenant, $user, , $municipio] = $this->crearCampana('admin');
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->firstOrFail();
        CoberturaSeccion::factory()->create([
            'tenant_id' => $tenant->id,
            'seccion_id' => $seccion->id,
            'capturados' => 42,
            'meta' => 100,
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/dashboard')
                ->waitFor('@dashboard-kpis')
                ->assertSeeIn('@kpi-capturados', 'Capturados')
                ->assertSeeIn('@kpi-capturados', '42')
                ->assertSeeIn('@kpi-avance', '42%')
                ->assertPresent('@dashboard-eventos')
                ->assertPresent('@dashboard-seguimientos');
        });
    }
}
