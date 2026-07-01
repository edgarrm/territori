<?php

namespace App\Http\Requests;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRedCiudadanaRequest extends FormRequest
{
    /**
     * Solo gestión (coordinador/admin) del tenant activo puede crear redes y
     * designar al enlace responsable.
     */
    public function authorize(): bool
    {
        $tenant = TenantContext::get();
        $membership = $tenant !== null ? $this->user()?->membershipEn($tenant) : null;

        return $membership?->esGestion() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = TenantContext::get()?->id;

        return [
            'nombre' => ['required', 'string', 'max:160'],
            'descripcion' => ['nullable', 'string', 'max:500'],
            // El enlace puede ser una membership de cualquier rol, pero debe
            // pertenecer al tenant activo.
            'enlace_membership_id' => [
                'required',
                Rule::exists('memberships', 'id')->where('tenant_id', $tenantId),
            ],
        ];
    }
}
