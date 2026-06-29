<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class EventoTest extends DuskTestCase
{
    /**
     * Crear un evento desde la UI lo agrega a la lista.
     */
    public function test_crear_un_evento_lo_muestra_en_la_lista(): void
    {
        [$tenant, $user] = $this->crearCampana('admin');

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/eventos')
                ->waitFor('@evento-nombre')
                ->type('@evento-nombre', 'Mitin Dusk')
                ->select('@evento-tipo', 'reunion')
                ->keys('@evento-fecha', '08', '01', '2026')
                ->type('@evento-lugar', 'Plaza Dusk')
                ->click('@evento-crear')
                ->waitForText('Mitin Dusk')
                ->assertSee('Mitin Dusk');
        });

        $this->assertDatabaseHas('eventos', [
            'nombre' => 'Mitin Dusk',
            'tenant_id' => $tenant->id,
            'tipo' => 'reunion',
        ]);
    }
}
