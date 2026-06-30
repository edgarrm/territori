<?php

namespace Tests\Browser;

use App\Models\Evento;
use App\Models\Seccion;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class EventosListaTest extends DuskTestCase
{
    /**
     * La lista de eventos distingue los que tienen sección de los multisección.
     */
    public function test_lista_muestra_seccion_o_multiseccion(): void
    {
        [$tenant, $user, , $municipio] = $this->crearCampana('admin');
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->orderBy('numero')->firstOrFail();

        Evento::factory()->create([
            'tenant_id' => $tenant->id,
            'seccion_id' => $seccion->id,
            'nombre' => 'Evento Con Sede',
        ]);
        Evento::factory()->create([
            'tenant_id' => $tenant->id,
            'seccion_id' => null,
            'nombre' => 'Evento Abierto',
        ]);

        $this->browse(function (Browser $browser) use ($user, $seccion) {
            $browser->loginAs($user)
                ->visit('/eventos')
                ->waitForText('Evento Con Sede')
                ->assertSee('Sección '.$seccion->numero)
                ->assertSee('Multisección');
        });
    }
}
