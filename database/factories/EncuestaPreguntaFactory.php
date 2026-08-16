<?php

namespace Database\Factories;

use App\Models\Encuesta;
use App\Models\EncuestaPregunta;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EncuestaPregunta>
 */
class EncuestaPreguntaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'encuesta_id' => Encuesta::factory(),
            'campo' => null,
            'etiqueta' => '¿'.fake()->sentence(4).'?',
            'tipo' => 'opcion_unica',
            'opciones' => ['Sí', 'No'],
            'incluida' => true,
            'orden' => 0,
        ];
    }

    public function textoLibre(): static
    {
        return $this->state([
            'tipo' => 'texto_libre',
            'opciones' => null,
        ]);
    }

    public function numerica(): static
    {
        return $this->state([
            'tipo' => 'numerica',
            'opciones' => null,
        ]);
    }

    public function excluida(): static
    {
        return $this->state(['incluida' => false]);
    }
}
