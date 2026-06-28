<?php

namespace App\Http\Requests;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

class StoreEventoRequest extends FormRequest
{
    /**
     * Cualquier miembro del tenant activo puede crear eventos.
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
            'nombre' => ['required', 'string', 'max:160'],
            'tipo' => ['required', 'string', 'max:40'],
            'fecha' => ['required', 'date'],
            'lugar' => ['nullable', 'string', 'max:200'],
            'seccion_id' => ['nullable', 'integer', 'exists:secciones,id'],
            'ubicacion' => ['nullable', 'array'],
            'ubicacion.lat' => ['required_with:ubicacion', 'numeric'],
            'ubicacion.lng' => ['required_with:ubicacion', 'numeric'],
        ];
    }
}
