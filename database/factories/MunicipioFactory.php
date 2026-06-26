<?php

namespace Database\Factories;

use App\Models\Entidad;
use App\Models\Municipio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Municipio>
 */
class MunicipioFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'entidad_id' => Entidad::factory(),
            'clave' => fake()->unique()->numberBetween(1, 999),
            'nombre' => fake()->city(),
        ];
    }
}
