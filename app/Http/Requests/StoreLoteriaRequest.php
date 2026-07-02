<?php

namespace App\Http\Requests;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLoteriaRequest extends FormRequest
{
    /**
     * Cualquier miembro de campo del tenant (no el rol "enlace") puede crear
     * loterías; se convierte en su encargado.
     */
    public function authorize(): bool
    {
        $tenant = TenantContext::get();
        $membership = $tenant !== null ? $this->user()?->membershipEn($tenant) : null;

        return $membership !== null && ! $membership->esEnlace();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenant = TenantContext::get();
        $membership = $tenant !== null ? $this->user()?->membershipEn($tenant) : null;

        return [
            'nombre' => ['required', 'string', 'max:160'],
            'fecha' => ['required', 'date'],
            'seccion_id' => [
                'required',
                'integer',
                Rule::exists('secciones', 'id')->where('municipio_id', $tenant?->municipio_id),
            ],
            // Solo gestión puede asignar la lotería a otro miembro.
            'asignado_membership_id' => [
                'nullable',
                'integer',
                Rule::prohibitedIf(! ($membership?->esGestion() ?? false)),
                Rule::exists('memberships', 'id')->where('tenant_id', $tenant?->id),
            ],
        ];
    }
}
