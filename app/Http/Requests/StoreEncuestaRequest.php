<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEncuestaRequest extends FormRequest
{
    /**
     * La autorización real la impone el middleware de ruta (rol:coordinador,admin).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:160'],
            // 'zona' se acepta como valor aunque F6/F7 (tabla zonas) no exista
            // aún en este worktree — ver nota de la spec.
            'ambito_tipo' => ['required', 'in:seccion,zona'],
            'canal' => ['nullable', 'in:nativo'],
            'modo_acceso' => ['required', 'in:brigadista,publico,ambos'],
            'preguntas' => ['required', 'array', 'min:1'],
            'preguntas.*.etiqueta' => ['required', 'string', 'max:300'],
            'preguntas.*.tipo' => ['required', 'in:opcion_unica,texto_libre,numerica'],
            'preguntas.*.opciones' => ['nullable', 'array'],
            'preguntas.*.opciones.*' => ['string', 'max:300'],
            'preguntas.*.orden' => ['nullable', 'integer'],
        ];
    }
}
