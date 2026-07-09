<?php

namespace Database\Seeders;

use App\Models\Partido;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Catálogo global de partidos, elección 2024 (colores institucionales
 * aproximados). Idempotente vía updateOrCreate por siglas: producción ya
 * puede tener datos.
 */
class PartidoSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $partidos = [
            ['siglas' => 'PAN', 'nombre' => 'Partido Acción Nacional', 'color' => '#0D5EAF'],
            ['siglas' => 'PRI', 'nombre' => 'Partido Revolucionario Institucional', 'color' => '#C4161C'],
            ['siglas' => 'PRD', 'nombre' => 'Partido de la Revolución Democrática', 'color' => '#FFCC00'],
            ['siglas' => 'PT', 'nombre' => 'Partido del Trabajo', 'color' => '#D52B1E'],
            ['siglas' => 'PVEM', 'nombre' => 'Partido Verde Ecologista de México', 'color' => '#4C9F38'],
            ['siglas' => 'MC', 'nombre' => 'Movimiento Ciudadano', 'color' => '#F58025'],
            ['siglas' => 'PAS', 'nombre' => 'Partido Sinaloense', 'color' => '#7B3F99'],
            ['siglas' => 'MORENA', 'nombre' => 'Movimiento Regeneración Nacional', 'color' => '#8B1538'],
            ['siglas' => 'PES', 'nombre' => 'Partido Encuentro Solidario', 'color' => '#4B0082'],
        ];

        foreach ($partidos as $partido) {
            Partido::updateOrCreate(['siglas' => $partido['siglas']], $partido);
        }
    }
}
