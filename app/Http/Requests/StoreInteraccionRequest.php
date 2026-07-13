<?php

namespace App\Http\Requests;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreInteraccionRequest extends FormRequest
{
    /**
     * Solo miembros del tenant activo registran interacciones (cualquier rol).
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
            'tipo' => ['required', 'in:llamada,correo,visita,whatsapp,sms,nota'],
            'resultado' => [
                'nullable',
                'in:contesto,no_contesto,buzon,correo_enviado,no_estaba,rechazo,compromiso',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->input('tipo') === 'nota' && $value !== null && $value !== '') {
                        $fail('Una nota no lleva resultado.');
                    }
                },
            ],
            'nota' => ['nullable', 'string'],
            'fecha' => ['nullable', 'date'],
            'proximo_seguimiento' => ['nullable', 'date'],
            'verificar' => [
                'nullable',
                'boolean',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->boolean('verificar') && $this->input('tipo') === 'nota') {
                        $fail('Una nota no puede verificar el contacto.');
                    }
                },
            ],
        ];
    }
}
