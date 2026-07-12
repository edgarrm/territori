<?php

namespace App\Http\Requests\Settings;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

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
            'modo_calculo_competitividad' => ['required', 'in:votos,porcentaje'],

            'categorias_competitividad' => ['required', 'array', 'min:1'],
            'categorias_competitividad.*.nombre' => ['required', 'string', 'max:60'],
            'categorias_competitividad.*.color' => ['required', 'regex:/^#[0-9a-f]{6}$/i'],
            'categorias_competitividad.*.umbral' => ['nullable', 'numeric'],

            'indicadores' => ['required', 'array'],
            'indicadores.competitividad' => ['required', 'boolean'],
            'indicadores.tipo_seccion' => ['required', 'boolean'],
            'indicadores.indice_neutral' => ['required', 'boolean'],
            'indicadores.oportunidad' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $categorias = $this->input('categorias_competitividad');

            if (! is_array($categorias) || $categorias === []) {
                return;
            }

            $ultimo = array_key_last($categorias);

            $nombres = collect($categorias)->pluck('nombre')->filter(fn ($n) => is_string($n));
            $nombresNormalizados = $nombres->map(fn (string $nombre): string => mb_strtolower(trim($nombre)));
            if ($nombresNormalizados->count() !== $nombresNormalizados->unique()->count()) {
                $validator->errors()->add('categorias_competitividad', 'Los nombres de categoría deben ser únicos.');
            }

            $slugs = $nombres->map(fn (string $nombre): string => Str::slug($nombre));
            if ($slugs->count() !== $slugs->unique()->count()) {
                $validator->errors()->add('categorias_competitividad', 'Dos nombres de categoría producen el mismo identificador; usa nombres más distintos.');
            }

            $umbralAnterior = null;
            foreach ($categorias as $indice => $categoria) {
                if ($indice === $ultimo) {
                    continue;
                }

                $umbral = $categoria['umbral'] ?? null;

                if ($umbral === null || ! is_numeric($umbral)) {
                    $validator->errors()->add("categorias_competitividad.{$indice}.umbral", 'Cada categoría, salvo la última, requiere un umbral numérico.');

                    continue;
                }

                if ($umbralAnterior !== null && (float) $umbral >= $umbralAnterior) {
                    $validator->errors()->add("categorias_competitividad.{$indice}.umbral", 'Los umbrales deben ser estrictamente descendentes.');
                }

                $umbralAnterior = (float) $umbral;
            }
        });
    }
}
