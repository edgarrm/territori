<?php

namespace Database\Factories;

use App\Models\Coalicion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Coalicion>
 */
class CoalicionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => fake()->unique()->words(3, true),
            'anio' => 2024,
            'partidos' => [],
        ];
    }
}
