<?php

namespace Tests\Browser;

use App\Models\Seccion;
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

    /**
     * El perfil del brigadista es de captura: las vistas de análisis quedan
     * fuera de su alcance.
     */
    public function test_brigadista_no_accede_a_las_vistas_de_analisis(): void
    {
        [$tenant, $user, $membership, $municipio] = $this->crearCampana('brigadista');
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->firstOrFail();
        $membership->secciones()->attach($seccion->id, ['tenant_id' => $tenant->id]);

        $this->browse(function (Browser $browser) use ($user, $seccion) {
            $browser->loginAs($user)
                ->visit('/dashboard')->assertSee('403')
                ->visit('/mapa')->assertSee('403')
                ->visit('/prioridades')->assertSee('403')
                ->visit('/secciones/'.$seccion->id)->assertSee('403');
        });
    }

    /**
     * Aterriza en sus capturados y el sidebar no ofrece las vistas de análisis.
     */
    public function test_brigadista_aterriza_en_sus_capturados(): void
    {
        [, $user] = $this->crearCampana('brigadista');

        $this->browse(function (Browser $browser) use ($user) {
            $browser->visit('/login')
                ->type('email', $user->email)
                ->type('password', 'password')
                ->press('Iniciar sesión')
                ->waitForLocation('/mis-capturados')
                ->assertPathIs('/mis-capturados')
                ->assertSee('Mis capturados')
                ->assertDontSee('Mapa')
                ->assertDontSee('Prioridades');
        });
    }
}
