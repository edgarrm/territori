<?php

namespace Database\Factories;

use App\Models\Evento;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Evento>
 */
class EventoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'nombre' => 'Mitin '.fake()->city(),
            'tipo' => fake()->randomElement(['mitin', 'reunion', 'recorrido', 'asamblea']),
            'fecha' => now()->addDays(fake()->numberBetween(-5, 10)),
            'lugar' => fake()->streetName(),
            'seccion_id' => null,
            'ubicacion' => null,
        ];
    }
}
