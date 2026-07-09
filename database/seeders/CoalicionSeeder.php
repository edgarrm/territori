<?php

namespace Database\Seeders;

use App\Models\Coalicion;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Catálogo global de coaliciones por año electoral. Idempotente vía
 * updateOrCreate por (anio, nombre): producción ya puede tener datos.
 */
class CoalicionSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $coaliciones = [
            ['nombre' => 'Fuerza y Corazón por México', 'anio' => 2024, 'partidos' => ['PAN', 'PRI', 'PRD', 'PAS']],
            ['nombre' => 'Sigamos Haciendo Historia', 'anio' => 2024, 'partidos' => ['MORENA', 'PVEM']],
        ];

        foreach ($coaliciones as $coalicion) {
            Coalicion::updateOrCreate(
                ['anio' => $coalicion['anio'], 'nombre' => $coalicion['nombre']],
                $coalicion
            );
        }
    }
}
