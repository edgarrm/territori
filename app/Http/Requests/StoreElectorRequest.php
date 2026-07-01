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
        $tenant = TenantContext::get();
        $tenantId = $tenant?->id;

        // El rol "enlace" (acceso restringido) solo captura en sus redes; el
        // resto de roles puede usar cualquier modo, incluido red_ciudadana.
        $membership = $tenant !== null ? $this->user()?->membershipEn($tenant) : null;
        $modos = $membership?->esEnlace()
            ? 'red_ciudadana'
            : 'individual,loteria,evento,red_ciudadana';

        return [
            'modo_captura' => ['required', 'in:'.$modos],
            'evento_id' => ['nullable', 'integer'],
            'red_ciudadana_id' => ['nullable', 'integer'],
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
            'email' => ['nullable', 'email', 'max:160'],
            'consentimiento' => ['accepted'],
            'aviso_privacidad_id' => [
                'required',
                Rule::exists('avisos_privacidad', 'id')->where('tenant_id', $tenantId),
            ],
            'seccion_id' => [
                'nullable',
                'integer',
                Rule::exists('secciones', 'id')->where('municipio_id', $tenant?->municipio_id),
            ],
            'domicilio' => ['nullable', 'string', 'max:255'],
            'observaciones' => ['nullable', 'string'],
            'ubicacion' => ['nullable', 'array'],
            'ubicacion.lat' => ['required_with:ubicacion', 'numeric'],
            'ubicacion.lng' => ['required_with:ubicacion', 'numeric'],
        ];
    }
}
