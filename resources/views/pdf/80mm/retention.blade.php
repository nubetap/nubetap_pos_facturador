@extends('pdf.layouts.80mm')

@section('content')
    {{-- Header --}}
    @include('pdf.components.header-ticket-exact', [
        'company' => $company,
        'document' => $document,
        'tipo_documento_nombre' => $tipo_documento_nombre
    ])

    {{-- Información de Retención --}}
    <div style="margin-bottom: 8px; padding: 6px; background-color: #f8f9fa; border-left: 3px solid #007bff;">
        <div style="font-size: 8px; margin-bottom: 3px;">
            <strong>FECHA EMISIÓN:</strong> {{ $fecha_emision }}
        </div>
        <div style="font-size: 8px; margin-bottom: 3px;">
            <strong>RÉGIMEN:</strong> {{ $totales['regimen'] }}
        </div>
        <div style="font-size: 8px;">
            <strong>TASA:</strong> {{ $totales['tasa'] }}%
        </div>
    </div>

    {{-- Documentos Afectados --}}
    <div style="margin-bottom: 8px;">
        <div style="font-size: 9px; font-weight: bold; margin-bottom: 4px; padding: 4px; background-color: #343a40; color: white; text-align: center;">
            DOCUMENTOS AFECTADOS
        </div>
        @foreach($detalles as $detalle)
        <div style="margin-bottom: 6px; padding: 5px; border: 1px solid #dee2e6; font-size: 7px;">
            <div style="margin-bottom: 2px;">
                <strong>TIPO:</strong> {{ $detalle['tipo_doc'] ?? '-' }} |
                <strong>NRO:</strong> {{ $detalle['num_doc'] ?? '-' }}
            </div>
            <div style="margin-bottom: 2px;">
                <strong>F. EMISIÓN:</strong> {{ isset($detalle['fecha_emision']) ? (is_string($detalle['fecha_emision']) ? $detalle['fecha_emision'] : $detalle['fecha_emision']->format('d/m/Y')) : '-' }}
            </div>
            <div style="margin-bottom: 2px;">
                <strong>F. RETENCIÓN:</strong> {{ isset($detalle['fecha_retencion']) ? (is_string($detalle['fecha_retencion']) ? $detalle['fecha_retencion'] : $detalle['fecha_retencion']->format('d/m/Y')) : '-' }}
            </div>
            <div style="display: flex; justify-content: space-between; margin-top: 3px; padding-top: 3px; border-top: 1px solid #dee2e6;">
                <span><strong>TOTAL DOC:</strong> {{ $moneda }} {{ number_format($detalle['imp_total'] ?? 0, 2) }}</span>
                <span><strong>RETENIDO:</strong> {{ $moneda }} {{ number_format($detalle['imp_retenido'] ?? 0, 2) }}</span>
            </div>
        </div>
        @endforeach
    </div>

    {{-- Total en Letras --}}
    <div style="margin-bottom: 8px; padding: 5px; background-color: #f8f9fa; border-left: 3px solid #007bff; font-size: 7px;">
        <strong>SON:</strong> {{ $total_en_letras }}
    </div>

    {{-- Totales --}}
    <div style="margin-bottom: 8px; font-size: 8px;">
        <div style="display: flex; justify-content: space-between; padding: 5px; background-color: #f8f9fa; border: 1px solid #dee2e6;">
            <strong>TOTAL DOCUMENTOS:</strong>
            <span>{{ $moneda }} {{ number_format($totales['total_documentos'], 2) }}</span>
        </div>
        <div style="display: flex; justify-content: space-between; padding: 5px; background-color: #343a40; color: white; border: 1px solid #343a40; font-weight: bold;">
            <strong>TOTAL RETENIDO:</strong>
            <span>{{ $moneda }} {{ number_format($totales['total_retenido'], 2) }}</span>
        </div>
        <div style="display: flex; justify-content: space-between; padding: 5px; background-color: #f8f9fa; border: 1px solid #dee2e6;">
            <strong>NETO A PAGAR:</strong>
            <span>{{ $moneda }} {{ number_format($totales['total_pagado'], 2) }}</span>
        </div>
    </div>

    {{-- Footer --}}
    @include('pdf.components.footer-ticket-exact', [
        'document' => $document,
        'qr_code' => $qr_code ?? null,
        'hash' => $hash ?? null,
        'tipo_documento_nombre' => 'COMPROBANTE DE RETENCIÓN'
    ])
@endsection
