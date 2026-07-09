<?php

namespace Tests\Feature\Desglose;

use App\Models\Coalicion;
use App\Models\EstadisticaSeccion;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Partido;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DesgloseVotosSeccionTest extends TestCase
{
    use RefreshDatabase;

    private function coalicionesFixture(): void
    {
        Coalicion::factory()->create(['nombre' => 'Fuerza y Corazón por México', 'anio' => 2024, 'partidos' => ['PAN', 'PRI', 'PRD', 'PAS']]);
        Coalicion::factory()->create(['nombre' => 'Sigamos Haciendo Historia', 'anio' => 2024, 'partidos' => ['MORENA', 'PVEM']]);
    }

    /**
     * @return array{0: Tenant, 1: User, 2: Municipio}
     */
    private function tenantConPartido(?string $siglas): array
    {
        $municipio = Municipio::factory()->create();
        $partido = $siglas !== null ? Partido::factory()->create(['siglas' => $siglas]) : null;
        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id, 'partido_id' => $partido?->id]);
        $user = User::factory()->create();
        Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => 'coordinador']);

        return [$tenant, $user, $municipio];
    }

    private function votosSeccion2540(): array
    {
        return ['PAN' => 30, 'PRI' => 6, 'PT' => 3, 'MC' => 8, 'PAS' => 5, 'MORENA' => 57, 'PAN_PRI' => 1, 'PAN_PRI_PRD' => 3];
    }

    public function test_resumen_incluye_desglose_agrupado_por_bloque(): void
    {
        $this->coalicionesFixture();
        [$tenant, $user, $municipio] = $this->tenantConPartido('MORENA');
        $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id]);
        EstadisticaSeccion::factory()->create([
            'seccion_id' => $seccion->id,
            'total_votos' => 116,
            'votos_partidos' => $this->votosSeccion2540(),
        ]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/secciones/{$seccion->id}/resumen");

        $response->assertOk();
        $response->assertJsonPath('desglose_2024.total_votos', 116);
        $response->assertJsonPath('desglose_2024.votos_nulos', 3);

        $bloques = collect($response->json('desglose_2024.bloques'))->keyBy('nombre');
        $this->assertSame(45, $bloques['Fuerza y Corazón por México']['total']);
        $this->assertSame(57, $bloques['Sigamos Haciendo Historia']['total']);
        $this->assertTrue($bloques['Sigamos Haciendo Historia']['es_bloque_propio']);
        $this->assertFalse($bloques['Fuerza y Corazón por México']['es_bloque_propio']);

        // Partición: ninguna opción del jsonb queda fuera ni duplicada.
        $todasLasOpciones = $bloques->flatMap(fn (array $b) => collect($b['opciones'])->pluck('clave'));
        $this->assertSame($todasLasOpciones->count(), $todasLasOpciones->unique()->count());
        foreach (array_keys($this->votosSeccion2540()) as $clave) {
            $this->assertContains($clave, $todasLasOpciones);
        }
    }

    public function test_resumen_sin_votos_partidos_devuelve_desglose_null(): void
    {
        [$tenant, $user, $municipio] = $this->tenantConPartido('MORENA');
        $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id]);
        EstadisticaSeccion::factory()->create(['seccion_id' => $seccion->id, 'votos_partidos' => null, 'ganador_bloque' => null]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/secciones/{$seccion->id}/resumen");

        $response->assertOk();
        $response->assertJsonPath('desglose_2024', null);
    }

    public function test_resumen_sin_estadistica_devuelve_desglose_null(): void
    {
        [$tenant, $user, $municipio] = $this->tenantConPartido('MORENA');
        $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/secciones/{$seccion->id}/resumen");

        $response->assertOk();
        $response->assertJsonPath('desglose_2024', null);
    }

    public function test_sin_partido_configurado_ningun_bloque_es_propio(): void
    {
        $this->coalicionesFixture();
        [$tenant, $user, $municipio] = $this->tenantConPartido(null);
        $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id]);
        EstadisticaSeccion::factory()->create([
            'seccion_id' => $seccion->id,
            'total_votos' => 116,
            'votos_partidos' => $this->votosSeccion2540(),
        ]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/secciones/{$seccion->id}/resumen");

        $response->assertOk();
        $bloques = collect($response->json('desglose_2024.bloques'));
        $this->assertTrue($bloques->every(fn (array $b) => $b['es_bloque_propio'] === false));
    }
}
