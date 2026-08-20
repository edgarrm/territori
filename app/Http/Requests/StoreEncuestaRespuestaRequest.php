<?php

namespace App\Http\Requests;

use App\Models\Encuesta;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class StoreEncuestaRespuestaRequest extends FormRequest
{
    /**
     * Cualquier miembro del tenant activo puede responder (brigadista en
     * campo incluido). Sin membership → 403, antes de validar.
     */
    public function authorize(): bool
    {
        $tenant = TenantContext::get();

        return $tenant !== null && $this->user()?->membershipEn($tenant) !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ambito_id' => ['required', 'integer', $this->reglaAmbito()],
            'respuestas' => ['required', 'array', 'min:1'],
            'respuestas.*' => ['required', 'string'],
        ];
    }

    /**
     * `ambito_id` debe referenciar una sección/zona real y en el ámbito de
     * esta encuesta, no cualquier id que el cliente decida mandar (si no, se
     * tabulan ámbitos inventados o de otro tenant). Resolución manual
     * (anti-binding): el modelo aún no llegó al controller en este punto.
     */
    private function reglaAmbito(): Exists|string
    {
        $encuesta = Encuesta::query()->where('id', $this->route('encuesta'))->first();

        if ($encuesta === null) {
            return 'integer';
        }

        return $encuesta->ambito_tipo === 'seccion'
            ? Rule::exists('secciones', 'id')->where('municipio_id', $encuesta->tenant->municipio_id)
            : Rule::exists('zonas', 'id')->where('tenant_id', $encuesta->tenant_id);
    }
}
