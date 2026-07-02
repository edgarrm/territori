<?php

namespace Tests\Browser;

use App\Models\Seccion;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class LoteriaTest extends DuskTestCase
{
    /**
     * Crear una lotería desde la UI la agrega a la lista.
     */
    public function test_crear_una_loteria_la_muestra_en_la_lista(): void
    {
        [$tenant, $user] = $this->crearCampana('admin');

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/loterias')
                ->waitFor('@loteria-nombre')
                ->type('@loteria-nombre', 'Lotería Dusk')
                ->keys('@loteria-fecha', '08', '01', '2026')
                ->click('@loteria-crear')
                ->waitForText('Lotería Dusk')
                ->assertSee('Lotería Dusk');
        });

        $this->assertDatabaseHas('loterias', [
            'nombre' => 'Lotería Dusk',
            'tenant_id' => $tenant->id,
        ]);
    }

    /**
     * El anfitrión aterriza en /loterias tras login, crea una lotería en su
     * zona y captura un elector desde el modal de la lista.
     */
    public function test_anfitrion_crea_loteria_y_captura_desde_el_modal(): void
    {
        [$tenant, $user, $membership, $municipio] = $this->crearCampana('anfitrion');
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->orderBy('numero')->firstOrFail();
        $membership->secciones()->attach($seccion->id, ['tenant_id' => $tenant->id]);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/loterias')
                ->waitFor('@loteria-nombre')
                ->type('@loteria-nombre', 'Lotería Anfitrión Dusk')
                ->keys('@loteria-fecha', '08', '01', '2026')
                ->click('@loteria-crear')
                ->waitForText('Lotería Anfitrión Dusk')
                ->click('@loteria-capturar')
                ->waitFor('@captura-loteria-nombre')
                ->type('@captura-loteria-nombre', 'Invitado Dusk')
                ->type('@captura-loteria-telefono', '5522223333')
                ->check('@captura-loteria-consentimiento')
                ->click('@captura-loteria-guardar')
                ->waitForText('Elector capturado.');
        });

        $this->assertDatabaseHas('loterias', [
            'nombre' => 'Lotería Anfitrión Dusk',
            'tenant_id' => $tenant->id,
            'membership_id' => $membership->id,
        ]);

        $this->assertDatabaseHas('electores', [
            'nombre' => 'Invitado Dusk',
            'tenant_id' => $tenant->id,
            'modo_captura' => 'loteria',
        ]);
    }
}
