<?php

namespace Database\Factories;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Membership>
 */
class MembershipFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'rol' => 'brigadista',
            'activo' => true,
        ];
    }

    public function brigadista(): static
    {
        return $this->state(['rol' => 'brigadista']);
    }

    public function coordinador(): static
    {
        return $this->state(['rol' => 'coordinador']);
    }

    public function admin(): static
    {
        return $this->state(['rol' => 'admin']);
    }

    public function enlace(): static
    {
        return $this->state(['rol' => 'enlace']);
    }
}
