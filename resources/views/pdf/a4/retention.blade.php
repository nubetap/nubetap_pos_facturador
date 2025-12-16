@extends('pdf.layouts.a4')

@section('content')
    {{-- Header con el mismo diseño que las facturas --}}
    @include('pdf.components.header', [
        'company' => $company,
        'document' => $document,
        'tipo_documento_nombre' => $tipo_documento_nombre,
        'fecha_emision' => $fecha_emision,
        'format' => 'a4'
    ])

    {{-- Información del Proveedor (equivalente a client-info) --}}
    <div style="margin-bottom: 15px; padding: 10px; background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px;">
        <table style="width: 100%; font-size: 10px;">
            <tr>
                <td style="width: 50%; padding: 5px;">
                    <strong style="color: #495057;">FECHA DE EMISIÓN:</strong><br>
                    <span style="font-size: 11px;">{{ $fecha_emision }}</span>
                </td>
                <td style="width: 25%; padding: 5px;">
                    <strong style="color: #495057;">RÉGIMEN:</strong><br>
                    <span style="font-size: 11px;">{{ $totales['regimen'] }}</span>
                </td>
                <td style="width: 25%; padding: 5px;">
                    <strong style="color: #495057;">TASA:</strong><br>
                    <span style="font-size: 11px;">{{ $totales['tasa'] }}%</span>
                </td>
            </tr>
        </table>
    </div>

    {{-- Tabla de Documentos Afectados --}}
    <div style="margin-bottom: 15px;">
        <table style="width: 100%; border-collapse: collapse; font-size: 9px;">
            <thead>
                <tr style="background-color: #343a40; color: white;">
                    <th style="border: 1px solid #dee2e6; padding: 8px; text-align: center; width: 8%;">TIPO</th>
                    <th style="border: 1px solid #dee2e6; padding: 8px; text-align: left; width: 22%;">NÚMERO</th>
                    <th style="border: 1px solid #dee2e6; padding: 8px; text-align: center; width: 15%;">F. EMISIÓN</th>
                    <th style="border: 1px solid #dee2e6; padding: 8px; text-align: center; width: 15%;">F. RETENCIÓN</th>
                    <th style="border: 1px solid #dee2e6; padding: 8px; text-align: right; width: 15%;">TOTAL DOC.</th>
                    <th style="border: 1px solid #dee2e6; padding: 8px; text-align: right; width: 15%;">RETENCIÓN</th>
                    <th style="border: 1px solid #dee2e6; padding: 8px; text-align: right; width: 10%;">TASA %</th>
                </tr>
            </thead>
            <tbody>
                @foreach($detalles as $detalle)
                <tr style="background-color: {{ $loop->iteration % 2 == 0 ? '#f8f9fa' : 'white' }};">
                    <td style="border: 1px solid #dee2e6; padding: 6px; text-align: center;">{{ $detalle['tipo_doc'] ?? '-' }}</td>
                    <td style="border: 1px solid #dee2e6; padding: 6px;">{{ $detalle['num_doc'] ?? '-' }}</td>
                    <td style="border: 1px solid #dee2e6; padding: 6px; text-align: center;">
                        {{ isset($detalle['fecha_emision']) ? (is_string($detalle['fecha_emision']) ? $detalle['fecha_emision'] : $detalle['fecha_emision']->format('d/m/Y')) : '-' }}
                    </td>
                    <td style="border: 1px solid #dee2e6; padding: 6px; text-align: center;">
                        {{ isset($detalle['fecha_retencion']) ? (is_string($detalle['fecha_retencion']) ? $detalle['fecha_retencion'] : $detalle['fecha_retencion']->format('d/m/Y')) : '-' }}
                    </td>
                    <td style="border: 1px solid #dee2e6; padding: 6px; text-align: right;">{{ $moneda }} {{ number_format($detalle['imp_total'] ?? 0, 2) }}</td>
                    <td style="border: 1px solid #dee2e6; padding: 6px; text-align: right; font-weight: bold;">{{ $moneda }} {{ number_format($detalle['imp_retenido'] ?? 0, 2) }}</td>
                    <td style="border: 1px solid #dee2e6; padding: 6px; text-align: right;">{{ number_format($totales['tasa'], 2) }}%</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Total en Letras --}}
    <div style="margin-bottom: 10px; padding: 8px; background-color: #f8f9fa; border-left: 4px solid #007bff;">
        <table style="width: 100%;">
            <tr>
                <td style="font-size: 9px; color: #6c757d;">
                    <strong>SON:</strong> <span style="font-size: 10px; color: #212529;">{{ $total_en_letras }}</span>
                </td>
            </tr>
        </table>
    </div>

    {{-- Totales --}}
    <div style="margin-top: 10px;">
        <table style="width: 100%; font-size: 10px;">
            <tr>
                <td style="width: 70%;"></td>
                <td style="width: 30%;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr style="background-color: #f8f9fa;">
                            <td style="padding: 8px; border: 1px solid #dee2e6;"><strong>TOTAL DOCUMENTOS:</strong></td>
                            <td style="padding: 8px; border: 1px solid #dee2e6; text-align: right;">{{ $moneda }} {{ number_format($totales['total_documentos'], 2) }}</td>
                        </tr>
                        <tr style="background-color: #343a40; color: white;">
                            <td style="padding: 10px; border: 1px solid #dee2e6;"><strong>TOTAL RETENIDO:</strong></td>
                            <td style="padding: 10px; border: 1px solid #dee2e6; text-align: right; font-size: 12px; font-weight: bold;">{{ $moneda }} {{ number_format($totales['total_retenido'], 2) }}</td>
                        </tr>
                        <tr style="background-color: #f8f9fa;">
                            <td style="padding: 8px; border: 1px solid #dee2e6;"><strong>NETO A PAGAR:</strong></td>
                            <td style="padding: 8px; border: 1px solid #dee2e6; text-align: right;">{{ $moneda }} {{ number_format($totales['total_pagado'], 2) }}</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>

    {{-- Footer con hash --}}
    @include('pdf.components.qr-footer', [
        'qr_code' => $qr_code,
        'hash' => $hash,
        'format' => 'a4'
    ])
@endsection
