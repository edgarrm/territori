<?php

namespace Database\Factories;

use App\Models\AvisoPrivacidad;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AvisoPrivacidad>
 */
class AvisoPrivacidadFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'version' => '1.0',
            'texto' => fake()->paragraph(),
            'vigente_desde' => now()->subDay(),
        ];
    }
}
