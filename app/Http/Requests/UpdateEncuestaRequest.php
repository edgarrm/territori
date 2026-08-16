<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEncuestaRequest extends FormRequest
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
            'nombre' => ['sometimes', 'string', 'max:160'],
            'ambito_tipo' => ['sometimes', 'in:seccion,zona'],
            'modo_acceso' => ['sometimes', 'in:brigadista,publico,ambos'],
            'estado' => ['sometimes', 'in:activa,pausada'],
            'preguntas' => ['sometimes', 'array', 'min:1'],
            'preguntas.*.etiqueta' => ['required_with:preguntas', 'string', 'max:300'],
            'preguntas.*.tipo' => ['required_with:preguntas', 'in:opcion_unica,texto_libre,numerica'],
            'preguntas.*.opciones' => ['nullable', 'array'],
            'preguntas.*.opciones.*' => ['string', 'max:300'],
            'preguntas.*.orden' => ['nullable', 'integer'],
        ];
    }
}
