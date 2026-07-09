<?php

namespace App\Http\Requests\Settings;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CampanaUpdateRequest extends FormRequest
{
    /**
     * Autorización real la impone el middleware `rol:admin` de la ruta.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'partido_id' => ['nullable', 'exists:partidos,id'],
            'umbral_ganada_franca' => ['required', 'integer', 'min:1'],
            'umbral_alfa' => [
                'required',
                'integer',
                'min:1',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ((int) $value <= (int) $this->input('umbral_beta')) {
                        $fail('El umbral alfa debe ser mayor que el umbral beta.');
                    }
                },
            ],
            'umbral_beta' => ['required', 'integer', 'min:1'],
            'indicadores' => ['required', 'array'],
            'indicadores.competitividad' => ['required', 'boolean'],
            'indicadores.tipo_seccion' => ['required', 'boolean'],
            'indicadores.indice_neutral' => ['required', 'boolean'],
            'indicadores.oportunidad' => ['required', 'boolean'],
        ];
    }
}
