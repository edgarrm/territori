<?php

namespace Tests\Browser;

use App\Models\Coalicion;
use App\Models\Elector;
use App\Models\EstadisticaSeccion;
use App\Models\Partido;
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
        // El detalle de sección exige 2FA: incluye la lista de electores (PII).
        $this->confirmarDobleFactor($user);
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->orderBy('numero')->firstOrFail();

        $this->browse(function (Browser $browser) use ($user, $seccion) {
            $browser->loginAs($user)
                ->visit("/secciones/{$seccion->id}")
                ->waitForText("Sección {$seccion->numero}")
                ->assertPathIs("/secciones/{$seccion->id}")
                ->waitForText('Cobertura');
        });
    }

    /**
     * F3: la card de desglose de votos aparece entre "Elección 2024" y
     * "Perfil por edad" (posición exacta pedida por el cliente).
     */
    public function test_card_de_desglose_aparece_entre_eleccion_y_perfil_por_edad(): void
    {
        [$tenant, $user, , $municipio] = $this->crearCampana('admin');
        // El detalle de sección exige 2FA: incluye la lista de electores (PII).
        $this->confirmarDobleFactor($user);
        $partido = Partido::factory()->create(['siglas' => 'MORENA']);
        $tenant->update(['partido_id' => $partido->id]);
        Coalicion::factory()->create(['nombre' => 'Sigamos Haciendo Historia', 'anio' => 2024, 'partidos' => ['MORENA', 'PVEM']]);
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->orderBy('numero')->firstOrFail();
        EstadisticaSeccion::factory()->create([
            'seccion_id' => $seccion->id,
            'ganador_bloque' => 'Morena-PVEM',
            'total_votos' => 116,
            'votos_partidos' => ['MORENA' => 57, 'MC' => 40],
        ]);

        $this->browse(function (Browser $browser) use ($user, $seccion) {
            $browser->loginAs($user)
                ->visit("/secciones/{$seccion->id}")
                ->waitForText('Elección 2024')
                ->waitForText('Votos por partido y coalición (2024)')
                ->waitForText('Perfil por edad');

            $texto = $browser->script('return document.body.innerText;')[0];

            $posEleccion = mb_strpos($texto, 'Elección 2024');
            $posDesglose = mb_strpos($texto, 'Votos por partido y coalición (2024)');
            $posPerfil = mb_strpos($texto, 'Perfil por edad');

            $this->assertNotFalse($posEleccion);
            $this->assertNotFalse($posDesglose);
            $this->assertNotFalse($posPerfil);
            $this->assertTrue($posEleccion < $posDesglose && $posDesglose < $posPerfil);
        });
    }

    /**
     * F4: el botón "Agregar elector" vive en el header de la card "Electores"
     * (ya no en el header de la página) y sigue abriendo el mismo modal.
     */
    public function test_boton_agregar_elector_vive_en_la_card_electores(): void
    {
        [, $user, , $municipio] = $this->crearCampana('admin');
        // El detalle de sección exige 2FA: incluye la lista de electores (PII).
        $this->confirmarDobleFactor($user);
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->orderBy('numero')->firstOrFail();

        $this->browse(function (Browser $browser) use ($user, $seccion) {
            $browser->loginAs($user)
                ->visit("/secciones/{$seccion->id}")
                ->waitForText("Sección {$seccion->numero}")
                ->waitForText('Electores');

            $enHeaderDePagina = $browser->script(
                "return !!document.querySelector('h1')?.closest('div')?.querySelector('button');"
            )[0];
            $this->assertFalse($enHeaderDePagina);

            $enCardElectores = $browser->script(<<<'JS'
                const h2s = [...document.querySelectorAll('h2')];
                const electoresH2 = h2s.find((h) => h.textContent.includes('Electores'));
                return !!electoresH2?.parentElement?.querySelector('button');
            JS)[0];
            $this->assertTrue($enCardElectores);

            $browser->press('Agregar elector')
                ->waitForText("Agregar elector a la sección {$seccion->numero}");
        });
    }

    /**
     * En móvil el detalle cabe a lo ancho y la lista de electores se muestra
     * como tarjetas (la tabla queda oculta debajo de `sm`).
     */
    public function test_el_detalle_es_legible_en_movil(): void
    {
        [$tenant, $user, $membership, $municipio] = $this->crearCampana('admin');
        // El detalle de sección exige 2FA: incluye la lista de electores (PII).
        $this->confirmarDobleFactor($user);
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->orderBy('numero')->firstOrFail();
        Elector::factory()->create([
            'tenant_id' => $tenant->id,
            'seccion_id' => $seccion->id,
            'membership_id' => $membership->id,
            'nombre' => 'Juana Pérez',
        ]);

        $this->browse(function (Browser $browser) use ($user, $seccion) {
            $browser->loginAs($user)
                ->resize(...$this->viewportMovil)
                ->visit("/secciones/{$seccion->id}")
                ->waitForText("Sección {$seccion->numero}")
                ->waitForText('Cobertura')
                ->waitForText('Juana Pérez')
                ->assertMissing('table');

            $this->assertSinScrollHorizontal($browser);
        });
    }
}
