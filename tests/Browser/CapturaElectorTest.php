<?php

namespace Tests\Browser;

use App\Models\Seccion;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class CapturaElectorTest extends DuskTestCase
{
    /**
     * Captura individual de un elector vía la UI; queda persistido.
     */
    public function test_captura_individual_crea_un_elector(): void
    {
        [$tenant, $user, , $municipio] = $this->crearCampana('admin');
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->firstOrFail();

        $this->browse(function (Browser $browser) use ($user, $seccion) {
            $browser->loginAs($user)
                ->visit('/captura')
                ->waitForText('Captura de electores')
                ->press('Individual')
                ->waitFor('@captura-seccion')
                ->select('@captura-seccion', (string) $seccion->id)
                ->type('@captura-nombre', 'Dusk Tester')
                ->type('@captura-telefono', '5512345678')
                ->check('@captura-consentimiento')
                ->click('@captura-guardar')
                ->waitForText('Elector capturado.');
        });

        $this->assertDatabaseHas('electores', [
            'nombre' => 'Dusk Tester',
            'tenant_id' => $tenant->id,
            'seccion_id' => $seccion->id,
        ]);
    }

    /**
     * Un brigadista con una zona asignada captura en ella correctamente.
     */
    public function test_brigadista_captura_en_su_zona_asignada(): void
    {
        [$tenant, $user, $membership, $municipio] = $this->crearCampana('brigadista');
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->firstOrFail();
        $membership->secciones()->attach($seccion->id, ['tenant_id' => $tenant->id]);

        $this->browse(function (Browser $browser) use ($user, $seccion) {
            $browser->loginAs($user)
                ->visit('/captura')
                ->waitForText('Captura de electores')
                ->press('Individual')
                ->waitFor('@captura-seccion')
                ->select('@captura-seccion', (string) $seccion->id)
                ->type('@captura-nombre', 'Brigada Zona')
                ->type('@captura-telefono', '5512349999')
                ->check('@captura-consentimiento')
                ->click('@captura-guardar')
                ->waitForText('Elector capturado.');
        });

        $this->assertDatabaseHas('electores', [
            'nombre' => 'Brigada Zona',
            'tenant_id' => $tenant->id,
            'seccion_id' => $seccion->id,
        ]);
    }
}
