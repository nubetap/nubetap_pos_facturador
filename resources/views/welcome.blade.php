<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>API Facturación Electrónica SUNAT - Perú</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            body {
                font-family: 'Inter', ui-sans-serif, system-ui, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .container {
                background: white;
                border-radius: 24px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                max-width: 900px;
                width: 100%;
                padding: 60px 50px;
                text-align: center;
            }
            .logo-wrapper {
                width: 100px;
                height: 100px;
                margin: 0 auto 30px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                border-radius: 20px;
                display: flex;
                align-items: center;
                justify-content: center;
                box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4);
            }
            .logo-wrapper svg {
                width: 50px;
                height: 50px;
                color: white;
            }
            h1 {
                font-size: 2.5rem;
                font-weight: 700;
                color: #1a202c;
                margin-bottom: 15px;
            }
            .subtitle {
                font-size: 1.1rem;
                color: #4a5568;
                margin-bottom: 40px;
                line-height: 1.7;
            }
            .features {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 25px;
                margin-top: 40px;
            }
            .feature {
                background: linear-gradient(135deg, #f7fafc 0%, #edf2f7 100%);
                padding: 30px 25px;
                border-radius: 16px;
                border: 2px solid transparent;
                transition: all 0.3s ease;
                position: relative;
                overflow: hidden;
            }
            .feature::before {
                content: '';
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                height: 4px;
                background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
                transform: scaleX(0);
                transition: transform 0.3s ease;
            }
            .feature:hover {
                transform: translateY(-8px);
                box-shadow: 0 15px 35px rgba(102, 126, 234, 0.25);
                border-color: #667eea;
            }
            .feature:hover::before {
                transform: scaleX(1);
            }
            .feature-icon {
                width: 48px;
                height: 48px;
                margin: 0 auto 15px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                border-radius: 12px;
                display: flex;
                align-items: center;
                justify-content: center;
                box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
            }
            .feature-icon svg {
                width: 28px;
                height: 28px;
                color: white;
            }
            .feature-title {
                font-size: 1.1rem;
                font-weight: 600;
                color: #2d3748;
                margin-bottom: 10px;
            }
            .feature-desc {
                font-size: 0.95rem;
                color: #718096;
                line-height: 1.5;
            }
            .status {
                display: inline-flex;
                align-items: center;
                gap: 10px;
                background: linear-gradient(135deg, #c6f6d5 0%, #9ae6b4 100%);
                color: #22543d;
                padding: 12px 28px;
                border-radius: 30px;
                font-weight: 600;
                font-size: 1rem;
                margin-top: 30px;
                box-shadow: 0 4px 12px rgba(72, 187, 120, 0.2);
            }
            .status svg {
                width: 20px;
                height: 20px;
                animation: pulse 2s infinite;
            }
            @keyframes pulse {
                0%, 100% { opacity: 1; }
                50% { opacity: 0.5; }
            }
            .version {
                margin-top: 50px;
                padding-top: 30px;
                border-top: 2px solid #e2e8f0;
                color: #718096;
                font-size: 0.95rem;
                display: flex;
                justify-content: center;
                gap: 30px;
                flex-wrap: wrap;
            }
            .version-item {
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .version-item svg {
                width: 20px;
                height: 20px;
                color: #667eea;
            }
            .version strong {
                color: #2d3748;
            }
            @media (max-width: 768px) {
                .container {
                    padding: 40px 30px;
                }
                h1 {
                    font-size: 2rem;
                }
                .subtitle {
                    font-size: 1rem;
                }
                .features {
                    grid-template-columns: 1fr;
                }
                .version {
                    flex-direction: column;
                    gap: 15px;
                }
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="logo-wrapper">
                <x-heroicon-o-document-text />
            </div>

            <h1>API de Facturación Electrónica</h1>
            <p class="subtitle">
                Sistema completo de emisión de comprobantes electrónicos para SUNAT Perú<br>
                Facturas, Boletas, Notas de Crédito, Notas de Débito, Guías de Remisión y más
            </p>

            <div class="status">
                <x-heroicon-o-check-badge />
                Sistema Operativo
            </div>

            <div class="features">
                <div class="feature">
                    <div class="feature-icon">
                        <x-heroicon-o-clipboard-document-list />
                    </div>
                    <div class="feature-title">Comprobantes CPE</div>
                    <div class="feature-desc">Facturas, Boletas, Notas de Crédito y Débito con firma digital</div>
                </div>

                <div class="feature">
                    <div class="feature-icon">
                        <x-heroicon-o-truck />
                    </div>
                    <div class="feature-title">Guías de Remisión</div>
                    <div class="feature-desc">Emisión GRE con integración directa SUNAT</div>
                </div>

                <div class="feature">
                    <div class="feature-icon">
                        <x-heroicon-o-chart-bar />
                    </div>
                    <div class="feature-title">Resúmenes Diarios</div>
                    <div class="feature-desc">Gestión automática de resúmenes y anulaciones</div>
                </div>

                <div class="feature">
                    <div class="feature-icon">
                        <x-heroicon-o-check-circle />
                    </div>
                    <div class="feature-title">Validación SUNAT</div>
                    <div class="feature-desc">Consulta de estado en tiempo real y descarga de CDR</div>
                </div>

                <div class="feature">
                    <div class="feature-icon">
                        <x-heroicon-o-shield-check />
                    </div>
                    <div class="feature-title">Seguro y Confiable</div>
                    <div class="feature-desc">Ambiente BETA y Producción certificados</div>
                </div>

                <div class="feature">
                    <div class="feature-icon">
                        <x-heroicon-o-document-arrow-down />
                    </div>
                    <div class="feature-title">XML y PDF</div>
                    <div class="feature-desc">Generación automática con firma electrónica</div>
                </div>
            </div>

            <div class="version">
                <div class="version-item">
                    <x-heroicon-o-code-bracket />
                    <span><strong>Tecnología:</strong> Laravel 12 + Greenter 5.1</span>
                </div>
                <div class="version-item">
                    <x-heroicon-o-cube />
                    <span><strong>Versión:</strong> v1.0</span>
                </div>
                <div class="version-item">
                    <x-heroicon-o-calendar />
                    <span><strong>Actualización:</strong> {{ date('d/m/Y') }}</span>
                </div>
            </div>
        </div>
    </body>
</html>
