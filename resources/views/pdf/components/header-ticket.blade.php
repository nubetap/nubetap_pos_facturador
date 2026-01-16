{{-- PDF Ticket Header Component (Original Style) --}}
{{-- Props: $company, $document, $tipo_documento_nombre, $format --}}

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
            <img src="data:{{ $logoMimeType }};base64,{{ $logoBase64 }}" alt="Logo Empresa" class="logo-img-ticket">
        </div>
    @endif

    {{-- Company Info --}}
    <div class="company-name">{{ strtoupper($company->razon_social ?? 'NOMBRE DE LA EMPRESA') }}</div>
    
    <div class="company-details">
        @if($company->nombre_comercial && $company->nombre_comercial != $company->razon_social)
            {{ $company->nombre_comercial }}<br>
        @endif
        
        {{ $company->direccion ?? 'DIRECCIÓN DE LA EMPRESA' }}<br>
        
        @if($company->distrito || $company->provincia)
            {{ $company->distrito }}{{ $company->provincia ? ', ' . $company->provincia : '' }}<br>
        @endif
        
        @if($company->telefono)
            Tel: {{ $company->telefono }}<br>
        @endif
        
        @if($company->email)
            {{ strtoupper($company->email) }}
        @endif
    </div>

    {{-- Document Info --}}
    <div class="document-info">
        <div>{{ strtoupper($tipo_documento_nombre) }}</div>
        <div>{{ $document->serie }}-{{ str_pad($document->correlativo, 6, '0', STR_PAD_LEFT) }}</div>
        <div>RUC: {{ $company->ruc }}</div>
    </div>
</div>