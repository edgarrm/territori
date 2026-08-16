<?php

namespace App\Http\Requests;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateZonaRequest extends FormRequest
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
        $tenantId = TenantContext::get()?->id;

        return [
            'nombre' => ['sometimes', 'string', 'max:160'],
            'clave' => [
                'sometimes',
                'nullable',
                'string',
                'max:60',
                Rule::unique('zonas', 'clave')
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId))
                    ->ignore($this->route('zona')),
            ],
            'descripcion' => ['sometimes', 'nullable', 'string'],
            'tipo' => ['sometimes', 'string', 'max:40'],
            'zona_padre_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('zonas', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'fuente' => ['sometimes', 'in:dibujada,importada'],
            'activa' => ['sometimes', 'boolean'],
            'geom' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
