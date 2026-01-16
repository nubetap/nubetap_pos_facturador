{{-- PDF Ticket Header Component (Exact Design Match) --}}
{{-- Props: $company, $document, $tipo_documento_nombre --}}

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

    // Fallback a logo por defecto
    if (!$logoBase64) {
        $defaultLogoPath = public_path('logo_factura.png');
        if (file_exists($defaultLogoPath)) {
            $logoBase64 = base64_encode(file_get_contents($defaultLogoPath));
            $logoMimeType = 'image/png';
        }
    }
@endphp

<div class="header">
    {{-- Logo --}}
    @if($logoBase64)
        <div class="logo-section-ticket">
            <img src="data:{{ $logoMimeType }};base64,{{ $logoBase64 }}" alt="Logo" class="logo-img-ticket">
        </div>
    @endif

    {{-- Company Name --}}
    <div class="company-name">{{ strtoupper($company->razon_social ?? 'EMPRESA DEMO SAC') }}</div>
    
    {{-- RUC --}}
    <div class="company-ruc">RUC: {{ $company->ruc ?? '20100100100' }}</div>
    
    {{-- Company Details --}}
    <div class="company-details">
        {{ $company->direccion ?? 'CALLE LAS NORMAS 123' }}<br>
        {{ $company->distrito ?? 'CALLAO' }} {{ $company->codigo_postal ?? '654 321' }}<br>
        Correo: {{ $company->email ?? 'Administrador@facturas.net' }}<br>
        Web: {{ $company->website ?? 'www.facturas.net' }}
    </div>

    {{-- Document Title --}}
    <div class="document-title">{{ strtoupper($tipo_documento_nombre ?? 'BOLETA DE VENTA ELECTRONICA') }}</div>
    
    {{-- Document Number --}}
    <div class="document-number">{{ $document->serie ?? 'B002' }} - {{ str_pad($document->correlativo ?? '10300686', 8, '0', STR_PAD_LEFT) }}</div>
</div>