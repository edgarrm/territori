<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class LoteriaTest extends DuskTestCase
{
    /**
     * Crear una lotería desde la UI la agrega a la lista.
     */
    public function test_crear_una_loteria_la_muestra_en_la_lista(): void
    {
        [$tenant, $user] = $this->crearCampana('admin');

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/loterias')
                ->waitFor('@loteria-nombre')
                ->type('@loteria-nombre', 'Lotería Dusk')
                ->keys('@loteria-fecha', '08', '01', '2026')
                ->click('@loteria-crear')
                ->waitForText('Lotería Dusk')
                ->assertSee('Lotería Dusk');
        });

        $this->assertDatabaseHas('loterias', [
            'nombre' => 'Lotería Dusk',
            'tenant_id' => $tenant->id,
        ]);
    }
}
