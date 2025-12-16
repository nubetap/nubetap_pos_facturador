<?php

namespace App\DTOs;

class DocumentDetailDTO extends BaseDTO
{
    public function __construct(
        public string $codigo_producto,
        public string $descripcion,
        public float $cantidad,
        public string $unidad_medida,
        public float $mto_valor_unitario,
        public string $tip_afe_igv = '10',
        public float $porcentaje_igv = 18.0,
        public ?float $mto_descuento = null,
        public ?float $factor_descuento = null,
        public ?float $isc = null,
        public ?float $porcentaje_isc = null,
        public ?string $tipo_sistema_isc = null,
        public ?array $otros_tributos = null,
        public ?int $product_id = null
    ) {
        $this->validateData();
    }

    /**
     * Validate DTO data
     */
    protected function validateData(): void
    {
        if ($this->cantidad <= 0) {
            throw new \InvalidArgumentException('Cantidad debe ser mayor a 0');
        }

        if ($this->mto_valor_unitario < 0) {
            throw new \InvalidArgumentException('Valor unitario no puede ser negativo');
        }

        if ($this->porcentaje_igv < 0 || $this->porcentaje_igv > 100) {
            throw new \InvalidArgumentException('Porcentaje IGV debe estar entre 0 y 100');
        }

        // Validar tipo de afectación IGV
        $tiposAfectacion = [
            '10', '11', '12', '13', '14', '15', '16', '17',
            '20', '21', '30', '31', '32', '33', '34', '35', '36', '37', '40'
        ];

        if (!in_array($this->tip_afe_igv, $tiposAfectacion)) {
            throw new \InvalidArgumentException("Tipo de afectación IGV inválido: {$this->tip_afe_igv}");
        }
    }

    /**
     * Calculate line totals
     */
    public function calculateTotals(): array
    {
        $valorVenta = $this->cantidad * $this->mto_valor_unitario;

        // Aplicar descuento
        if ($this->mto_descuento) {
            $valorVenta -= $this->mto_descuento;
        }

        // Calcular base IGV
        $baseIgv = in_array($this->tip_afe_igv, ['10', '17']) ? $valorVenta : 0;

        // Calcular IGV
        $igv = ($baseIgv * $this->porcentaje_igv) / 100;

        // Calcular precio unitario (con impuestos)
        $precioUnitario = ($valorVenta + $igv) / $this->cantidad;

        return [
            'mto_valor_venta' => round($valorVenta, 2),
            'mto_base_igv' => round($baseIgv, 2),
            'igv' => round($igv, 2),
            'total_impuestos' => round($igv + ($this->isc ?? 0), 2),
            'mto_precio_unitario' => round($precioUnitario, 2),
        ];
    }

    /**
     * Check if item is taxable (gravado)
     */
    public function isTaxable(): bool
    {
        return in_array($this->tip_afe_igv, ['10', '17']);
    }

    /**
     * Check if item is exempt (exonerado)
     */
    public function isExempt(): bool
    {
        return in_array($this->tip_afe_igv, ['20', '21']);
    }

    /**
     * Check if item is unaffected (inafecto)
     */
    public function isUnaffected(): bool
    {
        return in_array($this->tip_afe_igv, ['30', '31', '32', '33', '34', '35', '36', '37']);
    }

    /**
     * Check if item is export
     */
    public function isExport(): bool
    {
        return $this->tip_afe_igv === '40';
    }
}
