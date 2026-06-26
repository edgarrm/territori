<?php

namespace Database\Factories;

use App\Models\MetaSeccion;
use App\Models\Seccion;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetaSeccion>
 */
class MetaSeccionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'seccion_id' => Seccion::factory(),
            'meta_capturas' => fake()->numberBetween(50, 500),
            'fuente_meta' => 'manual',
        ];
    }
}
