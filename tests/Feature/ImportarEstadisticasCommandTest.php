<?php

namespace Tests\Feature;

use App\Models\EstadisticaSeccion;
use App\Models\Municipio;
use App\Models\Seccion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportarEstadisticasCommandTest extends TestCase
{
    use RefreshDatabase;

    private Municipio $municipio;

    private string $rutaElectoral;

    private string $rutaDemografico;

    protected function setUp(): void
    {
        parent::setUp();

        $this->municipio = Municipio::factory()->create();

        foreach ([101, 102, 103] as $numero) {
            Seccion::factory()->create(['municipio_id' => $this->municipio->id, 'numero' => $numero]);
        }

        $this->rutaElectoral = base_path('tests/Fixtures/estadisticas/electoral.csv');
        $this->rutaDemografico = base_path('tests/Fixtures/estadisticas/demografico.csv');
    }

    private function importar(): void
    {
        $this->artisan('territori:importar-estadisticas', [
            'electoral' => $this->rutaElectoral,
            'demografico' => $this->rutaDemografico,
            '--municipio' => $this->municipio->id,
        ])->assertSuccessful();
    }

    private function estadisticaDe(int $numero): ?EstadisticaSeccion
    {
        $seccionId = Seccion::query()
            ->where('municipio_id', $this->municipio->id)
            ->where('numero', $numero)
            ->value('id');

        return EstadisticaSeccion::query()->where('seccion_id', $seccionId)->first();
    }

    public function test_importa_resultados_electorales(): void
    {
        $this->importar();

        $estadistica = $this->estadisticaDe(101);

        $this->assertNotNull($estadistica);
        $this->assertSame(222, $estadistica->lista_nominal_2024);
        $this->assertSame(116, $estadistica->total_votos);
        $this->assertSame('52.25', $estadistica->participacion_pct);
        $this->assertSame(45, $estadistica->votos_fuerza);
        $this->assertSame('38.79', $estadistica->pct_fuerza);
        $this->assertSame(57, $estadistica->votos_morena_pvem);
        $this->assertSame('49.14', $estadistica->pct_morena_pvem);
        $this->assertSame(11, $estadistica->votos_otros);
        $this->assertSame('Morena-PVEM', $estadistica->ganador_bloque);
        $this->assertSame(12, $estadistica->margen_votos);
        $this->assertSame('10.34', $estadistica->margen_pp);
        $this->assertSame('MORENA', $estadistica->ganador_partido);
    }

    public function test_votos_partidos_solo_incluye_partidos_con_votos(): void
    {
        $this->importar();

        $votosPartidos = $this->estadisticaDe(101)->votos_partidos;

        $this->assertSame(30, $votosPartidos['PAN']);
        $this->assertSame(57, $votosPartidos['MORENA']);
        $this->assertArrayNotHasKey('PRD', $votosPartidos);
        $this->assertArrayNotHasKey('CAND_IND1', $votosPartidos);
    }

    public function test_seccion_inexistente_se_ignora_sin_crear_registro(): void
    {
        $this->importar();

        // La sección 999 del CSV electoral y la 103 sí existe pero solo en demográfico.
        $this->assertSame(3, EstadisticaSeccion::count());
        $this->assertNull(EstadisticaSeccion::query()->whereDoesntHave('seccion')->first());
    }

    public function test_demografico_se_fusiona_con_electoral_en_un_solo_registro(): void
    {
        $this->importar();

        $estadistica = $this->estadisticaDe(101);

        // Conserva lo electoral y agrega lo demográfico (fracciones a 0-100).
        $this->assertSame('Morena-PVEM', $estadistica->ganador_bloque);
        $this->assertSame('52.25', $estadistica->participacion_2024_pct);
        $this->assertSame('47.75', $estadistica->abstencion_2024_pct);
        $this->assertSame('88.35', $estadistica->indice_oportunidad);
        $this->assertSame('Alta', $estadistica->nivel_oportunidad);
        $this->assertSame('60-79', $estadistica->grupo_dominante);
        $this->assertSame('18-29', $estadistica->grupo_mayor_abstencion);
        $this->assertSame(125, $estadistica->potencial_movilizacion);
        $this->assertSame('Prioridad alta con estrategia por edad', $estadistica->recomendacion);

        $grupo = $estadistica->grupos_edad['18-29'];
        $this->assertSame(40, $grupo['ln']);
        $this->assertSame(16, $grupo['votos']);
        $this->assertSame(40.0, (float) $grupo['participacion']);
        $this->assertSame(60.0, (float) $grupo['abstencion']);
        $this->assertSame(5, $grupo['potencial']);
    }

    public function test_seccion_solo_demografica_queda_sin_datos_electorales(): void
    {
        $this->importar();

        $estadistica = $this->estadisticaDe(103);

        $this->assertNotNull($estadistica);
        $this->assertNull($estadistica->ganador_bloque);
        $this->assertSame('Media', $estadistica->nivel_oportunidad);
    }

    public function test_idempotencia(): void
    {
        $this->importar();
        $this->importar();

        $this->assertSame(3, EstadisticaSeccion::count());
        $this->assertSame('Morena-PVEM', $this->estadisticaDe(101)->ganador_bloque);
    }

    public function test_demografico_es_opcional(): void
    {
        $this->artisan('territori:importar-estadisticas', [
            'electoral' => $this->rutaElectoral,
            '--municipio' => $this->municipio->id,
        ])->assertSuccessful();

        $this->assertSame(2, EstadisticaSeccion::count());
        $this->assertNull($this->estadisticaDe(101)->nivel_oportunidad);
    }

    public function test_falla_con_municipio_inexistente(): void
    {
        $this->artisan('territori:importar-estadisticas', [
            'electoral' => $this->rutaElectoral,
            '--municipio' => 99999,
        ])->assertFailed();
    }

    public function test_usa_el_unico_municipio_si_no_se_indica(): void
    {
        $this->artisan('territori:importar-estadisticas', [
            'electoral' => $this->rutaElectoral,
        ])->assertSuccessful();

        $this->assertSame(2, EstadisticaSeccion::count());
    }
}
