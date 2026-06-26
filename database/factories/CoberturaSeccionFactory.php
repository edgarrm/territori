<?php

namespace Database\Factories;

use App\Models\CoberturaSeccion;
use App\Models\Seccion;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CoberturaSeccion>
 */
class CoberturaSeccionFactory extends Factory
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
            'capturados' => 0,
            'meta' => 0,
            'cobertura' => 0,
            'penetracion' => 0,
        ];
    }
}
