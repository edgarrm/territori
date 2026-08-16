<?php

namespace App\Http\Requests;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreZonaRequest extends FormRequest
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
            'nombre' => ['required', 'string', 'max:160'],
            'clave' => [
                'nullable',
                'string',
                'max:60',
                Rule::unique('zonas', 'clave')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'descripcion' => ['nullable', 'string'],
            'tipo' => ['required', 'string', 'max:40'],
            'zona_padre_id' => [
                'nullable',
                'integer',
                Rule::exists('zonas', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'fuente' => ['nullable', 'in:dibujada,importada'],
            'activa' => ['nullable', 'boolean'],
            'geom' => ['nullable', 'array'],
        ];
    }
}
