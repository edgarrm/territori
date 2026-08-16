<?php

namespace App\Actions\Encuestas;

use App\Enums\CanalEncuesta;
use App\Models\Encuesta;
use App\Models\EncuestaPregunta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Crea una encuesta del tenant activo con su constructor de preguntas.
 * `token_publico` solo se genera cuando `modo_acceso` incluye 'publico'; nunca
 * derivado del id (Str::random(40), no adivinable).
 */
class CrearEncuesta
{
    /**
     * @param  array<string, mixed>  $datos
     */
    public function handle(array $datos, ?int $creadoPorMembershipId): Encuesta
    {
        return DB::transaction(function () use ($datos, $creadoPorMembershipId): Encuesta {
            $modoAcceso = $datos['modo_acceso'] ?? 'brigadista';

            $encuesta = Encuesta::create([
                'nombre' => $datos['nombre'],
                'canal' => $datos['canal'] ?? CanalEncuesta::Nativo->value,
                'configuracion_canal' => null,
                'ambito_tipo' => $datos['ambito_tipo'],
                'modo_acceso' => $modoAcceso,
                'token_publico' => in_array($modoAcceso, ['publico', 'ambos'], true) ? Str::random(40) : null,
                'estado' => 'activa',
                'creado_por_membership_id' => $creadoPorMembershipId,
            ]);

            foreach ($datos['preguntas'] as $orden => $pregunta) {
                EncuestaPregunta::create([
                    'encuesta_id' => $encuesta->id,
                    'campo' => null,
                    'etiqueta' => $pregunta['etiqueta'],
                    'tipo' => $pregunta['tipo'],
                    'opciones' => $pregunta['opciones'] ?? null,
                    'incluida' => true,
                    'orden' => $pregunta['orden'] ?? $orden,
                ]);
            }

            return $encuesta->fresh('preguntas');
        });
    }
}
