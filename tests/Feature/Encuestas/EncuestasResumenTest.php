<?php

namespace Tests\Feature\Encuestas;

use App\Models\Encuesta;
use App\Models\EncuestaPregunta;
use App\Models\EncuestaRespuestaAgregada;
use App\Models\Membership;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Zona;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F10: visualización de encuestas (mapa + ficha). Cubre los 7 criterios de
 * aceptación de `_ai/specs/encuestas-visualizacion.spec.md`.
 */
class EncuestasResumenTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: User, 2: Membership}
     */
    private function setupCampana(string $rol = 'coordinador'): array
    {
        $this->withoutVite();

        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $membership = Membership::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'rol' => $rol,
        ]);
        TenantContext::set($tenant);

        return [$tenant, $user, $membership];
    }

    /**
     * @return array{0: Encuesta, 1: EncuestaPregunta}
     */
    private function crearEncuestaConUnaPregunta(Tenant $tenant, array $overrides = []): array
    {
        TenantContext::set($tenant);

        $encuesta = Encuesta::factory()->create(array_merge(['ambito_tipo' => 'seccion'], $overrides));

        $pregunta = EncuestaPregunta::factory()->create([
            'encuesta_id' => $encuesta->id,
            'etiqueta' => '¿Apoya la obra del parque?',
            'tipo' => 'opcion_unica',
            'opciones' => ['Sí', 'No', 'No sabe'],
            'orden' => 1,
        ]);

        return [$encuesta, $pregunta];
    }

    private function tabular(Encuesta $encuesta, EncuestaPregunta $pregunta, Seccion $seccion, string $opcion): void
    {
        $fila = EncuestaRespuestaAgregada::query()
            ->where('encuesta_id', $encuesta->id)
            ->where('pregunta_id', $pregunta->id)
            ->where('ambito_tipo', 'seccion')
            ->where('ambito_id', $seccion->id)
            ->where('opcion', $opcion)
            ->first();

        if ($fila !== null) {
            $fila->update(['conteo' => $fila->conteo + 1]);

            return;
        }

        EncuestaRespuestaAgregada::create([
            'encuesta_id' => $encuesta->id,
            'pregunta_id' => $pregunta->id,
            'ambito_tipo' => 'seccion',
            'ambito_id' => $seccion->id,
            'opcion' => $opcion,
            'conteo' => 1,
        ]);
    }

    // --- 1. Desglose por sección con conteo y % sobre el total de esa pregunta+sección ---
    public function test_resumen_devuelve_desglose_con_conteo_y_porcentaje_por_seccion(): void
    {
        [$tenant, $user] = $this->setupCampana();
        $s1 = Seccion::factory()->create(['municipio_id' => $tenant->municipio_id]);
        $s2 = Seccion::factory()->create(['municipio_id' => $tenant->municipio_id]);
        $s3 = Seccion::factory()->create(['municipio_id' => $tenant->municipio_id]);
        [$encuesta, $pregunta] = $this->crearEncuestaConUnaPregunta($tenant);

        // Sección 1: 3 "Sí", 1 "No" → Sí 75%, No 25%.
        $this->tabular($encuesta, $pregunta, $s1, 'Sí');
        $this->tabular($encuesta, $pregunta, $s1, 'Sí');
        $this->tabular($encuesta, $pregunta, $s1, 'Sí');
        $this->tabular($encuesta, $pregunta, $s1, 'No');

        // Sección 2: 1 "No sabe" → 100%.
        $this->tabular($encuesta, $pregunta, $s2, 'No sabe');

        $respuesta = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/encuestas/{$encuesta->id}/resumen?ambito=seccion")
            ->assertOk();

        $resultados = collect($respuesta->json('resultados'))->keyBy('ambito_id');

        $preguntaS1 = $resultados[$s1->id]['preguntas'][0];
        $this->assertSame(4, $preguntaS1['total_respuestas']);
        $opcionesS1 = collect($preguntaS1['opciones'])->keyBy('opcion');
        $this->assertSame(3, $opcionesS1['Sí']['conteo']);
        $this->assertEqualsWithDelta(0.75, $opcionesS1['Sí']['pct'], 0.0001);
        $this->assertSame(1, $opcionesS1['No']['conteo']);
        $this->assertEqualsWithDelta(0.25, $opcionesS1['No']['pct'], 0.0001);
        // Opción sin ninguna respuesta en esta sección: conteo 0, no se omite.
        $this->assertSame(0, $opcionesS1['No sabe']['conteo']);

        $preguntaS2 = $resultados[$s2->id]['preguntas'][0];
        $this->assertSame(1, $preguntaS2['total_respuestas']);
        $opcionesS2 = collect($preguntaS2['opciones'])->keyBy('opcion');
        $this->assertEqualsWithDelta(1.0, $opcionesS2['No sabe']['pct'], 0.0001);

        // Referencia a $s3 para que PHPStan no la marque como no usada: se
        // valida en el siguiente test (criterio 2).
        $this->assertNotNull($s3);
    }

    // --- 2. Sección sin ninguna respuesta: aparece con conteos en 0, no se omite ---
    public function test_seccion_sin_respuestas_aparece_con_conteos_en_cero(): void
    {
        [$tenant, $user] = $this->setupCampana();
        $conRespuestas = Seccion::factory()->create(['municipio_id' => $tenant->municipio_id]);
        $sinRespuestas = Seccion::factory()->create(['municipio_id' => $tenant->municipio_id]);
        [$encuesta, $pregunta] = $this->crearEncuestaConUnaPregunta($tenant);

        $this->tabular($encuesta, $pregunta, $conRespuestas, 'Sí');

        $respuesta = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/encuestas/{$encuesta->id}/resumen?ambito=seccion")
            ->assertOk();

        $resultados = collect($respuesta->json('resultados'))->keyBy('ambito_id');

        // La sección sin respuestas SÍ aparece en el listado.
        $this->assertTrue($resultados->has($sinRespuestas->id));

        $preguntaSinRespuestas = $resultados[$sinRespuestas->id]['preguntas'][0];
        $this->assertSame(0, $preguntaSinRespuestas['total_respuestas']);
        foreach ($preguntaSinRespuestas['opciones'] as $opcion) {
            $this->assertSame(0, $opcion['conteo']);
            // json_encode(0.0) serializa como `0` (no `0.0`): comparación
            // numérica laxa, no assertSame, para no depender de esa forma.
            $this->assertEquals(0.0, $opcion['pct']);
        }
    }

    // --- 3/4. Datos para colorear el mapa: opción mayoritaria (categórico) y % de una opción (gradiente) ---
    public function test_resumen_trae_la_opcion_mayoritaria_primero_para_el_modo_categorico(): void
    {
        [$tenant, $user] = $this->setupCampana();
        $seccion = Seccion::factory()->create(['municipio_id' => $tenant->municipio_id]);
        [$encuesta, $pregunta] = $this->crearEncuestaConUnaPregunta($tenant);

        $this->tabular($encuesta, $pregunta, $seccion, 'No');
        $this->tabular($encuesta, $pregunta, $seccion, 'Sí');
        $this->tabular($encuesta, $pregunta, $seccion, 'Sí');

        $respuesta = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/encuestas/{$encuesta->id}/resumen?ambito=seccion")
            ->assertOk();

        $resultados = collect($respuesta->json('resultados'))->keyBy('ambito_id');
        $opciones = $resultados[$seccion->id]['preguntas'][0]['opciones'];

        // Ordenadas por conteo descendente: la opción mayoritaria ("Sí") va
        // primero, tal como consume el modo categórico del mapa.
        $this->assertSame('Sí', $opciones[0]['opcion']);
        $this->assertSame(2, $opciones[0]['conteo']);
        $this->assertEqualsWithDelta(2 / 3, $opciones[0]['pct'], 0.0001);
    }

    public function test_resumen_trae_el_pct_de_cada_opcion_para_el_modo_gradiente(): void
    {
        [$tenant, $user] = $this->setupCampana();
        $seccion = Seccion::factory()->create(['municipio_id' => $tenant->municipio_id]);
        [$encuesta, $pregunta] = $this->crearEncuestaConUnaPregunta($tenant);

        $this->tabular($encuesta, $pregunta, $seccion, 'No');
        $this->tabular($encuesta, $pregunta, $seccion, 'Sí');
        $this->tabular($encuesta, $pregunta, $seccion, 'Sí');
        $this->tabular($encuesta, $pregunta, $seccion, 'Sí');

        $respuesta = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/encuestas/{$encuesta->id}/resumen?ambito=seccion")
            ->assertOk();

        $resultados = collect($respuesta->json('resultados'))->keyBy('ambito_id');
        $opciones = collect($resultados[$seccion->id]['preguntas'][0]['opciones'])->keyBy('opcion');

        // El % de "No" (una opción cualquiera, no necesariamente la
        // mayoritaria) es lo que consume el modo gradiente del mapa.
        $this->assertEqualsWithDelta(0.25, $opciones['No']['pct'], 0.0001);
    }

    // --- 5. Ficha de sección: card "Encuesta: {nombre}" con respuestas en esa sección ---
    public function test_resumen_de_seccion_incluye_encuestas_activas_con_respuestas(): void
    {
        [$tenant, $user] = $this->setupCampana();
        $seccion = Seccion::factory()->create(['municipio_id' => $tenant->municipio_id]);
        [$encuesta, $pregunta] = $this->crearEncuestaConUnaPregunta($tenant, ['nombre' => 'Encuesta de obra pública']);
        $this->tabular($encuesta, $pregunta, $seccion, 'Sí');

        // Otra encuesta activa, sin ninguna respuesta en esta sección: no debe aparecer.
        [$otraEncuesta] = $this->crearEncuestaConUnaPregunta($tenant, ['nombre' => 'Encuesta sin respuestas']);

        $respuesta = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/secciones/{$seccion->id}/resumen")
            ->assertOk();

        $encuestas = $respuesta->json('encuestas');
        $this->assertCount(1, $encuestas);
        $this->assertSame($encuesta->id, $encuestas[0]['encuesta_id']);
        $this->assertNotSame($otraEncuesta->id, $encuestas[0]['encuesta_id']);
        $this->assertSame('Encuesta de obra pública', $encuestas[0]['nombre']);
        $this->assertSame(1, $encuestas[0]['preguntas'][0]['total_respuestas']);
    }

    // Misma card, pero para la ficha de zona (ZonaController::resumen).
    public function test_resumen_de_zona_incluye_encuestas_activas_de_ambito_zona_con_respuestas(): void
    {
        [$tenant, $user] = $this->setupCampana();
        $zona = Zona::factory()->create();
        [$encuesta, $pregunta] = $this->crearEncuestaConUnaPregunta($tenant, [
            'nombre' => 'Encuesta de zona',
            'ambito_tipo' => 'zona',
        ]);

        $fila = EncuestaRespuestaAgregada::create([
            'encuesta_id' => $encuesta->id,
            'pregunta_id' => $pregunta->id,
            'ambito_tipo' => 'zona',
            'ambito_id' => $zona->id,
            'opcion' => 'Sí',
            'conteo' => 5,
        ]);
        $this->assertNotNull($fila);

        $respuesta = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/zonas/{$zona->id}/resumen")
            ->assertOk();

        $encuestas = $respuesta->json('encuestas');
        $this->assertCount(1, $encuestas);
        $this->assertSame('Encuesta de zona', $encuestas[0]['nombre']);
    }

    // --- 6. Encuesta pausada: no aparece como seleccionable en el mapa ---
    public function test_encuesta_pausada_no_aparece_entre_las_disponibles_del_mapa(): void
    {
        [$tenant, $user] = $this->setupCampana();
        $this->crearEncuestaConUnaPregunta($tenant, ['nombre' => 'Encuesta activa']);
        $this->crearEncuestaConUnaPregunta($tenant, ['nombre' => 'Encuesta pausada', 'estado' => 'pausada']);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get('/mapa')
            ->assertInertia(fn ($page) => $page
                ->component('Mapa')
                ->has('encuestasDisponibles', 1)
                ->where('encuestasDisponibles.0.nombre', 'Encuesta activa')
            );
    }

    // --- 7. Dos tenants con encuestas del mismo nombre: nunca se mezclan ---
    public function test_resumen_nunca_mezcla_datos_entre_tenants(): void
    {
        [$tenantA, $userA] = $this->setupCampana();
        $seccionA = Seccion::factory()->create(['municipio_id' => $tenantA->municipio_id]);
        [$encuestaA, $preguntaA] = $this->crearEncuestaConUnaPregunta($tenantA, ['nombre' => 'Encuesta compartida']);
        $this->tabular($encuestaA, $preguntaA, $seccionA, 'Sí');

        [$tenantB, $userB] = $this->setupCampana();
        $seccionB = Seccion::factory()->create(['municipio_id' => $tenantB->municipio_id]);
        [$encuestaB, $preguntaB] = $this->crearEncuestaConUnaPregunta($tenantB, ['nombre' => 'Encuesta compartida']);
        $this->tabular($encuestaB, $preguntaB, $seccionB, 'No');
        $this->tabular($encuestaB, $preguntaB, $seccionB, 'No');

        // El tenant A no puede leer el resumen de la encuesta del tenant B.
        $this->actingAs($userA)->withSession(['tenant_id' => $tenantA->id])
            ->getJson("/api/encuestas/{$encuestaB->id}/resumen?ambito=seccion")
            ->assertNotFound();

        // El resumen propio del tenant A no trae ni rastro de los conteos de B.
        $respuestaA = $this->actingAs($userA)->withSession(['tenant_id' => $tenantA->id])
            ->getJson("/api/encuestas/{$encuestaA->id}/resumen?ambito=seccion")
            ->assertOk();

        $resultadosA = collect($respuestaA->json('resultados'))->keyBy('ambito_id');
        $opcionesA = collect($resultadosA[$seccionA->id]['preguntas'][0]['opciones'])->keyBy('opcion');
        $this->assertSame(1, $opcionesA['Sí']['conteo']);
        $this->assertSame(0, $opcionesA['No']['conteo']);
    }
}
