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

    /**
     * En móvil el panel lateral se muestra como cajón inferior: arranca fuera
     * de la pantalla (el mapa la ocupa toda) y se abre desde la barra compacta.
     */
    public function test_en_movil_el_panel_se_muestra_como_cajon_inferior(): void
    {
        [, $user] = $this->crearCampana('admin');

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->resize(...$this->viewportMovil)
                ->visit('/mapa')
                ->waitFor('.leaflet-container')
                ->assertVisible('@mapa-barra-movil');

            $this->assertFalse(
                $this->panelDentroDelViewport($browser),
                'El cajón debería arrancar fuera de la pantalla.'
            );

            $browser->click('@mapa-abrir-panel')
                ->pause(600)
                // El rótulo se muestra en mayúsculas por CSS (`uppercase`).
                ->assertSeeIn('@mapa-panel', 'AVANCE GLOBAL');

            $this->assertTrue(
                $this->panelDentroDelViewport($browser),
                'El cajón debería estar visible tras pulsar el botón.'
            );

            $browser->click('@mapa-cerrar-panel')->pause(600);

            $this->assertFalse(
                $this->panelDentroDelViewport($browser),
                'El cajón debería cerrarse al pulsar «Cerrar».'
            );

            $this->assertSinScrollHorizontal($browser);
        });
    }

    /**
     * El cajón siempre está en el DOM; lo que cambia es su desplazamiento
     * vertical, así que se comprueba contra el alto del viewport.
     */
    private function panelDentroDelViewport(Browser $browser): bool
    {
        return (bool) $browser->script(
            'const p = document.querySelector("[dusk=mapa-panel]");'
            .'return p.getBoundingClientRect().top < window.innerHeight - 1;'
        )[0];
    }
}
