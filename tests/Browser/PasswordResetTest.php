<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class PasswordResetTest extends DuskTestCase
{
    /**
     * Solicitar el enlace de restablecimiento muestra el mensaje de estado.
     */
    public function test_solicitar_enlace_de_restablecimiento(): void
    {
        [, $user] = $this->crearCampana('admin');

        $this->browse(function (Browser $browser) use ($user) {
            $browser->visit('/forgot-password')
                ->waitForText('Olvidé mi contraseña')
                ->type('email', $user->email)
                ->press('Enviar enlace de restablecimiento')
                ->waitFor('.text-green-600');
        });
    }
}
