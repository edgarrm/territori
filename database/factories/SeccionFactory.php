<?php

namespace Database\Factories;

use App\Models\Municipio;
use App\Models\Seccion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Seccion>
 */
class SeccionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'municipio_id' => Municipio::factory(),
            'numero' => fake()->unique()->numberBetween(1, 9999),
            'tipo' => fake()->numberBetween(1, 2),
            'distrito_federal' => fake()->numberBetween(1, 10),
            'distrito_local' => fake()->numberBetween(1, 10),
            'lista_nominal' => fake()->numberBetween(100, 2000),
        ];
    }
}
