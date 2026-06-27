<?php

namespace Database\Factories;

use App\Models\Elector;
use App\Models\Interaccion;
use App\Models\Membership;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Interaccion>
 */
class InteraccionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'elector_id' => Elector::factory(),
            'membership_id' => Membership::factory(),
            'tipo' => 'llamada',
            'resultado' => 'contesto',
            'nota' => null,
            'fecha' => now(),
            'proximo_seguimiento' => null,
            'atendido_en' => null,
        ];
    }

    /**
     * Seguimiento pendiente y vencido (aparece en la agenda).
     */
    public function pendienteHoy(): static
    {
        return $this->state(fn (): array => [
            'proximo_seguimiento' => now()->toDateString(),
            'atendido_en' => null,
        ]);
    }
}
