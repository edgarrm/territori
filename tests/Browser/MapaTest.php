<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class MapaTest extends DuskTestCase
{
    /**
     * El mapa de cobertura carga con el panel de la campaña y el lienzo Leaflet.
     */
    public function test_el_mapa_de_cobertura_carga(): void
    {
        [$tenant, $user] = $this->crearCampana('admin');

        $this->browse(function (Browser $browser) use ($user, $tenant) {
            $browser->loginAs($user)
                ->visit('/mapa')
                ->waitForText('Mapa de cobertura')
                ->assertSee($tenant->nombre)
                ->waitFor('.leaflet-container');
        });
    }
}
