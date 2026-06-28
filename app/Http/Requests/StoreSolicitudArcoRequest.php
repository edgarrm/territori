<?php

namespace App\Http\Requests;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSolicitudArcoRequest extends FormRequest
{
    /**
     * Cualquier miembro del tenant activo puede registrar una solicitud ARCO.
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
        $tenantId = TenantContext::get()?->id;

        return [
            'tipo' => ['required', 'in:acceso,rectificacion,cancelacion,oposicion'],
            'elector_id' => [
                'nullable',
                'integer',
                Rule::exists('electores', 'id')->where('tenant_id', $tenantId),
            ],
        ];
    }
}
