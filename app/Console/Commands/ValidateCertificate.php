<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Company;
use App\Services\GreenterService;
use App\Services\StorageService;

class ValidateCertificate extends Command
{
    protected $signature = 'sunat:validate-certificate {--company=1 : ID de la empresa}';
    protected $description = 'Valida el certificado digital configurado para SUNAT';

    public function handle()
    {
        $companyId = $this->option('company');
        $company = Company::find($companyId);

        if (!$company) {
            $this->error("❌ No se encontró la empresa con ID: {$companyId}");
            return;
        }

        $this->info("🔍 Validando certificado para empresa: {$company->razon_social}");
        $this->info("📄 RUC: {$company->ruc}");

        // Verificar que existe certificado
        if (empty($company->certificado_pem)) {
            $this->error("❌ No hay certificado configurado");
            return;
        }

        // Validar estructura PEM
        $this->validatePemStructure($company->certificado_pem);

        // Probar carga en Greenter
        try {
            $storageService = app(StorageService::class);
            $greenterService = new GreenterService($company, $storageService);
            $this->info("✅ Certificado cargado correctamente en Greenter");
        } catch (\Exception $e) {
            $this->error("❌ Error al cargar certificado en Greenter: " . $e->getMessage());
            $this->suggestSolutions();
            return;
        }

        // Mostrar información del certificado
        $this->showCertificateInfo($company->certificado_pem);
        
        $this->info("✅ Certificado válido y listo para usar con SUNAT");
    }

    private function validatePemStructure(string $pem)
    {
        $this->info("🔧 Validando estructura PEM...");

        // Verificar clave privada
        if (strpos($pem, '-----BEGIN PRIVATE KEY-----') !== false) {
            $this->info("  ✅ Clave privada encontrada");
        } else {
            $this->error("  ❌ Clave privada no encontrada");
        }

        // Verificar certificado
        if (strpos($pem, '-----BEGIN CERTIFICATE-----') !== false) {
            $this->info("  ✅ Certificado encontrado");
        } else {
            $this->error("  ❌ Certificado no encontrado");
        }

        // Verificar saltos de línea
        if (strpos($pem, "\r\n") !== false) {
            $this->warn("  ⚠️  Detectados saltos de línea Windows (\\r\\n) - se normalizarán automáticamente");
        }
    }

    private function showCertificateInfo(string $pem)
    {
        $this->info("📋 Información del certificado:");
        
        // Extraer el certificado (sin clave privada)
        if (preg_match('/-----BEGIN CERTIFICATE-----(.*?)-----END CERTIFICATE-----/s', $pem, $matches)) {
            $certData = base64_decode(preg_replace('/\s+/', '', $matches[1]));
            $certInfo = openssl_x509_parse($certData);
            
            if ($certInfo) {
                $this->info("  📅 Válido desde: " . date('Y-m-d H:i:s', $certInfo['validFrom_time_t']));
                $this->info("  📅 Válido hasta: " . date('Y-m-d H:i:s', $certInfo['validTo_time_t']));
                $this->info("  🏢 Emisor: " . $certInfo['issuer']['CN'] ?? 'N/A');
                $this->info("  👤 Sujeto: " . $certInfo['subject']['CN'] ?? 'N/A');
                
                // Verificar si está vencido
                if (time() > $certInfo['validTo_time_t']) {
                    $this->error("  ❌ CERTIFICADO VENCIDO");
                } else {
                    $this->info("  ✅ Certificado vigente");
                }
            }
        }
    }

    private function suggestSolutions()
    {
        $this->warn("\n🔧 Posibles soluciones:");
        $this->warn("1. Verifica que el certificado esté en formato PEM correcto");
        $this->warn("2. Asegúrate que incluya tanto la clave privada como el certificado");
        $this->warn("3. Revisa que no esté corrupto o mal formateado");
        $this->warn("4. Si usas certificado PKCS#12 (.pfx), conviértelo a PEM:");
        $this->warn("   openssl pkcs12 -in certificado.pfx -out certificado.pem -nodes");
    }
}