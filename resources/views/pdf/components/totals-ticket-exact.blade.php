{{-- PDF Ticket Totals Component (Exact Design Match) --}}
{{-- Props: $document, $totales, $total_en_letras --}}
{{--
    Renderiza el bloque tributario completo con todos los conceptos SUNAT.
    Cada línea lee directamente del documento ($document->mto_*); si el
    régimen del emisor no aplica a esa categoría, el monto será 0.00.

    Casos de render:
    - Régimen General/MyPe (boleta gravada): mto_oper_gravadas > 0, mto_igv > 0
    - NRUS (D. Leg. 937 - inafecto): mto_oper_inafectas > 0, mto_igv = 0
    - Mixto: cualquier combinación de gravadas/inafectas/exoneradas

    Esta plantilla es agnóstica al régimen — solo presenta los campos que
    DocumentService::calculateTotals() ya pobló correctamente según los
    tip_afe_igv de cada línea.
--}}

@php
    $moneda = $document->moneda ?? 'PEN';
    $opGravadas = $document->mto_oper_gravadas ?? 0;
    $opInafectas = $document->mto_oper_inafectas ?? 0;
    $opExoneradas = $document->mto_oper_exoneradas ?? 0;
    $totalDescuentos = $document->descuento_global ?? 0;
    $totalIgv = $document->mto_igv ?? 0;
    $totalIsc = $document->mto_isc ?? 0;
    $totalPagar = $document->mto_imp_venta ?? ($totales['total'] ?? 0);
@endphp

<div class="totals-section">
    <div class="total-line">
        <span class="total-text">Total Ope. Gravadas</span>
        <span class="total-dots">........................</span>
        <span class="total-value">{{ $moneda }} {{ number_format($opGravadas, 2) }}</span>
    </div>

    <div class="total-line">
        <span class="total-text">Total Ope. Inafectadas</span>
        <span class="total-dots">....................</span>
        <span class="total-value">{{ $moneda }} {{ number_format($opInafectas, 2) }}</span>
    </div>

    <div class="total-line">
        <span class="total-text">Total Ope. Exoneradas</span>
        <span class="total-dots">.....................</span>
        <span class="total-value">{{ $moneda }} {{ number_format($opExoneradas, 2) }}</span>
    </div>

    <div class="total-line">
        <span class="total-text">Total Descuentos</span>
        <span class="total-dots">............................</span>
        <span class="total-value">{{ $moneda }} {{ number_format($totalDescuentos, 2) }}</span>
    </div>

    <div class="total-line">
        <span class="total-text">Total IGV</span>
        <span class="total-dots">..................................</span>
        <span class="total-value">{{ $moneda }} {{ number_format($totalIgv, 2) }}</span>
    </div>

    <div class="total-line">
        <span class="total-text">Total ISC</span>
        <span class="total-dots">..................................</span>
        <span class="total-value">{{ $moneda }} {{ number_format($totalIsc, 2) }}</span>
    </div>

    <div class="total-line total-final">
        <span class="total-text">TOTAL A PAGAR</span>
        <span class="total-dots">.....................................</span>
        <span class="total-value">{{ $moneda }} {{ number_format($totalPagar, 2) }}</span>
    </div>
</div>

{{-- Total en Letras --}}
<div class="total-letras">
    SON: {{ strtoupper($total_en_letras ?? 'CERO CON 00/100 SOLES') }}
</div>

{{-- Payment Info --}}
<div class="payment-info">
    <div><strong>FORMA DE PAGO:</strong> {{ $document->forma_pago_tipo ?? 'EFECTIVO' }}</div>
    <div><strong>COND.VENTA:</strong> {{ $document->condicion_venta ?? 'CONTADO' }}</div>
</div>

{{-- Observations --}}
@if(!empty($document->observaciones))
    <div class="payment-info">
        <div><strong>Observaciones:</strong></div>
        <div>{{ $document->observaciones }}</div>
    </div>
@endif
