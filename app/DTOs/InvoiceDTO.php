<?php

namespace App\DTOs;

use Carbon\Carbon;

class InvoiceDTO extends BaseDTO
{
    public function __construct(
        public int $company_id,
        public int $branch_id,
        public int $client_id,
        public string $tipo_operacion,
        public string $fecha_emision,
        public ?string $fecha_vencimiento = null,
        public string $moneda = 'PEN',
        public float $tipo_cambio = 1.0,
        public ?string $observaciones = null,
        public ?string $numero_orden_compra = null,
        public ?string $numero_guia = null,
        public array $detalles = [],
        public array $legends = [],
        public ?array $cuotas = null,
        public ?array $anticipos = null,
        public ?array $guias_relacionadas = null,
        public ?array $documentos_relacionados = null,
        public ?float $monto_descuento = null,
        public ?float $porcentaje_descuento = null,
        public ?int $usuario_id = null
    ) {
        $this->validateData();
    }

    /**
     * Validate DTO data
     */
    protected function validateData(): void
    {
        // Validar que tenga al menos un detalle
        if (empty($this->detalles)) {
            throw new \InvalidArgumentException('La factura debe tener al menos un detalle');
        }

        // Validar tipo de operación
        $tiposOperacion = ['0101', '0200', '0201', '0202', '0203', '0204', '0205', '0206', '0207', '0208'];
        if (!in_array($this->tipo_operacion, $tiposOperacion)) {
            throw new \InvalidArgumentException("Tipo de operación inválido: {$this->tipo_operacion}");
        }

        // Validar moneda
        if (!in_array($this->moneda, ['PEN', 'USD'])) {
            throw new \InvalidArgumentException("Moneda inválida: {$this->moneda}");
        }

        // Validar fecha de emisión
        try {
            Carbon::parse($this->fecha_emision);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException("Fecha de emisión inválida: {$this->fecha_emision}");
        }

        // Validar cuotas si es crédito
        if ($this->tipo_operacion === 'credito' && empty($this->cuotas)) {
            throw new \InvalidArgumentException('Las ventas al crédito deben tener cuotas definidas');
        }

        // Validar estructura de detalles
        foreach ($this->detalles as $index => $detalle) {
            if (!isset($detalle['cantidad'], $detalle['mto_valor_unitario'], $detalle['descripcion'])) {
                throw new \InvalidArgumentException("Detalle #{$index} incompleto");
            }

            if ($detalle['cantidad'] <= 0) {
                throw new \InvalidArgumentException("Detalle #{$index}: cantidad debe ser mayor a 0");
            }

            if ($detalle['mto_valor_unitario'] < 0) {
                throw new \InvalidArgumentException("Detalle #{$index}: valor unitario no puede ser negativo");
            }
        }
    }

    /**
     * Get prepared data for invoice creation
     */
    public function toCreateArray(): array
    {
        $data = $this->toArray();

        // Asegurar que fecha_emision sea Carbon
        $data['fecha_emision'] = Carbon::parse($this->fecha_emision);

        if ($this->fecha_vencimiento) {
            $data['fecha_vencimiento'] = Carbon::parse($this->fecha_vencimiento);
        }

        return $data;
    }

    /**
     * Calculate totals from details
     */
    public function calculateTotals(): array
    {
        $totalGravadas = 0;
        $totalExoneradas = 0;
        $totalInafectas = 0;
        $totalExportacion = 0;
        $totalIgv = 0;
        $totalIsc = 0;
        $totalOtrosImpuestos = 0;

        foreach ($this->detalles as $detalle) {
            $tipAfeIgv = $detalle['tip_afe_igv'] ?? '10';
            $valorVenta = $detalle['cantidad'] * $detalle['mto_valor_unitario'];

            switch ($tipAfeIgv) {
                case '10': // Gravado
                case '17': // Gravado - Retiro por premio
                case '20': // Gravado - Retiro por publicidad
                case '30': // Gravado - Bonificación
                case '31': // Gravado - Retiro
                case '32': // Gravado - Retiro por convenio
                case '33': // Gravado - Retiro por muestras
                case '34': // Gravado - Retiro por convenio colectivo
                case '35': // Gravado - Retiro por premio
                case '36': // Gravado - Retiro por publicidad
                case '37': // Gravado - Retiro como bonificación
                    $totalGravadas += $valorVenta;
                    $totalIgv += ($detalle['igv'] ?? 0);
                    break;
                case '20': // Exonerado
                case '21': // Exonerado - Transferencia gratuita
                    $totalExoneradas += $valorVenta;
                    break;
                case '30': // Inafecto
                case '31': // Inafecto - Retiro por Bonificación
                case '32': // Inafecto - Retiro
                case '33': // Inafecto - Retiro por Muestras Médicas
                case '34': // Inafecto - Retiro por Convenio Colectivo
                case '35': // Inafecto - Retiro por premio
                case '36': // Inafecto - Retiro por publicidad
                    $totalInafectas += $valorVenta;
                    break;
                case '40': // Exportación
                    $totalExportacion += $valorVenta;
                    break;
            }

            $totalIsc += ($detalle['isc'] ?? 0);
            $totalOtrosImpuestos += ($detalle['otros_tributos'] ?? 0);
        }

        $subtotal = $totalGravadas + $totalExoneradas + $totalInafectas + $totalExportacion;
        $total = $subtotal + $totalIgv + $totalIsc + $totalOtrosImpuestos;

        // Aplicar descuento si existe
        if ($this->monto_descuento) {
            $total -= $this->monto_descuento;
        }

        return [
            'total_operaciones_gravadas' => round($totalGravadas, 2),
            'total_operaciones_exoneradas' => round($totalExoneradas, 2),
            'total_operaciones_inafectas' => round($totalInafectas, 2),
            'total_exportacion' => round($totalExportacion, 2),
            'total_igv' => round($totalIgv, 2),
            'total_isc' => round($totalIsc, 2),
            'total_otros_cargos' => round($totalOtrosImpuestos, 2),
            'mto_imp_venta' => round($total, 2),
        ];
    }
}
