<?php

namespace App\Http\Requests\Boleta;

use App\Models\Boleta;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBoletaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fecha_emision' => 'sometimes|date',
            'ubl_version' => 'nullable|string|max:5',
            'tipo_operacion' => 'nullable|string|max:4',
            'moneda' => 'sometimes|string|max:3',
            'metodo_envio' => 'sometimes|string|in:individual,resumen_diario',
            'forma_pago_tipo' => 'nullable|string|max:20',
            'forma_pago_cuotas' => 'nullable|array',

            // Cliente
            'client' => 'sometimes|array',
            'client.tipo_documento' => 'sometimes|string|max:1',
            'client.numero_documento' => 'sometimes|string|max:15',
            'client.razon_social' => 'sometimes|string|max:255',
            'client.nombre_comercial' => 'nullable|string|max:255',
            'client.direccion' => 'nullable|string|max:255',
            'client.ubigeo' => 'nullable|string|max:6',
            'client.distrito' => 'nullable|string|max:100',
            'client.provincia' => 'nullable|string|max:100',
            'client.departamento' => 'nullable|string|max:100',
            'client.telefono' => 'nullable|string|max:20',
            'client.email' => 'nullable|email|max:255',

            // Detalles
            'detalles' => 'sometimes|array|min:1',
            'detalles.*.codigo' => 'required|string|max:30',
            'detalles.*.descripcion' => 'required|string|max:255',
            'detalles.*.unidad' => 'required|string|max:3',
            'detalles.*.cantidad' => 'required|numeric|min:0.01',
            'detalles.*.mto_valor_unitario' => 'required|numeric|min:0',
            'detalles.*.mto_valor_gratuito' => 'nullable|numeric|min:0',
            'detalles.*.porcentaje_igv' => 'required|numeric|min:0|max:100',
            'detalles.*.porcentaje_ivap' => 'nullable|numeric|min:0|max:100',
            'detalles.*.tip_afe_igv' => 'required|string|max:2',

            // ISC (Impuesto Selectivo al Consumo)
            'detalles.*.tip_sis_isc' => 'nullable|string|in:01,02,03',
            'detalles.*.porcentaje_isc' => 'nullable|numeric|min:0|max:1000',
            'detalles.*.isc' => 'nullable|numeric|min:0',

            // ICBPER (Impuesto a las Bolsas Plásticas)
            'detalles.*.icbper' => 'nullable|numeric|min:0',
            'detalles.*.factor_icbper' => 'nullable|numeric|min:0',

            // Descuentos por línea
            'detalles.*.descuentos' => 'nullable|array',
            'detalles.*.descuentos.*.monto' => 'required_with:detalles.*.descuentos|numeric|min:0',

            // Leyendas
            'leyendas' => 'nullable|array',
            'leyendas.*.code' => 'required|string|max:4',
            'leyendas.*.value' => 'required|string|max:255',

            'datos_adicionales' => 'nullable|array',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Obtener la boleta que se está actualizando
            $boleta = Boleta::find($this->route('id'));

            if (!$boleta) {
                $validator->errors()->add('boleta_id', 'La boleta no existe.');
                return;
            }

            // Solo se puede actualizar si está RECHAZADO o PENDIENTE
            if (!in_array($boleta->estado_sunat, ['RECHAZADO', 'PENDIENTE'])) {
                $validator->errors()->add('estado_sunat',
                    'Solo se pueden actualizar boletas con estado RECHAZADO o PENDIENTE. Estado actual: ' . $boleta->estado_sunat
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'fecha_emision.date' => 'La fecha de emisión debe ser una fecha válida.',
            'moneda.max' => 'La moneda no puede tener más de 3 caracteres.',
            'metodo_envio.in' => 'El método de envío debe ser individual o resumen_diario.',
            'client.tipo_documento.max' => 'El tipo de documento del cliente no puede tener más de 1 carácter.',
            'client.numero_documento.max' => 'El número de documento del cliente no puede tener más de 15 caracteres.',
            'client.razon_social.max' => 'La razón social del cliente no puede tener más de 255 caracteres.',
            'client.email.email' => 'El email del cliente debe ser válido.',
            'detalles.min' => 'Debe incluir al menos un detalle.',
            'detalles.*.codigo.required' => 'El código del producto es requerido.',
            'detalles.*.descripcion.required' => 'La descripción del producto es requerida.',
            'detalles.*.unidad.required' => 'La unidad del producto es requerida.',
            'detalles.*.cantidad.required' => 'La cantidad del producto es requerida.',
            'detalles.*.cantidad.min' => 'La cantidad debe ser mayor a 0.',
            'detalles.*.mto_valor_unitario.required' => 'El valor unitario es requerido.',
            'detalles.*.mto_valor_unitario.min' => 'El valor unitario debe ser mayor o igual a 0.',
            'detalles.*.porcentaje_igv.required' => 'El porcentaje de IGV es requerido.',
            'detalles.*.tip_afe_igv.required' => 'El tipo de afectación IGV es requerido.',
        ];
    }
}
