<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use App\Models\Invoice;

class UpdateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fecha_emision' => 'sometimes|date',
            'fecha_vencimiento' => 'nullable|date|after_or_equal:fecha_emision',
            'moneda' => 'sometimes|string|in:PEN,USD',
            'tipo_operacion' => 'nullable|string|max:4',
            'forma_pago_tipo' => 'sometimes|string|in:Contado,Credito',
            'forma_pago_cuotas' => 'nullable|array',
            'forma_pago_cuotas.*.moneda' => 'required_with:forma_pago_cuotas|string',
            'forma_pago_cuotas.*.monto' => 'required_with:forma_pago_cuotas|numeric|min:0',
            'forma_pago_cuotas.*.fecha_pago' => 'required_with:forma_pago_cuotas|date',

            // Cliente - se puede actualizar
            'client.tipo_documento' => 'sometimes|string|in:1,4,6,0',
            'client.numero_documento' => 'sometimes|string|max:15',
            'client.razon_social' => 'sometimes|string|max:255',
            'client.nombre_comercial' => 'nullable|string|max:255',
            'client.direccion' => 'nullable|string|max:255',
            'client.ubigeo' => 'nullable|string|size:6',
            'client.distrito' => 'nullable|string|max:100',
            'client.provincia' => 'nullable|string|max:100',
            'client.departamento' => 'nullable|string|max:100',
            'client.telefono' => 'nullable|string|max:20',
            'client.email' => 'nullable|email|max:100',

            // Detalles - se pueden actualizar
            'detalles' => 'sometimes|array|min:1',
            'detalles.*.codigo' => 'required|string|max:50',
            'detalles.*.descripcion' => 'required|string|max:500',
            'detalles.*.unidad' => 'required|string|max:3',
            'detalles.*.cantidad' => 'required|numeric|min:0.001',
            'detalles.*.mto_valor_unitario' => 'required|numeric|min:0',
            'detalles.*.porcentaje_igv' => 'nullable|numeric|min:0',
            'detalles.*.porcentaje_ivap' => 'nullable|numeric|min:0|max:100',
            'detalles.*.mto_valor_gratuito' => 'nullable|numeric|min:0',
            'detalles.*.tip_afe_igv' => 'nullable|string|in:10,11,12,13,14,15,16,17,20,21,30,31,32,33,34,35,36,40',
            'detalles.*.codigo_producto_sunat' => 'nullable|string|max:50',

            // ISC (Impuesto Selectivo al Consumo)
            'detalles.*.tip_sis_isc' => 'nullable|string|in:01,02,03',
            'detalles.*.porcentaje_isc' => 'nullable|numeric|min:0|max:1000',

            // ICBPER (Impuesto a las Bolsas Plásticas)
            'detalles.*.factor_icbper' => 'nullable|numeric|min:0',

            // Descuentos por línea
            'detalles.*.descuentos' => 'nullable|array',
            'detalles.*.descuentos.*.monto' => 'required_with:detalles.*.descuentos|numeric|min:0',

            // Detracción (opcional)
            'detraccion' => 'nullable|array',
            'detraccion.codigo_bien_servicio' => 'required_with:detraccion|string|max:3',
            'detraccion.codigo_medio_pago' => 'nullable|string|max:3',
            'detraccion.cuenta_banco' => 'nullable|string|max:20',
            'detraccion.porcentaje' => 'required_with:detraccion|numeric|min:0|max:100',
            'detraccion.monto' => 'nullable|numeric|min:0',

            // Percepción (opcional)
            'percepcion' => 'nullable|array',
            'percepcion.cod_regimen' => 'required_with:percepcion|string|max:2',
            'percepcion.tasa' => 'required_with:percepcion|numeric|min:0|max:100',
            'percepcion.monto' => 'required_with:percepcion|numeric|min:0',
            'percepcion.monto_base' => 'required_with:percepcion|numeric|min:0',
            'percepcion.monto_total' => 'required_with:percepcion|numeric|min:0',

            // Retención (opcional)
            'retencion' => 'nullable|array',
            'retencion.cod_regimen' => 'required_with:retencion|string|max:2',
            'retencion.tasa' => 'required_with:retencion|numeric|min:0|max:100',
            'retencion.monto' => 'required_with:retencion|numeric|min:0',
            'retencion.monto_base' => 'required_with:retencion|numeric|min:0',
            'retencion.monto_total' => 'required_with:retencion|numeric|min:0',

            // Descuentos globales
            'descuentos' => 'nullable|array',
            'descuentos.*.cod_tipo' => 'required_with:descuentos|string|in:00,01,02,03,04',
            'descuentos.*.factor' => 'required_with:descuentos|numeric|min:0',
            'descuentos.*.monto' => 'required_with:descuentos|numeric|min:0',
            'descuentos.*.monto_base' => 'required_with:descuentos|numeric|min:0',

            // Anticipos
            'anticipos' => 'nullable|array',
            'anticipos.*.tipo_doc_rel' => 'required_with:anticipos|string|in:02,03',
            'anticipos.*.nro_doc_rel' => 'required_with:anticipos|string|max:50',
            'anticipos.*.total' => 'required_with:anticipos|numeric|min:0',

            // Datos adicionales opcionales
            'guias' => 'nullable|array',
            'documentos_relacionados' => 'nullable|array',
            'datos_adicionales' => 'nullable|array',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Obtener la factura que se está actualizando
            $invoice = Invoice::find($this->route('id'));

            if (!$invoice) {
                $validator->errors()->add('invoice_id', 'La factura no existe.');
                return;
            }

            // Solo se puede actualizar si está RECHAZADO o PENDIENTE
            if (!in_array($invoice->estado_sunat, ['RECHAZADO', 'PENDIENTE'])) {
                $validator->errors()->add('estado_sunat',
                    'Solo se pueden actualizar facturas con estado RECHAZADO o PENDIENTE. Estado actual: ' . $invoice->estado_sunat
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'fecha_emision.date' => 'La fecha de emisión debe ser una fecha válida.',
            'fecha_vencimiento.date' => 'La fecha de vencimiento debe ser una fecha válida.',
            'fecha_vencimiento.after_or_equal' => 'La fecha de vencimiento debe ser igual o posterior a la fecha de emisión.',
            'moneda.in' => 'La moneda debe ser PEN o USD.',
            'forma_pago_tipo.in' => 'El tipo de forma de pago debe ser Contado o Credito.',
            'client.tipo_documento.in' => 'El tipo de documento del cliente debe ser válido.',
            'client.razon_social.required' => 'La razón social del cliente es requerida.',
            'detalles.min' => 'Debe incluir al menos un detalle.',
            'detalles.*.codigo.required' => 'El código del producto es requerido.',
            'detalles.*.descripcion.required' => 'La descripción del producto es requerida.',
            'detalles.*.unidad.required' => 'La unidad del producto es requerida.',
            'detalles.*.cantidad.required' => 'La cantidad del producto es requerida.',
            'detalles.*.cantidad.min' => 'La cantidad debe ser mayor a 0.',
            'detalles.*.mto_valor_unitario.required' => 'El valor unitario es requerido.',
            'detalles.*.mto_valor_unitario.min' => 'El valor unitario debe ser mayor o igual a 0.',
        ];
    }
}
