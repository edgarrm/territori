<?php

namespace Tests\Browser;

use App\Models\Seccion;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class MetasTest extends DuskTestCase
{
    /**
     * Editar la meta manual de una sección la persiste.
     */
    public function test_editar_meta_de_seccion(): void
    {
        [$tenant, $user, , $municipio] = $this->crearCampana('coordinador');
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->orderBy('numero')->firstOrFail();

        $this->browse(function (Browser $browser) use ($user, $seccion) {
            $browser->loginAs($user)
                ->visit('/metas')
                ->waitForText('Metas por sección')
                ->waitFor("@meta-capturas-{$seccion->id}")
                ->clear("@meta-capturas-{$seccion->id}")
                ->type("@meta-capturas-{$seccion->id}", '150')
                ->click("@meta-guardar-{$seccion->id}")
                ->pause(1200);
        });

        $this->assertDatabaseHas('metas_seccion', [
            'tenant_id' => $tenant->id,
            'seccion_id' => $seccion->id,
            'meta_capturas' => 150,
        ]);
    }
}
