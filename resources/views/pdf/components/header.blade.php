{{-- PDF Header Component --}}
{{-- Props: $company, $document, $tipo_documento_nombre, $fecha_emision, $format --}}

@php
    // Obtener logo de la empresa desde logo_path (URL pública)
    $logoUrl = $company->logo_path ?? null;
    $logoBase64 = null;
    $logoMimeType = 'image/png';

    if ($logoUrl) {
        // Detectar tipo MIME por extensión
        $extension = strtolower(pathinfo(parse_url($logoUrl, PHP_URL_PATH), PATHINFO_EXTENSION));
        $mimeTypes = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif'];
        $logoMimeType = $mimeTypes[$extension] ?? 'image/png';

        // Descargar logo desde URL
        $logoContent = @file_get_contents($logoUrl);
        if ($logoContent) {
            $logoBase64 = base64_encode($logoContent);
        }
    }

    // Fallback a logo por defecto si no hay logo de empresa
    if (!$logoBase64) {
        $defaultLogoPath = public_path('logo_factura.png');
        if (file_exists($defaultLogoPath)) {
            $logoBase64 = base64_encode(file_get_contents($defaultLogoPath));
            $logoMimeType = 'image/png';
        }
    }
@endphp

@if(in_array($format, ['a4', 'A4', 'a5', 'A5']))
    {{-- A4/A5 Header --}}
    <div class="header">
        <div class="logo-section">
            @if($logoBase64)
                <img src="data:{{ $logoMimeType }};base64,{{ $logoBase64 }}" alt="Logo Empresa" class="logo-img">
            @endif
        </div>
        
        <div class="company-section">
            <div class="company-name">{{ strtoupper($company->razon_social ?? 'EMPRESA') }}</div>
            <div class="company-details">
                @if($company->direccion)
                    {{ $company->direccion }}<br>
                @endif
                @if($company->distrito || $company->provincia || $company->departamento)
                    {{ $company->distrito ? $company->distrito . ', ' : '' }}{{ $company->provincia ? $company->provincia . ', ' : '' }}{{ $company->departamento }}<br>
                @endif
                @if($company->telefono)
                    TELÉFONO: {{ $company->telefono }}<br>
                @endif
                @if($company->email)
                    EMAIL: {{ $company->email }}<br>
                @endif
                @if($company->web)
                    WEB: {{ $company->web }}
                @endif
            </div>
        </div>
        
        <div class="document-section">
            <div class="factura-box">
                <p><b>RUC {{ $company->ruc ?? 'N/A' }}</b></p>
                <p><b>{{ strtoupper($tipo_documento_nombre ?? 'FACTURA ELECTRÓNICA') }}</b></p>
                <p><b>{{ $document->serie }}-{{ str_pad($document->correlativo, 6, '0', STR_PAD_LEFT) }}</b></p>
            </div>
        </div>
    </div>
@else
    {{-- Ticket Header (50mm, 80mm, ticket) --}}
    <div class="header">
        <div class="logo-section-ticket">
            @if($logoBase64)
                <img src="data:{{ $logoMimeType }};base64,{{ $logoBase64 }}" alt="Logo Empresa" class="logo-img-ticket">
            @endif
        </div>
        <div class="company-name">{{ strtoupper($company->razon_social ?? 'EMPRESA') }}</div>
        <div class="company-details">
            @if($company->nombre_comercial)
                {{ $company->nombre_comercial }}<br>
            @endif
            RUC: {{ $company->ruc ?? '' }}<br>
            {{ $company->direccion ?? '' }}<br>
            @if($company->telefono)
                Tel: {{ $company->telefono }}<br>
            @endif
            @if($company->email)
                Email: {{ $company->email }}
            @endif
        </div>
        
        <div class="document-info">
            <div>{{ strtoupper($tipo_documento_nombre) }}</div>
            <div>{{ $document->numero_completo }}</div>
            <div>{{ $fecha_emision }}</div>
        </div>
    </div>
@endif