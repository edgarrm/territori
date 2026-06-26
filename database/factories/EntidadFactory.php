<?php

namespace Database\Factories;

use App\Models\Entidad;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Entidad>
 */
class EntidadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clave' => fake()->unique()->numberBetween(1, 32),
            'nombre' => fake()->city(),
        ];
    }
}
