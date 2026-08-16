<?php

namespace Database\Factories;

use App\Enums\CanalEncuesta;
use App\Models\Encuesta;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Encuesta>
 */
class EncuestaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'nombre' => 'Encuesta '.fake()->unique()->sentence(2),
            'canal' => CanalEncuesta::Nativo,
            'configuracion_canal' => null,
            'ambito_tipo' => 'seccion',
            'modo_acceso' => 'brigadista',
            'token_publico' => null,
            'estado' => 'activa',
            'creado_por_membership_id' => null,
        ];
    }

    public function publica(): static
    {
        return $this->state(fn (): array => [
            'modo_acceso' => 'publico',
            'token_publico' => Str::random(40),
        ]);
    }

    public function ambos(): static
    {
        return $this->state(fn (): array => [
            'modo_acceso' => 'ambos',
            'token_publico' => Str::random(40),
        ]);
    }

    public function pausada(): static
    {
        return $this->state(['estado' => 'pausada']);
    }
}
