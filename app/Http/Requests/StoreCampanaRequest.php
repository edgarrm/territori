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
            'marca_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }

    /**
     * Datos de la campaña ya validados, con el tipo concreto que espera
     * RegistrarCampana::handle() (los campos opcionales nulos se omiten via null).
     *
     * @return array{nombre: string, municipio_id: int, subdominio: string|null, marca_nombre: string|null, marca_color: string|null}
     */
    public function datosCampana(): array
    {
        return [
            'nombre' => $this->string('nombre')->toString(),
            'municipio_id' => $this->integer('municipio_id'),
            'subdominio' => $this->filled('subdominio') ? $this->string('subdominio')->toString() : null,
            'marca_nombre' => $this->filled('marca_nombre') ? $this->string('marca_nombre')->toString() : null,
            'marca_color' => $this->filled('marca_color') ? $this->string('marca_color')->toString() : null,
        ];
    }
}
