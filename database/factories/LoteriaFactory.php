<?php

namespace Database\Factories;

use App\Models\Loteria;
use App\Models\Membership;
use App\Models\Seccion;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Loteria>
 */
class LoteriaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'membership_id' => Membership::factory(),
            'seccion_id' => Seccion::factory(),
            'nombre' => 'Lotería '.$this->faker->streetName(),
            'fecha' => now(),
        ];
    }
}
