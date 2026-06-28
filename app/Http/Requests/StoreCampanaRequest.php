<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCampanaRequest extends FormRequest
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
            'nombre' => ['required', 'string', 'max:160'],
            'municipio_id' => ['required', 'integer', 'exists:municipios,id'],
            'subdominio' => ['nullable', 'string', 'max:63', 'unique:tenants,subdominio'],
            'marca_nombre' => ['nullable', 'string', 'max:120'],
            'marca_color' => ['nullable', 'string', 'max:7'],
        ];
    }
}
