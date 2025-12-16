<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero' => $this->numero_completo,
            'serie' => $this->serie,
            'correlativo' => $this->correlativo,
            'tipo_documento' => $this->tipo_documento,

            // Fechas
            'fecha_emision' => $this->fecha_emision?->format('Y-m-d'),
            'fecha_vencimiento' => $this->fecha_vencimiento?->format('Y-m-d'),

            // Información comercial
            'moneda' => $this->moneda,
            'moneda_simbolo' => $this->moneda === 'PEN' ? 'S/' : '$',
            'tipo_operacion' => $this->tipo_operacion,
            'forma_pago' => [
                'tipo' => $this->forma_pago_tipo,
                'cuotas' => $this->forma_pago_cuotas
            ],

            // Montos
            'totales' => [
                'gravada' => (float) $this->mto_oper_gravadas,
                'exonerada' => (float) $this->mto_oper_exoneradas,
                'inafecta' => (float) $this->mto_oper_inafectas,
                'exportacion' => (float) $this->mto_oper_exportacion,
                'gratuita' => (float) $this->mto_oper_gratuitas,
                'igv' => (float) $this->mto_igv,
                'isc' => (float) $this->mto_isc,
                'icbper' => (float) $this->mto_icbper,
                'total_impuestos' => (float) $this->total_impuestos,
                'total' => (float) $this->mto_imp_venta,
                'total_formatted' => number_format($this->mto_imp_venta, 2)
            ],

            // Estado SUNAT
            'estado' => [
                'sunat' => $this->estado_sunat,
                'color' => $this->getEstadoColor(),
                'icon' => $this->getEstadoIcon(),
                'descripcion' => $this->getEstadoDescripcion()
            ],

            // Relaciones
            'company' => [
                'id' => $this->company->id,
                'ruc' => $this->company->ruc,
                'razon_social' => $this->company->razon_social
            ],

            'branch' => $this->when($this->branch, [
                'id' => $this->branch?->id,
                'nombre' => $this->branch?->nombre
            ]),

            'client' => $this->when($this->client, [
                'id' => $this->client?->id,
                'razon_social' => $this->client?->razon_social,
                'tipo_documento' => $this->client?->tipo_documento,
                'numero_documento' => $this->client?->numero_documento
            ]),

            // Archivos disponibles
            'files' => [
                'xml' => [
                    'exists' => !empty($this->xml_path),
                    'url' => !empty($this->xml_path) ? route('api.v1.invoices.download-xml', $this->id) : null
                ],
                'cdr' => [
                    'exists' => !empty($this->cdr_path),
                    'url' => !empty($this->cdr_path) ? route('api.v1.invoices.download-cdr', $this->id) : null
                ],
                'pdf' => [
                    'url' => route('api.v1.invoices.download-pdf', $this->id)
                ]
            ],

            // Detalles del documento
            'detalles_count' => is_array($this->detalles) ? count($this->detalles) : 0,
            'detalles' => $this->when($request->input('include_details'), $this->detalles),

            // Metadatos
            'hash' => $this->codigo_hash,
            'usuario_creacion' => $this->usuario_creacion,

            // Timestamps
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * Get estado color
     */
    protected function getEstadoColor(): string
    {
        return match($this->estado_sunat) {
            'ACEPTADO' => 'success',
            'PENDIENTE' => 'warning',
            'EN_COLA' => 'info',
            'RECHAZADO' => 'danger',
            'ERROR' => 'danger',
            default => 'secondary'
        };
    }

    /**
     * Get estado icon
     */
    protected function getEstadoIcon(): string
    {
        return match($this->estado_sunat) {
            'ACEPTADO' => 'check-circle',
            'PENDIENTE' => 'clock',
            'EN_COLA' => 'hourglass',
            'RECHAZADO' => 'x-circle',
            'ERROR' => 'alert-triangle',
            default => 'help-circle'
        };
    }

    /**
     * Get estado descripción
     */
    protected function getEstadoDescripcion(): string
    {
        return match($this->estado_sunat) {
            'ACEPTADO' => 'Aceptado por SUNAT',
            'PENDIENTE' => 'Pendiente de envío',
            'EN_COLA' => 'En cola de envío',
            'RECHAZADO' => 'Rechazado por SUNAT',
            'ERROR' => 'Error al procesar',
            default => 'Estado desconocido'
        };
    }
}
