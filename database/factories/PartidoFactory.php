<?php

namespace Database\Factories;

use App\Models\Partido;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Partido>
 */
class PartidoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'siglas' => strtoupper(fake()->unique()->lexify('????')),
            'nombre' => fake()->company(),
            'color' => fake()->hexColor(),
        ];
    }
}
