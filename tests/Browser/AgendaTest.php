<?php

namespace Tests\Browser;

use App\Models\Elector;
use App\Models\Interaccion;
use App\Models\Seccion;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class AgendaTest extends DuskTestCase
{
    /**
     * Marcar un seguimiento como atendido lo saca de la agenda y lo cierra en BD.
     */
    public function test_marcar_seguimiento_atendido(): void
    {
        [$tenant, $user, $membership, $municipio] = $this->crearCampana('admin');
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->orderBy('numero')->firstOrFail();

        $elector = Elector::factory()->create([
            'tenant_id' => $tenant->id,
            'seccion_id' => $seccion->id,
            'membership_id' => $membership->id,
            'nombre' => 'Pendiente Tester',
        ]);
        $interaccion = Interaccion::factory()->pendienteHoy()->create([
            'tenant_id' => $tenant->id,
            'elector_id' => $elector->id,
            'membership_id' => $membership->id,
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/agenda')
                ->waitForText('Pendiente Tester')
                ->press('Atendido')
                ->waitForText('No tienes seguimientos pendientes');
        });

        $this->assertDatabaseMissing('interacciones', [
            'id' => $interaccion->id,
            'atendido_en' => null,
        ]);
    }
}
