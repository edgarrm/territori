<?php

namespace App\Http\Requests;

use App\Models\Membership;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBrigadistaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'name' => ['nullable', 'string', 'max:160'],
            // El rol asignable depende del rol de quien invita (matriz en
            // Membership::rolesQuePuedeAsignar): un coordinador nunca puede
            // crear coordinadores ni admins.
            'rol' => ['required', Rule::in($this->rolesAsignables())],
            'meta_diaria' => ['nullable', 'integer', 'min:0'],
            'activo' => ['boolean'],
        ];
    }

    /**
     * Roles que el miembro autenticado puede asignar en el tenant activo.
     *
     * @return list<string>
     */
    private function rolesAsignables(): array
    {
        $tenant = TenantContext::get();
        $membership = $tenant !== null ? $this->user()?->membershipEn($tenant) : null;

        return $membership instanceof Membership ? $membership->rolesQuePuedeAsignar() : [];
    }
}
