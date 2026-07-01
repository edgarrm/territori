<?php

namespace App\Http\Requests;

use App\Support\Telefono;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class UpdateElectorRequest extends FormRequest
{
    /**
     * Solo miembros del tenant activo editan electores (cualquier rol).
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
            'nombre' => ['sometimes', 'required', 'string', 'max:160'],
            'telefono' => [
                'sometimes',
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (Telefono::normalizar((string) $value) === null) {
                        $fail('El teléfono debe tener al menos 10 dígitos.');
                    }
                },
            ],
            'email' => ['sometimes', 'nullable', 'email', 'max:160'],
            'domicilio' => ['sometimes', 'nullable', 'string', 'max:255'],
            'observaciones' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
