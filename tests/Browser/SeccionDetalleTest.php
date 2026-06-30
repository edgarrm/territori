<?php

namespace Tests\Browser;

use App\Models\Seccion;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class SeccionDetalleTest extends DuskTestCase
{
    /**
     * La página de detalle de una sección carga con su número y cobertura.
     */
    public function test_detalle_de_seccion_carga(): void
    {
        [, $user, , $municipio] = $this->crearCampana('admin');
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->orderBy('numero')->firstOrFail();

        $this->browse(function (Browser $browser) use ($user, $seccion) {
            $browser->loginAs($user)
                ->visit("/secciones/{$seccion->id}")
                ->waitForText("Sección {$seccion->numero}")
                ->assertPathIs("/secciones/{$seccion->id}")
                ->waitForText('Cobertura');
        });
    }
}
