<?php

namespace Database\Factories;

use App\Models\EstadisticaSeccion;
use App\Models\Seccion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EstadisticaSeccion>
 */
class EstadisticaSeccionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $listaNominal = fake()->numberBetween(200, 3000);
        $totalVotos = (int) round($listaNominal * fake()->randomFloat(2, 0.3, 0.7));
        $votosFuerza = (int) round($totalVotos * fake()->randomFloat(2, 0.25, 0.6));
        $votosMorena = max(0, $totalVotos - $votosFuerza - fake()->numberBetween(0, 50));
        $votosOtros = max(0, $totalVotos - $votosFuerza - $votosMorena);
        $ganaFuerza = $votosFuerza >= $votosMorena;

        return [
            'seccion_id' => Seccion::factory(),
            'lista_nominal_2024' => $listaNominal,
            'total_votos' => $totalVotos,
            'participacion_pct' => round($totalVotos / $listaNominal * 100, 2),
            'votos_fuerza' => $votosFuerza,
            'pct_fuerza' => round($votosFuerza / max(1, $totalVotos) * 100, 2),
            'votos_morena_pvem' => $votosMorena,
            'pct_morena_pvem' => round($votosMorena / max(1, $totalVotos) * 100, 2),
            'votos_otros' => $votosOtros,
            'pct_otros' => round($votosOtros / max(1, $totalVotos) * 100, 2),
            'ganador_bloque' => $ganaFuerza ? 'Fuerza y Corazón' : 'Morena-PVEM',
            'margen_votos' => abs($votosFuerza - $votosMorena),
            'margen_pp' => round(abs($votosFuerza - $votosMorena) / max(1, $totalVotos) * 100, 2),
            'ganador_partido' => $ganaFuerza ? fake()->randomElement(['PAN', 'PRI', 'PAS']) : 'MORENA',
            'votos_partidos' => ['PAN' => $votosFuerza, 'MORENA' => $votosMorena],
            'participacion_2024_pct' => fake()->randomFloat(2, 30, 70),
            'abstencion_2024_pct' => fake()->randomFloat(2, 30, 70),
            'indice_oportunidad' => fake()->randomFloat(2, 0, 100),
            'nivel_oportunidad' => fake()->randomElement(['Alta', 'Media', 'Baja', 'Seguimiento']),
            'grupo_dominante' => fake()->randomElement(['18-29', '30-39', '40-49', '50-59', '60-79', '80+']),
            'grupo_mayor_abstencion' => '18-29',
            'potencial_movilizacion' => fake()->numberBetween(0, 1500),
            'tipo_composicion_edad' => fake()->randomElement(['Diversificada', 'Concentrada', 'Media']),
            'universo_operativo' => 'Operativa',
            'recomendacion' => fake()->randomElement([
                'Prioridad alta con estrategia por edad',
                'Segmentar por grupo de edad',
                'Seguimiento territorial',
            ]),
            'grupos_edad' => [
                '18-29' => ['ln' => 500, 'votos' => 150, 'participacion' => 30.0, 'abstencion' => 70.0, 'potencial' => 120],
                '60-79' => ['ln' => 300, 'votos' => 180, 'participacion' => 60.0, 'abstencion' => 40.0, 'potencial' => 10],
            ],
        ];
    }

    /**
     * Sección sin datos demográficos (solo resultados electorales).
     */
    public function sinDemografia(): static
    {
        return $this->state(fn (): array => [
            'participacion_2024_pct' => null,
            'abstencion_2024_pct' => null,
            'indice_oportunidad' => null,
            'nivel_oportunidad' => null,
            'grupo_dominante' => null,
            'grupo_mayor_abstencion' => null,
            'potencial_movilizacion' => null,
            'tipo_composicion_edad' => null,
            'universo_operativo' => null,
            'recomendacion' => null,
            'grupos_edad' => null,
        ]);
    }
}
