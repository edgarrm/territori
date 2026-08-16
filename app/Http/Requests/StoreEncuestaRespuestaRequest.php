<?php

namespace App\Http\Requests;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

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
            'ambito_id' => ['required', 'integer'],
            'respuestas' => ['required', 'array', 'min:1'],
            'respuestas.*' => ['required', 'string'],
        ];
    }
}
