<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ProfileDeleteRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => $this->currentPasswordRules(),
        ];
    }

    /**
     * El último admin activo de una campaña no puede darse de baja: al borrar
     * el usuario se borra su membresía en cascada, y sin admin ni coordinador no
     * queda nadie que pueda dar de alta miembros — la campaña, con su padrón,
     * se vuelve inadministrable y no hay forma de recuperarla desde la app.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $user = $this->user();

            if (! $user instanceof User) {
                return;
            }

            $campanas = $user->memberships()
                ->where('rol', 'admin')
                ->where('activo', true)
                ->get()
                ->filter(fn (Membership $propia): bool => Membership::query()
                    ->where('tenant_id', $propia->tenant_id)
                    ->where('rol', 'admin')
                    ->where('activo', true)
                    ->whereKeyNot($propia->id)
                    ->doesntExist(),
                );

            if ($campanas->isNotEmpty()) {
                $validator->errors()->add(
                    'password',
                    'Eres el único administrador activo de '.$campanas->count().' campaña(s). '
                    .'Nombra a otro administrador antes de eliminar tu cuenta.',
                );
            }
        });
    }
}
