<?php

namespace Database\Factories;

use App\Models\SolicitudArco;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SolicitudArco>
 */
class SolicitudArcoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'elector_id' => null,
            'tipo' => fake()->randomElement(['acceso', 'rectificacion', 'cancelacion', 'oposicion']),
            'estado' => 'pendiente',
            'solicitado_en' => now(),
            'atendido_en' => null,
        ];
    }
}
