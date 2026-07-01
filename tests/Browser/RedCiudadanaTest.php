<?php

namespace Tests\Browser;

use App\Models\RedCiudadana;
use App\Support\Tenancy\TenantContext;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class RedCiudadanaTest extends DuskTestCase
{
    /**
     * El rol enlace inicia sesión y aterriza en sus redes ciudadanas (no en el
     * dashboard); el menú lateral queda restringido a esa única sección.
     */
    public function test_enlace_inicia_sesion_y_aterriza_en_redes_ciudadanas(): void
    {
        [, $user] = $this->crearCampana('enlace');

        $this->browse(function (Browser $browser) use ($user) {
            $browser->visit('/login')
                ->type('email', $user->email)
                ->type('password', 'password')
                ->press('Iniciar sesión')
                ->waitForLocation('/redes-ciudadanas')
                ->assertPathIs('/redes-ciudadanas')
                ->assertSee('Redes ciudadanas')
                ->assertDontSee('Mapa');
        });
    }

    /**
     * El rol enlace no puede entrar al resto del dominio (dashboard).
     */
    public function test_enlace_no_accede_al_dashboard(): void
    {
        [, $user] = $this->crearCampana('enlace');

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/dashboard')
                ->assertSee('403');
        });
    }

    /**
     * El enlace captura un registro en su red desde la UI (modo red_ciudadana).
     */
    public function test_enlace_captura_registro_en_su_red(): void
    {
        [$tenant, $user, $membership] = $this->crearCampana('enlace');

        TenantContext::set($tenant);
        $red = RedCiudadana::factory()->create([
            'tenant_id' => $tenant->id,
            'enlace_membership_id' => $membership->id,
            'nombre' => 'Red Vecinal E2E',
        ]);

        $this->browse(function (Browser $browser) use ($user, $red) {
            $browser->loginAs($user)
                ->visit('/redes-ciudadanas')
                ->waitForText($red->nombre)
                ->press('Agregar registro')
                ->waitFor('[role=dialog]')
                ->within('[role=dialog]', function (Browser $modal) {
                    $modal->type('input[placeholder="Nombre"]', 'Vecino E2E')
                        ->type('input[placeholder="Teléfono (10 dígitos)"]', '5518889999')
                        ->check('input[type=checkbox]')
                        ->press('Guardar registro');
                })
                ->waitUntilMissing('[role=dialog]');
        });

        $this->assertDatabaseHas('electores', [
            'nombre' => 'Vecino E2E',
            'tenant_id' => $tenant->id,
            'modo_captura' => 'red_ciudadana',
            'red_ciudadana_id' => $red->id,
        ]);
    }
}
