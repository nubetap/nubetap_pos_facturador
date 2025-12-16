<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\GreenterService;
use Illuminate\Console\Command;

class ValidateSunatConfiguration extends Command
{
    /**
     * Nombre y firma del comando
     */
    protected $signature = 'sunat:validate {company_id? : ID de la empresa a validar (opcional)}';

    /**
     * Descripción del comando
     */
    protected $description = 'Validar configuración SUNAT de empresas (certificados, credenciales, endpoints)';

    /**
     * Ejecutar el comando
     */
    public function handle()
    {
        $this->info('╔══════════════════════════════════════════════╗');
        $this->info('║   VALIDADOR DE CONFIGURACIÓN SUNAT          ║');
        $this->info('╚══════════════════════════════════════════════╝');
        $this->newLine();

        // Determinar qué empresas validar
        $companies = $this->argument('company_id')
            ? [Company::findOrFail($this->argument('company_id'))]
            : Company::active()->get();

        if ($companies->isEmpty()) {
            $this->error('No hay empresas activas para validar.');
            return Command::FAILURE;
        }

        $this->info("Validando {$companies->count()} empresa(s)...");
        $this->newLine();

        $totalValidated = 0;
        $totalErrors = 0;

        foreach ($companies as $company) {
            $errors = $this->validateCompany($company);

            if ($errors === 0) {
                $totalValidated++;
            } else {
                $totalErrors += $errors;
            }

            $this->newLine();
        }

        // Resumen final
        $this->info('═══════════════════════════════════════════════');
        $this->info('RESUMEN DE VALIDACIÓN');
        $this->info('═══════════════════════════════════════════════');
        $this->line("✓ Empresas válidas: <fg=green>{$totalValidated}</>");
        $this->line("✗ Total errores: <fg=red>{$totalErrors}</>");
        $this->newLine();

        return $totalErrors === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Validar una empresa específica
     */
    protected function validateCompany(Company $company): int
    {
        $errors = 0;

        $this->line("─────────────────────────────────────────────────");
        $this->info("Empresa: {$company->razon_social}");
        $this->line("RUC: {$company->ruc}");
        $this->line("Modo: " . ($company->modo_produccion ? '<fg=red>PRODUCCIÓN</>' : '<fg=yellow>BETA</>'));
        $this->line("─────────────────────────────────────────────────");

        // 1. Validar certificado
        $this->line('📄 Validando certificado...');
        $certPath = storage_path('app/public/certificado/certificado.pem');

        if (!file_exists($certPath)) {
            $this->error("  ✗ Certificado no encontrado: {$certPath}");
            $this->warn("    Solución: Coloque el archivo certificado.pem en {$certPath}");
            $errors++;
        } else {
            $certContent = file_get_contents($certPath);

            if (!$this->isValidPem($certContent)) {
                $this->error('  ✗ Certificado PEM inválido');
                $this->warn('    Solución: Verifique que el certificado contenga PRIVATE KEY y CERTIFICATE');
                $errors++;
            } else {
                // Verificar expiración
                if ($this->isCertificateExpired($certContent)) {
                    $this->warn('  ⚠ Certificado expirado');
                    $errors++;
                } else {
                    $this->info('  ✓ Certificado válido y vigente');
                }
            }
        }

        // 2. Validar credenciales SOL
        $this->line('🔐 Validando credenciales SOL...');

        if (empty($company->usuario_sol)) {
            $this->error('  ✗ Usuario SOL no configurado');
            $errors++;
        } else {
            $this->info("  ✓ Usuario SOL: {$company->usuario_sol}");
        }

        if (empty($company->clave_sol)) {
            $this->error('  ✗ Clave SOL no configurada');
            $errors++;
        } else {
            $this->info('  ✓ Clave SOL configurada');
        }

        // 3. Validar endpoints
        $this->line('🌐 Validando endpoints...');

        $endpoint = $company->modo_produccion
            ? $company->endpoint_produccion
            : $company->endpoint_beta;

        if (empty($endpoint)) {
            $this->error('  ✗ Endpoint no configurado');
            $errors++;
        } else {
            $this->info("  ✓ Endpoint: {$endpoint}");
        }

        // 4. Test de conexión con Greenter
        $this->line('🔗 Probando conexión con Greenter...');

        try {
            if (!empty($company->usuario_sol) && !empty($company->clave_sol) && file_exists($certPath)) {
                $greenter = new GreenterService($company);
                $config = $greenter->getServiceConfiguration();

                $this->info('  ✓ Greenter inicializado correctamente');
                $this->line("    - Modo: {$config['mode']}");
                $this->line("    - Timeout: {$config['timeout']}s");
            } else {
                $this->warn('  ⚠ No se puede probar conexión: faltan configuraciones');
                $errors++;
            }
        } catch (\Exception $e) {
            $this->error('  ✗ Error al inicializar Greenter: ' . $e->getMessage());
            $errors++;
        }

        // 5. Validar configuraciones GRE (Guías de Remisión)
        $this->line('🚚 Validando configuraciones GRE...');

        if ($company->gre_client_id_beta || $company->gre_client_id_produccion) {
            $clientId = $company->modo_produccion
                ? $company->gre_client_id_produccion
                : $company->gre_client_id_beta;

            if ($clientId) {
                $this->info("  ✓ Client ID configurado");
            } else {
                $this->warn('  ⚠ Client ID no configurado para el modo actual');
            }
        } else {
            $this->line('  - GRE no configurado (opcional)');
        }

        return $errors;
    }

    /**
     * Validar si el certificado PEM tiene estructura válida
     */
    protected function isValidPem(string $content): bool
    {
        $hasCertificate = str_contains($content, '-----BEGIN CERTIFICATE-----') &&
                         str_contains($content, '-----END CERTIFICATE-----');

        $hasPrivateKey = (str_contains($content, '-----BEGIN PRIVATE KEY-----') &&
                         str_contains($content, '-----END PRIVATE KEY-----')) ||
                        (str_contains($content, '-----BEGIN RSA PRIVATE KEY-----') &&
                         str_contains($content, '-----END RSA PRIVATE KEY-----'));

        return $hasCertificate && $hasPrivateKey;
    }

    /**
     * Verificar si el certificado está expirado
     */
    protected function isCertificateExpired(string $content): bool
    {
        try {
            // Extraer solo el certificado (sin la clave privada)
            preg_match('/-----BEGIN CERTIFICATE-----(.*?)-----END CERTIFICATE-----/s', $content, $matches);

            if (!isset($matches[0])) {
                return false;
            }

            $cert = openssl_x509_read($matches[0]);

            if ($cert === false) {
                return false;
            }

            $certData = openssl_x509_parse($cert);

            if (!isset($certData['validTo_time_t'])) {
                return false;
            }

            return time() > $certData['validTo_time_t'];

        } catch (\Exception $e) {
            return false;
        }
    }
}
