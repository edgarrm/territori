<?php

namespace App\Http\Requests;

use App\Support\Telefono;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreElectorRequest extends FormRequest
{
    /**
     * Solo miembros del tenant activo pueden capturar (cualquier rol). Sin
     * membership → 403, antes de validar.
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
            'modo_captura' => ['required', 'in:individual,loteria,evento'],
            'evento_id' => ['nullable', 'integer'],
            'nombre' => ['required', 'string', 'max:160'],
            'telefono' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (Telefono::normalizar((string) $value) === null) {
                        $fail('El teléfono debe tener al menos 10 dígitos.');
                    }
                },
            ],
            'consentimiento' => ['accepted'],
            'aviso_privacidad_id' => [
                'required',
                Rule::exists('avisos_privacidad', 'id')->where('tenant_id', $tenantId),
            ],
            'seccion_id' => ['nullable', 'integer', 'exists:secciones,id'],
            'domicilio' => ['nullable', 'string', 'max:255'],
            'observaciones' => ['nullable', 'string'],
            'ubicacion' => ['nullable', 'array'],
            'ubicacion.lat' => ['required_with:ubicacion', 'numeric'],
            'ubicacion.lng' => ['required_with:ubicacion', 'numeric'],
        ];
    }
}
