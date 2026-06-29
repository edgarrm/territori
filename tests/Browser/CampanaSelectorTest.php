<?php

namespace Tests\Browser;

use App\Models\Membership;
use App\Models\Tenant;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class CampanaSelectorTest extends DuskTestCase
{
    /**
     * Un usuario con varias campañas activas debe elegir una antes de entrar.
     */
    public function test_usuario_con_varias_campanas_elige_una_y_entra(): void
    {
        [$tenant, $user, , $municipio] = $this->crearCampana('admin');
        $tenant2 = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        Membership::factory()->for($tenant2)->for($user)->create(['rol' => 'coordinador', 'activo' => true]);

        $this->browse(function (Browser $browser) use ($user, $tenant) {
            $browser->loginAs($user)
                ->visit('/dashboard')
                ->waitForText('Selecciona una campaña')
                ->assertPathIs('/campanas/seleccionar')
                ->click("@campana-{$tenant->id}")
                ->waitForLocation('/dashboard')
                ->assertPathIs('/dashboard');
        });
    }
}
