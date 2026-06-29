<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class LoginTest extends DuskTestCase
{
    /**
     * Con una sola campaña activa, el login auto-selecciona y entra al dashboard.
     */
    public function test_un_usuario_inicia_sesion_y_entra_al_dashboard(): void
    {
        [, $user] = $this->crearCampana('admin');

        $this->browse(function (Browser $browser) use ($user) {
            $browser->visit('/login')
                ->type('email', $user->email)
                ->type('password', 'password')
                ->press('Iniciar sesión')
                ->waitForLocation('/dashboard')
                ->assertPathIs('/dashboard');
        });
    }

    /**
     * Credenciales inválidas mantienen al usuario en el login.
     */
    public function test_credenciales_invalidas_no_inician_sesion(): void
    {
        [, $user] = $this->crearCampana('admin');

        $this->browse(function (Browser $browser) use ($user) {
            $browser->visit('/login')
                ->type('email', $user->email)
                ->type('password', 'incorrecta')
                ->press('Iniciar sesión')
                ->pause(2000)
                ->assertPathIs('/login');
        });
    }
}
