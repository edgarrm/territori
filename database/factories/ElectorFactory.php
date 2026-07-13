<?php

namespace Database\Factories;

use App\Models\AvisoPrivacidad;
use App\Models\Elector;
use App\Models\Membership;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Support\Telefono;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Elector>
 */
class ElectorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $telefono = '55'.fake()->numerify('########');

        return [
            'tenant_id' => Tenant::factory(),
            'seccion_id' => Seccion::factory(),
            'membership_id' => Membership::factory(),
            'modo_captura' => 'enlace_seccional',
            'loteria_id' => null,
            'evento_id' => null,
            'red_ciudadana_id' => null,
            'nombre' => fake()->name(),
            'telefono' => $telefono,
            'telefono_hash' => Telefono::hash($telefono),
            'email' => null,
            'domicilio' => null,
            'ubicacion' => null,
            'observaciones' => null,
            'consentimiento' => true,
            'aviso_privacidad_id' => AvisoPrivacidad::factory(),
        ];
    }

    /**
     * Elector con verificación confirmada por un canal.
     */
    public function verificado(string $via = 'whatsapp'): static
    {
        return $this->state(fn (array $attributes): array => [
            'verificado_en' => now(),
            'verificado_via' => $via,
            'verificado_membership_id' => Membership::factory(),
        ]);
    }
}
