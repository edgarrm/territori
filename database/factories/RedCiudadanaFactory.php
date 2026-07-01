<?php

namespace Database\Factories;

use App\Models\Membership;
use App\Models\RedCiudadana;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RedCiudadana>
 */
class RedCiudadanaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'enlace_membership_id' => Membership::factory(),
            'nombre' => 'Red '.fake()->lastName(),
            'descripcion' => null,
            'activa' => true,
        ];
    }
}
