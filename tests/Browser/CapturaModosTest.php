<?php

namespace Tests\Browser;

use App\Models\Evento;
use App\Models\Loteria;
use App\Models\Seccion;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class CapturaModosTest extends DuskTestCase
{
    /**
     * Modo Lotería: seleccionar una lotería existente y capturar un elector,
     * que hereda su sección y queda ligado a ella.
     */
    public function test_captura_en_modo_loteria(): void
    {
        [$tenant, $user, $membership, $municipio] = $this->crearCampana('admin');
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->orderBy('numero')->firstOrFail();
        $loteria = Loteria::factory()->create([
            'tenant_id' => $tenant->id,
            'membership_id' => $membership->id,
            'seccion_id' => $seccion->id,
            'nombre' => 'Lotería Captura',
        ]);

        $this->browse(function (Browser $browser) use ($user, $loteria) {
            $browser->loginAs($user)
                ->visit('/captura')
                ->waitForText('Captura de electores')
                ->waitFor('@loteria-select')
                ->select('@loteria-select', (string) $loteria->id)
                ->type('input[placeholder="Nombre"]', 'Loteria Tester')
                ->type('input[placeholder="Teléfono (10 dígitos)"]', '5511112222')
                ->check('input[type=checkbox]')
                ->press('Guardar y siguiente')
                ->waitForText('Elector capturado.');
        });

        $this->assertDatabaseHas('electores', [
            'nombre' => 'Loteria Tester',
            'tenant_id' => $tenant->id,
            'modo_captura' => 'loteria',
            'loteria_id' => $loteria->id,
            'seccion_id' => $seccion->id,
        ]);
    }

    /**
     * Modo Evento: capturar un asistente que hereda la sección del evento.
     */
    public function test_captura_en_modo_evento(): void
    {
        [$tenant, $user, , $municipio] = $this->crearCampana('admin');
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->orderBy('numero')->firstOrFail();
        $evento = Evento::factory()->create([
            'tenant_id' => $tenant->id,
            'seccion_id' => $seccion->id,
            'nombre' => 'Mitin Captura',
        ]);

        $this->browse(function (Browser $browser) use ($user, $evento) {
            $browser->loginAs($user)
                ->visit('/captura')
                ->waitForText('Captura de electores')
                ->press('Evento')
                ->waitFor('@captura-evento-select')
                ->select('@captura-evento-select', (string) $evento->id)
                ->type('input[placeholder="Nombre"]', 'Asistente Tester')
                ->type('input[placeholder="Teléfono (10 dígitos)"]', '5513334444')
                ->check('input[type=checkbox]')
                ->press('Guardar asistente')
                ->waitForText('Elector capturado.');
        });

        $this->assertDatabaseHas('electores', [
            'nombre' => 'Asistente Tester',
            'tenant_id' => $tenant->id,
            'modo_captura' => 'evento',
            'evento_id' => $evento->id,
        ]);
    }

    /**
     * Modo Evento sin sede: el evento no tiene sección, así que el formulario
     * debe pedirla y usarla al capturar.
     */
    public function test_captura_en_modo_evento_sin_seccion_pide_la_seccion(): void
    {
        [$tenant, $user, , $municipio] = $this->crearCampana('admin');
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->orderBy('numero')->firstOrFail();
        $evento = Evento::factory()->create([
            'tenant_id' => $tenant->id,
            'seccion_id' => null,
            'nombre' => 'Evento Sin Sede',
        ]);

        $this->browse(function (Browser $browser) use ($user, $evento, $seccion) {
            $browser->loginAs($user)
                ->visit('/captura')
                ->waitForText('Captura de electores')
                ->press('Evento')
                ->waitFor('@captura-evento-select')
                ->select('@captura-evento-select', (string) $evento->id)
                ->waitFor('@captura-evento-seccion')
                ->select('@captura-evento-seccion', (string) $seccion->id)
                ->type('input[placeholder="Nombre"]', 'Asistente Sin Sede')
                ->type('input[placeholder="Teléfono (10 dígitos)"]', '5515556666')
                ->check('input[type=checkbox]')
                ->press('Guardar asistente')
                ->waitForText('Elector capturado.');
        });

        $this->assertDatabaseHas('electores', [
            'nombre' => 'Asistente Sin Sede',
            'tenant_id' => $tenant->id,
            'modo_captura' => 'evento',
            'evento_id' => $evento->id,
            'seccion_id' => $seccion->id,
        ]);
    }
}
