<?php

namespace App\Traits;

use App\Models\CompanyConfiguration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

trait HasCompanyConfigurations
{
    /**
     * Cache key para configuraciones de empresa
     */
    protected function getConfigCacheKey(string $suffix = ''): string
    {
        return "company_config_{$this->id}" . ($suffix ? "_{$suffix}" : '');
    }

    /**
     * Cache tag para esta empresa
     */
    protected function getConfigCacheTag(): string
    {
        return "company_{$this->id}";
    }

    /**
     * Limpiar cache de configuraciones de esta empresa
     */
    public function clearConfigCache(): void
    {
        // Intentar usar tags si el driver lo soporta (Redis, Memcached)
        try {
            Cache::tags([$this->getConfigCacheTag()])->flush();
            \Log::info("Cache limpiado usando tags", ['company_id' => $this->id]);
        } catch (\BadMethodCallException $e) {
            // Si el driver no soporta tags, limpiar keys específicas
            $keysToForget = [
                $this->getConfigCacheKey('all'),
                $this->getConfigCacheKey(),
            ];

            // Agregar keys específicas de configuración
            $configTypes = ['tax_settings', 'document_settings', 'service_endpoints', 'sunat_credentials'];
            $environments = ['general', 'beta', 'produccion'];
            $services = ['general', 'facturacion', 'guias_remision'];

            foreach ($configTypes as $type) {
                foreach ($environments as $env) {
                    foreach ($services as $service) {
                        $keysToForget[] = $this->getConfigCacheKey("{$type}_{$env}_{$service}");
                    }
                }
            }

            foreach ($keysToForget as $key) {
                Cache::forget($key);
            }

            \Log::info("Cache limpiado manualmente", [
                'company_id' => $this->id,
                'keys_cleared' => count($keysToForget)
            ]);
        }
    }

    // ==================== MÉTODOS PRINCIPALES DE CONFIGURACIÓN ====================

    /**
     * Obtener configuración específica con cache
     */
    public function getConfig(string $configType, string $environment = null, string $serviceType = null, $default = null)
    {
        $environment = $environment ?? ($this->modo_produccion ? 'produccion' : 'beta');
        $cacheKey = $this->getConfigCacheKey("{$configType}_{$environment}_{$serviceType}");
        $cacheTime = now()->addHour(); // 1 hora de cache

        // Intentar usar tags si el driver lo soporta
        try {
            return Cache::tags([$this->getConfigCacheTag()])
                ->remember($cacheKey, $cacheTime, function () use ($configType, $environment, $serviceType, $default) {
                    return $this->fetchConfigFromDatabase($configType, $environment, $serviceType, $default);
                });
        } catch (\BadMethodCallException $e) {
            // Fallback sin tags
            return Cache::remember($cacheKey, $cacheTime, function () use ($configType, $environment, $serviceType, $default) {
                return $this->fetchConfigFromDatabase($configType, $environment, $serviceType, $default);
            });
        }
    }

    /**
     * Obtener configuración desde la base de datos
     */
    protected function fetchConfigFromDatabase(string $configType, string $environment, ?string $serviceType, $default)
    {
        $query = $this->activeConfigurations()
            ->where('config_type', $configType)
            ->where('environment', $environment);

        if ($serviceType) {
            $query->where('service_type', $serviceType);
        }

        $config = $query->first();

        if (!$config) {
            // Buscar configuración general si no se encuentra específica del ambiente
            $config = $this->activeConfigurations()
                ->where('config_type', $configType)
                ->where('environment', 'general')
                ->when($serviceType, function ($q) use ($serviceType) {
                    return $q->where('service_type', $serviceType);
                })
                ->first();
        }

        return $config ? $config->config_data : $default;
    }

    /**
     * Establecer configuración específica
     */
    public function setConfig(string $configType, array $configData, string $environment = null, string $serviceType = 'general', string $description = null): CompanyConfiguration
    {
        $environment = $environment ?? ($this->modo_produccion ? 'produccion' : 'beta');

        \Log::info("SetConfig Debug", [
            'company_id' => $this->id,
            'config_type' => $configType,
            'environment' => $environment,
            'service_type' => $serviceType,
            'modo_produccion' => $this->modo_produccion
        ]);

        // Buscar configuración existente
        $config = $this->configurations()
            ->where('config_type', $configType)
            ->where('environment', $environment)
            ->where('service_type', $serviceType)
            ->first();

        \Log::info("SetConfig Search Result", [
            'found_config' => $config ? $config->id : null,
            'existing_data' => $config ? $config->config_data : null
        ]);

        if ($config) {
            // Actualizar existente
            \Log::info("Updating existing config", ['config_id' => $config->id]);
            $config->update([
                'config_data' => $configData,
                'description' => $description,
                'is_active' => true,
            ]);
            \Log::info("Updated config data", ['new_data' => $config->fresh()->config_data]);
        } else {
            // Crear nueva
            \Log::info("Creating new config");
            $config = $this->configurations()->create([
                'config_type' => $configType,
                'environment' => $environment,
                'service_type' => $serviceType,
                'config_data' => $configData,
                'description' => $description,
                'is_active' => true,
            ]);
            \Log::info("Created new config", ['config_id' => $config->id]);
        }

        $this->clearConfigCache();
        return $config;
    }

    /**
     * Obtener todas las configuraciones activas agrupadas
     */
    public function getAllConfigurations(): Collection
    {
        $cacheKey = $this->getConfigCacheKey('all');
        $cacheTime = now()->addHour();

        // Intentar usar tags si el driver lo soporta
        try {
            return Cache::tags([$this->getConfigCacheTag()])
                ->remember($cacheKey, $cacheTime, function () {
                    return $this->activeConfigurations()
                        ->orderBy('config_type')
                        ->orderBy('environment')
                        ->get()
                        ->groupBy(['config_type', 'environment', 'service_type']);
                });
        } catch (\BadMethodCallException $e) {
            // Fallback sin tags
            return Cache::remember($cacheKey, $cacheTime, function () {
                return $this->activeConfigurations()
                    ->orderBy('config_type')
                    ->orderBy('environment')
                    ->get()
                    ->groupBy(['config_type', 'environment', 'service_type']);
            });
        }
    }

    // ==================== MÉTODOS ESPECÍFICOS PARA CREDENCIALES SUNAT ====================

    /**
     * Obtener credenciales SUNAT para un servicio específico
     */
    public function getSunatCredentials(string $serviceType = 'facturacion', string $environment = null): ?array
    {
        $environment = $environment ?? ($this->modo_produccion ? 'produccion' : 'beta');
        
        return $this->getConfig('sunat_credentials', $environment, $serviceType);
    }

    /**
     * Establecer credenciales SUNAT para un servicio específico
     */
    public function setSunatCredentials(string $serviceType, array $credentials, string $environment = null): CompanyConfiguration
    {
        $environment = $environment ?? ($this->modo_produccion ? 'produccion' : 'beta');
        
        return $this->setConfig(
            'sunat_credentials',
            $credentials,
            $environment,
            $serviceType,
            "Credenciales SUNAT para {$serviceType} en {$environment}"
        );
    }

    /**
     * Verificar si tiene credenciales SUNAT configuradas
     */
    public function hasSunatCredentials(string $serviceType = 'facturacion', string $environment = null): bool
    {
        $credentials = $this->getSunatCredentials($serviceType, $environment);
        
        if (!$credentials) {
            return false;
        }

        // Validar campos mínimos requeridos
        return !empty($credentials['client_id']) && !empty($credentials['client_secret']);
    }

    // ==================== MÉTODOS ESPECÍFICOS PARA GRE ====================

    /**
     * Obtener credenciales GRE desde la tabla companies (nuevo sistema)
     */
    public function getGreCredentials(): array
    {
        $environment = $this->modo_produccion ? 'produccion' : 'beta';
        
        return [
            'client_id' => $this->getGreClientId(),
            'client_secret' => $this->getGreClientSecret(),
            'ruc_proveedor' => $this->getGreRucProveedor(),
            'usuario_sol' => $this->getGreUsuarioSol(),
            'clave_sol' => $this->getGreClaveSol(),
            'environment' => $environment
        ];
    }

    /**
     * Obtener Client ID para GRE según ambiente
     */
    public function getGreClientId(): ?string
    {
        return $this->modo_produccion 
            ? $this->gre_client_id_produccion 
            : $this->gre_client_id_beta;
    }

    /**
     * Obtener Client Secret para GRE según ambiente
     */
    public function getGreClientSecret(): ?string
    {
        return $this->modo_produccion 
            ? $this->gre_client_secret_produccion 
            : $this->gre_client_secret_beta;
    }

    /**
     * Obtener RUC del proveedor GRE
     */
    public function getGreRucProveedor(): ?string
    {
        return $this->gre_ruc_proveedor ?? $this->ruc;
    }

    /**
     * Obtener Usuario SOL para GRE
     */
    public function getGreUsuarioSol(): ?string
    {
        return $this->gre_usuario_sol ?? $this->usuario_sol;
    }

    /**
     * Obtener Clave SOL para GRE
     */
    public function getGreClaveSol(): ?string
    {
        return $this->gre_clave_sol ?? $this->clave_sol;
    }

    /**
     * Verificar si las credenciales GRE están configuradas
     */
    public function hasGreCredentials(): bool
    {
        $clientId = $this->getGreClientId();
        $clientSecret = $this->getGreClientSecret();
        
        return !empty($clientId) && !empty($clientSecret);
    }

    /**
     * Configurar credenciales GRE para un ambiente específico
     */
    public function setGreCredentials(array $credentials, string $environment = null): void
    {
        $environment = $environment ?? ($this->modo_produccion ? 'produccion' : 'beta');
        
        $updateData = [];
        
        if (isset($credentials['client_id'])) {
            $updateData["gre_client_id_{$environment}"] = $credentials['client_id'];
        }
        
        if (isset($credentials['client_secret'])) {
            $updateData["gre_client_secret_{$environment}"] = $credentials['client_secret'];
        }
        
        if (isset($credentials['ruc_proveedor'])) {
            $updateData['gre_ruc_proveedor'] = $credentials['ruc_proveedor'];
        }
        
        if (isset($credentials['usuario_sol'])) {
            $updateData['gre_usuario_sol'] = $credentials['usuario_sol'];
        }
        
        if (isset($credentials['clave_sol'])) {
            $updateData['gre_clave_sol'] = $credentials['clave_sol'];
        }
        
        if (!empty($updateData)) {
            $this->update($updateData);
            $this->clearConfigCache();
        }
    }

    /**
     * Limpiar credenciales GRE para un ambiente específico
     */
    public function clearGreCredentials(string $environment = null): void
    {
        $environment = $environment ?? ($this->modo_produccion ? 'produccion' : 'beta');
        
        $updateData = [
            "gre_client_id_{$environment}" => null,
            "gre_client_secret_{$environment}" => null,
        ];
        
        $this->update($updateData);
        $this->clearConfigCache();
    }

    /**
     * Copiar credenciales GRE de un ambiente a otro
     */
    public function copyGreCredentials(string $fromEnvironment, string $toEnvironment): bool
    {
        $fromClientId = $fromEnvironment === 'produccion' 
            ? $this->gre_client_id_produccion 
            : $this->gre_client_id_beta;
            
        $fromClientSecret = $fromEnvironment === 'produccion' 
            ? $this->gre_client_secret_produccion 
            : $this->gre_client_secret_beta;
            
        if (empty($fromClientId) || empty($fromClientSecret)) {
            return false;
        }
        
        $credentials = [
            'client_id' => $fromClientId,
            'client_secret' => $fromClientSecret
        ];
        
        $this->setGreCredentials($credentials, $toEnvironment);
        
        return true;
    }

    // ==================== MÉTODOS ESPECÍFICOS PARA CONFIGURACIONES DE SERVICIOS ====================

    /**
     * Obtener endpoints de servicios SUNAT
     */
    public function getSunatEndpoints(string $serviceType = 'facturacion'): array
    {
        $environment = $this->modo_produccion ? 'produccion' : 'beta';

        // Primero intentar company_configurations (override opcional)
        $configEndpoints = $this->getConfig('service_endpoints', $environment, $serviceType, null);

        if ($configEndpoints && !empty($configEndpoints['endpoint'] ?? '')) {
            return $configEndpoints;
        }

        // Fuente principal: campos directos del modelo Company
        if ($serviceType === 'facturacion') {
            $endpoint = $this->modo_produccion ? $this->endpoint_produccion : $this->endpoint_beta;
            return [
                'endpoint' => $endpoint,
                'wsdl' => $endpoint ? str_replace('billService', 'billService?wsdl', $endpoint) : '',
                'timeout' => $this->modo_produccion ? 45 : 30,
            ];
        }

        return [
            'endpoint' => '',
            'wsdl' => '',
            'timeout' => 30,
        ];
    }

    /**
     * Obtener endpoint específico para servicio
     */
    public function getSunatEndpoint(string $serviceType, string $type = 'endpoint'): string
    {
        $endpoints = $this->getSunatEndpoints($serviceType);
        return $endpoints[$type] ?? '';
    }

    /**
     * Obtener endpoint para facturas según modo
     */
    public function getInvoiceEndpoint(): string
    {
        return $this->getSunatEndpoint('facturacion', 'endpoint');
    }

    /**
     * Obtener endpoint para guías de remisión
     */
    public function getGuideEndpoint(): string
    {
        return $this->getSunatEndpoint('guias_remision', 'endpoint');
    }

    /**
     * Obtener API endpoint para guías de remisión
     */
    public function getGuideApiEndpoint(): string
    {
        return $this->getSunatEndpoint('guias_remision', 'api_endpoint');
    }

    // ==================== MÉTODOS ESPECÍFICOS PARA CONFIGURACIONES DE IMPUESTOS ====================

    /**
     * Obtener configuraciones de impuestos
     */
    public function getTaxSettings(): array
    {
        return $this->getConfig('tax_settings', 'general', null, [
            'igv_porcentaje' => 18.00,
            'icbper_monto' => 0.50,
            'ivap_porcentaje' => 4.00,
            'redondeo_automatico' => true,
        ]);
    }

    /**
     * Obtener porcentaje de IGV
     */
    public function getIgvPercentage(): float
    {
        $taxSettings = $this->getTaxSettings();
        return (float) ($taxSettings['igv_porcentaje'] ?? 18.00);
    }

    /**
     * Obtener monto del ICBPER
     */
    public function getIcbperAmount(): float
    {
        $taxSettings = $this->getTaxSettings();
        return (float) ($taxSettings['icbper_monto'] ?? 0.50);
    }

    // ==================== MÉTODOS DE CONFIGURACIÓN ESPECÍFICOS ====================

    /**
     * Obtener configuraciones de facturación
     */
    public function getInvoiceConfig(): array
    {
        return $this->getConfig('invoice_settings', 'general') ?? [];
    }

    /**
     * Obtener configuraciones de guías de remisión
     */
    public function getGuideConfig(): array
    {
        return $this->getConfig('gre_settings', 'general') ?? [];
    }

    /**
     * Obtener configuraciones de documentos
     */
    public function getDocumentConfig(): array
    {
        return $this->getConfig('document_settings', 'general') ?? [];
    }

    /**
     * Obtener configuraciones de archivos
     */
    public function getFileConfig(): array
    {
        return $this->getConfig('file_settings', 'general') ?? [];
    }

    // ==================== MÉTODOS DE VALIDACIÓN Y UTILIDAD ====================

    /**
     * Verificar si debe generar PDF automáticamente
     */
    public function shouldGeneratePdfAutomatically(): bool
    {
        $docConfig = $this->getDocumentConfig();
        return (bool) ($docConfig['generar_pdf_automatico'] ?? false);
    }

    /**
     * Verificar si debe enviar a SUNAT automáticamente
     */
    public function shouldSendToSunatAutomatically(): bool
    {
        $docConfig = $this->getDocumentConfig();
        return (bool) ($docConfig['enviar_sunat_automatico'] ?? false);
    }

    /**
     * Obtener resumen de configuraciones para logging
     */
    public function getConfigSummary(): array
    {
        $mode = $this->modo_produccion ? 'PRODUCCIÓN' : 'BETA';
        
        return [
            'modo' => $mode,
            'facturacion_endpoint' => $this->getInvoiceEndpoint(),
            'guias_endpoint' => $this->getGuideEndpoint(),
            'guias_api_endpoint' => $this->getGuideApiEndpoint(),
            'igv_porcentaje' => $this->getIgvPercentage(),
            'generar_pdf_auto' => $this->shouldGeneratePdfAutomatically(),
            'enviar_sunat_auto' => $this->shouldSendToSunatAutomatically(),
            'credenciales_gre_configuradas' => $this->hasGreCredentials(),
            'gre_client_id' => $this->getGreClientId() ? '***' . substr($this->getGreClientId(), -4) : 'No configurado',
        ];
    }

    /**
     * Inicializar configuraciones por defecto para una empresa nueva
     */
    public function initializeDefaultConfigurations(): void
    {
        // Solo inicializar si no tiene configuraciones
        if ($this->activeConfigurations()->count() > 0) {
            return;
        }

        $this->createDefaultConfigurations();
        $this->clearConfigCache();
    }

    /**
     * Crear configuraciones por defecto
     */
    protected function createDefaultConfigurations(): void
    {
        $defaultConfigs = $this->getDefaultConfigurationData();

        foreach ($defaultConfigs as $config) {
            $this->configurations()->create($config);
        }
    }

    /**
     * Obtener configuración completa de servicio SUNAT (compatibilidad con GreenterService)
     */
    public function getSunatServiceConfig(string $service): array
    {
        $environment = $this->modo_produccion ? 'produccion' : 'beta';
        
        // Mapear nombres de servicios
        $serviceMapping = [
            'facturacion' => 'facturacion',
            'guias_remision' => 'guias_remision',
            'resumenes_diarios' => 'facturacion', // Los resúmenes usan el mismo endpoint que facturas
        ];
        
        $serviceType = $serviceMapping[$service] ?? $service;
        
        // Obtener configuración usando el nuevo sistema
        return $this->getSunatEndpoints($serviceType);
    }

    /**
     * Fusionar configuraciones con las por defecto (compatibilidad con CompanyConfigService)
     */
    public function mergeWithDefaults(): void
    {
        // Inicializar configuraciones por defecto si no existen
        $this->initializeDefaultConfigurations();
    }

    /**
     * Obtener configuraciones en formato legacy (compatibilidad con CompanyConfigService)
     */
    public function getConfiguracionesAttribute(): array
    {
        // Convertir configuraciones de la nueva estructura al formato legacy
        $configurations = $this->getAllConfigurations();
        
        $legacy = [];
        
        foreach ($configurations as $configType => $environments) {
            foreach ($environments as $environment => $serviceTypes) {
                foreach ($serviceTypes as $serviceType => $configs) {
                    $config = $configs->first();
                    if ($config) {
                        $legacy = array_merge_recursive($legacy, [
                            $configType => [
                                $serviceType => [
                                    $environment => $config->config_data
                                ]
                            ]
                        ]);
                    }
                }
            }
        }
        
        return $legacy;
    }

    /**
     * Obtener configuraciones por defecto en formato compatible con ConfigurableCompany
     * Este método existe para compatibilidad con código que espera el trait ConfigurableCompany
     */
    public function getDefaultConfigurations(): array
    {
        return [
            'servicios_sunat' => [
                'facturacion' => [
                    'beta' => [
                        'endpoint' => 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService',
                        'wsdl' => 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService?wsdl',
                        'timeout' => 30,
                    ],
                    'produccion' => [
                        'endpoint' => 'https://e-factura.sunat.gob.pe/ol-ti-itcpfegem/billService',
                        'wsdl' => 'https://e-factura.sunat.gob.pe/ol-ti-itcpfegem/billService?wsdl',
                        'timeout' => 45,
                    ]
                ],
                'guias_remision' => [
                    'beta' => [
                        'endpoint' => 'https://gre-test.nubefact.com/v1',
                        'api_endpoint' => 'https://api-cpe-beta.sunat.gob.pe/v1/',
                        'wsdl' => 'https://e-beta.sunat.gob.pe/ol-ti-itcpgre-beta/billService?wsdl',
                        'timeout' => 30,
                    ],
                    'produccion' => [
                        'endpoint' => 'https://api-cpe.sunat.gob.pe/v1/',
                        'api_endpoint' => 'https://api-cpe.sunat.gob.pe/v1/',
                        'wsdl' => 'https://e-guiaremision.sunat.gob.pe/ol-ti-itemision-guia-gem/billService?wsdl',
                        'timeout' => 45,
                    ]
                ],
                'resumenes_diarios' => [
                    'beta' => [
                        'endpoint' => 'https://e-beta.sunat.gob.pe/ol-ti-itemision-otroscpe-gem-beta/billService',
                        'wsdl' => 'https://e-beta.sunat.gob.pe/ol-ti-itemision-otroscpe-gem-beta/billService?wsdl',
                        'timeout' => 60,
                    ],
                    'produccion' => [
                        'endpoint' => 'https://e-factura.sunat.gob.pe/ol-ti-itemision-otroscpe-gem/billService',
                        'wsdl' => 'https://e-factura.sunat.gob.pe/ol-ti-itemision-otroscpe-gem/billService?wsdl',
                        'timeout' => 60,
                    ]
                ],
                'comunicaciones_baja' => [
                    'beta' => [
                        'endpoint' => 'https://e-beta.sunat.gob.pe/ol-ti-itemision-otroscpe-gem-beta/billService',
                        'wsdl' => 'https://e-beta.sunat.gob.pe/ol-ti-itemision-otroscpe-gem-beta/billService?wsdl',
                        'timeout' => 60,
                    ],
                    'produccion' => [
                        'endpoint' => 'https://e-factura.sunat.gob.pe/ol-ti-itemision-otroscpe-gem/billService',
                        'wsdl' => 'https://e-factura.sunat.gob.pe/ol-ti-itemision-otroscpe-gem/billService?wsdl',
                        'timeout' => 60,
                    ]
                ],
                'retenciones' => [
                    'beta' => [
                        'endpoint' => 'https://e-beta.sunat.gob.pe/ol-ti-itemision-otroscpe-gem-beta/billService',
                        'wsdl' => 'https://e-beta.sunat.gob.pe/ol-ti-itemision-otroscpe-gem-beta/billService?wsdl',
                        'timeout' => 60,
                    ],
                    'produccion' => [
                        'endpoint' => 'https://e-factura.sunat.gob.pe/ol-ti-itemision-otroscpe-gem/billService',
                        'wsdl' => 'https://e-factura.sunat.gob.pe/ol-ti-itemision-otroscpe-gem/billService?wsdl',
                        'timeout' => 60,
                    ]
                ]
            ],

            'credenciales_gre' => [
                'beta' => [
                    'client_id' => 'test-85e5b0ae-255c-4891-a595-0b98c65c9854',
                    'client_secret' => 'test-Hty/M6QshYvPgItX2P0+Kw==',
                    'ruc_proveedor' => '20161515648',
                    'usuario_sol' => 'MODDATOS',
                    'clave_sol' => 'MODDATOS',
                ],
                'produccion' => [
                    'client_id' => null,
                    'client_secret' => null,
                    'ruc_proveedor' => null,
                    'usuario_sol' => null,
                    'clave_sol' => null,
                ]
            ],

            'facturacion' => [
                'igv_porcentaje' => 18.00,
                'isc_porcentaje' => 0.00,
                'icbper_monto' => 0.50,
                'ivap_porcentaje' => 4.00,
                'redondeo_automatico' => true,
                'decimales_precio_unitario' => 10,
                'decimales_cantidad' => 10,
                'incluir_leyenda_monto' => true,
                'validar_ruc_cliente' => false,
                'permitir_precio_cero' => false,
            ],

            'guias_remision' => [
                'peso_default_kg' => 1.000,
                'bultos_default' => 1,
                'verificacion_automatica' => true,
                'intentos_maximos_verificacion' => 5,
                'intervalo_verificacion_segundos' => 30,
                'timeout_consulta_segundos' => 300,
                'reintento_envio_fallido' => 3,
                'modalidad_transporte_default' => '02',
                'motivo_traslado_default' => '01',
            ],

            'resumenes_diarios' => [
                'generar_automatico' => false,
                'hora_generacion' => '23:59',
                'verificar_estado_automatico' => true,
                'intentos_maximos_verificacion' => 10,
                'intervalo_verificacion_minutos' => 5,
                'incluir_solo_pendientes' => true,
                'agrupar_por_fecha' => true,
            ],

            'comunicaciones_baja' => [
                'motivo_default' => '01',
                'generar_automatico_rechazo' => false,
                'verificar_estado_automatico' => true,
                'permitir_anulacion_mismo_dia' => true,
            ],

            'archivos' => [
                'conservar_xml' => true,
                'conservar_cdr' => true,
                'conservar_pdf' => true,
                'ruta_base' => 'sunat/{company_id}/{year}/{month}',
                'estructura_carpetas' => '{tipo_documento}/{serie}',
                'dias_conservar_archivos' => 2555,
                'comprimir_archivos_antiguos' => false,
                'backup_automatico' => false,
            ],

            'documentos' => [
                'generar_xml_automatico' => true,
                'generar_pdf_automatico' => false,
                'enviar_sunat_automatico' => false,
                'formato_pdf_default' => 'a4',
                'orientacion_pdf_default' => 'portrait',
                'incluir_qr_pdf' => true,
                'incluir_hash_pdf' => true,
                'marca_agua_pdf' => null,
                'logo_en_pdf' => true,
            ],

            'notificaciones' => [
                'email_envio_exitoso' => false,
                'email_envio_fallido' => true,
                'email_destinatario' => null,
                'email_copia_oculta' => null,
                'webhook_url' => null,
                'webhook_eventos' => ['enviado', 'aceptado', 'rechazado'],
                'webhook_token' => null,
            ],

            'seguridad' => [
                'log_transacciones' => true,
                'log_detallado' => false,
                'verificar_certificado_vigencia' => true,
                'dias_alerta_vencimiento_cert' => 30,
                'backup_certificado' => true,
                'encriptar_credenciales' => true,
            ],
        ];
    }

    /**
     * Obtener datos de configuraciones por defecto
     */
    protected function getDefaultConfigurationData(): array
    {
        return [
            // Endpoints de servicios SUNAT - Beta
            [
                'config_type' => 'service_endpoints',
                'environment' => 'beta',
                'service_type' => 'facturacion',
                'config_data' => [
                    'endpoint' => 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService',
                    'wsdl' => 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService?wsdl',
                    'timeout' => 30,
                ],
                'description' => 'Endpoints para facturación en ambiente beta'
            ],
            [
                'config_type' => 'service_endpoints',
                'environment' => 'beta',
                'service_type' => 'guias_remision',
                'config_data' => [
                    'endpoint' => 'https://gre-test.nubefact.com/v1',
                    'api_endpoint' => 'https://api-cpe-beta.sunat.gob.pe/v1/',
                    'wsdl' => 'https://e-beta.sunat.gob.pe/ol-ti-itcpgre-beta/billService?wsdl',
                    'timeout' => 30,
                ],
                'description' => 'Endpoints para guías de remisión en ambiente beta'
            ],

            // Endpoints de servicios SUNAT - Producción
            [
                'config_type' => 'service_endpoints',
                'environment' => 'produccion',
                'service_type' => 'facturacion',
                'config_data' => [
                    'endpoint' => 'https://e-factura.sunat.gob.pe/ol-ti-itcpfegem/billService',
                    'wsdl' => 'https://e-factura.sunat.gob.pe/ol-ti-itcpfegem/billService?wsdl',
                    'timeout' => 45,
                ],
                'description' => 'Endpoints para facturación en ambiente producción'
            ],
            [
                'config_type' => 'service_endpoints',
                'environment' => 'produccion',
                'service_type' => 'guias_remision',
                'config_data' => [
                    'endpoint' => 'https://api-cpe.sunat.gob.pe/v1/',
                    'api_endpoint' => 'https://api-cpe.sunat.gob.pe/v1/',
                    'wsdl' => 'https://e-guiaremision.sunat.gob.pe/ol-ti-itemision-guia-gem/billService?wsdl',
                    'timeout' => 45,
                ],
                'description' => 'Endpoints para guías de remisión en ambiente producción'
            ],

            // Credenciales SUNAT por defecto para beta
            [
                'config_type' => 'sunat_credentials',
                'environment' => 'beta',
                'service_type' => 'guias_remision',
                'config_data' => [
                    'client_id' => 'test-85e5b0ae-255c-4891-a595-0b98c65c9854',
                    'client_secret' => 'test-Hty/M6QshYvPgItX2P0+Kw==',
                    'ruc_proveedor' => '20161515648',
                    'usuario_sol' => 'MODDATOS',
                    'clave_sol' => 'MODDATOS',
                ],
                'description' => 'Credenciales por defecto para GRE en ambiente beta'
            ],

            // Configuraciones de impuestos
            [
                'config_type' => 'tax_settings',
                'environment' => 'general',
                'service_type' => 'general',
                'config_data' => [
                    'igv_porcentaje' => 18.00,
                    'isc_porcentaje' => 0.00,
                    'icbper_monto' => 0.50,
                    'ivap_porcentaje' => 4.00,
                    'redondeo_automatico' => true,
                ],
                'description' => 'Configuraciones de impuestos por defecto'
            ],

            // Configuraciones de documentos
            [
                'config_type' => 'document_settings',
                'environment' => 'general',
                'service_type' => 'general',
                'config_data' => [
                    'generar_xml_automatico' => true,
                    'generar_pdf_automatico' => false,
                    'enviar_sunat_automatico' => false,
                    'incluir_qr_pdf' => true,
                    'incluir_hash_pdf' => true,
                    'logo_en_pdf' => true,
                ],
                'description' => 'Configuraciones de documentos por defecto'
            ],

            // Configuraciones de archivos
            [
                'config_type' => 'file_settings',
                'environment' => 'general',
                'service_type' => 'general',
                'config_data' => [
                    'conservar_xml' => true,
                    'conservar_cdr' => true,
                    'conservar_pdf' => true,
                    'dias_conservar_archivos' => 2555, // 7 años aprox
                    'comprimir_archivos_antiguos' => false,
                    'backup_automatico' => false,
                ],
                'description' => 'Configuraciones de archivos por defecto'
            ]
        ];
    }
}