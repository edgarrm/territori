<?php

namespace Database\Factories;

use App\Models\ConexionGoogle;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ConexionGoogle>
 */
class ConexionGoogleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'access_token' => Str::random(40),
            'refresh_token' => Str::random(40),
            'expira_en' => now()->addHour(),
            'cuenta_email' => fake()->unique()->safeEmail(),
            'conectado_por_membership_id' => null,
            'conectado_en' => now(),
        ];
    }

    public function vencida(): static
    {
        return $this->state(fn (): array => [
            'expira_en' => now()->subMinutes(5),
        ]);
    }
}
