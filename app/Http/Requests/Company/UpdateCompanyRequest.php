<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ajusta si necesitas lógica de autorización
    }

    public function rules(): array
    {
        $companyId = $this->route('company')->id ?? null;

        // PATCH parcial: cada campo se valida solo si viene en el payload.
        // - 'sometimes|required' → si la key viene, no puede ser vacía.
        // - 'sometimes|nullable' → si la key viene, puede ser null/vacía.
        // - 'sometimes|boolean'  → si la key viene, debe ser boolean.
        // Si la key NO viene, el campo se ignora (no se actualiza).
        return [
            'ruc' => [
                'sometimes',
                'required',
                'string',
                'size:11',
                Rule::unique('companies', 'ruc')->ignore($companyId),
            ],
            'razon_social' => 'sometimes|required|string|max:255',
            'nombre_comercial' => 'sometimes|nullable|string|max:255',
            'direccion' => 'sometimes|required|string|max:255',
            'ubigeo' => 'sometimes|required|string|size:6',
            'distrito' => 'sometimes|required|string|max:100',
            'provincia' => 'sometimes|required|string|max:100',
            'departamento' => 'sometimes|required|string|max:100',
            'telefono' => 'sometimes|nullable|string|max:20',
            'email' => 'sometimes|nullable|email|max:255',
            'web' => 'sometimes|nullable|url|max:255',
            'usuario_sol' => 'sometimes|required|string|max:50',
            'clave_sol' => 'sometimes|required|string|max:100',
            'certificado_pem' => 'sometimes|nullable|file|mimes:pem,crt,cer,txt|max:2048',
            'certificado_password' => 'sometimes|nullable|string|max:100',
            'endpoint_beta' => 'sometimes|nullable|url|max:255',
            'endpoint_produccion' => 'sometimes|nullable|url|max:255',
            'modo_produccion' => 'sometimes|nullable|in:true,false,1,0',
            'logo_path' => 'sometimes|nullable|file|mimes:png,jpeg,jpg|max:2048',
            'activo' => 'sometimes|boolean',
            // Proveedor CPE alterno (NRUS): cambiar de greenter↔validapse,
            // o refrescar el token y empresa_id sincronizados desde Django.
            'cpe_provider' => 'sometimes|nullable|in:greenter,validapse',
            'validapse_empresa_id' => 'sometimes|nullable|integer|min:1',
            'validapse_token_acceso' => 'sometimes|nullable|string|max:500',
        ];
    }
}
