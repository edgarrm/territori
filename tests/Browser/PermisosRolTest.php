<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class PermisosRolTest extends DuskTestCase
{
    /**
     * Un brigadista no puede entrar a una ruta exclusiva de admin.
     */
    public function test_brigadista_no_accede_a_crear_campana(): void
    {
        [, $user] = $this->crearCampana('brigadista');

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/campanas/crear')
                ->assertSee('403');
        });
    }

    /**
     * Un brigadista no puede entrar a la gestión de brigadistas (coord/admin).
     */
    public function test_brigadista_no_accede_a_gestion_de_brigadistas(): void
    {
        [, $user] = $this->crearCampana('brigadista');

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/brigadistas')
                ->assertSee('403');
        });
    }
}
