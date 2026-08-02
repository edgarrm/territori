<?php

namespace App\Http\Requests;

use App\Models\Elector;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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

    /**
     * El tenant acota qué electores existen; el acceso por zona acota cuáles
     * puede señalar este miembro en particular (mismo criterio que la ficha).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $electorId = $this->input('elector_id');

            if ($electorId === null || $validator->errors()->has('elector_id')) {
                return;
            }

            $tenant = TenantContext::get();
            $viewer = $tenant !== null ? $this->user()?->membershipEn($tenant) : null;
            $elector = Elector::query()->whereKey($electorId)->first();

            if ($viewer === null || $elector === null || ! $viewer->puedeAccederElector($elector)) {
                $validator->errors()->add('elector_id', 'No tienes acceso a este elector.');
            }
        });
    }
}
